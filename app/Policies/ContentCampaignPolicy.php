<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\ContentCampaign;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Everyone in the organization can see what is planned. Writing a campaign and
 * approving what the model wrote both speak for the business in public, so
 * they sit with the store manager.
 */
class ContentCampaignPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, ContentCampaign $campaign): bool
    {
        return $this->belongsToTenant($campaign) && $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    public function update(User $user, ContentCampaign $campaign): bool
    {
        return $this->belongsToTenant($campaign) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    public function delete(User $user, ContentCampaign $campaign): bool
    {
        return $this->belongsToTenant($campaign) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    public function generate(User $user, ContentCampaign $campaign): bool
    {
        return $this->belongsToTenant($campaign) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    /**
     * Approving is what puts words in front of customers, so it is the same
     * rank that writes the campaign rather than anyone who can see it.
     */
    public function approve(User $user, ContentCampaign $campaign): bool
    {
        return $this->belongsToTenant($campaign) && $this->hasRole($user, OrganizationRole::LocationAdmin);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }

    protected function belongsToTenant(ContentCampaign $campaign): bool
    {
        return $campaign->organization_id === $this->tenancy->id();
    }
}
