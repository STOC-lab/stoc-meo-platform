<?php

namespace App\Jobs;

use App\Enums\AiReplyStatus;
use App\Models\Review;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Sends an approved reply draft to Google.
 *
 * This is the last step of the AI reply flow — generate, approve, publish. The
 * draft becomes the review's real reply only once Google has taken it, so a
 * failed call leaves the store front honestly showing as unanswered.
 *
 * It runs on the Business Profile queue rather than the AI one: the work is an
 * outbound call to Google, and it shares that connection's rate limit.
 */
class PublishReviewReplyJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'gbp';

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
        return [60, 300, 900];
    }

    public function handle(GBPClientFactory $clients, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->review->organization_id, function () use ($clients) {
            $review = $this->review;

            if ($review->ai_reply_status !== AiReplyStatus::Approved) {
                return;
            }

            $comment = (string) $review->ai_reply;

            if ($comment === '') {
                $this->giveUp($review, '返信文が空のため送信できません。');

                return;
            }

            $location = $review->location;

            try {
                $account = $clients->connectionFor($location);

                $clients->reviews($account)->reply(
                    (string) $account->gbp_account_name,
                    (string) $location->gbp_location_id,
                    $review->google_review_id,
                    $comment,
                );
            } catch (GBPAuthenticationException $e) {
                $this->giveUp($review, 'Googleビジネスプロフィールとの連携が切れています。再接続してください。');

                return;
            }

            // Only now is it the store front's real answer.
            $review->forceFill([
                'reply' => $comment,
                'replied_at' => now(),
                'ai_reply_status' => AiReplyStatus::Published,
                'ai_reply_error' => null,
            ])->save();
        });
    }

    protected function giveUp(Review $review, string $reason): void
    {
        $review->forceFill([
            'ai_reply_status' => AiReplyStatus::Failed,
            'ai_reply_error' => mb_substr($reason, 0, 255),
        ])->save();

        $this->fail(new RuntimeException($reason));
    }

    public function failed(?Throwable $exception): void
    {
        app(Tenancy::class)->forOrganization($this->review->organization_id, function () use ($exception) {
            $review = $this->review->fresh();

            if ($review === null || $review->ai_reply_status?->isFinished()) {
                return;
            }

            $review->forceFill([
                'ai_reply_status' => AiReplyStatus::Failed,
                'ai_reply_error' => mb_substr($exception?->getMessage() ?? 'Googleへの返信に失敗しました。', 0, 255),
            ])->save();
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'gbp',
            'review-reply',
            'organization:'.$this->review->organization_id,
            'review:'.$this->review->getKey(),
        ];
    }
}
