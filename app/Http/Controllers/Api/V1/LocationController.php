<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\QuotaExceededException;
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
    /**
     * Store fronts per page. `locations` stays a flat array under its own key
     * with the paging beside it, because the location switcher in the shell
     * reads that list on every screen and a changed envelope would empty it.
     */
    public const PER_PAGE = 15;

    public function __construct(protected FeatureResolver $features) {}

    /**
     * List the store fronts, optionally narrowed to one brand or to the ones
     * being run right now.
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
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'locations' => collect($locations->items())
                ->map(fn (Location $location) => $this->present($location))
                ->all(),
            'meta' => [
                'current_page' => $locations->currentPage(),
                'last_page' => $locations->lastPage(),
                'per_page' => $locations->perPage(),
                'total' => $locations->total(),
            ],
            'allowance' => $this->allowance($organization),
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
        $this->guardLocationAllowance($organization);

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
     * The numeric ceiling on store fronts, counted from the rows that exist
     * now rather than from a monthly counter: deleting one gives the slot back
     * the same second.
     *
     * This sits behind the entitlement above rather than replacing it. The
     * flag is what says "this plan runs one shop" and carries the better
     * upgrade prompt; this is what says "and this one runs at most eight".
     *
     * @throws QuotaExceededException
     */
    protected function guardLocationAllowance(Organization $organization): void
    {
        // A plan that does not mention the limit at all predates it; that is
        // not the same as a plan that sets it to zero, and FeatureResolver
        // answers 0 for both. Inventing a ceiling for the first kind would
        // lock every organization on an older plan out of its own product.
        if (! $this->features->has(Feature::LocationLimit, $organization)) {
            return;
        }

        $used = $organization->locations()->count();
        $limit = $this->features->limit(Feature::LocationLimit, $organization);

        if ($limit === null) {
            return;
        }

        if ($used >= $limit) {
            throw QuotaExceededException::for(Feature::LocationLimit, $limit, $used);
        }
    }

    /**
     * @return array<string, int|null>
     */
    protected function allowance(Organization $organization): array
    {
        $limit = $this->features->has(Feature::LocationLimit, $organization)
            ? $this->features->limit(Feature::LocationLimit, $organization)
            : null;
        $used = $organization->locations()->count();

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
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
                'slug' => $location->brand->slug,
            ],
            'slug' => $location->slug,
            'gbp_location_id' => $location->gbp_location_id,
            'linked_to_gbp' => $location->isLinkedToGbp(),
            'website_url' => $location->website_url,
            'phone' => $location->phone,
            'postal_code' => $location->postal_code,
            'prefecture' => $location->prefecture,
            'city' => $location->city,
            'address' => $location->address,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'has_coordinates' => $location->hasCoordinates(),
            'google_place_id' => $location->google_place_id,
            'google_maps_url' => $location->google_maps_url,
            'is_active' => $location->is_active,
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
