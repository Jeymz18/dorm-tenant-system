-- ============================================================
-- Migration: add rejection_reason to tenants
-- ============================================================
-- Adds the column that lets an admin record why an application
-- was rejected, and lets a rejected applicant see that reason
-- instead of an indefinite "under review" message.
--
-- Safe to run more than once — IF NOT EXISTS means it does nothing
-- (instead of erroring) if the column is already there.
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_rejection_reason.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes the column.
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL AFTER approval_status;
