-- Unify to five org-scoped roles (org_admin | finance_admin | project_manager | cost_owner | executive)
-- Prefer migrateSchema() / migrateUnifiedFiveRoles() at boot; this file mirrors that logic for manual runs.

-- 1) Widen user_organizations
ALTER TABLE user_organizations
  MODIFY COLUMN role ENUM(
    'super_admin','member',
    'org_admin','finance_admin','project_manager','cost_owner','executive'
  ) NOT NULL DEFAULT 'executive';

UPDATE user_organizations SET role = 'org_admin' WHERE role = 'super_admin';

UPDATE user_organizations uo
SET uo.role = 'project_manager'
WHERE uo.role IN ('member')
  AND EXISTS (
    SELECT 1 FROM project_members pm
    INNER JOIN projects p ON p.id = pm.project_id
    WHERE pm.user_id = uo.user_id
      AND p.org_id = uo.org_id
      AND pm.role = 'super_admin'
  );

UPDATE user_organizations uo
SET uo.role = 'cost_owner'
WHERE uo.role IN ('member')
  AND EXISTS (
    SELECT 1 FROM project_members pm
    INNER JOIN projects p ON p.id = pm.project_id
    WHERE pm.user_id = uo.user_id
      AND p.org_id = uo.org_id
      AND pm.role = 'member'
  );

UPDATE user_organizations SET role = 'executive' WHERE role IN ('member');

ALTER TABLE user_organizations
  MODIFY COLUMN role ENUM(
    'org_admin','finance_admin','project_manager','cost_owner','executive'
  ) NOT NULL DEFAULT 'executive';

-- 2) Invitations
ALTER TABLE invitations
  MODIFY COLUMN invite_role ENUM(
    'super_admin','member',
    'org_admin','finance_admin','project_manager','cost_owner','executive'
  ) NOT NULL DEFAULT 'cost_owner';

UPDATE invitations SET invite_role = 'org_admin' WHERE invite_role = 'super_admin';
UPDATE invitations SET invite_role = 'cost_owner' WHERE invite_role = 'member';

ALTER TABLE invitations
  MODIFY COLUMN invite_role ENUM(
    'org_admin','finance_admin','project_manager','cost_owner','executive'
  ) NOT NULL DEFAULT 'cost_owner';

-- 3) Legacy users.role
ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'super_admin','member',
    'org_admin','finance_admin','project_manager','cost_owner','executive'
  ) NOT NULL DEFAULT 'executive';

UPDATE users SET role = 'org_admin' WHERE role = 'super_admin';
UPDATE users SET role = 'executive' WHERE role = 'member';

ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'org_admin','finance_admin','project_manager','cost_owner','executive'
  ) NOT NULL DEFAULT 'executive';
