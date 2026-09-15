<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'テスト商店']);
    }

    protected function fromSpa(): static
    {
        return $this->withHeader('Origin', (string) config('app.url'));
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Issue an invitation directly, returning the plaintext token from the link.
     *
     * @return array{0: Invitation, 1: string}
     */
    protected function invite(string $email, OrganizationRole $role = OrganizationRole::Staff): array
    {
        [$plain, $hashed] = Invitation::generateToken();

        $invitation = Invitation::create([
            'organization_id' => $this->organization->id,
            'invited_by' => $this->member('owner')->id,
            'email' => $email,
            'role' => $role->value,
            'token' => $hashed,
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        return [$invitation, $plain];
    }

    public function test_an_org_admin_invites_an_address_and_the_email_is_sent(): void
    {
        Notification::fake();

        $this->actingAs($this->member('org_admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'staff',
            ])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'new@example.com')
            ->assertJsonPath('invitation.role', 'staff')
            ->assertJsonPath('invitation.organization.name', 'テスト商店')
            ->assertJsonPath('invitation.requires_registration', true);

        $invitation = Invitation::acrossTenants()->firstOrFail();

        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame(OrganizationRole::Staff, $invitation->role);
        $this->assertNull($invitation->accepted_at);

        Notification::assertSentOnDemand(
            OrganizationInvitationNotification::class,
            function (OrganizationInvitationNotification $notification, array $channels, object $notifiable) {
                return $notifiable->routes['mail'] === 'new@example.com'
                    && str_contains($notification->acceptUrl, '/invitations/')
                    && $notification->toMail($notifiable) instanceof OrganizationInvitationMail;
            },
        );
    }

    public function test_the_stored_token_is_only_a_hash(): void
    {
        Notification::fake();

        $this->actingAs($this->member('org_admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'viewer',
            ])
            ->assertCreated();

        $plainToken = null;

        Notification::assertSentOnDemand(
            OrganizationInvitationNotification::class,
            function (OrganizationInvitationNotification $notification) use (&$plainToken) {
                $plainToken = str($notification->acceptUrl)->afterLast('/')->toString();

                return true;
            },
        );

        $this->assertNotNull($plainToken);
        $this->assertDatabaseMissing('invitations', ['token' => $plainToken]);
        $this->assertDatabaseHas('invitations', ['token' => Invitation::hashToken($plainToken)]);
    }

    public function test_a_location_admin_cannot_invite(): void
    {
        Notification::fake();

        $this->actingAs($this->member('staff'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'viewer',
            ])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_only_an_owner_can_invite_an_owner(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        Notification::fake();

        $this->actingAs($this->member('owner'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'owner',
            ])
            ->assertCreated();
    }

    public function test_an_existing_member_cannot_be_invited_again(): void
    {
        $existing = $this->member('viewer');

        $this->actingAs($this->member('org_admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => $existing->email,
                'role' => 'staff',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_re_inviting_replaces_the_outstanding_invitation(): void
    {
        Notification::fake();
        $admin = $this->member('org_admin');

        foreach (['viewer', 'staff'] as $role) {
            $this->actingAs($admin)
                ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                    'email' => 'new@example.com',
                    'role' => $role,
                ])
                ->assertCreated();
        }

        $this->assertSame(1, Invitation::acrossTenants()->count());
        $this->assertSame(OrganizationRole::Staff, Invitation::acrossTenants()->firstOrFail()->role);
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'superuser',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_the_invitation_email_renders(): void
    {
        [$invitation] = $this->invite('new@example.com');

        $mailable = new OrganizationInvitationMail(
            $invitation->load(['organization', 'inviter']),
            'https://meo.stoc-plus.site/invitations/test-token',
        );

        $mailable->assertHasSubject('テスト商店 への招待');
        $mailable->assertSeeInHtml('テスト商店');
        $mailable->assertSeeInHtml('スタッフ');
        $mailable->assertSeeInHtml('https://meo.stoc-plus.site/invitations/test-token', false);
        $mailable->assertSeeInText('招待');
    }

    public function test_the_email_reaches_the_mailer(): void
    {
        // Tests use the array transport, so this exercises the notification,
        // the mailable and the view together.
        $this->actingAs($this->member('org_admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'staff',
            ])
            ->assertCreated();

        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);
        $this->assertSame('new@example.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertStringContainsString('テスト商店', $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_the_invitation_can_be_looked_up_from_the_link(): void
    {
        [, $token] = $this->invite('new@example.com');

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('invitation.email', 'new@example.com')
            ->assertJsonPath('invitation.organization.name', 'テスト商店')
            ->assertJsonPath('invitation.role_label', 'スタッフ')
            ->assertJsonPath('invitation.requires_registration', true);
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->getJson('/api/v1/invitations/nope')->assertNotFound();
    }

    public function test_a_new_user_registers_while_accepting(): void
    {
        [$invitation, $token] = $this->invite('new@example.com');

        $this->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", [
                'name' => '新人 太郎',
                'password' => 'password-1234',
                'password_confirmation' => 'password-1234',
            ])
            ->assertOk()
            ->assertJsonPath('invitation.accepted', true);

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('新人 太郎', $user->name);
        $this->assertSame(OrganizationRole::Staff, $user->roleIn($this->organization));
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_new_user_must_supply_a_name_and_password(): void
    {
        [, $token] = $this->invite('new@example.com');

        $this->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'password']);
    }

    public function test_an_existing_user_accepts_while_signed_in(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        [$invitation, $token] = $this->invite('known@example.com', OrganizationRole::OrgAdmin);

        $this->actingAs($user)
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertOk();

        $this->assertSame(OrganizationRole::OrgAdmin, $user->roleIn($this->organization));
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_existing_account_cannot_be_taken_over_by_holding_the_link(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        [, $token] = $this->invite('known@example.com');

        $this->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", [
                'name' => '乗っ取り',
                'password' => 'not-the-password',
                'password_confirmation' => 'not-the-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertFalse($user->belongsToOrganization($this->organization));
        $this->assertGuest('web');
    }

    public function test_an_existing_account_accepts_by_supplying_its_password(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        [$invitation, $token] = $this->invite('known@example.com', OrganizationRole::OrgAdmin);

        $this->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('invitation.accepted', true);

        $this->assertSame(OrganizationRole::OrgAdmin, $user->roleIn($this->organization));
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * The bug this replaced: an invitee already signed in under another address
     * — or a browser left signed in as somebody else — was answered 403 and had
     * no way past it, even though the link itself was perfectly good.
     */
    public function test_a_signed_in_user_can_accept_an_invitation_addressed_to_somebody_else(): void
    {
        $other = User::factory()->create(['email' => 'someone.else@example.com']);
        [$invitation, $token] = $this->invite('new@example.com', OrganizationRole::OrgAdmin);

        $this->actingAs($other)
            ->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", [
                'name' => '阿部 健一',
                'password' => 'password-1234',
                'password_confirmation' => 'password-1234',
            ])
            ->assertOk()
            ->assertJsonPath('invitation.accepted', true);

        $invitee = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('阿部 健一', $invitee->name);
        $this->assertSame(OrganizationRole::OrgAdmin, $invitee->roleIn($this->organization));
        $this->assertNotNull($invitation->refresh()->accepted_at);

        // The session is handed over to the account the invitation names, so
        // the dashboard the SPA lands on is the invitee's.
        $this->assertAuthenticatedAs($invitee);
        $this->assertFalse($other->belongsToOrganization($this->organization));
    }

    public function test_a_signed_in_user_accepting_for_an_existing_account_still_needs_its_password(): void
    {
        $other = User::factory()->create(['email' => 'someone.else@example.com']);
        $invitee = User::factory()->create(['email' => 'known@example.com']);
        [$invitation, $token] = $this->invite('known@example.com');

        $this->actingAs($other)
            ->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'not-the-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertFalse($invitee->belongsToOrganization($this->organization));
        $this->assertNull($invitation->refresh()->accepted_at);
        $this->assertAuthenticatedAs($other);

        $this->actingAs($other)
            ->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'password'])
            ->assertOk();

        $this->assertSame(OrganizationRole::Staff, $invitee->roleIn($this->organization));
        $this->assertAuthenticatedAs($invitee);
    }

    public function test_the_link_says_when_the_invitee_has_to_supply_a_password(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        [, $token] = $this->invite('known@example.com');

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('invitation.requires_registration', false)
            ->assertJsonPath('invitation.requires_password', true);

        [, $fresh] = $this->invite('new@example.com');

        $this->getJson("/api/v1/invitations/{$fresh}")
            ->assertOk()
            ->assertJsonPath('invitation.requires_registration', true)
            ->assertJsonPath('invitation.requires_password', false);
    }

    public function test_an_accepted_invitation_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        [, $token] = $this->invite('known@example.com');

        $this->actingAs($user)->postJson("/api/v1/invitations/{$token}/accept")->assertOk();

        $this->actingAs($user)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410);
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        [$invitation, $token] = $this->invite('known@example.com');

        $invitation->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($user)
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertStatus(410);

        $this->assertFalse($user->belongsToOrganization($this->organization));
    }

    public function test_an_invitation_for_an_existing_account_says_so(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        [, $token] = $this->invite('known@example.com');

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('invitation.requires_registration', false);
    }

    public function test_an_org_admin_lists_the_outstanding_invitations(): void
    {
        Invitation::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'pending@example.com',
        ]);
        Invitation::factory()->accepted()->create([
            'organization_id' => $this->organization->id,
            'email' => 'joined@example.com',
        ]);
        Invitation::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'email' => 'elsewhere@example.com',
        ]);

        $this->actingAs($this->member('org_admin'))
            ->getJson("/api/v1/organizations/{$this->organization->id}/invitations")
            ->assertOk()
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.email', 'pending@example.com');
    }

    public function test_a_location_admin_cannot_list_the_invitations(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->getJson("/api/v1/organizations/{$this->organization->id}/invitations")
            ->assertForbidden();
    }

    public function test_an_org_admin_revokes_an_invitation_and_the_link_stops_working(): void
    {
        [$plain, $hashed] = Invitation::generateToken();

        $invitation = Invitation::factory()->create([
            'organization_id' => $this->organization->id,
            'token' => $hashed,
        ]);

        $this->actingAs($this->member('org_admin'))
            ->deleteJson("/api/v1/organizations/{$this->organization->id}/invitations/{$invitation->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);

        $this->getJson("/api/v1/invitations/{$plain}")->assertNotFound();
    }

    public function test_an_accepted_invitation_cannot_be_revoked(): void
    {
        $invitation = Invitation::factory()->accepted()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->member('org_admin'))
            ->deleteJson("/api/v1/organizations/{$this->organization->id}/invitations/{$invitation->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
    }

    public function test_an_invitation_of_another_organization_cannot_be_revoked(): void
    {
        $foreign = Invitation::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('org_admin'))
            ->deleteJson("/api/v1/organizations/{$this->organization->id}/invitations/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('invitations', ['id' => $foreign->id]);
    }

    public function test_a_queued_invitation_email_is_dropped_when_the_invitation_is_gone(): void
    {
        config(['queue.default' => 'database']);

        [$invitation] = $this->invite('new@example.com');

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationNotification($invitation, 'https://example.test/invitations/token'));

        // Accepted, revoked or expired away before the worker got to it.
        $invitation->delete();

        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
