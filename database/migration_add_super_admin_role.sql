-- ============================================================
-- Migration: support the super_admin role
-- ============================================================
-- Run against an existing installation:
--   mysql -u root -p dorm_tenant_system < database/migration_add_super_admin_role.sql
--
-- After this migration, promote a trusted existing administrator
-- explicitly. Replace the email below, remove the comment, and run
-- the UPDATE separately only after verifying the target account:
--   UPDATE users SET role = 'super_admin'
--   WHERE email = 'trusted.admin@example.com' AND role = 'admin';
--
-- Do not grant super_admin from public registration or ordinary admin
-- forms. The schema.sql fresh-install definition includes this enum.
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','admin','tenant') NOT NULL DEFAULT 'tenant';