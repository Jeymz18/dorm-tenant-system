-- ============================================================
-- Migration: add key_returned to tenants
-- ============================================================
-- Run this if you already imported database/schema.sql before the
-- Check-in/Check-out Monitor page was added. It adds the missing
-- column without touching any existing data.
--
-- Safe to run more than once — IF NOT EXISTS means it does nothing
-- (instead of erroring) if the column is already there.
--
-- If you're setting the project up for the first time, you don't
-- need this file at all — schema.sql already includes the column.
--
-- How to run it (phpMyAdmin):
--   1. Open phpMyAdmin → select the dorm_tenant_system database.
--   2. Click the "SQL" tab.
--   3. Paste everything below and click "Go".
--
-- How to run it (command line):
--   mysql -u root -p dorm_tenant_system < database/migration_add_key_returned.sql
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS key_returned BOOLEAN NOT NULL DEFAULT FALSE AFTER status;
