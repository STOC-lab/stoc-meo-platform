<?php

namespace App\Console\Commands;

use App\Jobs\SyncGBPPerformanceJob;
use App\Models\GbpAccount;
use Illuminate\Console\Command;

/**
 * Queues a performance sync for every connected store front.
 *
 * Google's figures move slowly and the window each job asks for overlaps the
 * last one, so this runs weekly rather than nightly.
 */
class SyncGbpPerformance extends Command
{
    protected $signature = 'gbp:sync-performance';

    protected $description = 'Queue a Business Profile performance sync for every connected store front';

    public function handle(): int
    {
        $queued = 0;

        GbpAccount::acrossTenants()
            ->connected()
            ->with('location.organization')
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$queued) {
                foreach ($accounts as $account) {
                    if (! $this->shouldSync($account)) {
                        continue;
                    }

                    SyncGBPPerformanceJob::dispatch($account->location);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} performance sync(s).");

        return self::SUCCESS;
    }

    protected function shouldSync(GbpAccount $account): bool
    {
        return $account->location !== null
            && filled($account->location->gbp_location_id)
            && $account->location->organization?->isActive() === true;
    }
}
