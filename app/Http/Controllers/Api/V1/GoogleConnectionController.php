<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GbpTokenStatus;
use App\Http\Controllers\Controller;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Services\GBP\GBPConnectionMonitor;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Connecting a store front to its Google Business Profile.
 *
 * The SPA authenticates with a session cookie, but the round trip to Google
 * leaves and re-enters the application, so the flow is driven stateless and
 * the state is kept in the cache instead: it carries which store front is
 * being connected and who asked, which is what the callback needs and what the
 * query string must not be trusted for.
 *
 * access_type=offline with prompt=consent is what makes Google return a
 * refresh token. Without both, a reconnection comes back with an access token
 * that dies within the hour and nothing to renew it with.
 */
class GoogleConnectionController extends Controller
{
    /**
     * How long someone has to finish the consent screen.
     */
    public const STATE_TTL = 900;

    public function __construct(
        protected Tenancy $tenancy,
        protected GBPConnectionMonitor $monitor,
    ) {}

    /**
     * Hand back the Google consent URL for the SPA to send the browser to.
     */
    public function redirect(Request $request): JsonResponse
    {
        $location = $this->locationFrom($request);

        $this->authorize('connect', [GbpAccount::class, $location]);

        $state = Str::random(40);

        Cache::put($this->stateKey($state), [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
            'user_id' => $request->user()->getKey(),
        ], self::STATE_TTL);

        $url = Socialite::driver('google')
            ->stateless()
            ->scopes(config('gbp.scopes'))
            ->with([
                'access_type' => 'offline',
                // Google only returns a refresh token on a fresh consent, so
                // reconnecting has to ask for one again.
                'prompt' => 'consent',
                'state' => $state,
            ])
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'redirect_url' => $url,
            'state' => $state,
            'expires_in' => self::STATE_TTL,
        ]);
    }

    /**
     * Receive the grant and store the connection.
     *
     * This runs outside the tenant middleware — the browser arrives from
     * Google carrying nothing of ours but the state — so the organization
     * comes from the state we put there ourselves.
     */
    public function callback(Request $request): JsonResponse
    {
        if (filled($request->query('error'))) {
            return response()->json([
                'message' => 'Googleとの連携がキャンセルされました。',
                'reason' => (string) $request->query('error'),
            ], 400);
        }

        $state = $this->consumeState((string) $request->query('state', ''));

        if ($state === null) {
            return response()->json([
                'message' => '連携リクエストの有効期限が切れています。もう一度お試しください。',
            ], 422);
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            // Socialite raises InvalidStateException for a tampered round trip
            // and a client exception when Google refuses the code. Neither is
            // worth telling the browser apart: both mean start again.
            return response()->json([
                'message' => 'Googleからの応答を検証できませんでした。もう一度お試しください。',
            ], 422);
        }

        $location = Location::acrossTenants()->find($state['location_id']);

        if ($location === null) {
            return response()->json(['message' => '対象の店舗が見つかりません。'], 404);
        }

        $account = $this->store($location, $googleUser);

        return response()->json([
            'message' => 'Googleビジネスプロフィールと連携しました。',
            'connection' => $this->present($account),
        ]);
    }

    /**
     * The current connection, for the settings screen.
     */
    public function show(Request $request): JsonResponse
    {
        $location = $this->locationFrom($request);

        $this->authorize('connect', [GbpAccount::class, $location]);

        $account = GbpAccount::query()->where('location_id', $location->getKey())->first();

        return response()->json([
            'connection' => $account === null ? null : $this->present($account),
        ]);
    }

    /**
     * Store or replace the store front's connection.
     *
     * A reconnection keeps the row and replaces the tokens. Google returns a
     * refresh token only on a fresh consent, so an answer without one must not
     * wipe the one already held.
     */
    protected function store(Location $location, $googleUser): GbpAccount
    {
        $account = GbpAccount::acrossTenants()->firstOrNew([
            'location_id' => $location->getKey(),
        ]);

        $account->fill([
            'organization_id' => $location->organization_id,
            'google_account_id' => (string) $googleUser->getId(),
            'google_email' => $googleUser->getEmail(),
            'access_token_encrypted' => $googleUser->token,
            'token_expires_at' => $googleUser->expiresIn === null
                ? null
                : now()->addSeconds((int) $googleUser->expiresIn),
            'token_status' => GbpTokenStatus::Active,
        ]);

        if (filled($googleUser->refreshToken)) {
            $account->refresh_token_encrypted = $googleUser->refreshToken;
        }

        $account->save();

        // Reconnecting is what clears the "please reconnect" alert.
        $this->monitor->markRestored($account);

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

    protected function stateKey(string $state): string
    {
        return 'gbp-oauth-state:'.$state;
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
    protected function present(GbpAccount $account): array
    {
        return [
            'id' => $account->id,
            'location_id' => $account->location_id,
            'google_account_id' => $account->google_account_id,
            'google_email' => $account->google_email,
            'gbp_account_name' => $account->gbp_account_name,
            'token_status' => $account->token_status->value,
            'token_status_label' => $account->token_status->label(),
            'needs_reconnection' => $account->token_status->needsReconnection(),
            'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'connected_at' => $account->created_at?->toIso8601String(),
        ];
    }
}
