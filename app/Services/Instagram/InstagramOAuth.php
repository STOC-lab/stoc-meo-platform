<?php

namespace App\Services\Instagram;

use App\Services\Instagram\Exceptions\InstagramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * The Facebook Login round trip for the Instagram API: the consent URL, and
 * the calls that turn the code it hands back into a connection worth storing.
 *
 * Instagram is reached through a Facebook Page here, not through Instagram
 * Login. The code buys a short-lived user token, which is exchanged at once
 * for a long-lived one of about sixty days — the only one kept — and the
 * professional account is found as the one linked to a Page the person
 * manages. That long-lived user token is what InstagramClient publishes with
 * on graph.facebook.com.
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
        return rtrim((string) $this->config['authorize_url'], '/').'/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->config['app_id'],
            'redirect_uri' => $this->config['redirect'],
            'response_type' => 'code',
            'scope' => implode(',', (array) ($this->config['scopes'] ?? [])),
            'state' => $state,
            'display' => 'page',
            // Walks someone whose Instagram account is not yet linked to a
            // Page through linking it, instead of returning no account.
            'extras' => json_encode(['setup' => ['channel' => 'IG_API_ONBOARDING']]),
        ]);
    }

    /**
     * Trade the authorization code for a long-lived token and the Instagram
     * professional account it can act for.
     *
     * @return array{ig_user_id: string, username: string|null, access_token: string, expires_in: int|null}
     *
     * @throws InstagramException
     */
    public function connect(string $code): array
    {
        $shortLived = $this->exchangeCode($code);
        $longLived = $this->exchangeForLongLived($shortLived);
        $account = $this->instagramAccount($longLived['access_token']);

        return [
            'ig_user_id' => $account['id'],
            'username' => $account['username'],
            'access_token' => $longLived['access_token'],
            'expires_in' => $longLived['expires_in'],
        ];
    }

    /**
     * @throws InstagramException
     */
    protected function exchangeCode(string $code): string
    {
        $response = $this->get('oauth/access_token', [
            'client_id' => $this->config['app_id'],
            'client_secret' => $this->config['app_secret'],
            'redirect_uri' => $this->config['redirect'],
            'code' => $code,
        ]);

        $token = $response->json('access_token');

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
        $response = $this->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->config['app_id'],
            'client_secret' => $this->config['app_secret'],
            'fb_exchange_token' => $shortLivedToken,
        ]);

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
     * The first Instagram professional account linked to a Page the person
     * manages. Someone managing several is connected to the first for now;
     * choosing between them is the settings screen's job once it exists.
     *
     * @return array{id: string, username: string|null}
     *
     * @throws InstagramException
     */
    protected function instagramAccount(string $accessToken): array
    {
        $response = $this->get('me/accounts', [
            'fields' => 'id,name,instagram_business_account{id,username}',
            'access_token' => $accessToken,
        ]);

        foreach ((array) $response->json('data', []) as $page) {
            $account = $page['instagram_business_account'] ?? null;

            if (is_array($account) && filled($account['id'] ?? null)) {
                return [
                    'id' => (string) $account['id'],
                    'username' => is_string($account['username'] ?? null) ? $account['username'] : null,
                ];
            }
        }

        throw InstagramException::for('no Facebook Page with a linked Instagram professional account was granted');
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws InstagramException
     */
    protected function get(string $path, array $query): Response
    {
        try {
            $response = $this->http
                ->baseUrl($this->graphUrl())
                ->acceptJson()
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw InstagramException::for($e->getMessage());
        }

        if ($response->failed()) {
            throw InstagramException::for('HTTP '.$response->status().' '.($response->json('error.message') ?? $response->reason()));
        }

        return $response;
    }

    protected function graphUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://graph.facebook.com'), '/').'/'.$this->version();
    }

    protected function version(): string
    {
        return trim((string) ($this->config['version'] ?? 'v26.0'), '/');
    }
}
