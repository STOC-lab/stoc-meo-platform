<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisType;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\Location;
use App\Models\MeoScore;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The MEO score and the AI analyses written from it.
 *
 * Both are read from what the nightly and weekly sweeps have already stored,
 * so the endpoints answer at once and opening a dashboard never spends the
 * organization's model allowance.
 */
class MeoInsightController extends Controller
{
    /**
     * How much score history one request may ask for.
     */
    public const MAX_HISTORY_DAYS = 365;

    public function __construct(protected Tenancy $tenancy) {}

    /**
     * The latest score, with its working and its recent history.
     */
    public function score(Request $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('view', $location);

        $days = min(
            (int) $request->integer('days', 30),
            self::MAX_HISTORY_DAYS,
        );

        $history = MeoScore::query()
            ->where('location_id', $location->getKey())
            ->where('calculated_at', '>=', now()->subDays(max(1, $days)))
            ->orderBy('calculated_at')
            ->get();

        $latest = $history->last();

        return response()->json([
            'score' => $latest === null ? null : [
                'score' => $latest->score,
                'breakdown' => $latest->breakdown,
                'weakest' => array_key_first($latest->weakestComponents()),
                'calculated_at' => $latest->calculated_at->toIso8601String(),
            ],
            'history' => $history->map(fn (MeoScore $entry) => [
                'date' => $entry->calculated_at->toDateString(),
                'score' => $entry->score,
            ])->all(),
        ]);
    }

    /**
     * The analyses written for this store front, newest first.
     */
    public function analyses(Request $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('view', $location);

        $filters = $request->validate([
            'type' => ['sometimes', Rule::enum(AnalysisType::class)],
        ]);

        $analyses = Analysis::query()
            ->where('location_id', $location->getKey())
            ->when(
                isset($filters['type']),
                fn ($query) => $query->where('type', $filters['type']),
            )
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'analyses' => $analyses->map(fn (Analysis $analysis) => [
                'id' => $analysis->id,
                'type' => $analysis->type->value,
                'type_label' => $analysis->type->label(),
                'summary' => $analysis->summary(),
                'highlights' => $analysis->content['highlights'] ?? [],
                'watch' => $analysis->content['watch'] ?? [],
                'figures' => $analysis->content['figures'] ?? null,
                'period_start' => $analysis->period_start->toDateString(),
                'period_end' => $analysis->period_end->toDateString(),
                'model' => $analysis->model,
                'created_at' => $analysis->created_at?->toIso8601String(),
            ])->all(),
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
}
