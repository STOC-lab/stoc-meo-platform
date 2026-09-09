<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Models\RankingResult;
use App\Services\Ranking\RankProviderRouter;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks one keyword's rank and records the answer.
 *
 * One keyword per job: a provider that fails for one search term does not hold
 * up the rest of the sweep, and the retries below apply to just that term. The
 * work is an outbound HTTP call, so it runs on its own queue rather than
 * competing with the fast jobs on the default one.
 */
class FetchDailyRankingsJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'rankings';

    /**
     * A provider outage is usually brief, so the attempts are spread over
     * roughly twenty minutes before the job is given up on.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Keyword $keyword)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(RankProviderRouter $providers, Tenancy $tenancy): void
    {
        $keyword = $this->keyword;

        $tenancy->forOrganization($keyword->organization_id, function () use ($keyword, $providers) {
            $result = $providers->fetch($keyword->loadMissing('location'));

            RankingResult::create([
                'organization_id' => $keyword->organization_id,
                'location_id' => $keyword->location_id,
                'keyword_id' => $keyword->getKey(),
                'rank' => $result->rank,
                'search_url' => $result->searchUrl,
                'checked_at' => $result->checkedAt,
                'provider' => $result->provider,
            ]);

            // The new observation is what makes a drop measurable, so the
            // check for one follows it rather than running on its own clock.
            CheckRankingAlertsJob::dispatch($keyword);
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'rankings',
            'organization:'.$this->keyword->organization_id,
            'keyword:'.$this->keyword->getKey(),
        ];
    }
}
