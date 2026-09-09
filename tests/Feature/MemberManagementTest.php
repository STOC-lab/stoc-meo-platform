<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->member('owner');
    }

    protected function member(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = ''): string
    {
        return "/api/v1/organizations/{$this->organization->id}/members".$path;
    }

    protected function roleOf(User $user): ?OrganizationRole
    {
        return $user->roleIn($this->organization);
    }

    public function test_an_org_admin_lists_the_membership(): void
    {
        $viewer = $this->member('viewer', ['name' => '田中太郎']);

        $this->actingAs($this->member('org_admin'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(3, 'members')
            ->assertJsonFragment([
                'id' => $viewer->id,
                'name' => '田中太郎',
                'email' => $viewer->email,
                'role' => 'viewer',
                'role_label' => '閲覧者',
            ]);
    }

    public function test_members_of_another_organization_are_not_listed(): void
    {
        $other = Organization::factory()->create();
        $other->users()->attach(User::factory()->create(), ['role' => 'owner']);

        $this->actingAs($this->owner)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'members');
    }

    public function test_a_location_admin_cannot_list_the_membership(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->getJson($this->url())
            ->assertForbidden();
    }

    public function test_a_user_outside_the_organization_cannot_list_the_membership(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson($this->url())
            ->assertForbidden();
    }

    public function test_an_org_admin_changes_a_members_role(): void
    {
        $orgAdmin = $this->member('org_admin');
        $member = $this->member('viewer');

        $this->actingAs($orgAdmin)
            ->patchJson($this->url("/{$member->id}"), ['role' => 'staff'])
            ->assertOk()
            ->assertJsonPath('member.role', 'staff')
            ->assertJsonPath('member.role_label', 'スタッフ');

        $this->assertSame(OrganizationRole::Staff, $this->roleOf($member));
    }

    public function test_the_role_must_be_a_known_one(): void
    {
        $member = $this->member('viewer');

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$member->id}"), ['role' => 'superuser'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_a_location_admin_cannot_change_a_members_role(): void
    {
        $locationAdmin = $this->member('location_admin');
        $member = $this->member('viewer');

        $this->actingAs($locationAdmin)
            ->patchJson($this->url("/{$member->id}"), ['role' => 'org_admin'])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Viewer, $this->roleOf($member));
    }

    public function test_only_an_owner_may_grant_ownership(): void
    {
        $orgAdmin = $this->member('org_admin');
        $member = $this->member('staff');

        $this->actingAs($orgAdmin)
            ->patchJson($this->url("/{$member->id}"), ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$member->id}"), ['role' => 'owner'])
            ->assertOk();

        $this->assertSame(OrganizationRole::Owner, $this->roleOf($member));
    }

    public function test_an_org_admin_cannot_change_an_owners_role(): void
    {
        $orgAdmin = $this->member('org_admin');
        $secondOwner = $this->member('owner');

        $this->actingAs($orgAdmin)
            ->patchJson($this->url("/{$secondOwner->id}"), ['role' => 'viewer'])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Owner, $this->roleOf($secondOwner));
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$this->owner->id}"), ['role' => 'org_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(OrganizationRole::Owner, $this->roleOf($this->owner));
    }

    public function test_an_owner_can_step_down_once_another_owner_exists(): void
    {
        $this->member('owner');

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$this->owner->id}"), ['role' => 'org_admin'])
            ->assertOk();

        $this->assertSame(OrganizationRole::OrgAdmin, $this->roleOf($this->owner));
    }

    public function test_an_org_admin_removes_a_member(): void
    {
        $orgAdmin = $this->member('org_admin');
        $member = $this->member('viewer');

        $this->actingAs($orgAdmin)
            ->deleteJson($this->url("/{$member->id}"))
            ->assertNoContent();

        $this->assertDatabaseMissing('organization_users', [
            'organization_id' => $this->organization->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_a_location_admin_cannot_remove_a_member(): void
    {
        $locationAdmin = $this->member('location_admin');
        $member = $this->member('viewer');

        $this->actingAs($locationAdmin)
            ->deleteJson($this->url("/{$member->id}"))
            ->assertForbidden();

        $this->assertNotNull($this->roleOf($member));
    }

    public function test_an_org_admin_cannot_remove_an_owner(): void
    {
        $orgAdmin = $this->member('org_admin');
        $secondOwner = $this->member('owner');

        $this->actingAs($orgAdmin)
            ->deleteJson($this->url("/{$secondOwner->id}"))
            ->assertForbidden();

        $this->assertNotNull($this->roleOf($secondOwner));
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson($this->url("/{$this->owner->id}"))
            ->assertStatus(422);

        $this->assertNotNull($this->roleOf($this->owner));
    }

    public function test_a_user_who_is_not_a_member_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$stranger->id}"), ['role' => 'viewer'])
            ->assertNotFound();

        $this->assertNull($this->roleOf($stranger));
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
