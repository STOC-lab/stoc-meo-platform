<?php

namespace App\Services\Instagram;

use App\Enums\InstagramTokenStatus;
use App\Models\InstagramAccount;
use App\Services\Instagram\Exceptions\InstagramAuthenticationException;
use App\Services\Instagram\Exceptions\InstagramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Publishes to an Instagram professional account through Meta's Graph API.
 *
 * Publishing is two calls, not one: a container is created for the image and
 * caption, and then that container is published. Meta requires the image to be
 * at a URL it can fetch — it does not accept an upload — so a campaign's
 * source image has to be reachable before this is called.
 *
 * A token Meta has stopped honouring is marked on the connection so the job
 * stops rather than retrying into the same refusal.
 */
class InstagramClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
    ) {}

    /**
     * Publish one image post, answering with Meta's id for it.
     *
     * @throws InstagramException
     */
    public function publishImage(InstagramAccount $account, string $imageUrl, string $caption): string
    {
        $container = $this->createContainer($account, $imageUrl, $caption);

        return $this->publishContainer($account, $container);
    }

    /**
     * @throws InstagramException
     */
    protected function createContainer(InstagramAccount $account, string $imageUrl, string $caption): string
    {
        $body = $this->post($account, "/{$account->ig_user_id}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]);

        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw InstagramException::for('the media container was not created');
        }

        return $id;
    }

    /**
     * @throws InstagramException
     */
    protected function publishContainer(InstagramAccount $account, string $containerId): string
    {
        $body = $this->post($account, "/{$account->ig_user_id}/media_publish", [
            'creation_id' => $containerId,
        ]);

        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw InstagramException::for('the post was not published');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws InstagramException
     */
    protected function post(InstagramAccount $account, string $path, array $payload): array
    {
        if (! $account->isUsable()) {
            throw InstagramAuthenticationException::because('the connection is '.$account->token_status->value);
        }

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->acceptJson()
                ->post(ltrim($path, '/'), [
                    ...$payload,
                    'access_token' => (string) $account->accessToken(),
                ]);
        } catch (ConnectionException $e) {
            throw InstagramException::for($e->getMessage());
        }

        if ($response->status() === 401 || $this->looksLikeATokenProblem($response)) {
            $account->markUnusable(InstagramTokenStatus::Expired);

            throw InstagramAuthenticationException::because($this->errorMessage($response));
        }

        if ($response->failed()) {
            throw InstagramException::for('HTTP '.$response->status().' '.$this->errorMessage($response));
        }

        return (array) $response->json();
    }

    /**
     * Meta answers 400 for an expired or withdrawn token as readily as for a
     * bad argument, and says which in the error subcode: 190 is the OAuth
     * family, and 102 is a session that is no longer valid.
     */
    protected function looksLikeATokenProblem(Response $response): bool
    {
        $code = (int) ($response->json('error.code') ?? 0);
        $type = (string) ($response->json('error.type') ?? '');

        return in_array($code, [102, 190], true) || $type === 'OAuthException';
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message') ?? $response->reason() ?? '');
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://graph.facebook.com'), '/')
            .'/'.trim((string) ($this->config['version'] ?? 'v21.0'), '/');
    }
}
