<?php

declare(strict_types=1);

namespace CostSavings;

/**
 * Single org-scoped role model. Five roles drive both org powers and project/grid ACL.
 */
final class OrgRole
{
    public const ROLE_ORG_ADMIN = 'org_admin';
    public const ROLE_FINANCE_ADMIN = 'finance_admin';
    public const ROLE_PROJECT_MANAGER = 'project_manager';
    public const ROLE_COST_OWNER = 'cost_owner';
    public const ROLE_EXECUTIVE = 'executive';

    /** @deprecated Legacy DB value; remapped by migration to org_admin. */
    public const ROLE_SUPER_ADMIN = 'super_admin';
    /** @deprecated Legacy DB value; remapped by migration. */
    public const ROLE_MEMBER = 'member';

    public const VISIBILITY_FULL = 'full';
    public const VISIBILITY_ASSIGNED = 'assigned';

    /** @return list<string> */
    public static function allRoles(): array
    {
        return [
            self::ROLE_ORG_ADMIN,
            self::ROLE_FINANCE_ADMIN,
            self::ROLE_PROJECT_MANAGER,
            self::ROLE_COST_OWNER,
            self::ROLE_EXECUTIVE,
        ];
    }

    public static function normalize(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === self::ROLE_SUPER_ADMIN) {
            return self::ROLE_ORG_ADMIN;
        }
        if ($role === self::ROLE_MEMBER || $role === 'admin') {
            return self::ROLE_COST_OWNER;
        }
        if (in_array($role, self::allRoles(), true)) {
            return $role;
        }

