<?php

namespace App\Jobs;

use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Models\GbpPost;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\GBPClientFactory;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Sends one stored post to a store front's Business Profile.
 *
 * The post is claimed with a conditional update before anything is sent, so a
 * retry cannot publish the same thing to Google twice, and the organization's
 * monthly allowance is charged on that claim rather than per attempt.
 *
 * A connection Google has stopped honouring ends the job rather than retrying
 * it: the post stays failed with the reason, and the alert the client raised
 * is what asks someone to reconnect.
 */
class PublishGbpPostJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'gbp';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public GbpPost $post)
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

            if ($post->claim() && ! $this->chargeAllowance($post, $usage)) {
                return;
            }

            $location = $post->location;

            try {
                $account = $clients->connectionFor($location);
            } catch (GBPAuthenticationException $e) {
                $this->giveUp($post, 'Googleビジネスプロフィールとの連携が切れています。再接続してください。');

                return;
            }

            try {
                $published = $clients->localPosts($account)->create(
                    (string) $account->gbp_account_name,
                    (string) $location->gbp_location_id,
                    $post->content,
                    $post->media_url,
                    $post->cta_type,
                    $post->cta_url,
                );
            } catch (GBPAuthenticationException $e) {
                $this->giveUp($post, 'Googleビジネスプロフィールとの連携が切れています。再接続してください。');

                return;
            }

            $post->markPublished((string) ($published['name'] ?? ''));
        });
    }

    /**
     * Charge the post against the plan's monthly allowance, answering whether
     * there was room. Running out is the organization's answer rather than a
     * fault, so the post is marked failed and not retried.
     */
    protected function chargeAllowance(GbpPost $post, UsageTracker $usage): bool
    {
        try {
            $usage->consume(Feature::GbpPostMonthlyLimit, 1, $post->organization);
        } catch (QuotaExceededException $e) {
            $this->giveUp($post, 'ご利用中のプランの今月の上限に達しました。');

            return false;
        }

        return true;
    }

    /**
     * Stop for a reason retrying cannot fix.
     */
    protected function giveUp(GbpPost $post, string $reason): void
    {
        $post->markFailed($reason);

        $this->fail(new RuntimeException($reason));
    }

    /**
     * Once the retries are spent the post is closed off, so it is visible with
     * its reason rather than left forever mid-publish.
     */
    public function failed(?Throwable $exception): void
    {
        app(Tenancy::class)->forOrganization($this->post->organization_id, function () use ($exception) {
            $post = $this->post->fresh();

            if ($post === null || $post->status->isFinished()) {
                return;
            }

            $post->markFailed($exception?->getMessage() ?? 'Googleへの投稿に失敗しました。');
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'gbp',
            'posts',
            'organization:'.$this->post->organization_id,
            'gbp_post:'.$this->post->getKey(),
        ];
    }
}
