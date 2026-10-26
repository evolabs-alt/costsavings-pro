-- Collapse admin into member (two-tier roles: super_admin | member)
-- Safe to run once while ENUM still includes 'admin'. Re-running after shrink is a no-op
-- for UPDATEs (no admin rows) but ALTER may error if already applied — prefer migrateSchema.

-- 1) Remap data while 'admin' still exists in ENUMs
UPDATE user_organizations SET role = 'member' WHERE role = 'admin';
UPDATE project_members     SET role = 'member' WHERE role = 'admin';
UPDATE users               SET role = 'member' WHERE role = 'admin';
UPDATE invitations         SET invite_role = 'member' WHERE invite_role = 'admin';

-- 2) Shrink ENUMs
ALTER TABLE user_organizations
  MODIFY COLUMN role ENUM('super_admin','member') NOT NULL DEFAULT 'member';

ALTER TABLE project_members
  MODIFY COLUMN role ENUM('super_admin','member') NOT NULL DEFAULT 'member';

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','member') NOT NULL DEFAULT 'member';

ALTER TABLE invitations
  MODIFY COLUMN invite_role ENUM('member') NOT NULL DEFAULT 'member';
