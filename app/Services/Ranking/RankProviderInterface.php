<?php

namespace App\Services\Ranking;

use App\Models\Keyword;

/**
 * A source of local rank data.
 *
 * A provider that cannot answer for reasons outside the caller's control — the
 * API is down, the credentials are wrong, the response is unreadable — throws
 * RankProviderException so the router can try the next one. Answering "the
 * store front is not in the results" is a successful answer, not a failure.
 *
 * A check may name the point it is run from, which is what a heatmap varies
 * between its grid points. A provider that cannot search from a coordinate is
 * free to ignore it and answer for its configured region.
 */
interface RankProviderInterface
{
    /**
     * The name recorded against the results this provider produces.
     */
    public function name(): string;

    /**
     * Whether the provider is configured well enough to be worth calling.
     */
    public function isAvailable(): bool;

    /**
     * @throws RankProviderException
     */
    public function fetch(Keyword $keyword, ?GeoPoint $from = null): RankResult;
}
