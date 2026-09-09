<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Who may look at and change an organization's membership. The rules that
 * protect the owner seat — only an owner may hand out or take away ownership,
 * and the last one cannot be removed — depend on the member being acted on and
 * live in MemberController.
 */
class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Viewer);
    }

    /**
     * Members are visible to everyone in the organization: knowing who to ask
     * for access is not privileged information.
     */
    public function viewMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Viewer);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    public function viewInvitations(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    public function manageInvitations(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }
}
