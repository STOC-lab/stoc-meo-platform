<?php

namespace App\Jobs;

use App\Enums\CampaignPostStatus;
use App\Models\ContentCampaignPost;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Exceptions\AIRefusalException;
use App\Services\AI\Prompts\CampaignContentPrompt;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Writes one campaign post for one channel.
 *
 * One job per post rather than one for the campaign: the same theme is written
 * differently for each channel, and a model that fails on the Instagram
 * caption should not cost the Business Profile one.
 *
 * The post is left awaiting approval. Nothing published to a customer-facing
 * channel goes straight from the model to the public.
 */
class GenerateCampaignContentJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ai';

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public ContentCampaignPost $post)
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
        CampaignContentPrompt $prompt,
        Tenancy $tenancy,
    ): void {
        $tenancy->forOrganization($this->post->organization_id, function () use ($providers, $prompt) {
            $post = $this->post;

            if ($post->status->isFinished()) {
                return;
            }

            // Claim it, so a second dispatch of the same post does not run the
            // model twice for one caption.
            if (! $post->transition(CampaignPostStatus::Pending, CampaignPostStatus::AiGenerating)
                && $post->status !== CampaignPostStatus::AiGenerating) {
                return;
            }

            $campaign = $post->campaign;

            if ($campaign === null) {
                $this->giveUp($post, 'キャンペーンが見つかりません。');

                return;
            }

            $channel = $post->channel;
            $userPrompt = $prompt->user($campaign, $channel);

            try {
                $result = $providers->make()->complete(
                    $userPrompt,
                    $prompt->system($channel),
                    [
                        'model' => (string) config('ai.campaign.model', 'fast'),
                        'max_tokens' => (int) config('ai.campaign.max_tokens', 1500),
                    ],
                );
            } catch (AIRefusalException $e) {
                $this->giveUp($post, 'AIがこのテーマでの生成を見送りました。テーマを見直してください。');

                return;
            } catch (AIException $e) {
                $post->recordAttempt($e->getMessage());

                throw $e;
            }

            [$content, $hashtags] = $prompt->split(
                $result->content,
                $channel,
                (int) config('ai.campaign.hashtag_limit', 12),
            );

            if ($content === '') {
                $this->giveUp($post, 'AIが空の投稿文を返しました。');

                return;
            }

            $post->forceFill([
                'ai_prompt' => $userPrompt,
                'ai_content' => $content,
                'ai_hashtags' => $hashtags,
                'status' => CampaignPostStatus::AwaitingApproval,
                'last_error' => null,
            ])->save();
        });
    }

    protected function giveUp(ContentCampaignPost $post, string $reason): void
    {
        $post->markFailed($reason);

        $this->fail(new RuntimeException($reason));
    }

    public function failed(?Throwable $exception): void
    {
        app(Tenancy::class)->forOrganization($this->post->organization_id, function () use ($exception) {
            $post = $this->post->fresh();

            if ($post === null || $post->status->isFinished()) {
                return;
            }

            $post->markFailed($exception?->getMessage() ?? '投稿文の生成に失敗しました。');
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'ai',
            'campaign',
            'channel:'.$this->post->channel->value,
            'organization:'.$this->post->organization_id,
            'campaign_post:'.$this->post->getKey(),
        ];
    }
}
