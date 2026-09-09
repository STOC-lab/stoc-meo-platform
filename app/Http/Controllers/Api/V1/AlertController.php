<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The alerts raised about one store front — a rank that fell sharply, a
 * Business Profile connection that needs reconnecting.
 *
 * Alerts are raised by the jobs that notice them, so there is no create
 * endpoint; what a person does with one is read it.
 */
class AlertController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function index(Request $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', Alert::class);

        $filters = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $alerts = Alert::query()
            ->where('location_id', $location->getKey())
            ->when($filters['unread'] ?? false, fn ($query) => $query->unread())
            ->latest('id')
            ->limit($filters['limit'] ?? 50)
            ->get();

        return response()->json([
            'alerts' => $alerts->map(fn (Alert $alert) => $this->present($alert))->all(),
            'unread_count' => Alert::query()
                ->where('location_id', $location->getKey())
                ->unread()
                ->count(),
        ]);
    }

    /**
     * Mark one alert read, or unread again.
     */
    public function update(Request $request, Location $location, Alert $alert): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('update', $alert);

        $validated = $request->validate([
            'is_read' => ['required', 'boolean'],
        ]);

        $alert->update(['is_read' => $validated['is_read']]);

        return response()->json(['alert' => $this->present($alert->fresh())]);
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
     * @return array<string, mixed>
     */
    protected function present(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'type' => $alert->type->value,
            'type_label' => $alert->type->label(),
            'payload' => $alert->payload ?? [],
            'is_read' => $alert->is_read,
            'keyword_id' => $alert->keyword_id,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
