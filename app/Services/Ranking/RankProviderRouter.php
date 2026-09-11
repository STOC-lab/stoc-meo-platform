<?php

namespace App\Services\Ranking;

use App\Models\Keyword;
use Illuminate\Support\Facades\Log;

/**
 * Asks each provider in turn until one answers, skipping the ones that are not
 * configured. The last provider is expected to be one that cannot fail, so a
 * check always produces a result to record.
 *
 * Degrading to that last provider spends the day's one row on a "not found"
 * that may never have been true, which is the right answer to a provider that
 * has rejected the request and the wrong one to a provider that was merely
 * busy. A caller that will genuinely try again says so, and a transient
 * failure is then raised for it to retry rather than written off here.
 */
class RankProviderRouter
{
    /**
     * @param  array<int, RankProviderInterface>  $providers
     */
    public function __construct(protected array $providers) {}

    /**
     * @param  bool  $callerWillRetry  Whether the caller has an attempt left to
     *                                 spend on a transient failure. A caller
     *                                 that does not — the last attempt of a
     *                                 job, or a heatmap that would re-run every
     *                                 other point of its grid — leaves this
     *                                 false and takes the degraded answer.
     *
     * @throws RankProviderException
     */
    public function fetch(Keyword $keyword, ?GeoPoint $from = null, bool $callerWillRetry = false): RankResult
    {
        foreach ($this->providers as $provider) {
            if (! $provider->isAvailable()) {
                continue;
            }

            try {
                return $provider->fetch($keyword, $from);
            } catch (RankProviderException $e) {
                $retrying = $e->isTransient() && $callerWillRetry;

                // Losing one provider is expected; losing all of them is not,
                // so each attempt is recorded either way. This warning is what
                // makes a keyword that quietly went to the fallback findable
                // afterwards.
                Log::warning('Rank provider failed.', [
                    'provider' => $provider->name(),
                    'keyword_id' => $keyword->getKey(),
                    'reason' => $e->getMessage(),
                    'transient' => $e->isTransient(),
                    'action' => $retrying ? 'raising for the caller to retry' : 'trying the next provider',
                ]);

                if ($retrying) {
                    throw $e;
                }
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
