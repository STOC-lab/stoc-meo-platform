<?php

namespace App\Services\Reports;

use App\Enums\AlertType;
use App\Enums\HeatmapRunStatus;
use App\Models\Alert;
use App\Models\Competitor;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RankingResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Gathers what a month's report says, one store front at a time.
 *
 * Everything is read across tenants and filtered by organization explicitly,
 * because a report is built by a queued job rather than inside a request and
 * must not depend on a tenant having been set.
 *
 * The aggregates deliberately let SQL skip the checks that found nothing:
 * best, worst and average describe the positions the store front actually
 * held, and how often it was missing is reported separately rather than
 * being averaged in as though it were a rank.
 */
class MonthlyReportBuilder
{
    /**
     * Everything the report view needs for one organization and month.
     *
     * @return array<string, mixed>
     */
    public function build(Organization $organization, CarbonImmutable $periodStart): array
    {
        $start = $periodStart->startOfMonth();
        $end = $start->endOfMonth();

        $locations = Location::acrossTenants()
            ->where('organization_id', $organization->getKey())
            ->orderBy('name')
            ->get();

        $sections = $locations
            ->map(fn (Location $location) => $this->forLocation($location, $start, $end))
            ->all();

        return [
            'organization' => [
                'id' => $organization->getKey(),
                'name' => $organization->name,
            ],
            'period' => [
                'label' => $start->format('Y年n月'),
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'generated_at' => CarbonImmutable::now()->format('Y年n月j日 H:i'),
            'locations' => $sections,
            'totals' => $this->totals($sections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function forLocation(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rankings = $this->rankingSummary($location, $start, $end);

        return [
            'id' => $location->getKey(),
            'name' => $location->name,
            'address' => $location->address,
            'rankings' => $rankings,
            'heatmaps' => $this->heatmapSummary($location, $start, $end),
            'competitors' => $this->competitorSummary($location, $rankings),
            'alerts' => $this->alertSummary($location, $start, $end),
        ];
    }

    /**
     * Best, worst and average position per keyword over the month.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rankingSummary(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keywords = Keyword::acrossTenants()
            ->where('location_id', $location->getKey())
            ->orderBy('keyword')
            ->get()
            ->keyBy(fn (Keyword $keyword) => $keyword->getKey());

        if ($keywords->isEmpty()) {
            return [];
        }

        $aggregates = RankingResult::acrossTenants()
            ->where('location_id', $location->getKey())
            ->whereIn('keyword_id', $keywords->keys())
            ->whereBetween('checked_at', [$start, $end])
            ->groupBy('keyword_id')
            ->selectRaw('keyword_id')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw('MIN(`rank`) as best')
            ->selectRaw('MAX(`rank`) as worst')
            ->selectRaw('AVG(`rank`) as average')
            ->selectRaw('SUM(CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END) as missing')
            ->get()
            ->keyBy('keyword_id');

        return $keywords->map(function (Keyword $keyword) use ($aggregates) {
            $row = $aggregates->get($keyword->getKey());

            return [
                'keyword' => $keyword->keyword,
                'is_active' => $keyword->is_active,
                'checks' => (int) ($row->checks ?? 0),
                'missing' => (int) ($row->missing ?? 0),
                'best' => $row?->best === null ? null : (int) $row->best,
                'worst' => $row?->worst === null ? null : (int) $row->worst,
                'average' => $row?->average === null ? null : round((float) $row->average, 1),
            ];
        })->values()->all();
    }

    /**
     * What the month's heatmaps were run on and what they found.
     *
     * @return array<string, mixed>
     */
    protected function heatmapSummary(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $runs = HeatmapRun::acrossTenants()
            ->where('location_id', $location->getKey())
            ->whereBetween('created_at', [$start, $end])
            ->with('keyword')
            ->orderBy('created_at')
            ->get();

        $completed = $runs->filter(fn (HeatmapRun $run) => $run->status === HeatmapRunStatus::Completed);

        $coverage = $this->heatmapCoverage($completed);

        return [
            'total' => $runs->count(),
            'completed' => $completed->count(),
            'failed' => $runs->filter(fn (HeatmapRun $run) => $run->status === HeatmapRunStatus::Failed)->count(),
            'runs' => $runs->map(fn (HeatmapRun $run) => [
                'keyword' => $run->keyword?->keyword,
                'grid_size' => $run->grid_size->value,
                'status' => $run->status->label(),
                'completed_at' => $run->completed_at?->format('n月j日 H:i'),
                ...$coverage[$run->getKey()] ?? ['ranked_points' => null, 'total_points' => null, 'best' => null, 'average' => null],
            ])->all(),
        ];
    }

    /**
     * How much of each finished grid the store front appeared in, and where it
     * stood when it did.
     *
     * @param  Collection<int, HeatmapRun>  $runs
     * @return array<int, array<string, mixed>>
     */
    protected function heatmapCoverage(Collection $runs): array
    {
        if ($runs->isEmpty()) {
            return [];
        }

        return HeatmapPoint::acrossTenants()
            ->whereIn('heatmap_run_id', $runs->modelKeys())
            ->groupBy('heatmap_run_id')
            ->selectRaw('heatmap_run_id')
            ->selectRaw('COUNT(*) as total_points')
            ->selectRaw('COUNT(`rank`) as ranked_points')
            ->selectRaw('MIN(`rank`) as best')
            ->selectRaw('AVG(`rank`) as average')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->heatmap_run_id => [
                'total_points' => (int) $row->total_points,
                'ranked_points' => (int) $row->ranked_points,
                'best' => $row->best === null ? null : (int) $row->best,
                'average' => $row->average === null ? null : round((float) $row->average, 1),
            ]])
            ->all();
    }

    /**
     * The rivals being watched, against the store front's own average for the
     * month.
     *
     * Their positions are not part of this: nothing in the product collects a
     * competitor's rank yet, so the report says who is being watched and how
     * the store front itself did, and says plainly that the comparison is not
     * available rather than implying one.
     *
     * @param  array<int, array<string, mixed>>  $rankings
     * @return array<string, mixed>
     */
    protected function competitorSummary(Location $location, array $rankings): array
    {
        $averages = array_filter(array_column($rankings, 'average'), fn ($average) => $average !== null);

        return [
            'own_average' => $averages === [] ? null : round(array_sum($averages) / count($averages), 1),
            'tracked' => Competitor::acrossTenants()
                ->where('location_id', $location->getKey())
                ->orderBy('name')
                ->get()
                ->map(fn (Competitor $competitor) => [
                    'name' => $competitor->name,
                    'gbp_place_id' => $competitor->gbp_place_id,
                ])->all(),
        ];
    }

    /**
     * The month's rank drops, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function alertSummary(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Alert::acrossTenants()
            ->where('location_id', $location->getKey())
            ->where('type', AlertType::RankDrop)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Alert $alert) => [
                'keyword' => $alert->payload['keyword'] ?? null,
                'previous_rank' => $alert->payload['previous_rank'] ?? null,
                'current_rank' => $alert->payload['current_rank'] ?? null,
                'drop' => $alert->payload['drop'] ?? null,
                'raised_at' => $alert->created_at?->format('n月j日'),
            ])->all();
    }

    /**
     * The organization-wide counts the summary page opens with.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, int>
     */
    protected function totals(array $sections): array
    {
        return [
            'locations' => count($sections),
            'keywords' => array_sum(array_map(fn ($section) => count($section['rankings']), $sections)),
            'heatmaps' => array_sum(array_map(fn ($section) => $section['heatmaps']['completed'], $sections)),
            'competitors' => array_sum(array_map(fn ($section) => count($section['competitors']['tracked']), $sections)),
            'alerts' => array_sum(array_map(fn ($section) => count($section['alerts']), $sections)),
        ];
    }
}
