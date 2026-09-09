<?php

namespace App\Services\GBP\Clients;

use App\Enums\GbpApi;
use App\Services\GBP\Exceptions\GBPException;

/**
 * Local posts, on v4 of the original Google My Business API — like reviews,
 * they were never moved to one of the newer APIs.
 */
class GBPLocalPostsClient extends GBPClient
{
    public function api(): GbpApi
    {
        return GbpApi::Legacy;
    }

    /**
     * The posts already on a store front.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GBPException
     */
    public function posts(string $accountName, string $locationName): array
    {
        return $this->paginate(
            $this->postsPath($accountName, $locationName),
            'localPosts',
            ['pageSize' => 50],
        );
    }

    /**
     * Publish a post. summary is the text; a photo and a call to action are
     * both optional, and are left out of the payload rather than sent empty,
     * which Google rejects.
     *
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    public function create(
        string $accountName,
        string $locationName,
        string $summary,
        ?string $mediaUrl = null,
        ?string $ctaType = null,
        ?string $ctaUrl = null,
    ): array {
        $payload = [
            'languageCode' => 'ja',
            'summary' => $summary,
            'topicType' => 'STANDARD',
        ];

        if (filled($mediaUrl)) {
            $payload['media'] = [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl' => $mediaUrl,
            ]];
        }

        if (filled($ctaType)) {
            $payload['callToAction'] = array_filter([
                'actionType' => $ctaType,
                // CALL is the one action type Google takes without a url.
                'url' => $ctaUrl,
            ], fn ($value) => filled($value));
        }

        return $this->post($this->postsPath($accountName, $locationName), $payload);
    }

    protected function postsPath(string $accountName, string $locationName): string
    {
        $account = str_starts_with($accountName, 'accounts/') ? $accountName : 'accounts/'.$accountName;
        $location = str_starts_with($locationName, 'locations/') ? $locationName : 'locations/'.$locationName;

        return $account.'/'.$location.'/localPosts';
    }
}
