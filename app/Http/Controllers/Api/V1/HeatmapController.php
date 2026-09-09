<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HeatmapGridSize;
use App\Enums\HeatmapRunStatus;
use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHeatmapRunRequest;
use App\Jobs\FetchHeatmapJob;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use App\Models\Location;
use App\Services\FeatureResolver;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The heatmaps run for one store front.
 *
 * The two grid sizes are entitled separately, so the routes carry no blanket
 * feature middleware — which grid a plan includes is checked here, against the
 * size actually asked for. Reading past runs is not gated at all: a plan that
 * loses a grid keeps the maps it has already paid for.
 *
 * The store front is the root of the URL and is bound before the tenant
 * middleware has run, so every action checks that it belongs to the active
 * organization before touching it.
 */
class HeatmapController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected FeatureResolver $features,
        protected UsageTracker $usage,
    ) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', HeatmapRun::class);

        $runs = $location->heatmapRuns()
            ->with('keyword')
            ->withCount('points')
            ->latest('id')
            ->get();

        return response()->json([
            'heatmaps' => $runs->map(fn (HeatmapRun $run) => $this->present($run))->all(),
            'allowances' => $this->allowances(),
        ]);
    }

    public function show(Location $location, HeatmapRun $heatmapRun): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('view', $heatmapRun);

        $heatmapRun->load('keyword')->loadCount('points');

        $points = $heatmapRun->points()
            ->orderBy('row')
            ->orderBy('col')
            ->get();

        return response()->json([
            'heatmap' => [
                ...$this->present($heatmapRun),
                'centre' => [
                    'lat' => $location->latitude === null ? null : (float) $location->latitude,
                    'lng' => $location->longitude === null ? null : (float) $location->longitude,
                ],
                'points' => $points->map(fn (HeatmapPoint $point) => [
                    'row' => $point->row,
                    'col' => $point->col,
                    'lat' => $point->lat,
                    'lng' => $point->lng,
                    'rank' => $point->rank,
                ])->all(),
                // The same ranks arranged as the map draws them, north-west
                // corner first, so the client does not have to rebuild the
                // layout from the coordinates.
                'grid' => $this->grid($heatmapRun, $points),
            ],
        ]);
    }

    /**
     * Queue a heatmap for a keyword of this store front.
     *
     * @throws FeatureNotAvailableException|QuotaExceededException
     */
    public function store(StoreHeatmapRunRequest $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('create', HeatmapRun::class);

        $size = HeatmapGridSize::from($request->validated('grid_size'));
        $keyword = $this->keywordOf($location, (int) $request->validated('keyword_id'));

        $this->guardGridAllowance($size);

        abort_if(
            ! $location->hasCoordinates(),
            422,
            'ヒートマップを実行するには、店舗の緯度・経度を登録してください。',
        );

        $run = $location->heatmapRuns()->create([
            'keyword_id' => $keyword->getKey(),
            'grid_size' => $size,
            'status' => HeatmapRunStatus::Pending,
            'scheduled_at' => now(),
        ]);

        FetchHeatmapJob::dispatch($run);

        return response()->json([
            'heatmap' => $this->present($run->load('keyword')),
            'allowances' => $this->allowances(),
        ], 202);
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
     * The keyword has to be one this store front tracks; anything else is a
     * keyword that does not exist as far as this store front is concerned.
     */
    protected function keywordOf(Location $location, int $keywordId): Keyword
    {
        $keyword = $location->keywords()->find($keywordId);

        abort_if($keyword === null, 404, 'このキーワードはこの店舗に登録されていません。');

        return $keyword;
    }

    /**
     * A grid the plan does not include is refused outright; one it includes
     * but has spent for the month is refused as a quota. Neither records
     * anything — the run is what costs, and it charges the allowance itself
     * once a worker picks it up.
     *
     * @throws FeatureNotAvailableException|QuotaExceededException
     */
    protected function guardGridAllowance(HeatmapGridSize $size): void
    {
        $feature = $size->feature();

        if (! $this->features->allows($feature)) {
            throw new FeatureNotAvailableException($feature);
        }

        $limit = $this->features->limit($feature);

        if ($limit !== null) {
            $used = $this->usage->used($feature);

            if ($used >= $limit) {
                throw QuotaExceededException::for($feature, $limit, $used);
            }
        }
    }

    /**
     * What is left of each grid's monthly allowance, for the UI to show before
     * anyone asks for a run they cannot have.
     *
     * @return array<string, array{limit: int|null, used: int, remaining: int|null}>
     */
    protected function allowances(): array
    {
        $allowances = [];

        foreach (HeatmapGridSize::cases() as $size) {
            $feature = $size->feature();

            $allowances[$size->value] = [
                'limit' => $this->features->limit($feature),
                'used' => $this->usage->used($feature),
                'remaining' => $this->usage->remaining($feature),
            ];
        }

        return $allowances;
    }

    /**
     * The run's ranks as a square matrix, with null wherever a point found
     * nothing or has not been checked yet.
     *
     * @param  Collection<int, HeatmapPoint>  $points
     * @return array<int, array<int, int|null>>
     */
    protected function grid(HeatmapRun $run, $points): array
    {
        $dimension = $run->grid_size->dimension();
        $grid = array_fill(0, $dimension, array_fill(0, $dimension, null));

        foreach ($points as $point) {
            if ($point->row < $dimension && $point->col < $dimension) {
                $grid[$point->row][$point->col] = $point->rank;
            }
        }

        return $grid;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(HeatmapRun $run): array
    {
        return [
            'id' => $run->id,
            'keyword_id' => $run->keyword_id,
            'keyword' => $run->keyword?->keyword,
            'grid_size' => $run->grid_size->value,
            'point_count' => $run->grid_size->pointCount(),
            'points_recorded' => $run->points_count ?? $run->points()->count(),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'failure_reason' => $run->failure_reason,
            'scheduled_at' => $run->scheduled_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
