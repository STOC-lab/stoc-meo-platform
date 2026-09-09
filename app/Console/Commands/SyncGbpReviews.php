<?php

namespace App\Console\Commands;

use App\Jobs\SyncReviewsJob;
use App\Models\GbpAccount;
use Illuminate\Console\Command;

/**
 * Queues a review sync for every connected store front.
 *
 * Only live connections are swept: one that Google has stopped honouring
 * already has an alert against it, and calling again would add nothing but
 * another failure.
 */
class SyncGbpReviews extends Command
{
    protected $signature = 'gbp:sync-reviews';

    protected $description = 'Queue a Google review sync for every connected store front';

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

                    SyncReviewsJob::dispatch($account->location);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} review sync(s).");

        return self::SUCCESS;
    }

    protected function shouldSync(GbpAccount $account): bool
    {
        return $account->location !== null
            && filled($account->location->gbp_location_id)
            && $account->location->organization?->isActive() === true;
    }
}
