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

    /**
     * Role titles and descriptions for the invite UI help modal.
     *
     * @return array<int, array{title:string, description:string, note?:string}>
     */
    public static function roleDescriptionsForInvite(): array
    {
        return [
            [
                'title' => 'Member',
                'description' => 'Sees public vendor rows plus confidential rows they manage. Can edit purposes on rows they manage.',
            ],
            [
                'title' => 'Administrator',
                'description' => 'Can invite users (as Member unless the inviter is a Super admin), manage members, and work with public vendor rows org-wide. Cannot create or delete projects.',
            ],
            [
                'title' => 'Super admin',
                'description' => 'Full organization access: create and delete projects, all vendor rows, choose invite role, and copy project data.',
                'note' => 'Not assignable via invite; shown for context.',
            ],
        ];
    }
}
