<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The v1.3 rename is a data migration, so it is worth proving it moves the
 * rows it is meant to and leaves the rest alone.
 */
class OrganizationRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renames_the_old_roles_and_leaves_the_others_alone(): void
    {
        $organization = Organization::factory()->create();

        $ids = collect(['editor', 'admin', 'viewer', 'owner'])
            ->mapWithKeys(fn (string $role) => [
                $role => $this->attachLegacyMember($organization, $role),
            ]);

        $this->migration()->up();

        $this->assertSame('staff', $this->roleOf($organization, $ids['editor']));
        $this->assertSame('org_admin', $this->roleOf($organization, $ids['admin']));
        $this->assertSame('viewer', $this->roleOf($organization, $ids['viewer']));
        $this->assertSame('owner', $this->roleOf($organization, $ids['owner']));
    }

    public function test_it_can_be_rolled_back(): void
    {
        $organization = Organization::factory()->create();
        $userId = $this->attachLegacyMember($organization, 'staff');

        $this->migration()->down();

        $this->assertSame('editor', $this->roleOf($organization, $userId));
    }

    /**
     * Attach a membership straight through the query builder: the model casts
     * the role, and the names this migration moves away from are no longer
     * part of the enum.
     */
    protected function attachLegacyMember(Organization $organization, string $role): int
    {
        $user = User::factory()->create();

        DB::table('organization_users')->insert([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->getKey();
    }

    protected function migration(): object
    {
        return require database_path(
            'migrations/2026_09_09_010933_align_organization_roles_with_design_v13.php',
        );
    }

    protected function roleOf(Organization $organization, int $userId): string
    {
        return DB::table('organization_users')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $userId)
            ->value('role');
    }
}
