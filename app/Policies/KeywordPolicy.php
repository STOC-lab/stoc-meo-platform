<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Keyword;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Which search terms a store front tracks is the store manager's call — the
 * same rank as keeping the store front's own details current — while everyone
 * in the organization can see what is being tracked and how it is doing.
 */
class KeywordPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Keyword $keyword): bool
    {
        return $this->belongsToTenant($keyword) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    public function update(User $user, Keyword $keyword): bool
    {
        return $this->belongsToTenant($keyword) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    public function delete(User $user, Keyword $keyword): bool
    {
        return $this->belongsToTenant($keyword) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(Keyword $keyword): bool
    {
        return $keyword->organization_id === $this->tenancy->id();
    }
}
