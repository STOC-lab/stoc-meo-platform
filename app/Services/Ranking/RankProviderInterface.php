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
    public function fetch(Keyword $keyword): RankResult;
}
