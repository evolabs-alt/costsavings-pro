<?php

declare(strict_types=1);

namespace CostSavings;

final class OrgRole
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public static function isPrivileged(string $role): bool
    {
        $r = strtolower(trim($role));

        return $r === self::ROLE_SUPER_ADMIN || $r === self::ROLE_ADMIN;
    }

    public static function isSuperAdmin(string $role): bool
    {
        return strtolower(trim($role)) === self::ROLE_SUPER_ADMIN;
    }

    /** Only super admins may grant org admin / super-admin roles via invite or promotion. */
    public static function canElevateOrgRoles(string $role): bool
    {
        return strtolower(trim($role)) === self::ROLE_SUPER_ADMIN;
    }

    public static function label(string $role): string
    {
        switch (strtolower(trim($role))) {
            case self::ROLE_SUPER_ADMIN:
                return 'Super admin';
            case self::ROLE_ADMIN:
                return 'Admin';
            case self::ROLE_MEMBER:
            default:
                return 'Member';
        }
    }
}
