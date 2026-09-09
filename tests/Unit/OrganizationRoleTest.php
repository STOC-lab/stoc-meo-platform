<?php

namespace Tests\Unit;

use App\Enums\OrganizationRole;
use PHPUnit\Framework\TestCase;

class OrganizationRoleTest extends TestCase
{
    public function test_the_ranking_follows_the_design_hierarchy(): void
    {
        $ranked = array_map(
            fn (OrganizationRole $role) => $role->value,
            collect(OrganizationRole::cases())
                ->sortBy(fn (OrganizationRole $role) => $role->level())
                ->values()
                ->all(),
        );

        $this->assertSame(
            ['viewer', 'staff', 'location_admin', 'org_admin', 'owner'],
            $ranked,
        );
    }

    public function test_a_role_satisfies_every_rank_at_or_below_it(): void
    {
        $this->assertTrue(OrganizationRole::OrgAdmin->atLeast(OrganizationRole::LocationAdmin));
        $this->assertTrue(OrganizationRole::LocationAdmin->atLeast(OrganizationRole::Staff));
        $this->assertTrue(OrganizationRole::Staff->atLeast(OrganizationRole::Staff));
    }

    public function test_a_role_does_not_satisfy_a_higher_rank(): void
    {
        $this->assertFalse(OrganizationRole::Staff->atLeast(OrganizationRole::LocationAdmin));
        $this->assertFalse(OrganizationRole::LocationAdmin->atLeast(OrganizationRole::OrgAdmin));
        $this->assertFalse(OrganizationRole::OrgAdmin->atLeast(OrganizationRole::Owner));
    }
}
