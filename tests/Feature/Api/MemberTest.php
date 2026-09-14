<?php

namespace Tests\Feature\Api;

use App\Enums\Feature;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The membership surface the members screen drives: who is listed, who may be
 * invited, and the four ways an organization can be left unmanageable that the
 * API refuses.
 *
 * This application has five roles, not two. `owner` is the seat the guards
 * protect — it is the one that can manage billing — so "the last admin" in a
 * specification is the last owner here.
 *
 * `MemberManagementTest` and `InvitationTest` cover which role may reach which
 * verb; this covers the refusals and the tenant boundary.
 */
class MemberTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

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

    protected function invitationUrl(): string
    {
        return "/api/v1/organizations/{$this->organization->id}/invitations";
    }

    /**
     * Put the organization on a plan seating this many people. Null is
     * unlimited.
     */
    protected function onPlanSeating(?int $members): void
    {
        $plan = Plan::factory()
            ->withFeatures([Feature::MemberLimit->value => $members])
            ->create();

        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();
    }

    public function test_a_guest_is_refused_every_verb(): void
    {
        $member = $this->member('staff');

        $this->getJson($this->url())->assertUnauthorized();
        $this->patchJson($this->url("/{$member->id}"), ['role' => 'viewer'])->assertUnauthorized();
        $this->deleteJson($this->url("/{$member->id}"))->assertUnauthorized();
        $this->postJson($this->invitationUrl(), ['email' => 'x@example.com', 'role' => 'staff'])
            ->assertUnauthorized();
    }

    public function test_the_list_holds_only_this_organizations_members(): void
    {
        $this->member('staff', ['name' => 'うちの人']);

        $other = Organization::factory()->create();
        $stranger = User::factory()->create(['name' => 'よその人']);
        $other->users()->attach($stranger, ['role' => 'owner']);

        $response = $this->actingAs($this->owner)->getJson($this->url())->assertOk();

        $names = collect($response->json('members'))->pluck('name');

        $this->assertCount(2, $names);
        $this->assertTrue($names->contains('うちの人'));
        $this->assertFalse($names->contains('よその人'));
    }

    public function test_a_member_is_listed_with_the_role_and_the_day_they_joined(): void
    {
        $staff = $this->member('staff', ['name' => '田中', 'email' => 'tanaka@example.com']);

        $row = collect($this->actingAs($this->owner)->getJson($this->url())->json('members'))
            ->firstWhere('id', $staff->id);

        $this->assertSame('田中', $row['name']);
        $this->assertSame('tanaka@example.com', $row['email']);
        $this->assertSame('staff', $row['role']);
        $this->assertNotNull($row['role_label']);
        $this->assertNotNull($row['joined_at']);
    }

    public function test_an_address_is_invited(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'new@example.com', 'role' => 'staff'])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'new@example.com')
            ->assertJsonPath('invitation.role', 'staff');
    }

    public function test_an_address_that_is_already_a_member_is_refused(): void
    {
        $existing = $this->member('staff', ['email' => 'taken@example.com']);

        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => $existing->email, 'role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_an_invitation_beyond_the_plans_seats_is_refused(): void
    {
        // One seat, and the owner is in it.
        $this->onPlanSeating(1);

        // A plan refusal is a 403 with the upgrade envelope, which is how
        // every other entitlement in this application answers.
        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'new@example.com', 'role' => 'staff'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'member.limit');

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_an_outstanding_invitation_holds_a_seat(): void
    {
        $this->onPlanSeating(2);

        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'first@example.com', 'role' => 'staff'])
            ->assertCreated();

        // The seat is taken from the moment the invitation goes out, not when
        // it is accepted; otherwise the refusal lands on whoever accepts last.
        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'second@example.com', 'role' => 'staff'])
            ->assertForbidden();
    }

    public function test_re_inviting_the_same_address_does_not_take_a_second_seat(): void
    {
        $this->onPlanSeating(2);

        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'again@example.com', 'role' => 'staff'])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'again@example.com', 'role' => 'viewer'])
            ->assertCreated();

        $this->assertSame(1, Invitation::query()->whereNull('accepted_at')->count());
    }

    public function test_a_plan_that_says_nothing_about_seats_is_not_given_a_ceiling(): void
    {
        $plan = Plan::factory()->withFeatures([])->create();
        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();

        $this->actingAs($this->owner)
            ->postJson($this->invitationUrl(), ['email' => 'new@example.com', 'role' => 'staff'])
            ->assertCreated();
    }

    public function test_a_role_is_changed(): void
    {
        $staff = $this->member('staff');

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$staff->id}"), ['role' => 'location_admin'])
            ->assertOk()
            ->assertJsonPath('member.role', 'location_admin');

        $this->assertSame(OrganizationRole::LocationAdmin, $staff->fresh()->roleIn($this->organization));
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $this->member('org_admin');

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$this->owner->id}"), ['role' => 'org_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(OrganizationRole::Owner, $this->owner->fresh()->roleIn($this->organization));
    }

    public function test_an_owner_may_be_demoted_once_there_is_another(): void
    {
        $second = $this->member('owner');

        $this->actingAs($second)
            ->patchJson($this->url("/{$this->owner->id}"), ['role' => 'org_admin'])
            ->assertOk();

        $this->assertSame(OrganizationRole::OrgAdmin, $this->owner->fresh()->roleIn($this->organization));
    }

    public function test_a_member_is_removed(): void
    {
        $staff = $this->member('staff');

        $this->actingAs($this->owner)
            ->deleteJson($this->url("/{$staff->id}"))
            ->assertNoContent();

        $this->assertNull($staff->fresh()->roleIn($this->organization));
    }

    public function test_removing_yourself_is_refused(): void
    {
        $second = $this->member('owner');

        $this->actingAs($second)
            ->deleteJson($this->url("/{$second->id}"))
            ->assertUnprocessable();

        $this->assertSame(OrganizationRole::Owner, $second->fresh()->roleIn($this->organization));
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        // Another owner, so the guard is the seat count rather than the
        // refusal to remove yourself.
        $second = $this->member('owner');
        $this->organization->users()->updateExistingPivot($second->getKey(), ['role' => 'owner']);

        $this->actingAs($second)
            ->deleteJson($this->url("/{$this->owner->id}"))
            ->assertNoContent();

        // Only one left: now nobody may take it.
        $this->actingAs($second)
            ->deleteJson($this->url("/{$second->id}"))
            ->assertUnprocessable();

        $this->assertSame(OrganizationRole::Owner, $second->fresh()->roleIn($this->organization));
    }

    public function test_a_member_of_another_organization_is_not_there(): void
    {
        $other = Organization::factory()->create();
        $stranger = User::factory()->create();
        $other->users()->attach($stranger, ['role' => 'staff']);

        $this->actingAs($this->owner)
            ->getJson($this->url("/{$stranger->id}"))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->patchJson($this->url("/{$stranger->id}"), ['role' => 'viewer'])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->deleteJson($this->url("/{$stranger->id}"))
            ->assertNotFound();

        $this->assertSame(OrganizationRole::Staff, $stranger->fresh()->roleIn($other));
    }
}
