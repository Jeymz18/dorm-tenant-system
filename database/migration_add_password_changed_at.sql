-- ============================================================
-- Migration: add password_changed_at to users
-- ============================================================
-- Lets the app force-logout any other session still using the
-- account's old password once it's reset — compared against a
-- snapshot stored in the session at login time (see login_user()
-- and require_login() in includes/auth.php).
--
-- Safe to run more than once — IF NOT EXISTS means it does nothing
-- (instead of erroring) if the column is already there.
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_password_changed_at.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes this column.
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME DEFAULT NULL AFTER password_hash;
