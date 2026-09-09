<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\HeatmapRun;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Everyone in the organization can read the maps; asking for a new one spends
 * the plan's monthly allowance, so it is the store manager's call — the same
 * rank that decides what the store front tracks in the first place.
 */
class HeatmapRunPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, HeatmapRun $run): bool
    {
        return $this->belongsToTenant($run) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(HeatmapRun $run): bool
    {
        return $run->organization_id === $this->tenancy->id();
    }
}
