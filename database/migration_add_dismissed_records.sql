-- ============================================================
-- Migration: add dismissed_records
-- ============================================================
-- Backs the "Clear" button on Tenant Registration/Approval, Track
-- Tenant Status, and the Check-in/Check-out Monitor. Clearing only
-- hides rows from that admin page — it records which (page, tenant)
-- pairs an admin dismissed so the page's query can filter them out.
-- The underlying tenants/payments/contracts rows are never touched,
-- so reports and analytics keep seeing everything.
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_dismissed_records.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes this table.
-- ============================================================

USE dorm_tenant_system;

CREATE TABLE IF NOT EXISTS dismissed_records (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page         VARCHAR(40) NOT NULL,
  tenant_id    INT UNSIGNED NOT NULL,
  dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dismissed (page, tenant_id),
  CONSTRAINT fk_dismissed_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB;
