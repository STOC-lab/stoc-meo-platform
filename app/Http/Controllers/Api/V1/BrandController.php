<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $brands = Brand::query()
            ->withCount('locations')
            ->orderBy('name')
            ->get();

        return response()->json([
            'brands' => $brands->map(fn (Brand $brand) => $this->present($brand))->all(),
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
     * Delete the brand. Its locations survive and fall back to having no
     * brand, so a mistaken deletion never takes store data with it.
     */
    public function destroy(Organization $organization, Brand $brand): Response
    {
        $this->authorize('delete', $brand);

        $brand->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'locations_count' => (int) ($brand->locations_count ?? 0),
            'created_at' => $brand->created_at?->toIso8601String(),
            'updated_at' => $brand->updated_at?->toIso8601String(),
        ];
    }
}
