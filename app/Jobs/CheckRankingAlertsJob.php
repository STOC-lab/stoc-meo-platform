<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Services\Alerts\RankDropDetector;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at what the day's check did to a keyword's rank and raises an alert if
 * it fell sharply.
 *
 * Dispatched by the rank check itself once the result is recorded, so the
 * comparison always has the new observation to work from. It is short database
 * work rather than an outbound call, so it stays on the default queue instead
 * of queuing behind the night's rank checks.
 */
class CheckRankingAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public Keyword $keyword) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RankDropDetector $detector, Tenancy $tenancy): void
    {
        $tenancy->forOrganization(
            $this->keyword->organization_id,
            fn () => $detector->detect($this->keyword),
        );
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'alerts',
            'organization:'.$this->keyword->organization_id,
            'keyword:'.$this->keyword->getKey(),
        ];
    }
}
