<?php

namespace App\Services\Instagram;

use App\Services\Instagram\Exceptions\InstagramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * The Instagram Login round trip: the consent URL, and the three calls that
 * turn the code it hands back into a connection worth storing.
 *
 * The code buys a token that lasts an hour. That one is exchanged at once for
 * a long-lived token of about sixty days, which is the only one kept, and the
 * profile is read with it so the settings screen can say which account was
 * connected.
 */
class InstagramOAuth
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
    ) {}

    /**
     * Whether the Meta app's credentials are configured at all.
     */
    public function isAvailable(): bool
    {
        return filled($this->config['app_id'] ?? null)
            && filled($this->config['app_secret'] ?? null)
            && filled($this->config['redirect'] ?? null);
    }

    public function authorizationUrl(string $state): string
    {
        return $this->config['authorize_url'].'?'.http_build_query([
            'client_id' => $this->config['app_id'],
            'redirect_uri' => $this->config['redirect'],
            'response_type' => 'code',
            'scope' => implode(',', (array) ($this->config['scopes'] ?? [])),
            'state' => $state,
        ]);
    }

    /**
     * Trade the authorization code for a long-lived token and the profile it
     * belongs to.
     *
     * @return array{ig_user_id: string, username: string|null, access_token: string, expires_in: int|null}
     *
     * @throws InstagramException
     */
    public function connect(string $code): array
    {
        $shortLived = $this->exchangeCode($code);
        $longLived = $this->exchangeForLongLived($shortLived);
        $profile = $this->profile($longLived['access_token']);

        return [
            'ig_user_id' => $profile['user_id'],
            'username' => $profile['username'],
            'access_token' => $longLived['access_token'],
            'expires_in' => $longLived['expires_in'],
        ];
    }

    /**
     * @throws InstagramException
     */
    protected function exchangeCode(string $code): string
    {
        $response = $this->send(fn () => $this->http
            ->asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post((string) $this->config['token_url'], [
                'client_id' => $this->config['app_id'],
                'client_secret' => $this->config['app_secret'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->config['redirect'],
                'code' => $code,
            ]));

        // Meta has answered both flat and wrapped in `data` for this call.
        $token = $response->json('access_token') ?? $response->json('data.0.access_token');

        if (! is_string($token) || $token === '') {
            throw InstagramException::for('the authorization code was not exchanged for a token');
        }

        return $token;
    }

    /**
     * @return array{access_token: string, expires_in: int|null}
     *
     * @throws InstagramException
     */
    protected function exchangeForLongLived(string $shortLivedToken): array
    {
        $response = $this->send(fn () => $this->http
            ->acceptJson()
            ->timeout($this->timeout())
            ->get($this->graphUrl('access_token'), [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->config['app_secret'],
                'access_token' => $shortLivedToken,
            ]));

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw InstagramException::for('the token was not exchanged for a long-lived one');
        }

        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $token,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
        ];
    }

    /**
     * The professional account's id and username.
     *
     * `user_id` is the Instagram account id that publishing addresses; `id` is
     * scoped to the app, and is only the fallback.
     *
     * @return array{user_id: string, username: string|null}
     *
     * @throws InstagramException
     */
    protected function profile(string $accessToken): array
    {
        $response = $this->send(fn () => $this->http
            ->acceptJson()
            ->timeout($this->timeout())
            ->get($this->graphUrl($this->version().'/me'), [
                'fields' => 'user_id,username',
                'access_token' => $accessToken,
            ]));

        $userId = $response->json('user_id') ?? $response->json('id');

        if (! is_scalar($userId) || (string) $userId === '') {
            throw InstagramException::for('the account profile could not be read');
        }

        $username = $response->json('username');

        return [
            'user_id' => (string) $userId,
            'username' => is_string($username) ? $username : null,
        ];
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws InstagramException
     */
    protected function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw InstagramException::for($e->getMessage());
        }

        if ($response->failed()) {
            $message = $response->json('error_message')
                ?? $response->json('error.message')
                ?? $response->reason();

            throw InstagramException::for('HTTP '.$response->status().' '.$message);
        }

        return $response;
    }

    protected function graphUrl(string $path): string
    {
        return rtrim((string) $this->config['graph_url'], '/').'/'.ltrim($path, '/');
    }

    protected function version(): string
    {
        return trim((string) ($this->config['version'] ?? 'v21.0'), '/');
    }

    protected function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 60);
    }
}
