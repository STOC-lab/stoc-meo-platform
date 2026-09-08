<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CheckRoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'tenant', 'role:editor'])
            ->get('/test/editor', fn () => response()->json(['ok' => true]));

        Route::middleware(['web', 'tenant', 'role:owner'])
            ->get('/test/owner', fn () => response()->json(['ok' => true]));
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    protected function member(string $role): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => $role]);

        return [$organization, $user];
    }

    public function test_a_matching_role_is_allowed(): void
    {
        [, $user] = $this->member('editor');

        $this->actingAs($user)->get('/test/editor')->assertOk();
    }

    public function test_a_higher_role_satisfies_a_lower_requirement(): void
    {
        [, $user] = $this->member('admin');

        $this->actingAs($user)->get('/test/editor')->assertOk();
    }

    public function test_a_lower_role_is_rejected(): void
    {
        [, $user] = $this->member('viewer');

        $this->actingAs($user)->get('/test/editor')->assertForbidden();
    }

    public function test_an_admin_cannot_reach_an_owner_only_route(): void
    {
        [, $user] = $this->member('admin');

        $this->actingAs($user)->get('/test/owner')->assertForbidden();
    }

    public function test_a_non_member_cannot_resolve_the_tenant(): void
    {
        $this->member('owner');

        $this->actingAs(User::factory()->create())
            ->get('/test/editor')
            ->assertForbidden();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->get('/test/editor')->assertUnauthorized();
    }

    public function test_the_organization_header_selects_the_tenant(): void
    {
        [$first, $user] = $this->member('owner');
        $second = Organization::factory()->create();
        $second->users()->attach($user, ['role' => 'viewer']);

        // With two memberships the header decides, and the viewer role on the
        // second organization is not enough for an editor route.
        $this->actingAs($user)
            ->withHeader('X-Organization-Id', (string) $first->id)
            ->get('/test/editor')
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('X-Organization-Id', $second->slug)
            ->get('/test/editor')
            ->assertForbidden();
    }

    public function test_an_ambiguous_membership_without_a_header_is_rejected(): void
    {
        [, $user] = $this->member('owner');
        Organization::factory()->create()->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->get('/test/editor')->assertForbidden();
    }
}
