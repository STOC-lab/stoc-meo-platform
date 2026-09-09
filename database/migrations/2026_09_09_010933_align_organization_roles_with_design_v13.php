<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the organization roles to the vocabulary of STOC MEO SYSTEM DESIGN
 * v1.3: editor becomes staff and admin becomes org_admin. Viewer and owner keep
 * their names, and location_admin — the new rank between staff and org_admin —
 * starts out unassigned.
 */
return new class extends Migration
{
    /**
     * The tables holding a role, and how the old names map to the new ones.
     *
     * @var array<int, string>
     */
    protected array $tables = ['organization_users', 'invitations'];

    /**
     * @var array<string, string>
     */
    protected array $renames = [
        'editor' => 'staff',
        'admin' => 'org_admin',
    ];

    public function up(): void
    {
        $this->rename($this->renames);
    }

    public function down(): void
    {
        $this->rename(array_flip($this->renames));
    }

    /**
     * @param  array<string, string>  $renames
     */
    protected function rename(array $renames): void
    {
        foreach ($this->tables as $table) {
            foreach ($renames as $from => $to) {
                DB::table($table)->where('role', $from)->update(['role' => $to]);
            }
        }
    }
};
