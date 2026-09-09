<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GbpPerformanceMetric;
use App\Models\Location;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a store front's Business Profile did — impressions, direction requests,
 * calls and clicks — over a range of days.
 *
 * The figures are read from what the weekly sync has already copied down
 * rather than from Google, so the endpoint answers at once and does not spend
 * the connection's quota on someone opening a dashboard.
 */
class GbpPerformanceController extends Controller
{
    /**
     * How far back a request may ask, so one cannot pull years of daily rows
     * in a single answer.
     */
    public const MAX_RANGE_DAYS = 400;

    public function __construct(protected Tenancy $tenancy) {}

    public function index(Request $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('view', $location);

        [$start, $end] = $this->range($request);

        $rows = GbpPerformanceMetric::query()
            ->where('location_id', $location->getKey())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        $series = [];
        $totals = [];

        foreach ($rows as $row) {
            $series[$row->metric][$row->date->toDateString()] = $row->value;
            $totals[$row->metric] = ($totals[$row->metric] ?? 0) + $row->value;
        }

        return response()->json([
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'metrics' => $series,
            'totals' => $totals,
            'last_synced_at' => $location->gbpAccount?->last_synced_at?->toIso8601String(),
        ]);
    }

    /**
     * Route model binding resolves the store front before the tenant is known,
     * so it can be one from another organization. Answer as though it does not
     * exist rather than confirming it does.
     */
    protected function authorizeLocation(Location $location): void
    {
        abort_unless($location->organization_id === $this->tenancy->id(), 404);
    }

    /**
     * The days asked for, defaulting to the last month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function range(Request $request): array
    {
        $validated = $request->validate([
            'start' => ['sometimes', 'date'],
            'end' => ['sometimes', 'date'],
        ]);

        $end = isset($validated['end'])
            ? CarbonImmutable::parse($validated['end'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $start = isset($validated['start'])
            ? CarbonImmutable::parse($validated['start'])->startOfDay()
            : $end->subDays(29);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        // A range longer than the cap is trimmed from the far end rather than
        // refused, so a client asking for everything still gets an answer.
        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            $start = $end->subDays(self::MAX_RANGE_DAYS);
        }

        return [$start, $end];
    }
}
