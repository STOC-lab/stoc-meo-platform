<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function fromSpa(): static
    {
        return $this->withHeader('Origin', (string) config('app.url'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '山田 太郎',
            'email' => 'taro@example.com',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
            'organization_name' => '株式会社ストック',
        ], $overrides);
    }

    public function test_registering_creates_the_user_organization_and_owner_membership(): void
    {
        Plan::factory()->create(['code' => 'meo_free', 'name' => 'MEO FREE']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('user.email', 'taro@example.com')
            ->assertJsonPath('organizations.0.name', '株式会社ストック')
            ->assertJsonPath('organizations.0.role', 'owner')
            ->assertJsonPath('organizations.0.plan.code', 'meo_free');

        $user = User::where('email', 'taro@example.com')->firstOrFail();
        $organization = Organization::firstOrFail();

        $this->assertSame(OrganizationRole::Owner, $user->roleIn($organization));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_organization_gets_a_slug_even_from_a_japanese_name(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->assertNotSame('', Organization::firstOrFail()->slug);
    }

    public function test_slugs_do_not_collide(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'jiro@example.com',
        ]))->assertCreated();

        $this->assertSame(2, Organization::query()->distinct()->count('slug'));
    }

    public function test_registering_without_a_free_plan_still_works(): void
    {
        $this->fromSpa()
            ->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('organizations.0.plan', null);
    }

    public function test_an_existing_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taro@example.com']);

        $this->fromSpa()
            ->postJson('/api/v1/auth/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->fromSpa()
            ->postJson('/api/v1/auth/register', $this->payload([
                'password_confirmation' => 'something-else',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_every_field_is_required(): void
    {
        $this->fromSpa()
            ->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'organization_name']);
    }

    public function test_nothing_is_created_when_registration_fails(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload(['email' => 'not-an-email']))
            ->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_users', 0);
    }
}
