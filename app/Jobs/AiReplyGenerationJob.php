<?php

namespace App\Jobs;

use App\Enums\AiReplyStatus;
use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Models\Review;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Exceptions\AIRefusalException;
use App\Services\AI\Prompts\ReviewReplyPrompt;
use App\Services\FeatureResolver;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Drafts a reply to one review.
 *
 * The draft is never sent to Google from here. It is written to ai_reply,
 * beside rather than over the reply Google actually holds, and left awaiting
 * approval — unless the plan includes review.auto_reply.enabled, in which case
 * it is approved on the spot and handed to the job that publishes it.
 *
 * The monthly allowance is charged when the draft is claimed, so the retries
 * below cost the organization nothing extra, and a model that declines ends
 * the work rather than being asked again.
 */
class AiReplyGenerationJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ai';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Review $review)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        AIProviderFactory $providers,
        ReviewReplyPrompt $prompt,
        FeatureResolver $features,
        UsageTracker $usage,
        Tenancy $tenancy,
    ): void {
        $tenancy->forOrganization($this->review->organization_id, function () use ($providers, $prompt, $features, $usage) {
            $review = $this->review;
            $organization = $review->organization;

            if ($review->ai_reply_status === AiReplyStatus::Published) {
                return;
            }

            // Claiming the draft is what charges the allowance, so a retry
            // finds it already claimed and does not charge again.
            if ($this->claim($review) && ! $this->chargeAllowance($review, $usage, $organization)) {
                return;
            }

            try {
                $result = $providers->make()->complete(
                    $prompt->user($review),
                    $prompt->system(),
                    [
                        'model' => (string) config('ai.review_reply.model', 'fast'),
                        'max_tokens' => (int) config('ai.review_reply.max_tokens', 600),
                    ],
                );
            } catch (AIRefusalException $e) {
                // The same request would be declined again.
                $this->giveUp($review, 'AIが返信文の生成を見送りました。手動で返信してください。');

                return;
            } catch (AIException $e) {
                $review->forceFill(['ai_reply_error' => mb_substr($e->getMessage(), 0, 255)])->save();

                throw $e;
            }

            $autoReply = $features->allows(Feature::ReviewAutoReplyEnabled, $organization);

            $review->forceFill([
                'ai_reply' => $result->content,
                'ai_reply_model' => $result->model,
                'ai_reply_generated_at' => now(),
                'ai_reply_status' => $autoReply ? AiReplyStatus::Approved : AiReplyStatus::AwaitingApproval,
                'ai_reply_error' => null,
            ])->save();

            // A plan with automatic replies skips the person; every other plan
            // waits for one.
            if ($autoReply) {
                PublishReviewReplyJob::dispatch($review);
            }
        });
    }

    /**
     * Take the draft, answering whether this call is the one that took it.
     */
    protected function claim(Review $review): bool
    {
        $claimed = Review::acrossTenants()
            ->whereKey($review->getKey())
            ->where(function ($query) {
                $query->whereNull('ai_reply_status')
                    ->orWhere('ai_reply_status', AiReplyStatus::Failed->value);
            })
            ->update(['ai_reply_status' => AiReplyStatus::Generating->value]);

        if ($claimed === 0) {
            return false;
        }

        $review->setAttribute('ai_reply_status', AiReplyStatus::Generating);

        return true;
    }

    /**
     * @throws QuotaExceededException
     */
    protected function chargeAllowance(Review $review, UsageTracker $usage, $organization): bool
    {
        try {
            $usage->consume(Feature::ReviewAiReplyMonthlyLimit, 1, $organization);
        } catch (QuotaExceededException $e) {
            $this->giveUp($review, 'ご利用中のプランの今月の上限に達しました。');

            return false;
        }

        return true;
    }

    protected function giveUp(Review $review, string $reason): void
    {
        $review->forceFill([
            'ai_reply_status' => AiReplyStatus::Failed,
            'ai_reply_error' => mb_substr($reason, 0, 255),
        ])->save();

        $this->fail(new \RuntimeException($reason));
    }

    /**
     * Once the retries are spent the draft is closed off, so it is visible
     * with its reason rather than left forever generating.
     */
    public function failed(?Throwable $exception): void
    {
        app(Tenancy::class)->forOrganization($this->review->organization_id, function () use ($exception) {
            $review = $this->review->fresh();

            if ($review === null || $review->ai_reply_status?->isFinished()) {
                return;
            }

            $review->forceFill([
                'ai_reply_status' => AiReplyStatus::Failed,
                'ai_reply_error' => mb_substr($exception?->getMessage() ?? 'AI返信の生成に失敗しました。', 0, 255),
            ])->save();
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'ai',
            'review-reply',
            'organization:'.$this->review->organization_id,
            'review:'.$this->review->getKey(),
        ];
    }
}
