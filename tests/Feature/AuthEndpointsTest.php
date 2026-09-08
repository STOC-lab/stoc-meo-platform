<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $password = 'password'): User
    {
        return User::factory()->create(['password' => $password]);
    }

    /**
     * Sanctum treats a request as stateful when it carries the SPA's origin,
     * which is what the browser sends and what starts the session.
     */
    protected function fromSpa(): static
    {
        return $this->withHeader('Origin', (string) config('app.url'));
    }

    public function test_a_user_logs_in_and_receives_their_memberships(): void
    {
        $user = $this->user();
        $plan = Plan::factory()->create(['code' => 'meo_light', 'name' => 'MEO LIGHT']);
        $organization = Organization::factory()->onPlan($plan)->create(['name' => 'テスト商店']);
        $organization->users()->attach($user, ['role' => 'admin']);

        $this->fromSpa()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('organizations.0.name', 'テスト商店')
            ->assertJsonPath('organizations.0.role', 'admin')
            ->assertJsonPath('organizations.0.plan.code', 'meo_light');

        $this->assertAuthenticatedAs($user);
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $user = $this->user();

        $this->fromSpa()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_an_email_and_password(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_the_profile_lists_every_membership(): void
    {
        $user = $this->user();

        foreach (['first', 'second'] as $index => $name) {
            $organization = Organization::factory()->create(['name' => $name]);
            $organization->users()->attach($user, ['role' => $index === 0 ? 'owner' : 'viewer']);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(2, 'organizations')
            ->assertJsonPath('organizations.0.role', 'owner');
    }

    public function test_a_user_without_organizations_gets_an_empty_list(): void
    {
        $this->actingAs($this->user())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(0, 'organizations');
    }

    public function test_the_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_user_logs_out(): void
    {
        $this->actingAs($this->user())
            ->fromSpa()
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The sanctum request guard caches the user it resolved during the
        // request, so assert against the session guard the endpoint clears.
        $this->assertGuest('web');
    }

    public function test_a_request_without_a_session_is_told_to_reload(): void
    {
        $user = $this->user();

        // No Origin header, so Sanctum does not start a session for it.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(419);
    }

    public function test_the_spa_is_served_for_client_side_routes(): void
    {
        $this->get('/login')->assertOk()->assertSee('id="app"', false);
        $this->get('/dashboard')->assertOk()->assertSee('id="app"', false);
    }

    public function test_the_spa_fallback_does_not_swallow_the_api(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->getJson('/api/v1/nothing-here')
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found.');
    }
}
