<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\ImprovementProposal;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Everyone in the organization can read the advice. Marking a proposal done or
 * turning it down is a decision about the store front's work, so it sits with
 * whoever runs the shop floor.
 */
class ImprovementProposalPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, ImprovementProposal $proposal): bool
    {
        return $this->belongsToTenant($proposal) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function update(User $user, ImprovementProposal $proposal): bool
    {
        return $this->belongsToTenant($proposal) && $this->hasRole($user, OrganizationRole::Staff);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(ImprovementProposal $proposal): bool
    {
        return $proposal->organization_id === $this->tenancy->id();
    }
}
