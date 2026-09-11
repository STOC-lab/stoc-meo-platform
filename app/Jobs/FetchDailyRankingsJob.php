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
 *
 * Those retries are the point of the job, so they have to be allowed to
 * happen: while an attempt is left, a provider having a moment fails the job
 * and is asked again on the backoff instead of being written off as a "not
 * found". Only the last attempt accepts the degraded answer, which is what
 * keeps the day to exactly one row per keyword.
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

    /**
     * Whether a transient provider failure still has an attempt to spend.
     *
     * The last attempt takes whatever answer it can get, because the day is
     * meant to end with exactly one row for the keyword — a gap the product
     * can show beats a job sitting in failed_jobs where nobody looks.
     */
    protected function canStillRetry(): bool
    {
        return $this->attempts() < $this->tries;
    }

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
            $result = $providers->fetch(
                $keyword->loadMissing('location'),
                callerWillRetry: $this->canStillRetry(),
            );

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
