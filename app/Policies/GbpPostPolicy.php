<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\GbpPost;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Everyone in the organization can see what has been posted. Publishing speaks
 * for the business in public and spends the plan's monthly allowance, so it
 * sits with the store manager.
 */
class GbpPostPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, GbpPost $post): bool
    {
        return $this->belongsToTenant($post) && $this->hasRole($user, OrganizationRole::Viewer);
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

    protected function belongsToTenant(GbpPost $post): bool
    {
        return $post->organization_id === $this->tenancy->id();
    }
}
