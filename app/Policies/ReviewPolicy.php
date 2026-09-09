<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Review;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Everyone in the organization can read the reviews. Answering one speaks for
 * the business in public, so it sits with staff and above rather than with
 * viewers.
 */
class ReviewPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Review $review): bool
    {
        return $this->belongsToTenant($review) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function reply(User $user, Review $review): bool
    {
        return $this->belongsToTenant($review) && $this->hasRole($user, OrganizationRole::Staff);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(Review $review): bool
    {
        return $review->organization_id === $this->tenancy->id();
    }
}
