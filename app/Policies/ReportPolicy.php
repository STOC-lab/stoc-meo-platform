<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Report;
use App\Models\User;
use App\Support\Tenancy;

/**
 * A report is a summary of what the organization already sees day to day, so
 * everyone in it may read one. Nobody creates one by hand: reports are built
 * by the monthly job.
 */
class ReportPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, OrganizationRole::Viewer);
    }

    public function view(User $user, Report $report): bool
    {
        return $report->organization_id === $this->tenancy->id()
            && $this->hasRole($user, OrganizationRole::Viewer);
    }

    protected function hasRole(User $user, OrganizationRole $role): bool
    {
        $organizationId = $this->tenancy->id();

        return $organizationId !== null && $user->hasRoleIn($organizationId, $role);
    }
}
