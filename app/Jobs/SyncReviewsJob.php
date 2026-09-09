<?php

namespace App\Jobs;

use App\Models\Location;
use App\Models\Review;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies one store front's reviews down from Google.
 *
 * Google owns the review, so the sync matches on its id and updates what it
 * already has rather than adding a second copy — a review whose text or rating
 * was edited comes back changed, and a reply left through the Google interface
 * arrives here the same way.
 *
 * A connection Google has stopped honouring is not worth retrying: the client
 * has already marked it and raised the alert, so the job stops rather than
 * spending its attempts on an answer that will not change.
 */
class SyncReviewsJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'gbp';

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public Location $location)
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
        $tenancy->forOrganization($this->location->organization_id, function () use ($clients) {
            try {
                $account = $clients->connectionFor($this->location);
            } catch (GBPAuthenticationException $e) {
                return;
            }

            $reviews = $clients->reviews($account)->reviews(
                (string) $account->gbp_account_name,
                (string) $this->location->gbp_location_id,
            );

            foreach ($reviews as $review) {
                $this->store($review);
            }

            $account->forceFill(['last_synced_at' => now()])->save();

            // A review that arrived overnight is answered without anyone
            // opening the application — on the plans that ask for it.
            AutoReplyReviewsJob::dispatch($this->location);
        });
    }

    /**
     * @param  array<string, mixed>  $review
     */
    protected function store(array $review): void
    {
        $googleId = $review['reviewId'] ?? $review['name'] ?? null;

        if (! is_string($googleId) || $googleId === '') {
            return;
        }

        $reply = $review['reviewReply'] ?? null;

        Review::acrossTenants()->updateOrCreate(
            [
                'location_id' => $this->location->getKey(),
                'google_review_id' => $googleId,
            ],
            [
                'organization_id' => $this->location->organization_id,
                'author_name' => $review['reviewer']['displayName'] ?? null,
                'author_photo_url' => $review['reviewer']['profilePhotoUrl'] ?? null,
                'rating' => $this->rating($review['starRating'] ?? null),
                'comment' => $review['comment'] ?? null,
                'reply' => $reply['comment'] ?? null,
                'replied_at' => $this->timestamp($reply['updateTime'] ?? null),
                'reviewed_at' => $this->timestamp($review['createTime'] ?? null),
            ],
        );
    }

    /**
     * Google names the stars rather than numbering them.
     */
    protected function rating(?string $starRating): ?int
    {
        return match ($starRating) {
            'ONE' => 1,
            'TWO' => 2,
            'THREE' => 3,
            'FOUR' => 4,
            'FIVE' => 5,
            default => null,
        };
    }

    protected function timestamp(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'gbp',
            'reviews',
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
