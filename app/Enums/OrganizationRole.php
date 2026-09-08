<?php

namespace App\Enums;

/**
 * Roles a user can hold inside an organization, ordered from least to most
 * privileged. Higher levels implicitly satisfy every lower level.
 */
enum OrganizationRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Admin = 'admin';
    case Owner = 'owner';

    /**
     * Numeric rank used to compare roles.
     */
    public function level(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Editor => 2,
            self::Admin => 3,
            self::Owner => 4,
        };
    }

    /**
     * Determine whether this role is at least as privileged as the given one.
     */
    public function atLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    public function label(): string
    {
        return match ($this) {
            self::Viewer => '閲覧者',
            self::Editor => '編集者',
            self::Admin => '管理者',
            self::Owner => 'オーナー',
        };
    }
}
