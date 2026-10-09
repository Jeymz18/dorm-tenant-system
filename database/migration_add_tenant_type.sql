-- ============================================================
-- Migration: add tenant_type to tenants
-- ============================================================
-- Adds a Student / Employee classification for each tenant, used
-- to break down the "Tenant Mix" widget on the Admin Dashboard.
--
-- Safe to run more than once — IF NOT EXISTS means it does nothing
-- (instead of erroring) if the column is already there.
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_tenant_type.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes the column.
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS tenant_type ENUM('Student','Employee') NOT NULL DEFAULT 'Student' AFTER status;
