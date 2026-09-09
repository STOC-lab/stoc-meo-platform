<?php

namespace App\Jobs;

use App\Enums\CampaignPostStatus;
use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Models\ContentCampaignPost;
use App\Models\InstagramAccount;
use App\Services\Instagram\Exceptions\InstagramAuthenticationException;
use App\Services\Instagram\Exceptions\InstagramException;
use App\Services\Instagram\InstagramClient;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Publishes one campaign post to Instagram.
 *
 * Its own job, as design v1.3 §22 asks: Instagram failing — a token Meta has
 * withdrawn, an image it cannot fetch — leaves the campaign's Business Profile
 * and blog posts untouched, because nothing about them passes through here.
 *
 * The post is claimed with a conditional transition before anything is sent,
 * so a retry after an ambiguous timeout cannot publish the same image twice,
 * and the monthly allowance is charged on that claim.
 */
class InstagramPublishJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'social';

    public int $tries = 3;

    public int $timeout = 300;

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

    public function handle(InstagramClient $instagram, UsageTracker $usage, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->post->organization_id, function () use ($instagram, $usage) {
            $post = $this->post;

            if ($post->status->isFinished()) {
                return;
            }

            if ($post->transition(CampaignPostStatus::Approved, CampaignPostStatus::Publishing)
                && ! $this->chargeAllowance($post, $usage)) {
                return;
            }

            if ($post->status !== CampaignPostStatus::Publishing) {
                return;
            }

            $campaign = $post->campaign;
            $account = $this->connectionFor($post);

            if ($account === null) {
                $this->giveUp($post, 'Instagramアカウントが接続されていません。');

                return;
            }

            $imageUrl = $campaign?->source_image_path;

            // Meta fetches the image itself, so a post without one cannot be
            // published as an image post at all.
            if (! filled($imageUrl)) {
                $this->giveUp($post, 'Instagramへの投稿には画像が必要です。');

                return;
            }

            try {
                $mediaId = $instagram->publishImage($account, (string) $imageUrl, (string) $post->ai_content);
            } catch (InstagramAuthenticationException $e) {
                $this->giveUp($post, 'Instagramとの連携が切れています。再接続してください。');

                return;
            } catch (InstagramException $e) {
                $post->recordAttempt($e->getMessage());

                throw $e;
            }

            $post->markPublished($mediaId);

            $account->forceFill(['last_published_at' => now()])->save();
        });
    }

    protected function connectionFor(ContentCampaignPost $post): ?InstagramAccount
    {
        $locationId = $post->campaign?->location_id;

        if ($locationId === null) {
            return null;
        }

        $account = InstagramAccount::acrossTenants()->where('location_id', $locationId)->first();

        return $account?->isUsable() === true ? $account : null;
    }

    /**
     * @throws QuotaExceededException
     */
    protected function chargeAllowance(ContentCampaignPost $post, UsageTracker $usage): bool
    {
        try {
            $usage->consume(Feature::InstagramPostMonthlyLimit, 1, $post->organization);
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

            $post->markFailed($exception?->getMessage() ?? 'Instagramへの投稿に失敗しました。');
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'instagram',
            'campaign',
            'organization:'.$this->post->organization_id,
            'campaign_post:'.$this->post->getKey(),
        ];
    }
}
