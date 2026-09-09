<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompetitorRequest;
use App\Models\Competitor;
use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The rival store fronts watched alongside one of the organization's own.
 *
 * The store front is the root of the URL and is bound before the tenant
 * middleware has run, so every action checks that it belongs to the active
 * organization before touching it.
 */
class CompetitorController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', Competitor::class);

        $competitors = $location->competitors()
            ->orderBy('name')
            ->get();

        return response()->json([
            'competitors' => $competitors->map(fn (Competitor $competitor) => $this->present($competitor))->all(),
            'allowance' => $this->allowance($location),
        ]);
    }

    public function store(StoreCompetitorRequest $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('create', Competitor::class);
        $this->guardCompetitorAllowance($location);

        $competitor = $location->competitors()->create($request->validated());

        return response()->json([
            'competitor' => $this->present($competitor),
            'allowance' => $this->allowance($location),
        ], 201);
    }

    /**
     * Stop watching a rival, which gives the allowance back.
     */
    public function destroy(Location $location, Competitor $competitor): Response
    {
        $this->authorizeLocation($location);
        $this->authorize('delete', $competitor);

        $competitor->delete();

        return response()->noContent();
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
     * The plan caps how many rivals a store front watches, counted from the
     * rows themselves rather than metered.
     *
     * @throws QuotaExceededException
     */
    protected function guardCompetitorAllowance(Location $location): void
    {
        $limit = Competitor::allowanceFor($location);

        if ($limit === null) {
            return;
        }

        $used = Competitor::countFor($location);

        if ($used >= $limit) {
            throw QuotaExceededException::for(Feature::CompetitorLimit, $limit, $used);
        }
    }

    /**
     * @return array<string, int|null>
     */
    protected function allowance(Location $location): array
    {
        return [
            'limit' => Competitor::allowanceFor($location),
            'used' => Competitor::countFor($location),
            'remaining' => Competitor::remainingAllowanceFor($location),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Competitor $competitor): array
    {
        return [
            'id' => $competitor->id,
            'name' => $competitor->name,
            'gbp_place_id' => $competitor->gbp_place_id,
            'created_at' => $competitor->created_at?->toIso8601String(),
        ];
    }
}
