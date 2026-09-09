<?php

namespace App\Services\GBP\Clients;

use App\Enums\GbpApi;
use App\Enums\GbpTokenStatus;
use App\Models\GbpAccount;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPConnectionMonitor;
use App\Services\GBP\GBPTokenRefresher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * What every Business Profile client shares: which API it speaks to, how a
 * request is authenticated, and what happens when Google says no.
 *
 * A token that has run out is renewed before the call rather than after a
 * failure, so an expired access token costs nothing. A 401 that survives that
 * means the grant itself is gone: the connection is marked unusable and an
 * alert is raised, because no amount of retrying will bring it back — someone
 * has to reconnect the account.
 */
abstract class GBPClient
{
    public function __construct(
        protected HttpFactory $http,
        protected GBPTokenRefresher $tokens,
        protected GBPConnectionMonitor $monitor,
        protected GbpAccount $account,
    ) {}

    /**
     * Which of the eight APIs this client speaks to.
     */
    abstract public function api(): GbpApi;

    public function account(): GbpAccount
    {
        return $this->account;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    protected function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    protected function put(string $path, array $payload = []): array
    {
        return $this->send('put', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws GBPException
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        $token = $this->authorize();

        $response = $this->call($method, $path, $data, $token);

        // A 401 with a token we had just renewed means the grant is gone
        // rather than the token being stale.
        if ($response->status() === 401) {
            $this->monitor->markUnusable($this->account, GbpTokenStatus::Expired);

            throw GBPAuthenticationException::for($this->api()->value, 'HTTP 401');
        }

        if ($response->status() === 403 && $this->looksRevoked($response)) {
            $this->monitor->markUnusable($this->account, GbpTokenStatus::Revoked);

            throw GBPAuthenticationException::for($this->api()->value, 'HTTP 403');
        }

        if ($response->failed()) {
            throw GBPException::for(
                $this->api()->value,
                'HTTP '.$response->status().' '.$this->errorMessage($response),
            );
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function call(string $method, string $path, array $data, string $token): Response
    {
        try {
            return $this->http
                ->baseUrl($this->api()->baseUrl())
                ->withToken($token)
                ->timeout((int) config('gbp.timeout', 30))
                ->acceptJson()
                ->{$method}(ltrim($path, '/'), $data);
        } catch (ConnectionException $e) {
            throw GBPException::for($this->api()->value, $e->getMessage());
        }
    }

    /**
     * The access token to call with, renewed first when it has run out.
     *
     * @throws GBPAuthenticationException
     */
    protected function authorize(): string
    {
        if (! $this->account->token_status->isUsable()) {
            throw GBPAuthenticationException::for(
                $this->api()->value,
                'the connection is '.$this->account->token_status->value,
            );
        }

        if ($this->account->hasExpiredAccessToken()) {
            $this->tokens->refresh($this->account);
        }

        $token = $this->account->accessToken();

        if (! filled($token)) {
            $this->monitor->markUnusable($this->account, GbpTokenStatus::Expired);

            throw GBPAuthenticationException::for($this->api()->value, 'no access token is stored');
        }

        return $token;
    }

    /**
     * Google answers 403 for both "this account may not do that" and "the
     * grant is gone". Only the second is worth disconnecting over.
     */
    protected function looksRevoked(Response $response): bool
    {
        $status = (string) ($response->json('error.status') ?? '');
        $message = (string) ($response->json('error.message') ?? '');

        return $status === 'PERMISSION_DENIED'
            && str_contains(strtolower($message), 'revoked');
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message') ?? $response->reason() ?? '');
    }

    /**
     * Walk a list endpoint to the end, gathering one field from each page.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws GBPException
     */
    protected function paginate(string $path, string $field, array $query = [], int $maxPages = 20): array
    {
        $items = [];
        $pageToken = null;
        $pages = 0;

        do {
            $body = $this->get($path, $pageToken === null ? $query : [...$query, 'pageToken' => $pageToken]);

            foreach ($body[$field] ?? [] as $item) {
                $items[] = $item;
            }

            $pageToken = $body['nextPageToken'] ?? null;
            $pages++;
            // A profile with an unreasonable amount of history should not hold
            // a worker forever; the sweep will catch up next time.
        } while (filled($pageToken) && $pages < $maxPages);

        return $items;
    }
}
