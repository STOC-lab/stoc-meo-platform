<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectGbpLocationRequest;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Choosing which Business Profile a connected store front speaks for.
 *
 * The OAuth round trip only proves who the Google user is. Reviews, posts and
 * performance all hang off an account and a location on Google's side, so
 * after connecting, an administrator picks both from what that user can
 * administer, and the choice is stored on the connection and the store front.
 */
class GbpConnectionController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected GBPClientFactory $clients,
    ) {}

    /**
     * The Business Profile accounts the connected Google user can act for.
     */
    public function accounts(Request $request): JsonResponse
    {
        $location = $this->locationFrom($request);

        return $this->askGoogle(fn (GbpAccount $account): array => [
            'accounts' => $this->clients->accounts($account)->accounts(),
        ], $location);
    }

    /**
     * The locations under one of those accounts.
     */
    public function locations(Request $request, string $accountId): JsonResponse
    {
        $location = $this->locationFrom($request);

        return $this->askGoogle(fn (GbpAccount $account): array => [
            'locations' => array_map(
                $this->presentLocation(...),
                $this->clients->businessInfo($account)->locations('accounts/'.$accountId, 'name,title,storefrontAddress'),
            ),
        ], $location);
    }

    /**
     * Store the chosen account on the connection and the chosen location on
     * the store front.
     *
     * The location is checked against what Google lists under the account, so
     * a store front cannot be pointed at a profile its connection has no
     * access to.
     */
    public function select(SelectGbpLocationRequest $request): JsonResponse
    {
        $location = $this->locationFrom($request);
        $accountName = (string) $request->validated('gbp_account_name');
        $gbpLocationId = (string) $request->validated('gbp_location_id');

        return $this->askGoogle(function (GbpAccount $account) use ($location, $accountName, $gbpLocationId): array|JsonResponse {
            $available = array_column(
                $this->clients->businessInfo($account)->locations($accountName, 'name'),
                'name',
            );

            if (! in_array($gbpLocationId, $available, true)) {
                return response()->json([
                    'message' => '選択したロケーションはこのアカウントに含まれていません。',
                    'errors' => ['gbp_location_id' => ['選択したロケーションはこのアカウントに含まれていません。']],
                ], 422);
            }

            $account->forceFill(['gbp_account_name' => $accountName])->save();
            $location->forceFill(['gbp_location_id' => $gbpLocationId])->save();

            return [
                'gbp_account_name' => $accountName,
                'gbp_location_id' => $gbpLocationId,
            ];
        }, $location);
    }

    /**
     * Run a call against the store front's connection, turning Google's
     * refusals into answers the settings screen can show.
     *
     * @param  callable(GbpAccount): (array<string, mixed>|JsonResponse)  $call
     */
    protected function askGoogle(callable $call, Location $location): JsonResponse
    {
        try {
            $result = $call($this->clients->connectionFor($location));
        } catch (GBPAuthenticationException) {
            return response()->json([
                'message' => 'Googleビジネスプロフィールとの連携が切れています。再接続してください。',
                'reconnect_required' => true,
            ], 409);
        } catch (GBPException) {
            return response()->json([
                'message' => 'Googleから情報を取得できませんでした。時間をおいて再度お試しください。',
            ], 502);
        }

        return $result instanceof JsonResponse ? $result : response()->json($result);
    }

    /**
     * The store front named in the request, checked against the active
     * organization and the caller's right to manage its connection.
     */
    protected function locationFrom(Request $request): Location
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
        ]);

        $location = Location::query()->find($validated['location_id']);

        abort_if($location === null, 404, '対象の店舗が見つかりません。');
        abort_unless($location->organization_id === $this->tenancy->id(), 404);

        $this->authorize('connect', [GbpAccount::class, $location]);

        return $location;
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array{name: string, title: string, address: string|null}
     */
    protected function presentLocation(array $location): array
    {
        $address = $location['storefrontAddress'] ?? [];

        $parts = array_filter([
            $address['administrativeArea'] ?? null,
            $address['locality'] ?? null,
            ...($address['addressLines'] ?? []),
        ]);

        return [
            'name' => (string) $location['name'],
            'title' => (string) ($location['title'] ?? $location['name']),
            'address' => $parts === [] ? null : implode(' ', $parts),
        ];
    }
}
