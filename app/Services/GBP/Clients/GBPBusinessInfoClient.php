<?php

namespace App\Services\GBP\Clients;

use App\Enums\GbpApi;
use App\Services\GBP\Exceptions\GBPException;

/**
 * The store front's own details — name, address, hours, categories — on the
 * Business Information API, which is where Google moved them when the old
 * v4 API was broken up.
 */
class GBPBusinessInfoClient extends GBPClient
{
    public function api(): GbpApi
    {
        return GbpApi::BusinessInformation;
    }

    /**
     * Every location the connected account can administer.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GBPException
     */
    public function locations(string $accountName, string $readMask = 'name,title,storefrontAddress,phoneNumbers,websiteUri'): array
    {
        return $this->paginate(
            $this->accountPath($accountName).'/locations',
            'locations',
            ['readMask' => $readMask, 'pageSize' => 100],
        );
    }

    /**
     * One location's details.
     *
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    public function location(string $locationName, string $readMask = 'name,title,storefrontAddress,phoneNumbers,websiteUri,regularHours,categories'): array
    {
        return $this->get($this->locationPath($locationName), ['readMask' => $readMask]);
    }

    /**
     * Change some of a location's details. Google requires the mask to name
     * exactly the fields being changed, so it is derived from the payload
     * rather than left to the caller to keep in step.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    public function updateLocation(string $locationName, array $attributes): array
    {
        return $this->put(
            $this->locationPath($locationName).'?updateMask='.implode(',', array_keys($attributes)),
            $attributes,
        );
    }

    /**
     * Google names a location "locations/{id}"; callers may hold either form.
     */
    protected function locationPath(string $name): string
    {
        return str_starts_with($name, 'locations/') ? $name : 'locations/'.$name;
    }

    protected function accountPath(string $name): string
    {
        return str_starts_with($name, 'accounts/') ? $name : 'accounts/'.$name;
    }
}