        return self::ROLE_EXECUTIVE;
    }

    public static function isValid(string $role): bool
    {
        return in_array(strtolower(trim($role)), self::allRoles(), true);
    }

    /** @deprecated Use capability helpers; kept for transitional call sites. */
    public static function isPrivileged(string $role): bool
    {
        return self::isOrgAdmin($role);
    }

    /** @deprecated Prefer canConnectQbo / canCreateProject / etc. */
    public static function isSuperAdmin(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN || $r === self::ROLE_FINANCE_ADMIN;
    }

    public static function isOrgAdmin(string $role): bool
    {
        return self::normalize($role) === self::ROLE_ORG_ADMIN;
    }

    public static function isFinanceAdmin(string $role): bool
    {
        return self::normalize($role) === self::ROLE_FINANCE_ADMIN;
    }

    public static function isProjectManager(string $role): bool
    {
        return self::normalize($role) === self::ROLE_PROJECT_MANAGER;
    }

    public static function isCostOwner(string $role): bool
    {
        return self::normalize($role) === self::ROLE_COST_OWNER;
    }

    public static function isExecutive(string $role): bool
    {
        return self::normalize($role) === self::ROLE_EXECUTIVE;
    }

    /** Org Admin and Finance Admin may assign any role. */
    public static function canElevateOrgRoles(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN || $r === self::ROLE_FINANCE_ADMIN;
    }

    /** @return list<string> */
    public static function assignableRolesBy(string $actorRole): array
    {
        $actor = self::normalize($actorRole);
        if ($actor === self::ROLE_ORG_ADMIN || $actor === self::ROLE_FINANCE_ADMIN) {
            return self::allRoles();
        }
        if ($actor === self::ROLE_PROJECT_MANAGER) {
            return [
                self::ROLE_PROJECT_MANAGER,
                self::ROLE_COST_OWNER,
                self::ROLE_EXECUTIVE,
            ];
        }

        return [];
    }

    public static function canAssign(string $actorRole, string $targetRole): bool
    {
        $target = self::normalize($targetRole);

        return in_array($target, self::assignableRolesBy($actorRole), true);
    }

    public static function canConnectQbo(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN || $r === self::ROLE_FINANCE_ADMIN;
    }

    public static function canSyncQboToProject(string $role): bool
    {
        return self::canConnectQbo($role);
    }

    public static function canRenameOrg(string $role): bool
    {
        return self::isOrgAdmin($role);
    }

    public static function canCreateOrg(string $role): bool
    {
        return self::isOrgAdmin($role);
    }

    public static function canInvite(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN
            || $r === self::ROLE_FINANCE_ADMIN
            || $r === self::ROLE_PROJECT_MANAGER;
    }

    public static function canChangeRole(string $role): bool
    {
        return self::canInvite($role);
    }

    public static function canDisableUser(string $role): bool
    {
        return self::canInvite($role);
    }

    public static function canCreateProject(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN
            || $r === self::ROLE_FINANCE_ADMIN
            || $r === self::ROLE_PROJECT_MANAGER;
    }

    public static function canRenameProject(string $role): bool
    {
        return self::canCreateProject($role);
    }

    public static function canDeleteProject(string $role): bool
    {
        return self::canCreateProject($role);
    }

    public static function canExport(string $role): bool
    {
        return in_array(self::normalize($role), self::allRoles(), true);
    }

    public static function canReceiveAllProjectEmails(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN
            || $r === self::ROLE_FINANCE_ADMIN
            || $r === self::ROLE_PROJECT_MANAGER;
    }

    public static function canUseAi(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN
            || $r === self::ROLE_FINANCE_ADMIN
            || $r === self::ROLE_PROJECT_MANAGER
            || $r === self::ROLE_COST_OWNER;
    }

    public static function canManageVendorStructure(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN
            || $r === self::ROLE_FINANCE_ADMIN
            || $r === self::ROLE_PROJECT_MANAGER;
    }

    public static function canEditVendorFields(string $role): bool
    {
        $r = self::normalize($role);

        return $r === self::ROLE_ORG_ADMIN
            || $r === self::ROLE_FINANCE_ADMIN
            || $r === self::ROLE_PROJECT_MANAGER
            || $r === self::ROLE_COST_OWNER;
    }

    public static function canViewFullGrid(string $role): bool
    {
        return self::visibilityMode($role) === self::VISIBILITY_FULL;
    }

    public static function seesFilteredRows(string $role): bool
    {
        return self::visibilityMode($role) === self::VISIBILITY_ASSIGNED;
    }

    /**
     * full = all rows; assigned = public + confidential where manager_user_id = user.
     */
    public static function visibilityMode(string $role): string
    {
        return self::isCostOwner($role) ? self::VISIBILITY_ASSIGNED : self::VISIBILITY_FULL;
    }

    /**
     * Whether loadVisibleItems should apply the Cost Owner filter.
     * Org admin / finance / PM / executive see all rows.
     */
    public static function usesAssignedVisibilityFilter(string $role): bool
    {
        return self::seesFilteredRows($role);
    }

    public static function label(string $role): string
    {
        return self::orgLabel($role);
    }

    public static function orgLabel(string $role): string
    {
        switch (self::normalize($role)) {
            case self::ROLE_ORG_ADMIN:
                return 'Org Admin';
            case self::ROLE_FINANCE_ADMIN:
                return 'Finance Admin';
            case self::ROLE_PROJECT_MANAGER:
                return 'Project Manager';
            case self::ROLE_COST_OWNER:
                return 'Cost Owner';
            case self::ROLE_EXECUTIVE:
            default:
                return 'Executive/Viewer';
        }
    }

    /** @deprecated Project-scoped labels removed; returns org label. */
    public static function projectLabel(string $role): string
    {
        return self::orgLabel($role);
    }

    /**
     * Resolve organization role from an invitations row.
     *
     * @param array<string,mixed> $inv
     */
    public static function resolveInviteRole(array $inv): string
    {
        $role = strtolower(trim((string) ($inv['invite_role'] ?? self::ROLE_COST_OWNER)));
        if ($role === self::ROLE_SUPER_ADMIN || $role === 'admin') {
            return self::ROLE_ORG_ADMIN;
        }
        if ($role === self::ROLE_MEMBER) {
            return self::ROLE_COST_OWNER;
        }
        if (self::isValid($role)) {
            return $role;
        }

        return self::ROLE_COST_OWNER;
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
                'title' => 'Org Admin',
                'description' => 'Full organization control: rename/create org, QBO, invite and manage all roles, projects, vendor grid, AI, and reports.',
            ],
            [
                'title' => 'Finance Admin',
                'description' => 'Same as Org Admin for QBO, invites, projects, grid, AI, and reports. Cannot rename or create organizations.',
            ],
            [
                'title' => 'Project Manager',
                'description' => 'Create and manage projects and the full vendor grid. May invite or change Project Manager, Cost Owner, and Executive roles only. No QBO connect/sync.',
            ],
            [
                'title' => 'Cost Owner',
                'description' => 'Sees public rows plus confidential rows assigned to them. Can update cost, frequency, owner, visibility, and status on those rows; AI assistant available. Cannot add/delete/rename vendor rows.',
            ],
            [
                'title' => 'Executive/Viewer',
                'description' => 'Read-only access to all project data, exports, and row chat. No edits, AI assistant, or project emails.',
            ],
        ];
    }
}
