<?php

namespace App\Enums;

/**
 * Roles a user can hold inside an organization, ordered from least to most
 * privileged. Higher levels implicitly satisfy every lower level.
 *
 * These are the organization-scoped roles of STOC MEO SYSTEM DESIGN v1.3. The
 * platform-scoped roles above them — agency_admin, stoc_admin and super_admin —
 * are not organization memberships and are left to a later phase; owner is the
 * seat held by whoever registered the organization and outranks org_admin.
 */
enum OrganizationRole: string
{
    case Viewer = 'viewer';
    case Staff = 'staff';
    case LocationAdmin = 'location_admin';
    case OrgAdmin = 'org_admin';
    case Owner = 'owner';

    /**
     * Numeric rank used to compare roles.
     */
    public function level(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Staff => 2,
            self::LocationAdmin => 3,
            self::OrgAdmin => 4,
            self::Owner => 5,
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
            self::Staff => 'スタッフ',
            self::LocationAdmin => '店舗管理者',
            self::OrgAdmin => '組織管理者',
            self::Owner => 'オーナー',
        };
    }
}
