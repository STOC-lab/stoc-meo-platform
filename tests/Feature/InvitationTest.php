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
    protected function invite(string $email, OrganizationRole $role = OrganizationRole::Editor): array
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

    public function test_an_admin_invites_an_address_and_the_email_is_sent(): void
    {
        Notification::fake();

        $this->actingAs($this->member('admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'editor',
            ])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'new@example.com')
            ->assertJsonPath('invitation.role', 'editor')
            ->assertJsonPath('invitation.organization.name', 'テスト商店')
            ->assertJsonPath('invitation.requires_registration', true);

        $invitation = Invitation::acrossTenants()->firstOrFail();

        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame(OrganizationRole::Editor, $invitation->role);
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

        $this->actingAs($this->member('admin'))
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

    public function test_an_editor_cannot_invite(): void
    {
        Notification::fake();

        $this->actingAs($this->member('editor'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'viewer',
            ])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_only_an_owner_can_invite_an_owner(): void
    {
        $this->actingAs($this->member('admin'))
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

        $this->actingAs($this->member('admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => $existing->email,
                'role' => 'editor',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_re_inviting_replaces_the_outstanding_invitation(): void
    {
        Notification::fake();
        $admin = $this->member('admin');

        foreach (['viewer', 'editor'] as $role) {
            $this->actingAs($admin)
                ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                    'email' => 'new@example.com',
                    'role' => $role,
                ])
                ->assertCreated();
        }

        $this->assertSame(1, Invitation::acrossTenants()->count());
        $this->assertSame(OrganizationRole::Editor, Invitation::acrossTenants()->firstOrFail()->role);
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->actingAs($this->member('admin'))
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
        $mailable->assertSeeInHtml('編集者');
        $mailable->assertSeeInHtml('https://meo.stoc-plus.site/invitations/test-token', false);
        $mailable->assertSeeInText('招待');
    }

    public function test_the_email_reaches_the_mailer(): void
    {
        // Tests use the array transport, so this exercises the notification,
        // the mailable and the view together.
        $this->actingAs($this->member('admin'))
            ->postJson("/api/v1/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'editor',
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
            ->assertJsonPath('invitation.role_label', '編集者')
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
        $this->assertSame(OrganizationRole::Editor, $user->roleIn($this->organization));
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
        [$invitation, $token] = $this->invite('known@example.com', OrganizationRole::Admin);

        $this->actingAs($user)
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertOk();

        $this->assertSame(OrganizationRole::Admin, $user->roleIn($this->organization));
        $this->assertNotNull($invitation->refresh()->accepted_at);
    }

    public function test_an_existing_account_must_sign_in_before_accepting(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        [, $token] = $this->invite('known@example.com');

        $this->fromSpa()
            ->postJson("/api/v1/invitations/{$token}/accept", [
                'name' => '乗っ取り',
                'password' => 'password-1234',
                'password_confirmation' => 'password-1234',
            ])
            ->assertUnauthorized();

        $this->assertFalse($user->belongsToOrganization($this->organization));
        $this->assertGuest('web');
    }

    public function test_a_different_signed_in_user_cannot_accept(): void
    {
        [, $token] = $this->invite('known@example.com');

        $this->actingAs(User::factory()->create(['email' => 'someone.else@example.com']))
            ->postJson("/api/v1/invitations/{$token}/accept")
            ->assertForbidden();

        $this->assertDatabaseCount('organization_users', 1);
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
}
