<?php

namespace Tests\Feature;

use App\Enums\InstagramTokenStatus;
use App\Models\InstagramAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InstagramConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.instagram.app_id' => '1698886537877170',
            'services.instagram.app_secret' => 'test-secret',
            'services.instagram.redirect' => 'https://app.test/api/v1/instagram/callback',
        ]);

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Answer the three calls the callback makes: code for a short-lived
     * token, that for a long-lived one, and the profile.
     */
    protected function fakeMeta(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'IGAA-short-lived',
                'user_id' => 17841400000000000,
                'permissions' => 'instagram_business_basic',
            ]),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'IGAA-long-lived',
                'token_type' => 'bearer',
                'expires_in' => 5183944,
            ]),
            'graph.instagram.com/v26.0/me*' => Http::response([
                'user_id' => '17841400000000000',
                'username' => 'stoc_co.ltd',
                'id' => '9876543210',
            ]),
        ]);
    }

    protected function startConnection(): string
    {
        return $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/instagram/connect?location_id='.$this->location->id)
            ->assertOk()
            ->json('state');
    }

    public function test_an_administrator_starts_a_connection(): void
    {
        $response = $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/instagram/connect?location_id='.$this->location->id)
            ->assertOk()
            ->assertJsonStructure(['redirect_url', 'state', 'expires_in']);

        $url = $response->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertSame('1698886537877170', $query['client_id']);
        $this->assertSame('https://app.test/api/v1/instagram/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(
            'instagram_business_basic,instagram_business_manage_comments,instagram_business_manage_messages',
            $query['scope'],
        );
        $this->assertSame($response->json('state'), $query['state']);
        $this->assertSame(
            $this->location->id,
            Cache::get('instagram-oauth-state:'.$response->json('state'))['location_id'],
        );
    }

    public function test_a_store_manager_cannot_connect_an_instagram_account(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->getJson('/api/v1/instagram/connect?location_id='.$this->location->id)
            ->assertForbidden();
    }

    public function test_a_store_front_of_another_organization_cannot_be_connected(): void
    {
        $foreign = Location::factory()->create();

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/instagram/connect?location_id='.$foreign->id)
            ->assertNotFound();
    }

    public function test_connecting_is_refused_while_the_app_secret_is_unset(): void
    {
        config(['services.instagram.app_secret' => null]);

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/instagram/connect?location_id='.$this->location->id)
            ->assertStatus(503);
    }

    public function test_the_callback_stores_a_long_lived_token_and_the_profile(): void
    {
        $this->fakeMeta();
        $state = $this->startConnection();

        $this->get('/api/v1/instagram/callback?code=auth-code&state='.$state)
            ->assertRedirect('/settings?tab=connections&instagram=success');

        $account = InstagramAccount::acrossTenants()->firstOrFail();

        $this->assertSame($this->location->id, $account->location_id);
        $this->assertSame($this->organization->id, $account->organization_id);
        $this->assertSame('17841400000000000', $account->ig_user_id);
        $this->assertSame('stoc_co.ltd', $account->username);
        $this->assertSame('IGAA-long-lived', $account->accessToken());
        $this->assertSame(InstagramTokenStatus::Active, $account->token_status);
        $this->assertTrue($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.instagram.com/oauth/access_token'
            && $request['code'] === 'auth-code'
            && $request['grant_type'] === 'authorization_code'
            && $request['redirect_uri'] === 'https://app.test/api/v1/instagram/callback');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.instagram.com/access_token')
            && $request['grant_type'] === 'ig_exchange_token'
            && $request['access_token'] === 'IGAA-short-lived');
    }

    public function test_the_stored_token_is_not_readable_in_the_database(): void
    {
        $this->fakeMeta();
        $state = $this->startConnection();

        $this->get('/api/v1/instagram/callback?code=auth-code&state='.$state);

        $this->assertStringNotContainsString('IGAA-long-lived', DB::table('instagram_accounts')->value('access_token_encrypted'));
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        $this->fakeMeta();
        $state = $this->startConnection();

        $this->get('/api/v1/instagram/callback?code=auth-code&state='.$state)
            ->assertRedirect('/settings?tab=connections&instagram=success');

        $this->get('/api/v1/instagram/callback?code=auth-code&state='.$state)
            ->assertRedirectContains('instagram=error');

        $this->assertSame(1, InstagramAccount::acrossTenants()->count());
    }

    public function test_an_unknown_state_is_refused(): void
    {
        $this->get('/api/v1/instagram/callback?code=auth-code&state=made-up')
            ->assertRedirectContains('/settings?tab=connections&instagram=error');

        $this->assertSame(0, InstagramAccount::acrossTenants()->count());
    }

    public function test_a_declined_consent_is_reported_rather_than_stored(): void
    {
        $state = $this->startConnection();

        $this->get('/api/v1/instagram/callback?error=access_denied&state='.$state)
            ->assertRedirectContains('instagram=error');

        $this->assertSame(0, InstagramAccount::acrossTenants()->count());
    }

    public function test_a_refused_code_is_logged_and_reported_as_an_error(): void
    {
        Log::spy();
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'error_type' => 'OAuthException',
                'code' => 400,
                'error_message' => 'This authorization code has been used',
            ], 400),
        ]);
        $state = $this->startConnection();

        $this->get('/api/v1/instagram/callback?code=spent-code&state='.$state)
            ->assertRedirectContains('instagram=error');

        $this->assertSame(0, InstagramAccount::acrossTenants()->count());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Instagram connection callback failed.'
            && str_contains($context['reason'], 'This authorization code has been used'));
    }

    public function test_reconnecting_replaces_the_token_on_the_same_row(): void
    {
        $existing = InstagramAccount::factory()->forLocation($this->location)->expired()->create();

        $this->fakeMeta();
        $state = $this->startConnection();

        $this->get('/api/v1/instagram/callback?code=auth-code&state='.$state)
            ->assertRedirect('/settings?tab=connections&instagram=success');

        $account = InstagramAccount::acrossTenants()->sole();

        $this->assertSame($existing->id, $account->id);
        $this->assertSame('IGAA-long-lived', $account->accessToken());
        $this->assertSame(InstagramTokenStatus::Active, $account->token_status);
    }

    public function test_the_connection_can_be_read_back_without_its_token(): void
    {
        $account = InstagramAccount::factory()->forLocation($this->location)->create(['username' => 'stoc_co.ltd']);

        $response = $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/instagram/connection?location_id='.$this->location->id)
            ->assertOk()
            ->assertJsonPath('connection.username', 'stoc_co.ltd')
            ->assertJsonPath('connection.token_status', 'active')
            ->assertJsonPath('connection.connected_at', $account->created_at->toIso8601String());

        $this->assertStringNotContainsString($account->accessToken(), $response->getContent());
    }

    public function test_an_unconnected_store_front_reads_back_as_nothing(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/instagram/connection?location_id='.$this->location->id)
            ->assertOk()
            ->assertJsonPath('connection', null);
    }

    public function test_a_guest_cannot_start_a_connection(): void
    {
        $this->getJson('/api/v1/instagram/connect?location_id='.$this->location->id)
            ->assertUnauthorized();
    }
}
