<?php

namespace App\Jobs;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Models\ContentCampaignPost;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPClientFactory;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Publishes one campaign post to a Business Profile.
 *
 * Instagram has its own job; this is the other half of the same split, so a
 * Business Profile failure leaves the Instagram post alone in exactly the same
 * way. WordPress is a channel the design names but nothing publishes to yet,
 * so a blog post is failed with a reason rather than silently left pending.
 */
class PublishCampaignPostJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'social';

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
        return [60, 300, 900];
    }

    public function handle(GBPClientFactory $clients, UsageTracker $usage, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->post->organization_id, function () use ($clients, $usage) {
            $post = $this->post;

            if ($post->status->isFinished()) {
                return;
            }

            if ($post->channel === CampaignChannel::Wordpress) {
                $this->giveUp($post, 'WordPressへの投稿は未対応です。');

                return;
            }

            if ($post->transition(CampaignPostStatus::Approved, CampaignPostStatus::Publishing)
                && ! $this->chargeAllowance($post, $usage)) {
                return;
            }

            if ($post->status !== CampaignPostStatus::Publishing) {
                return;
            }

            $location = $post->campaign?->location;

            if ($location === null) {
                $this->giveUp($post, '対象の店舗が見つかりません。');

                return;
            }

            try {
                $account = $clients->connectionFor($location);

                $published = $clients->localPosts($account)->create(
                    (string) $account->gbp_account_name,
                    (string) $location->gbp_location_id,
                    (string) $post->ai_content,
                    $post->campaign?->source_image_path,
                );
            } catch (GBPAuthenticationException $e) {
                $this->giveUp($post, 'Googleビジネスプロフィールとの連携が切れています。再接続してください。');

                return;
            } catch (GBPException $e) {
                $post->recordAttempt($e->getMessage());

                throw $e;
            }

            $post->markPublished((string) ($published['name'] ?? ''));
        });
    }

    /**
     * @throws QuotaExceededException
     */
    protected function chargeAllowance(ContentCampaignPost $post, UsageTracker $usage): bool
    {
        try {
            $usage->consume(Feature::GbpPostMonthlyLimit, 1, $post->organization);
        } catch (QuotaExceededException $e) {
            $this->giveUp($post, 'ご利用中のプランの今月の上限に達しました。');

            return false;
        }

        return true;
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

            $post->markFailed($exception?->getMessage() ?? '投稿に失敗しました。');
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'campaign',
            'channel:'.$this->post->channel->value,
            'organization:'.$this->post->organization_id,
            'campaign_post:'.$this->post->getKey(),
        ];
    }
}
