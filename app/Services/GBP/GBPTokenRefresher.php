<?php

namespace App\Services\GBP;

use App\Enums\GbpTokenStatus;
use App\Models\GbpAccount;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Exchanges a stored refresh token for a new access token.
 *
 * Google distinguishes between a refresh that failed for a reason worth
 * retrying and one that failed because the grant is gone: invalid_grant is the
 * second, and means the account was disconnected, the password changed, or the
 * token went unused too long. Only that marks the connection revoked; anything
 * else leaves it alone so the next attempt can succeed.
 */
class GBPTokenRefresher
{
    public function __construct(
        protected HttpFactory $http,
        protected GBPConnectionMonitor $monitor,
    ) {}

    /**
     * Renew the connection's access token in place.
     *
     * @throws GBPAuthenticationException
     */
    public function refresh(GbpAccount $account): GbpAccount
    {
        $refreshToken = $account->refreshToken();

        if (! filled($refreshToken)) {
            $this->monitor->markUnusable($account, GbpTokenStatus::Expired);

            throw GBPAuthenticationException::for('token', 'no refresh token is stored');
        }

        try {
            $response = $this->http
                ->asForm()
                ->timeout((int) config('gbp.timeout', 30))
                ->acceptJson()
                ->post((string) config('gbp.token.endpoint'), [
                    'client_id' => (string) config('services.google.client_id'),
                    'client_secret' => (string) config('services.google.client_secret'),
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (ConnectionException $e) {
            throw GBPAuthenticationException::for('token', $e->getMessage());
        }

        if ($response->failed()) {
            $this->handleFailure($account, (string) ($response->json('error') ?? ''), $response->status());
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            $this->monitor->markUnusable($account, GbpTokenStatus::Expired);

            throw GBPAuthenticationException::for('token', 'the refresh returned no access token');
        }

        // Google issues a new refresh token only occasionally; when it does,
        // the old one stops working, so it has to replace what is stored.
        if (filled($rotated = $response->json('refresh_token'))) {
            $account->forceFill(['refresh_token_encrypted' => $rotated])->save();
        }

        $account->storeAccessToken($accessToken, $response->json('expires_in'));

        return $account;
    }

    /**
     * @throws GBPAuthenticationException
     */
    protected function handleFailure(GbpAccount $account, string $error, int $status): never
    {
        // invalid_grant is Google saying the grant itself is gone; everything
        // else is worth another attempt later.
        $this->monitor->markUnusable(
            $account,
            $error === 'invalid_grant' ? GbpTokenStatus::Revoked : GbpTokenStatus::Expired,
        );

        throw GBPAuthenticationException::for('token', $error !== '' ? $error : "HTTP {$status}");
    }
}
