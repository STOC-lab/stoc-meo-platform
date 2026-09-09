<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Who may look at and change an organization's membership. v1.3 keeps the
 * member list itself behind the administrator rank, so who holds which role is
 * not visible to the people it describes. The rules that protect the owner
 * seat — only an owner may hand out or take away ownership, and the last one
 * cannot be removed — depend on the member being acted on and live in
 * MemberController.
 */
class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Viewer);
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::OrgAdmin);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::OrgAdmin);
    }

    public function viewInvitations(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::OrgAdmin);
    }

    public function manageInvitations(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::OrgAdmin);
    }
}
