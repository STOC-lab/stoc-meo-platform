<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Answers Stripe's HTTP calls from canned responses instead of the network.
 *
 * It sits under the real SDK rather than replacing it, so a test still
 * exercises Stripe's own serialisation and object hydration — the shape of the
 * parameters recorded here is the shape that would have gone over the wire.
 *
 * Install it with `\Stripe\ApiRequestor::setHttpClient()`, which is global, so
 * a test that sets one has to unset it again.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /**
     * Every request made, in order.
     *
     * @var array<int, array{method: string, path: string, url: string, params: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * Canned responses keyed by "METHOD /path", each a [body, status] pair.
     * A path with no response queued is answered 404, which is what Stripe
     * says about an object that does not exist.
     *
     * @var array<string, array<int, array{0: array<string, mixed>, 1: int}>>
     */
    protected array $responses = [];

    /**
     * Queue a response for a request. Queue several for one key to answer
     * repeated calls differently — a product that is missing and then exists.
     *
     * @param  array<string, mixed>  $body
     */
    public function push(string $method, string $path, array $body, int $status = 200): static
    {
        $this->responses[strtoupper($method).' '.$path][] = [$body, $status];

        return $this;
    }

    /**
     * The parameters of the nth request to a given method and path.
     *
     * @return array<string, mixed>|null
     */
    public function paramsFor(string $method, string $path, int $index = 0): ?array
    {
        return collect($this->requests)
            ->where('method', strtolower($method))
            ->where('path', $path)
            ->values()
            ->get($index)['params'] ?? null;
    }

    public function countFor(string $method, string $path): int
    {
        return collect($this->requests)
            ->where('method', strtolower($method))
            ->where('path', $path)
            ->count();
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);

        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'url' => $absUrl,
            'params' => $params,
        ];

        $key = strtoupper($method).' '.$path;

        [$body, $status] = empty($this->responses[$key])
            ? [['error' => ['type' => 'invalid_request_error', 'message' => "No such object: {$path}"]], 404]
            : array_shift($this->responses[$key]);

        return [json_encode($body), $status, []];
    }
}
