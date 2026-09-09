<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Connecting a store front to Google hands the application standing access to
 * that profile, so it sits with organization administrators rather than with
 * whoever manages the store day to day.
 */
class GbpAccountPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function connect(User $user, Location $location): bool
    {
        return $location->organization_id === $this->tenancy->id()
            && $this->hasRole($user, OrganizationRole::OrgAdmin);
    }

    public function view(User $user, GbpAccount $account): bool
    {
        return $account->organization_id === $this->tenancy->id()
            && $this->hasRole($user, OrganizationRole::Viewer);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }
}
