<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\GbpTokenStatus;
use App\Models\Alert;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * The OAuth round trip, with Socialite mocked throughout; nothing here reaches
 * Google.
 */
class GoogleConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'https://app.test/api/v1/auth/google/callback',
        ]);

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function member(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();
        ($organization ?? $this->organization)->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Stand in for the whole Socialite driver — both halves of the round trip
     * come through the same facade, so one mock has to answer for both.
     */
    protected function fakeSocialite(?string $refreshToken = '1//refresh-token'): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map(['id' => '1029384756', 'email' => 'owner@example.com', 'name' => 'Owner']);
        $user->token = 'ya29.access-token';
        $user->refreshToken = $refreshToken;
        $user->expiresIn = 3600;

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(
            new RedirectResponse('https://accounts.google.com/o/oauth2/auth?client_id=test-client')
        );
        $driver->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        return $user;
    }

    /**
     * Start a connection and return the state the callback will be given.
     */
    protected function startConnection(?Location $location = null): string
    {
        $response = $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/auth/google/redirect?location_id='.($location ?? $this->location)->id)
            ->assertOk();

        return $response->json('state');
    }

    public function test_an_administrator_starts_a_connection(): void
    {
        $this->fakeSocialite();

        $response = $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/auth/google/redirect?location_id='.$this->location->id)
            ->assertOk()
            ->assertJsonStructure(['redirect_url', 'state', 'expires_in']);

        $this->assertStringStartsWith('https://accounts.google.com/', $response->json('redirect_url'));

        // The state carries which store front is being connected, so the
        // callback does not have to trust the query string for it.
        $this->assertSame(
            $this->location->id,
            Cache::get('gbp-oauth-state:'.$response->json('state'))['location_id'],
        );
    }

    public function test_a_store_manager_cannot_connect_a_google_account(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->getJson('/api/v1/auth/google/redirect?location_id='.$this->location->id)
            ->assertForbidden();
    }

    public function test_a_store_front_of_another_organization_cannot_be_connected(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/auth/google/redirect?location_id='.$foreign->id)
            ->assertNotFound();
    }

    public function test_the_callback_stores_the_connection_with_its_tokens(): void
    {
        $this->fakeSocialite();
        $state = $this->startConnection();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)
            ->assertOk()
            ->assertJsonPath('connection.location_id', $this->location->id)
            ->assertJsonPath('connection.google_email', 'owner@example.com')
            ->assertJsonPath('connection.token_status', 'active')
            ->assertJsonPath('connection.needs_reconnection', false);

        $account = GbpAccount::acrossTenants()->firstOrFail();

        $this->assertSame('1029384756', $account->google_account_id);
        $this->assertSame('ya29.access-token', $account->accessToken());
        $this->assertSame('1//refresh-token', $account->refreshToken());
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_the_stored_tokens_are_not_readable_in_the_database(): void
    {
        $this->fakeSocialite();
        $state = $this->startConnection();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)->assertOk();

        $row = \DB::table('gbp_accounts')->first();

        $this->assertStringNotContainsString('ya29.access-token', $row->access_token_encrypted);
        $this->assertStringNotContainsString('1//refresh-token', $row->refresh_token_encrypted);
    }

    public function test_the_callback_never_returns_the_tokens(): void
    {
        $this->fakeSocialite();
        $state = $this->startConnection();

        $response = $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)->assertOk();

        $this->assertStringNotContainsString('ya29.access-token', $response->getContent());
        $this->assertStringNotContainsString('1//refresh-token', $response->getContent());
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        $this->fakeSocialite();
        $state = $this->startConnection();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)->assertOk();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)
            ->assertStatus(422);

        $this->assertSame(1, GbpAccount::acrossTenants()->count());
    }

    public function test_an_unknown_state_is_refused(): void
    {
        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state=made-up')
            ->assertStatus(422);

        $this->assertSame(0, GbpAccount::acrossTenants()->count());
    }

    public function test_a_declined_consent_is_reported_rather_than_stored(): void
    {
        $this->getJson('/api/v1/auth/google/callback?error=access_denied&state=anything')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'access_denied');

        $this->assertSame(0, GbpAccount::acrossTenants()->count());
    }

    public function test_reconnecting_replaces_the_tokens_on_the_same_row(): void
    {
        $existing = GbpAccount::factory()->forLocation($this->location)->expired()->create();

        $this->fakeSocialite();
        $state = $this->startConnection();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)->assertOk();

        $this->assertSame(1, GbpAccount::acrossTenants()->count());

        $account = $existing->fresh();

        $this->assertSame('ya29.access-token', $account->accessToken());
        $this->assertSame(GbpTokenStatus::Active, $account->token_status);
    }

    public function test_reconnecting_clears_the_outstanding_reconnect_alert(): void
    {
        GbpAccount::factory()->forLocation($this->location)->expired()->create();

        Alert::acrossTenants()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'type' => AlertType::GbpTokenExpired,
            'payload' => ['token_status' => 'expired'],
            'is_read' => false,
        ]);

        $this->fakeSocialite();
        $state = $this->startConnection();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)->assertOk();

        $this->assertSame(
            0,
            Alert::acrossTenants()->where('type', AlertType::GbpTokenExpired)->where('is_read', false)->count(),
        );
    }

    public function test_a_reconnection_without_a_new_refresh_token_keeps_the_one_stored(): void
    {
        $existing = GbpAccount::factory()->forLocation($this->location)
            ->create(['refresh_token_encrypted' => '1//original']);

        $this->fakeSocialite(refreshToken: null);
        $state = $this->startConnection();

        $this->getJson('/api/v1/auth/google/callback?code=auth-code&state='.$state)->assertOk();

        $this->assertSame('1//original', $existing->fresh()->refreshToken());
    }

    public function test_the_connection_can_be_read_back(): void
    {
        GbpAccount::factory()->forLocation($this->location)->expired()->create([
            'google_email' => 'owner@example.com',
        ]);

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/auth/google/connection?location_id='.$this->location->id)
            ->assertOk()
            ->assertJsonPath('connection.google_email', 'owner@example.com')
            ->assertJsonPath('connection.token_status', 'expired')
            ->assertJsonPath('connection.needs_reconnection', true);
    }

    public function test_an_unconnected_store_front_reads_back_as_nothing(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/auth/google/connection?location_id='.$this->location->id)
            ->assertOk()
            ->assertJsonPath('connection', null);
    }

    public function test_a_guest_cannot_start_a_connection(): void
    {
        $this->getJson('/api/v1/auth/google/redirect?location_id='.$this->location->id)
            ->assertUnauthorized();
    }
}
