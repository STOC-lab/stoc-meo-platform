<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Brand;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Brands are master data. Every member may read them, while creating,
 * renaming and deleting one is left to administrators.
 *
 * The organization a check is made against is the active tenant, so a model
 * belonging to another organization is always denied even if a route somehow
 * hands one over.
 */
class BrandPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->belongsToTenant($brand) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::OrgAdmin);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->belongsToTenant($brand) && $this->hasRole($user, OrganizationRole::OrgAdmin);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->belongsToTenant($brand) && $this->hasRole($user, OrganizationRole::OrgAdmin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(Brand $brand): bool
    {
        return $brand->organization_id === $this->tenancy->id();
    }
}
