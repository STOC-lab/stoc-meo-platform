<?php

namespace App\Services\GBP\Clients;

use App\Enums\GbpApi;
use App\Services\GBP\Exceptions\GBPException;

/**
 * The Business Profile accounts a Google user can act for, on the Account
 * Management API. A connection is made with a Google user, but reviews and
 * posts hang off an account ("accounts/{id}"), so one has to be chosen before
 * anything can be synced.
 */
class GBPAccountClient extends GBPClient
{
    public function api(): GbpApi
    {
        return GbpApi::AccountManagement;
    }

    /**
     * Every account the connected Google user can administer.
     *
     * @return array<int, array{name: string, id: string, account_name: string, type: string|null}>
     *
     * @throws GBPException
     */
    public function accounts(): array
    {
        return array_map(
            fn (array $account): array => [
                'name' => (string) $account['name'],
                'id' => substr((string) $account['name'], strlen('accounts/')),
                'account_name' => (string) ($account['accountName'] ?? $account['name']),
                'type' => $account['type'] ?? null,
            ],
            $this->paginate('accounts', 'accounts', ['pageSize' => 20]),
        );
    }
}
