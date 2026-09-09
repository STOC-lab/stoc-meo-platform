<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The membership of an organization: who belongs to it, what they may do, and
 * removing them.
 *
 * Two invariants protect the owner seat, since losing it would leave nobody
 * able to manage billing: only an owner may grant or take away ownership, and
 * the last owner can be neither demoted nor removed. Members are resolved
 * through the organization by the route's scoped bindings, so a user who is
 * not a member is a 404.
 */
class MemberController extends Controller
{
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('viewMembers', $organization);

        $members = $organization->users()->orderBy('users.name')->get();

        return response()->json([
            'members' => $members->map(fn (User $member) => $this->present($member))->all(),
        ]);
    }

    /**
     * Change the role a member holds.
     */
    public function update(UpdateMemberRequest $request, Organization $organization, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        $role = OrganizationRole::from($request->validated('role'));
        $current = $user->roleIn($organization);

        $this->guardOwnerSeat($request, $organization, $user, $current);

        if ($role !== OrganizationRole::Owner && $current === OrganizationRole::Owner && $this->isLastOwner($organization)) {
            throw ValidationException::withMessages([
                'role' => '組織には少なくとも1人のオーナーが必要です。',
            ]);
        }

        if ($role === OrganizationRole::Owner && ! $this->actorIsOwner($request, $organization)) {
            throw ValidationException::withMessages([
                'role' => 'オーナー権限を付与できるのはオーナーのみです。',
            ]);
        }

        $organization->users()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

        return response()->json([
            'member' => $this->present($organization->users()->whereKey($user->getKey())->firstOrFail()),
        ]);
    }

    /**
     * Remove a member from the organization.
     */
    public function destroy(Request $request, Organization $organization, User $user): Response
    {
        $this->authorize('manageMembers', $organization);

        $current = $user->roleIn($organization);

        $this->guardOwnerSeat($request, $organization, $user, $current);

        if ($current === OrganizationRole::Owner && $this->isLastOwner($organization)) {
            abort(422, '組織には少なくとも1人のオーナーが必要です。');
        }

        $organization->users()->detach($user->getKey());

        return response()->noContent();
    }

    /**
     * An administrator may manage every member except an owner; only another
     * owner may act on one.
     */
    protected function guardOwnerSeat(Request $request, Organization $organization, User $user, ?OrganizationRole $current): void
    {
        if ($current === OrganizationRole::Owner && ! $this->actorIsOwner($request, $organization)) {
            abort(403, 'オーナーに対する操作を行えるのはオーナーのみです。');
        }
    }

    protected function actorIsOwner(Request $request, Organization $organization): bool
    {
        return $request->user()?->roleIn($organization) === OrganizationRole::Owner;
    }

    protected function isLastOwner(Organization $organization): bool
    {
        return $organization->memberships()
            ->where('role', OrganizationRole::Owner->value)
            ->count() <= 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(User $member): array
    {
        $role = $member->pivot->role;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $role->value,
            'role_label' => $role->label(),
            'joined_at' => $member->pivot->created_at?->toIso8601String(),
        ];
    }
}
