<?php

namespace App\Services\GBP;

use App\Models\GbpAccount;
use App\Models\Location;
use App\Services\GBP\Clients\GBPBusinessInfoClient;
use App\Services\GBP\Clients\GBPClient;
use App\Services\GBP\Clients\GBPLocalPostsClient;
use App\Services\GBP\Clients\GBPPerformanceClient;
use App\Services\GBP\Clients\GBPReviewsClient;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * Builds the Business Profile clients for one store front's connection.
 *
 * Every client needs the same three things — an HTTP factory, the token
 * refresher and the connection monitor — and differs only in which API it
 * talks to, so they are assembled here rather than resolved individually from
 * the container.
 */
class GBPClientFactory
{
    public function __construct(
        protected HttpFactory $http,
        protected GBPTokenRefresher $tokens,
        protected GBPConnectionMonitor $monitor,
    ) {}

    public function businessInfo(GbpAccount $account): GBPBusinessInfoClient
    {
        return $this->make(GBPBusinessInfoClient::class, $account);
    }

    public function reviews(GbpAccount $account): GBPReviewsClient
    {
        return $this->make(GBPReviewsClient::class, $account);
    }

    public function localPosts(GbpAccount $account): GBPLocalPostsClient
    {
        return $this->make(GBPLocalPostsClient::class, $account);
    }

    public function performance(GbpAccount $account): GBPPerformanceClient
    {
        return $this->make(GBPPerformanceClient::class, $account);
    }

    /**
     * The connection behind a store front, refusing one that cannot be called
     * with rather than letting each caller discover it.
     *
     * @throws GBPAuthenticationException
     */
    public function connectionFor(Location $location): GbpAccount
    {
        $account = GbpAccount::acrossTenants()
            ->where('location_id', $location->getKey())
            ->first();

        if ($account === null) {
            throw GBPAuthenticationException::for('connection', 'the store front is not connected to a Business Profile');
        }

        if (! $account->isUsable()) {
            throw GBPAuthenticationException::for('connection', 'the connection is '.$account->token_status->value);
        }

        return $account;
    }

    /**
     * @template TClient of GBPClient
     *
     * @param  class-string<TClient>  $client
     * @return TClient
     */
    protected function make(string $client, GbpAccount $account): GBPClient
    {
        if (! is_subclass_of($client, GBPClient::class)) {
            throw new InvalidArgumentException("[{$client}] is not a Business Profile client.");
        }

        return new $client($this->http, $this->tokens, $this->monitor, $account);
    }
}
