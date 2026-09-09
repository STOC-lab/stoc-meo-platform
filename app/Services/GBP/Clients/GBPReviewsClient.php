<?php

namespace App\Services\GBP\Clients;

use App\Enums\GbpApi;
use App\Services\GBP\Exceptions\GBPException;

/**
 * Reviews, on v4 of the original Google My Business API.
 *
 * Reviews were never given a place in the APIs Google split the old one into,
 * so v4 is not a legacy path here — it is the only one, and design v1.3 says
 * so. Its resource names carry the account as well as the location, which the
 * newer APIs dropped.
 */
class GBPReviewsClient extends GBPClient
{
    public function api(): GbpApi
    {
        return GbpApi::Legacy;
    }

    /**
     * Every review on a store front, newest first.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GBPException
     */
    public function reviews(string $accountName, string $locationName): array
    {
        return $this->paginate(
            $this->reviewsPath($accountName, $locationName),
            'reviews',
            ['pageSize' => 50, 'orderBy' => 'updateTime desc'],
        );
    }

    /**
     * Answer a review. Google has one endpoint for writing and rewriting a
     * reply, so this is both.
     *
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    public function reply(string $accountName, string $locationName, string $reviewId, string $comment): array
    {
        return $this->put(
            $this->reviewsPath($accountName, $locationName).'/'.$this->reviewId($reviewId).'/reply',
            ['comment' => $comment],
        );
    }

    protected function reviewsPath(string $accountName, string $locationName): string
    {
        $account = str_starts_with($accountName, 'accounts/') ? $accountName : 'accounts/'.$accountName;
        $location = str_starts_with($locationName, 'locations/') ? $locationName : 'locations/'.$locationName;

        return $account.'/'.$location.'/reviews';
    }

    /**
     * A review is sometimes held as its full resource name and sometimes as
     * the trailing id alone.
     */
    protected function reviewId(string $review): string
    {
        return str_contains($review, '/') ? substr($review, strrpos($review, '/') + 1) : $review;
    }
}
