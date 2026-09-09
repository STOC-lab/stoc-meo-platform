<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Exceptions\AIRefusalException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Anthropic's Messages API, reached over HTTP the same way the DataForSEO and
 * Business Profile clients reach theirs.
 *
 * The work here is one prompt and one answer — a review reply, a caption — so
 * this calls /v1/messages directly rather than pulling in an SDK for a surface
 * this narrow.
 *
 * Two models are configured: the fast one writes review replies and captions,
 * and the stronger one is there for work that needs more judgement. Which is
 * used is the caller's choice, defaulting to the fast one.
 *
 * A refusal comes back as HTTP 200 with stop_reason "refusal" rather than as
 * an error, so stop_reason is checked before the content is read — otherwise a
 * decline would look like an empty answer.
 */
class ClaudeProvider implements AIProviderInterface
{
    public const NAME = 'claude';

    public const ENDPOINT = '/v1/messages';

    /**
     * The API version header Anthropic requires on every request.
     */
    public const API_VERSION = '2023-06-01';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isAvailable(): bool
    {
        return filled($this->config['api_key'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws AIException
     */
    public function complete(string $prompt, ?string $system = null, array $options = []): AIResult
    {
        $model = $this->model($options['model'] ?? null);

        $payload = array_filter([
            'model' => $model,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 1024),
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $options['temperature'] ?? null,
        ], fn ($value) => $value !== null);

        $body = $this->call($payload);

        $this->guardRefusal($body);

        return new AIResult(
            content: trim($this->textOf($body)),
            model: (string) ($body['model'] ?? $model),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            stopReason: $body['stop_reason'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AIException
     */
    protected function call(array $payload): array
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->withHeaders([
                    'x-api-key' => (string) $this->config['api_key'],
                    'anthropic-version' => self::API_VERSION,
                ])
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->acceptJson()
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            throw AIException::for($this->name(), $e->getMessage());
        }

        if ($response->failed()) {
            throw AIException::for(
                $this->name(),
                'HTTP '.$response->status().' '.(string) ($response->json('error.message') ?? ''),
            );
        }

        try {
            return (array) $response->json();
        } catch (Throwable $e) {
            throw AIException::for($this->name(), 'the response was not JSON');
        }
    }

    /**
     * A decline arrives as a successful response, so it has to be looked for
     * rather than caught.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws AIRefusalException
     */
    protected function guardRefusal(array $body): void
    {
        if (($body['stop_reason'] ?? null) === 'refusal') {
            throw AIRefusalException::because($this->name(), $body['stop_details']['category'] ?? null);
        }
    }

    /**
     * The text the model wrote, gathered from the blocks that carry any. A
     * response can hold more than one text block, and blocks of other kinds
     * are not text.
     *
     * @param  array<string, mixed>  $body
     */
    protected function textOf(array $body): string
    {
        $parts = [];

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * The model to call: the one asked for when it is one this application is
     * configured to use, and the fast one otherwise.
     */
    protected function model(?string $requested): string
    {
        $models = (array) ($this->config['models'] ?? []);

        if ($requested !== null && in_array($requested, $models, true)) {
            return $requested;
        }

        if ($requested !== null && isset($models[$requested])) {
            return (string) $models[$requested];
        }

        return (string) ($models['fast'] ?? 'claude-haiku-4-5');
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.anthropic.com'), '/');
    }
}
