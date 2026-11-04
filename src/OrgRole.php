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

    /** Only organization admins may grant organization admin roles. */
    public static function canElevateOrgRoles(string $role): bool
    {
        return strtolower(trim($role)) === self::ROLE_SUPER_ADMIN;
    }

    /** Organization-scoped display label (Manage Managers, etc.). */
    public static function label(string $role): string
    {
        return self::orgLabel($role);
    }

    public static function orgLabel(string $role): string
    {
        switch (strtolower(trim($role))) {
            case self::ROLE_SUPER_ADMIN:
                return 'Organization Admin';
            case self::ROLE_MEMBER:
            default:
                return 'Organization Member';
        }
    }

    public static function projectLabel(string $role): string
    {
        switch (strtolower(trim($role))) {
            case self::ROLE_SUPER_ADMIN:
                return 'Project super admin';
            case self::ROLE_MEMBER:
            default:
                return 'Project manager';
        }
    }

    /**
     * Resolve organization role from an invitations row.
     * Legacy invite_role=admin remaps to member; only super_admin elevates.
     *
     * @param array<string,mixed> $inv
     */
    public static function resolveInviteRole(array $inv): string
    {
        $role = strtolower(trim((string) ($inv['invite_role'] ?? self::ROLE_MEMBER)));
        if ($role === 'admin') {
            return self::ROLE_MEMBER;
        }
        if ($role === self::ROLE_SUPER_ADMIN) {
            return self::ROLE_SUPER_ADMIN;
        }

        return self::ROLE_MEMBER;
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
                'title' => 'Organization Admin',
                'description' => 'Organization: invite members, org settings, create/delete projects, QuickBooks, assign organization roles. Project: when also project super admin, full vendor access including confidential rows and copy operations.',
                'note' => 'Organization Admin and Project super admin are separate. Organization Admin controls the org; Project super admin controls vendor data within a project.',
            ],
            [
                'title' => 'Organization Member',
                'description' => 'Organization: invite and manage members, org settings, assign project managers. Project: as project manager, add/edit vendors (spend, manager, visibility), public rows plus confidential rows they manage.',
            ],
        ];
    }
}
