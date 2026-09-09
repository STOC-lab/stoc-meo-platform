<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Alert;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Alerts are what the organization needs to know about, so everyone can read
 * them. Clearing one is a small act of shop-floor housekeeping.
 */
class AlertPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Alert $alert): bool
    {
        return $this->belongsToTenant($alert) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function update(User $user, Alert $alert): bool
    {
        return $this->belongsToTenant($alert) && $this->hasRole($user, OrganizationRole::Staff);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(Alert $alert): bool
    {
        return $alert->organization_id === $this->tenancy->id();
    }
}
