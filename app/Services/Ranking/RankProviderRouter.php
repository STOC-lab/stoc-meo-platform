<?php

namespace App\Services\Ranking;

use App\Models\Keyword;
use Illuminate\Support\Facades\Log;

/**
 * Asks each provider in turn until one answers, skipping the ones that are not
 * configured. The last provider is expected to be one that cannot fail, so a
 * check always produces a result to record.
 */
class RankProviderRouter
{
    /**
     * @param  array<int, RankProviderInterface>  $providers
     */
    public function __construct(protected array $providers) {}

    /**
     * @throws RankProviderException
     */
    public function fetch(Keyword $keyword, ?GeoPoint $from = null): RankResult
    {
        foreach ($this->providers as $provider) {
            if (! $provider->isAvailable()) {
                continue;
            }

            try {
                return $provider->fetch($keyword, $from);
            } catch (RankProviderException $e) {
                // Losing one provider is expected; losing all of them is not,
                // so each attempt is recorded and the next one is tried.
                Log::warning('Rank provider failed, trying the next one.', [
                    'provider' => $provider->name(),
                    'keyword_id' => $keyword->getKey(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        throw new RankProviderException('No rank provider was able to answer.');
    }

    /**
     * @return array<int, string>
     */
    public function providerNames(): array
    {
        return array_map(fn (RankProviderInterface $provider) => $provider->name(), $this->providers);
    }
}
