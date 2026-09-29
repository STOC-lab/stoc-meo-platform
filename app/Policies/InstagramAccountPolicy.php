<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Connecting a store front to Instagram hands the application standing access
 * to that account, so, like Google, it sits with organization administrators.
 */
class InstagramAccountPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function connect(User $user, Location $location): bool
    {
        return $location->organization_id === $this->tenancy->id()
            && $this->hasRole($user, OrganizationRole::OrgAdmin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }
}
