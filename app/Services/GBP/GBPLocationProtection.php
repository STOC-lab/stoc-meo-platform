<?php

namespace App\Services\GBP;

use App\Models\GbpAccount;
use App\Models\Location;
use App\Services\GBP\Exceptions\GBPException;

/**
 * Holding a store front's Business Profile to the snapshot the application
 * keeps of it.
 *
 * The snapshot is kept in Google's own shape — `profile.description` is
 * stored as `profile => [description => …]` — so what is read back can be
 * compared with it and written to Google without translation.
 */
class GBPLocationProtection
{
    /**
     * What is asked of Google: the top-level field behind each protectable
     * path.
     */
    public const READ_MASK = 'title,phoneNumbers,websiteUri,regularHours,profile';

    public function __construct(protected GBPClientFactory $clients) {}

    /**
     * The protectable fields as Google holds them right now.
     *
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    public function snapshot(GbpAccount $account, Location $location): array
    {
        return $this->pick($this->current($account, $location), Location::GBP_PROTECTABLE_FIELDS);
    }

    /**
     * The location as Google holds it right now.
     *
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    public function current(GbpAccount $account, Location $location): array
    {
        return $this->clients->businessInfo($account)
            ->location((string) $location->gbp_location_id, self::READ_MASK);
    }

    /**
     * The protected fields where Google no longer agrees with the snapshot.
     *
     * A field the snapshot holds nothing for is left alone: the snapshot is
     * what the store front is put back to, and putting it back to nothing
     * would erase whatever was filled in since.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $canonical
     * @param  array<int, string>  $protectedFields
     * @return list<string>
     */
    public function drift(array $current, array $canonical, array $protectedFields): array
    {
        $drifted = [];

        foreach ($protectedFields as $field) {
            $expected = data_get($canonical, $field);

            if ($expected === null) {
                continue;
            }

            if ($this->normalise($expected) !== $this->normalise(data_get($current, $field))) {
                $drifted[] = $field;
            }
        }

        return $drifted;
    }

    /**
     * The given fields out of a location, nested as Google nests them.
     *
     * @param  array<string, mixed>  $location
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function pick(array $location, array $fields): array
    {
        $picked = [];

        foreach ($fields as $field) {
            $value = data_get($location, $field);

            if ($value !== null) {
                data_set($picked, $field, $value);
            }
        }

        return $picked;
    }

    /**
     * Google does not promise the order of an object's keys, so two readings
     * of the same hours can differ only in that. Lists keep their order.
     */
    protected function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->normalise(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
