<?php

namespace App\Console\Commands;

use App\Jobs\SyncGBPLocationJob;
use App\Models\GbpAccount;
use Illuminate\Console\Command;

/**
 * Queues a Business Profile protection check for every connected store front
 * that has asked for one.
 *
 * Only live connections are swept, as with the other syncs, and only store
 * fronts that are being run and have protection switched on: the rest have
 * nothing to hold Google to.
 */
class SyncGbpLocations extends Command
{
    protected $signature = 'gbp:sync-locations
                            {--location-id= : Check only this store front}';

    protected $description = 'Queue a Business Profile protection check for every protected store front';

    public function handle(): int
    {
        $locationId = $this->option('location-id');
        $queued = 0;

        GbpAccount::acrossTenants()
            ->connected()
            ->when(filled($locationId), fn ($query) => $query->where('location_id', (int) $locationId))
            ->with('location.organization')
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$queued) {
                foreach ($accounts as $account) {
                    if (! $this->shouldSync($account)) {
                        continue;
                    }

                    SyncGBPLocationJob::dispatch($account->location);
                    $queued++;
                }
            });

        if (filled($locationId) && $queued === 0) {
            $this->error("Store front {$locationId} is not a connected, active store front with protection switched on.");

            return self::FAILURE;
        }

        $this->info("Queued {$queued} location protection check(s).");

        return self::SUCCESS;
    }

    protected function shouldSync(GbpAccount $account): bool
    {
        return $account->location !== null
            && $account->location->is_active
            && $account->location->isLinkedToGbp()
            && $account->location->isGbpProtected()
            && $account->location->organization?->isActive() === true;
    }
}
