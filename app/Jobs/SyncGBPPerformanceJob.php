<?php

namespace App\Jobs;

use App\Models\GbpPerformanceMetric;
use App\Models\Location;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies one store front's Business Profile performance figures down from
 * Google.
 *
 * Google serves whole closed days and revises them for a while afterwards, so
 * the sweep asks for a window rather than a single day and overwrites what it
 * already holds. A day Google leaves out of the answer is a zero rather than a
 * gap, and is written as one so a report does not have to tell the two apart.
 */
class SyncGBPPerformanceJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'gbp';

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public Location $location)
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

    public function handle(GBPClientFactory $clients, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->location->organization_id, function () use ($clients) {
            try {
                $account = $clients->connectionFor($this->location);
            } catch (GBPAuthenticationException $e) {
                return;
            }

            [$start, $end] = $this->window();

            $metrics = (array) config('gbp.performance.metrics', []);

            $series = $clients->performance($account)->dailyMetrics(
                (string) $this->location->gbp_location_id,
                $metrics,
                $start,
                $end,
            );

            $this->store($series, $metrics, $start, $end);

            $account->forceFill(['last_synced_at' => now()])->save();
        });
    }

    /**
     * The days to ask for: the lookback window, ending far enough back that
     * Google has finished the last day in it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function window(): array
    {
        $end = CarbonImmutable::now()->subDays((int) config('gbp.performance.lag_days', 2))->startOfDay();
        $start = $end->subDays((int) config('gbp.performance.lookback_days', 7) - 1);

        return [$start, $end];
    }

    /**
     * @param  array<string, array<string, int>>  $series
     * @param  array<int, string>  $metrics
     */
    protected function store(array $series, array $metrics, CarbonImmutable $start, CarbonImmutable $end): void
    {
        foreach ($metrics as $metric) {
            $values = $series[$metric] ?? null;

            // A metric Google did not answer for at all is left alone rather
            // than written as a run of zeroes it never confirmed.
            if ($values === null) {
                continue;
            }

            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                GbpPerformanceMetric::acrossTenants()->updateOrCreate(
                    [
                        'location_id' => $this->location->getKey(),
                        'metric' => $metric,
                        'date' => $date->toDateString(),
                    ],
                    [
                        'organization_id' => $this->location->organization_id,
                        'value' => $values[$date->toDateString()] ?? 0,
                    ],
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'gbp',
            'performance',
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
