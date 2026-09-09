<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Competitor;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Who a store front watches is the store manager's call, the same rank that
 * decides what it tracks, while everyone in the organization can see the list.
 */
class CompetitorPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Competitor $competitor): bool
    {
        return $this->belongsToTenant($competitor) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    public function delete(User $user, Competitor $competitor): bool
    {
        return $this->belongsToTenant($competitor) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(Competitor $competitor): bool
    {
        return $competitor->organization_id === $this->tenancy->id();
    }
}
