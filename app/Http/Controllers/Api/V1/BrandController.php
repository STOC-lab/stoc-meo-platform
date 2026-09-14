<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\FeatureResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Brands of the active organization.
 *
 * The organization route parameter is what the tenant middleware pins the
 * request to; brands are then resolved through it by the route's scoped
 * bindings, so a brand belonging to another organization is a 404 before the
 * controller is reached.
 */
class BrandController extends Controller
{
    /**
     * Brands per page. The key stays `brands` and stays a flat array, with the
     * paging beside it under `meta`: every reader of this endpoint expects
     * that shape, and a chain with more than one page of brands does not
     * exist yet.
     */
    public const PER_PAGE = 15;

    public function __construct(protected FeatureResolver $features) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $brands = Brand::query()
            ->withCount('locations')
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'brands' => collect($brands->items())->map(fn (Brand $brand) => $this->present($brand))->all(),
            'meta' => $this->meta($brands),
            'allowance' => $this->allowance($organization),
        ]);
    }

    public function show(Organization $organization, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json(['brand' => $this->present($brand->loadCount('locations'))]);
    }

    public function store(StoreBrandRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('create', Brand::class);
        $this->guardBrandAllowance($organization);

        $brand = Brand::create($request->validated());

        return response()->json(['brand' => $this->present($brand->loadCount('locations'))], 201);
    }

    public function update(UpdateBrandRequest $request, Organization $organization, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $brand->update($request->validated());

        return response()->json(['brand' => $this->present($brand->loadCount('locations'))]);
    }

    /**
     * Delete the brand, once nothing is filed under it.
     *
     * A brand with store fronts is refused rather than quietly detaching them:
     * moving a chain's shops out from under their banner is a decision, and it
     * should be made deliberately on each shop rather than as a side effect of
     * tidying up the brand list. Deletion is soft either way.
     */
    public function destroy(Organization $organization, Brand $brand): Response
    {
        $this->authorize('delete', $brand);

        $attached = $brand->locations()->count();

        abort_if($attached > 0, 422, "このブランドには{$attached}件の店舗が紐づいています。先に店舗のブランドを変更してください。");

        $brand->delete();

        return response()->noContent();
    }

    /**
     * How many brands the plan allows, counted from the rows that exist now.
     * Deleting one gives the slot back immediately, which is why this is not
     * the UsageTracker's business — that counts consumption over a month.
     *
     * @throws QuotaExceededException
     */
    protected function guardBrandAllowance(Organization $organization): void
    {
        // A plan that does not mention the limit at all predates it; that is
        // not the same as a plan that sets it to zero, and FeatureResolver
        // answers 0 for both. Inventing a ceiling for the first kind would
        // lock every organization on an older plan out of its own product.
        if (! $this->features->has(Feature::BrandLimit, $organization)) {
            return;
        }

        $limit = $this->features->limit(Feature::BrandLimit, $organization);

        if ($limit === null) {
            return;
        }

        $used = $organization->brands()->count();

        if ($used >= $limit) {
            throw QuotaExceededException::for(Feature::BrandLimit, $limit, $used);
        }
    }

    /**
     * @return array<string, int|null>
     */
    protected function allowance(Organization $organization): array
    {
        $limit = $this->features->has(Feature::BrandLimit, $organization)
            ? $this->features->limit(Feature::BrandLimit, $organization)
            : null;
        $used = $organization->brands()->count();

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Brand>  $page
     * @return array<string, int>
     */
    protected function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'logo_url' => $brand->logo_url,
            'description' => $brand->description,
            'website_url' => $brand->website_url,
            'locations_count' => (int) ($brand->locations_count ?? 0),
            'created_at' => $brand->created_at?->toIso8601String(),
            'updated_at' => $brand->updated_at?->toIso8601String(),
        ];
    }
}
