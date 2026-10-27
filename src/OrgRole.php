<?php

declare(strict_types=1);

namespace CostSavings;

final class OrgRole
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_MEMBER = 'member';

    /** @deprecated Use isSuperAdmin(); kept as alias after admin role collapse. */
    public static function isPrivileged(string $role): bool
    {
        return self::isSuperAdmin($role);
    }

    public static function isSuperAdmin(string $role): bool
    {
        return strtolower(trim($role)) === self::ROLE_SUPER_ADMIN;
    }

    /** Only super admins may grant super_admin via project assignment. */
    public static function canElevateOrgRoles(string $role): bool
    {
        return strtolower(trim($role)) === self::ROLE_SUPER_ADMIN;
    }

    public static function label(string $role): string
    {
        switch (strtolower(trim($role))) {
            case self::ROLE_SUPER_ADMIN:
                return 'Super admin';
            case self::ROLE_MEMBER:
            default:
                return 'Manager';
        }
    }

    /**
     * Role titles and descriptions for help UI.
     *
     * @return array<int, array{title:string, description:string, note?:string}>
     */
    public static function roleDescriptionsForInvite(): array
    {
        return [
            [
                'title' => 'Super admin',
                'description' => 'Organization: invite managers, org settings, create/delete projects, QuickBooks. Project: full vendor access including confidential rows and copy operations.',
                'note' => 'Org super admin can create projects; project super admin controls all vendor data within a project.',
            ],
            [
                'title' => 'Manager',
                'description' => 'Organization: invite and manage managers, org settings, assign project managers. Project: add/edit vendors (spend, manager, visibility), public rows plus confidential rows they manage.',
            ],
        ];
    }
}
