<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Invitations into an organization: issuing them, looking one up from the
 * emailed link, and accepting it — with or without an existing account.
 */
class InvitationController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Invite an address to the active organization.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->tenancy->organization();
        $inviter = $request->user();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $role = OrganizationRole::from($data['role']);

        // Only an owner may hand out ownership.
        if ($role === OrganizationRole::Owner && $inviter->roleIn($organization) !== OrganizationRole::Owner) {
            throw ValidationException::withMessages([
                'role' => 'オーナー権限を付与できるのはオーナーのみです。',
            ]);
        }

        if ($this->alreadyMember($organization, $data['email'])) {
            throw ValidationException::withMessages([
                'email' => 'このユーザーは既に組織のメンバーです。',
            ]);
        }

        [$plainToken, $hashedToken] = Invitation::generateToken();

        // Re-inviting the same address replaces the outstanding invitation, so
        // an old link cannot be used after the role has been changed.
        $invitation = DB::transaction(function () use ($organization, $inviter, $data, $role, $hashedToken) {
            Invitation::where('organization_id', $organization->getKey())
                ->where('email', $data['email'])
                ->whereNull('accepted_at')
                ->delete();

            return Invitation::create([
                'organization_id' => $organization->getKey(),
                'invited_by' => $inviter->getKey(),
                'email' => $data['email'],
                'role' => $role->value,
                'token' => $hashedToken,
                'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
            ]);
        });

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationNotification(
                $invitation->load(['organization', 'inviter']),
                $this->acceptUrl($plainToken),
            ));

        return response()->json([
            'invitation' => $this->present($invitation),
        ], 201);
    }

    /**
     * What a link recipient is being offered.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findOrFail($token);

        return response()->json(['invitation' => $this->present($invitation)]);
    }

    /**
     * Accept the invitation, registering the invitee when they have no account.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->findOrFail($token);

        if (! $invitation->isPending()) {
            abort(410, $invitation->isAccepted()
                ? 'この招待は既に使用されています。'
                : 'この招待は有効期限が切れています。');
        }

        $user = $request->user();

        if ($user !== null) {
            abort_unless(
                $this->matchesInvitation($user, $invitation),
                403,
                'この招待は別のメールアドレス宛てに発行されています。',
            );
        } else {
            // Holding the token proves control of the mailbox, which is enough
            // to create the account it was addressed to — but not enough to
            // sign in as an account that already exists.
            abort_if(
                User::where('email', $invitation->email)->exists(),
                401,
                'このメールアドレスのアカウントは既に存在します。ログインしてから招待を承諾してください。',
            );

            $user = $this->registerInvitee($request, $invitation);
        }

        DB::transaction(function () use ($invitation, $user) {
            if (! $user->belongsToOrganization($invitation->organization_id)) {
                $invitation->organization->users()->attach($user, ['role' => $invitation->role->value]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        if ($request->user() === null) {
            $this->requireSession($request);

            Auth::login($user);
            $request->session()->regenerate();
        }

        return response()->json([
            'invitation' => $this->present($invitation->refresh()),
        ]);
    }

    /**
     * Create the account the invitation was addressed to.
     */
    protected function registerInvitee(Request $request, Invitation $invitation): User
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        return User::create([
            'name' => $data['name'],
            'email' => $invitation->email,
            'password' => $data['password'],
        ]);
    }

    protected function matchesInvitation(User $user, Invitation $invitation): bool
    {
        return mb_strtolower($user->email) === mb_strtolower($invitation->email);
    }

    protected function alreadyMember(Organization $organization, string $email): bool
    {
        return $organization->users()->where('users.email', $email)->exists();
    }

    protected function findOrFail(string $token): Invitation
    {
        return Invitation::forToken($token)
            ->with(['organization', 'inviter'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Invitation $invitation): array
    {
        return [
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'role_label' => $invitation->role->label(),
            'organization' => [
                'id' => $invitation->organization->id,
                'name' => $invitation->organization->name,
            ],
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted' => $invitation->isAccepted(),
            'expired' => $invitation->isExpired(),
            'requires_registration' => ! User::where('email', $invitation->email)->exists(),
        ];
    }

    protected function acceptUrl(string $plainToken): string
    {
        return rtrim((string) config('app.url'), '/').'/invitations/'.$plainToken;
    }

    protected function requireSession(Request $request): void
    {
        abort_unless($request->hasSession(), 419, 'セッションを開始できませんでした。ページを再読み込みしてください。');
    }
}
