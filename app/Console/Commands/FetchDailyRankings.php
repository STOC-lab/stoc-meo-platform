<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Jobs\FetchDailyRankingsJob;
use App\Models\Keyword;
use App\Models\Organization;
use App\Services\FeatureResolver;
use Illuminate\Console\Command;

/**
 * Queues the day's rank checks.
 *
 * Only active keywords of active organizations whose plan includes daily
 * ranking are swept; the rest keep whatever cadence their plan grants. Each
 * keyword becomes its own job so a slow or failing provider costs one search
 * term rather than the whole run.
 */
class FetchDailyRankings extends Command
{
    protected $signature = 'rankings:fetch-daily';

    protected $description = 'Queue a rank check for every active keyword on a plan with daily ranking';

    public function handle(FeatureResolver $features): int
    {
        $queued = 0;
        $skipped = 0;

        Keyword::acrossTenants()
            ->active()
            ->with('organization')
            ->orderBy('organization_id')
            ->chunkById(200, function ($keywords) use ($features, &$queued, &$skipped) {
                foreach ($keywords as $keyword) {
                    if (! $this->shouldCheck($keyword->organization, $features)) {
                        $skipped++;

                        continue;
                    }

                    FetchDailyRankingsJob::dispatch($keyword);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} rank check(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    protected function shouldCheck(?Organization $organization, FeatureResolver $features): bool
    {
        return $organization !== null
            && $organization->isActive()
            && $features->allows(Feature::RankingEnabled, $organization)
            && $features->allows(Feature::RankingDaily, $organization);
    }
}
