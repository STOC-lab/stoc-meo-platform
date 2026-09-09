<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Exceptions\FeatureNotAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use App\Models\Organization;
use App\Services\FeatureResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Store fronts of the active organization.
 *
 * As with brands, the organization route parameter pins the tenant and the
 * route's scoped bindings resolve the location through it, so another
 * organization's store front is a 404.
 */
class LocationController extends Controller
{
    public function __construct(protected FeatureResolver $features) {}

    /**
     * List the store fronts, optionally narrowed to one brand.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('viewAny', Location::class);

        $locations = Location::query()
            ->with('brand')
            ->when(
                $request->filled('brand_id'),
                fn ($query) => $query->where('brand_id', $request->integer('brand_id')),
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'locations' => $locations->map(fn (Location $location) => $this->present($location))->all(),
        ]);
    }

    public function show(Organization $organization, Location $location): JsonResponse
    {
        $this->authorize('view', $location);

        return response()->json(['location' => $this->present($location->load('brand'))]);
    }

    public function store(StoreLocationRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('create', Location::class);
        $this->guardMultiLocationAllowance($organization);

        $location = Location::create($request->validated());

        return response()->json(['location' => $this->present($location->load('brand'))], 201);
    }

    public function update(UpdateLocationRequest $request, Organization $organization, Location $location): JsonResponse
    {
        $this->authorize('update', $location);

        $location->update($request->validated());

        return response()->json(['location' => $this->present($location->load('brand'))]);
    }

    public function destroy(Organization $organization, Location $location): Response
    {
        $this->authorize('delete', $location);

        $location->delete();

        return response()->noContent();
    }

    /**
     * Plans without the multi-location entitlement run a single store front,
     * so the refusal only bites on the second one — signing up and adding the
     * first shop is never blocked.
     *
     * @throws FeatureNotAvailableException
     */
    protected function guardMultiLocationAllowance(Organization $organization): void
    {
        if ($organization->locations()->count() === 0) {
            return;
        }

        if (! $this->features->allows(Feature::MultiLocationEnabled, $organization)) {
            throw new FeatureNotAvailableException(Feature::MultiLocationEnabled);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'brand' => $location->brand === null ? null : [
                'id' => $location->brand->id,
                'name' => $location->brand->name,
            ],
            'gbp_location_id' => $location->gbp_location_id,
            'linked_to_gbp' => $location->isLinkedToGbp(),
            'website_url' => $location->website_url,
            'phone' => $location->phone,
            'address' => $location->address,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'has_coordinates' => $location->hasCoordinates(),
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
