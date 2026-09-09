<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Store fronts are read by every member and edited by editors, who maintain
 * the details that appear on the Google Business Profile. Adding or removing
 * a store front changes what the organization is billed for, so it stays with
 * administrators.
 */
class LocationPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Location $location): bool
    {
        return $this->belongsToTenant($location) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Admin);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->belongsToTenant($location) && $this->hasRole($user, OrganizationRole::Editor);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->belongsToTenant($location) && $this->hasRole($user, OrganizationRole::Admin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(Location $location): bool
    {
        return $location->organization_id === $this->tenancy->id();
    }
}
