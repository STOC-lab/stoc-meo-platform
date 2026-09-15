<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Enums\OrganizationRole;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\FeatureResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    public function __construct(protected FeatureResolver $features) {}

    /**
     * The invitations that are still outstanding, newest first. Accepted ones
     * are left out: the invitee shows up in the member list instead.
     */
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('viewInvitations', $organization);

        $invitations = Invitation::query()
            ->whereNull('accepted_at')
            ->with(['organization', 'inviter'])
            ->latest()
            ->get();

        return response()->json([
            'invitations' => $invitations
                ->map(fn (Invitation $invitation) => $this->present($invitation))
                ->all(),
        ]);
    }

    /**
     * Invite an address to the active organization.
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('manageInvitations', $organization);

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

        $this->guardSeatAllowance($organization, $data['email']);

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
     * How many people the plan seats, counted from the members who are here
     * plus the invitations still outstanding.
     *
     * An invitation holds a seat. Counting only accepted members would let an
     * organization invite its way past the plan and only discover it when the
     * last person tried to sign in — and the person refused would be whoever
     * happened to accept last, not whoever was invited last.
     *
     * Re-inviting an address that already has an outstanding invitation
     * replaces it rather than adding one, so that address does not pay twice.
     *
     * @throws QuotaExceededException
     */
    protected function guardSeatAllowance(Organization $organization, string $email): void
    {
        // A plan that does not mention the limit predates it; that is not the
        // same as a plan seating nobody, and FeatureResolver answers 0 to both.
        if (! $this->features->has(Feature::MemberLimit, $organization)) {
            return;
        }

        $limit = $this->features->limit(Feature::MemberLimit, $organization);

        if ($limit === null) {
            return;
        }

        $used = $organization->memberships()->count() + Invitation::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('accepted_at')
            ->where('email', '!=', $email)
            ->count();

        if ($used >= $limit) {
            throw QuotaExceededException::for(Feature::MemberLimit, $limit, $used);
        }
    }

    /**
     * Withdraw an outstanding invitation, so its link stops working.
     */
    public function destroy(Organization $organization, Invitation $invitation): Response
    {
        $this->authorize('manageInvitations', $organization);

        if ($invitation->isAccepted()) {
            abort(422, 'この招待は既に承諾されているため取り消せません。');
        }

        $invitation->delete();

        return response()->noContent();
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
     * Accept the invitation as the address it names, registering that account
     * when it does not exist yet.
     *
     * A session that is already open belongs to whoever was last using the
     * browser, which is not necessarily the person the link was mailed to — an
     * invitee who is signed in under a personal address, or a shop owner who
     * opened the link to check it. Refusing the link because the open session
     * names somebody else stranded the invitation on a working token, so the
     * session no longer decides anything: the invitation does, and the
     * acceptance hands the browser over to the account it names.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->findOrFail($token);

        if (! $invitation->isPending()) {
            abort(410, $invitation->isAccepted()
                ? 'この招待は既に使用されています。'
                : 'この招待は有効期限が切れています。');
        }

        $signedIn = $request->user();
        $existing = $this->accountFor($invitation);

        if ($signedIn !== null && $this->matchesInvitation($signedIn, $invitation)) {
            // Already signed in as the invitee; the session stays as it is.
            $user = $signedIn;
            $handOverSession = false;
        } else {
            // Holding the token proves control of the mailbox, which is enough
            // to create the account it was addressed to — but not enough to be
            // handed a session on an account that already exists, whatever the
            // browser is signed in as. That one proves itself by its password.
            $user = $existing === null
                ? $this->registerInvitee($request, $invitation)
                : $this->authenticateInvitee($request, $existing);

            $handOverSession = true;
        }

        DB::transaction(function () use ($invitation, $user) {
            if (! $user->belongsToOrganization($invitation->organization_id)) {
                $invitation->organization->users()->attach($user, ['role' => $invitation->role->value]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        if ($handOverSession) {
            $this->requireSession($request);

            Auth::login($user);
            $request->session()->regenerate();
        }

        return response()->json([
            'invitation' => $this->present($invitation->refresh(), $user),
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

    /**
     * Prove the invitee is who the invitation names, when that account already
     * exists. The route is throttled, which is what keeps this from being a
     * password oracle for any address someone holds a link for.
     */
    protected function authenticateInvitee(Request $request, User $user): User
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'パスワードが正しくありません。',
            ]);
        }

        return $user;
    }

    protected function accountFor(Invitation $invitation): ?User
    {
        return User::where('email', $invitation->email)->first();
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
    protected function present(Invitation $invitation, ?User $viewer = null): array
    {
        $existing = $this->accountFor($invitation);
        $viewer ??= Auth::user();

        return [
            // The list is what the members screen offers a "withdraw" button
            // against, and the route binds on the id.
            'id' => $invitation->id,
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
            'requires_registration' => $existing === null,
            // An account that already exists proves itself by its password
            // before the browser is handed over to it — unless it is the
            // session that is already open.
            'requires_password' => $existing !== null
                && ($viewer === null || ! $this->matchesInvitation($viewer, $invitation)),
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
