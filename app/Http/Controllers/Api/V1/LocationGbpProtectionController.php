<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Organization;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPClientFactory;
use App\Services\GBP\GBPLocationProtection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Switching Business Profile protection on and off for a store front.
 *
 * Switching it on takes what Google holds right now as the snapshot to keep,
 * so the moment it is switched on is the moment the profile is known to be
 * right; the nightly sweep holds it there from then on.
 */
class LocationGbpProtectionController extends Controller
{
    public function __construct(
        protected GBPClientFactory $clients,
        protected GBPLocationProtection $protection,
    ) {}

    public function store(Organization $organization, Location $location): JsonResponse
    {
        $this->authorize('update', $location);

        if (! $location->isLinkedToGbp()) {
            return response()->json([
                'message' => 'Googleビジネスプロフィールと連携していない店舗は保護できません。',
            ], 422);
        }

        try {
            $snapshot = $this->protection->snapshot($this->clients->connectionFor($location), $location);
        } catch (GBPAuthenticationException) {
            return response()->json([
                'message' => 'Googleビジネスプロフィールとの連携が切れています。再接続してください。',
                'reconnect_required' => true,
            ], 409);
        } catch (GBPException $e) {
            // The browser gets one sentence whatever Google said, so this log
            // line is the only place the actual refusal survives.
            Log::error('Reading the Business Profile to protect it failed.', [
                'location_id' => $location->getKey(),
                'transient' => $e->isTransient(),
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Googleから店舗情報を取得できませんでした。時間をおいて再度お試しください。',
            ], 502);
        }

        $location->forceFill([
            'gbp_protected_fields' => Location::GBP_PROTECTABLE_FIELDS,
            'gbp_canonical_data' => $snapshot,
            'gbp_last_verified_at' => now(),
        ])->save();

        return response()->json(['gbp_protection' => $this->present($location)]);
    }

    /**
     * Switching off keeps nothing: switching on again takes a fresh snapshot.
     */
    public function destroy(Organization $organization, Location $location): JsonResponse
    {
        $this->authorize('update', $location);

        $location->forceFill([
            'gbp_protected_fields' => null,
            'gbp_canonical_data' => null,
            'gbp_last_verified_at' => null,
        ])->save();

        return response()->json(['gbp_protection' => $this->present($location)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Location $location): array
    {
        return [
            'enabled' => $location->isGbpProtected(),
            'fields' => $location->gbp_protected_fields ?? [],
            'last_verified_at' => $location->gbp_last_verified_at?->toIso8601String(),
        ];
    }
}
