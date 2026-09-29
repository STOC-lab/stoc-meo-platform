<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstagramTokenStatus;
use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Models\Location;
use App\Services\Instagram\Exceptions\InstagramException;
use App\Services\Instagram\InstagramOAuth;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connecting a store front to its Instagram professional account.
 *
 * The same shape as the Google connection: the round trip to Meta leaves and
 * re-enters the application, so the state is kept in the cache and carries
 * which store front is being connected and who asked. The query string is
 * never trusted for either.
 */
class InstagramConnectionController extends Controller
{
    /**
     * How long someone has to finish the consent screen.
     */
    public const STATE_TTL = 900;

    public function __construct(
        protected Tenancy $tenancy,
        protected InstagramOAuth $oauth,
    ) {}

    /**
     * Hand back the Instagram consent URL for the SPA to send the browser to.
     */
    public function connect(Request $request): JsonResponse
    {
        $location = $this->locationFrom($request);

        $this->authorize('connect', [InstagramAccount::class, $location]);

        abort_unless($this->oauth->isAvailable(), 503, 'Instagram連携は現在設定中です。しばらくお待ちください。');

        $state = Str::random(40);

        Cache::put($this->stateKey($state), [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
            'user_id' => $request->user()->getKey(),
        ], self::STATE_TTL);

        return response()->json([
            'redirect_url' => $this->oauth->authorizationUrl($state),
            'state' => $state,
            'expires_in' => self::STATE_TTL,
        ]);
    }

    /**
     * Receive the grant, store the connection and send the browser back to the
     * settings screen.
     *
     * This runs outside the tenant middleware, so the organization comes from
     * the state we put there ourselves. The browser arrived by navigation, so
     * every outcome is a redirect the settings screen reads, never JSON.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (filled($request->query('error'))) {
            return $this->backToSettings('error', 'Instagramとの連携がキャンセルされました。');
        }

        $state = $this->consumeState((string) $request->query('state', ''));

        if ($state === null) {
            return $this->backToSettings('error', '連携リクエストの有効期限が切れています。もう一度お試しください。');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->backToSettings('error', 'Instagramからの応答を検証できませんでした。もう一度お試しください。');
        }

        $location = Location::acrossTenants()->find($state['location_id']);

        if ($location === null) {
            return $this->backToSettings('error', '対象の店舗が見つかりません。');
        }

        try {
            $grant = $this->oauth->connect($code);
        } catch (InstagramException $e) {
            // The browser gets one sentence whatever went wrong, so this is
            // the only place a spent code is told apart from a wrong secret.
            Log::warning('Instagram connection callback failed.', [
                'location_id' => $state['location_id'],
                'user_id' => $state['user_id'],
                'reason' => $e->getMessage(),
            ]);

            return $this->backToSettings('error', 'Instagramからの応答を検証できませんでした。もう一度お試しください。');
        }

        $this->store($location, $grant);

        return $this->backToSettings('success');
    }

    /**
     * The current connection, for the settings screen.
     */
    public function show(Request $request): JsonResponse
    {
        $location = $this->locationFrom($request);

        $this->authorize('connect', [InstagramAccount::class, $location]);

        $account = InstagramAccount::query()->where('location_id', $location->getKey())->first();

        return response()->json([
            'connection' => $account === null ? null : $this->present($account),
        ]);
    }

    /**
     * Store or replace the store front's connection. A reconnection keeps the
     * row, so its connection date stays the date it was first connected.
     *
     * @param  array{ig_user_id: string, username: string|null, access_token: string, expires_in: int|null}  $grant
     */
    protected function store(Location $location, array $grant): InstagramAccount
    {
        $account = InstagramAccount::acrossTenants()->firstOrNew([
            'location_id' => $location->getKey(),
        ]);

        $account->fill([
            'organization_id' => $location->organization_id,
            'ig_user_id' => $grant['ig_user_id'],
            'username' => $grant['username'],
            'access_token_encrypted' => $grant['access_token'],
            'token_expires_at' => $grant['expires_in'] === null ? null : now()->addSeconds($grant['expires_in']),
            'token_status' => InstagramTokenStatus::Active,
        ]);

        $account->save();

        return $account;
    }

    /**
     * Read the state once and drop it, so a callback cannot be replayed.
     *
     * @return array{location_id: int, organization_id: int, user_id: int}|null
     */
    protected function consumeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $key = $this->stateKey($state);
        $payload = Cache::get($key);

        Cache::forget($key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * The settings screen's connections tab, told how the round trip went.
     */
    protected function backToSettings(string $outcome, ?string $message = null): RedirectResponse
    {
        $query = array_filter([
            'tab' => 'connections',
            'instagram' => $outcome,
            'message' => $message,
        ]);

        return redirect('/settings?'.http_build_query($query));
    }

    protected function stateKey(string $state): string
    {
        return 'instagram-oauth-state:'.$state;
    }

    /**
     * The store front being connected, taken from the request and checked
     * against the active organization.
     */
    protected function locationFrom(Request $request): Location
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
        ]);

        $location = Location::query()->find($validated['location_id']);

        abort_if($location === null, 404, '対象の店舗が見つかりません。');
        abort_unless($location->organization_id === $this->tenancy->id(), 404);

        return $location;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(InstagramAccount $account): array
    {
        return [
            'id' => $account->id,
            'location_id' => $account->location_id,
            'ig_user_id' => $account->ig_user_id,
            'username' => $account->username,
            'token_status' => $account->token_status->value,
            'token_status_label' => $account->token_status->label(),
            'needs_reconnection' => $account->token_status->needsReconnection(),
            'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            'connected_at' => $account->created_at?->toIso8601String(),
        ];
    }
}
