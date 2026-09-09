<?php

namespace App\Services\GBP\Clients;

use App\Enums\GbpApi;
use App\Services\GBP\Exceptions\GBPException;
use Carbon\CarbonInterface;

/**
 * Impressions, direction requests, calls and clicks, on the Business Profile
 * Performance API.
 *
 * Google takes the date range as separate year, month and day parameters
 * rather than as dates, and answers with one series per metric of whole closed
 * days.
 */
class GBPPerformanceClient extends GBPClient
{
    public function api(): GbpApi
    {
        return GbpApi::Performance;
    }

    /**
     * Daily values for the given metrics over a date range, keyed by metric.
     *
     * @param  array<int, string>  $metrics
     * @return array<string, array<string, int>> metric => [Y-m-d => value]
     *
     * @throws GBPException
     */
    public function dailyMetrics(
        string $locationName,
        array $metrics,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        if ($metrics === []) {
            return [];
        }

        $body = $this->get($this->locationPath($locationName).':fetchMultiDailyMetricsTimeSeries', [
            'dailyMetrics' => $metrics,
            'dailyRange.start_date.year' => $start->year,
            'dailyRange.start_date.month' => $start->month,
            'dailyRange.start_date.day' => $start->day,
            'dailyRange.end_date.year' => $end->year,
            'dailyRange.end_date.month' => $end->month,
            'dailyRange.end_date.day' => $end->day,
        ]);

        $series = [];

        foreach ($body['multiDailyMetricTimeSeries'] ?? [] as $group) {
            foreach ($group['dailyMetricTimeSeries'] ?? [] as $entry) {
                $metric = $entry['dailyMetric'] ?? null;

                if ($metric === null) {
                    continue;
                }

                $series[$metric] = $this->points($entry['timeSeries']['datedValues'] ?? []);
            }
        }

        return $series;
    }

    /**
     * Google leaves a day's value out entirely when it is zero, so a missing
     * value is a zero rather than a gap.
     *
     * @param  array<int, array<string, mixed>>  $datedValues
     * @return array<string, int>
     */
    protected function points(array $datedValues): array
    {
        $points = [];

        foreach ($datedValues as $point) {
            $date = $point['date'] ?? null;

            if (! is_array($date) || ! isset($date['year'], $date['month'], $date['day'])) {
                continue;
            }

            $key = sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']);

            $points[$key] = (int) ($point['value'] ?? 0);
        }

        return $points;
    }

    protected function locationPath(string $name): string
    {
        return str_starts_with($name, 'locations/') ? $name : 'locations/'.$name;
    }
}
