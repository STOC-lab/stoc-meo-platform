<?php

namespace App\Services\Ranking;

use App\Models\Keyword;
use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads local rank from DataForSEO's Google Maps SERP endpoint.
 *
 * The live endpoint is used rather than the task queue: one keyword is one
 * call, which keeps a failure to a single keyword and lets the job's own
 * retries handle it. calculate_rectangles stays off, as settled in design
 * v1.3 — the pixel geometry it adds is not something the product reads, and it
 * costs on every call.
 *
 * Sandbox mode swaps the host for DataForSEO's sandbox, which answers with the
 * same shape at no cost. It is the default until live credentials are in
 * place.
 *
 * A check may name the point it is run from, which is how a heatmap asks the
 * same keyword from every square of its grid. DataForSEO takes either a named
 * region or a coordinate but not both, so a coordinate replaces location_name
 * for that call rather than narrowing it.
 */
class DataForSEOProvider implements RankProviderInterface
{
    public const NAME = 'dataforseo';

    public const ENDPOINT = '/v3/serp/google/maps/live/advanced';

    /**
     * DataForSEO answers 20000 on success, at both the envelope and task level.
     */
    public const STATUS_OK = 20000;

    /**
     * Statuses that describe the moment rather than the request. 401 is here
     * because DataForSEO answers a burst of live calls from one account with
     * it as readily as it answers a bad password: on 2026-09-11 three of five
     * keywords in the same second were served and two were refused, with the
     * credentials verified good either side of the sweep. Retrying costs one
     * call; not retrying costs that keyword its day.
     */
    public const TRANSIENT_STATUSES = [401, 408, 425, 429];

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
        return filled($this->config['login'] ?? null)
            && filled($this->config['password'] ?? null);
    }

    public function fetch(Keyword $keyword, ?GeoPoint $from = null): RankResult
    {
        $response = $this->call($keyword, $from);
        $task = $this->firstTask($response);
        $result = $task['result'][0] ?? [];
        $searchUrl = $result['check_url'] ?? null;

        foreach ($result['items'] ?? [] as $item) {
            if ($this->matches($item, $keyword->location)) {
                return new RankResult(
                    $this->rankOf($item),
                    $this->name(),
                    $searchUrl,
                );
            }
        }

        return RankResult::notFound($this->name(), $searchUrl);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RankProviderException
     */
    protected function call(Keyword $keyword, ?GeoPoint $from = null): array
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->withBasicAuth((string) $this->config['login'], (string) $this->config['password'])
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->acceptJson()
                ->post(self::ENDPOINT, [$this->task($keyword, $from)]);
        } catch (ConnectionException $e) {
            throw RankProviderException::transient($this->name(), $e->getMessage());
        }

        if ($response->failed()) {
            throw RankProviderException::for(
                $this->name(),
                "HTTP {$response->status()}",
                $this->isTransientStatus($response->status()),
            );
        }

        try {
            $body = (array) $response->json();
        } catch (Throwable $e) {
            // A reply that is not JSON at all is a gateway or a proxy talking,
            // not DataForSEO rejecting the request.
            throw RankProviderException::transient($this->name(), 'the response was not JSON');
        }

        if (($body['status_code'] ?? null) !== self::STATUS_OK) {
            throw RankProviderException::for(
                $this->name(),
                (string) ($body['status_message'] ?? 'unexpected status '.($body['status_code'] ?? 'none')),
                $this->isTransientStatus($body['status_code'] ?? null),
            );
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws RankProviderException
     */
    protected function firstTask(array $body): array
    {
        $task = $body['tasks'][0] ?? null;

        if (! is_array($task)) {
            throw RankProviderException::transient($this->name(), 'the response carried no task');
        }

        if (($task['status_code'] ?? null) !== self::STATUS_OK) {
            throw RankProviderException::for(
                $this->name(),
                (string) ($task['status_message'] ?? 'the task did not complete'),
                $this->isTransientStatus($task['status_code'] ?? null),
            );
        }

        return $task;
    }

    /**
     * Whether a status is worth asking again.
     *
     * DataForSEO's own codes are its HTTP status with two more digits — 40100
     * is a 401, 50000 a 500 — so both kinds of status are read the same way
     * once the extra digits are taken off. Anything the far side blames on
     * itself counts, along with the throttles listed above; everything else is
     * a rejected request that would be rejected again.
     */
    protected function isTransientStatus(int|string|null $status): bool
    {
        if ($status === null) {
            return false;
        }

        $status = (int) $status;

        if ($status >= 10000) {
            $status = intdiv($status, 100);
        }

        return $status >= 500 || in_array($status, self::TRANSIENT_STATUSES, true);
    }

    /**
     * The request body for one keyword, optionally pinned to the point the
     * search is run from.
     *
     * @return array<string, mixed>
     */
    protected function task(Keyword $keyword, ?GeoPoint $from = null): array
    {
        return [
            'keyword' => $keyword->keyword,
            'language_code' => (string) ($this->config['language_code'] ?? 'ja'),
            ...$from === null
                ? ['location_name' => (string) ($this->config['location_name'] ?? 'Japan')]
                : ['location_coordinate' => $from->toCoordinateString($this->zoom())],
            'device' => 'desktop',
            'os' => 'windows',
            'depth' => (int) ($this->config['depth'] ?? 100),
            'calculate_rectangles' => false,
        ];
    }

    /**
     * The map zoom a coordinate search is run at. It decides how much ground
     * one grid point sees, so it is configurable rather than fixed here.
     */
    protected function zoom(): int
    {
        return (int) ($this->config['zoom'] ?? 14);
    }

    /**
     * Whether a result row is this store front. The Google Business Profile id
     * is the reliable answer when the store front has been linked; otherwise
     * the name is all there is to go on.
     *
     * @param  array<string, mixed>  $item
     */
    protected function matches(array $item, Location $location): bool
    {
        if (filled($location->gbp_location_id)) {
            $id = Str::afterLast($location->gbp_location_id, '/');

            foreach (['place_id', 'cid', 'feature_id'] as $field) {
                if (filled($item[$field] ?? null) && (string) $item[$field] === $id) {
                    return true;
                }
            }
        }

        return $this->normalize((string) ($item['title'] ?? '')) === $this->normalize($location->name);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function rankOf(array $item): ?int
    {
        $rank = $item['rank_absolute'] ?? $item['rank_group'] ?? null;

        return is_numeric($rank) ? (int) $rank : null;
    }

    protected function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', '', $value) ?? $value);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['sandbox'] ?? true
            ? $this->config['sandbox_url']
            : $this->config['base_url']), '/');
    }
}
