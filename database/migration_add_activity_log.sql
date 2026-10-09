-- ============================================================
-- Migration: add activity_log table
-- ============================================================
-- A running feed of notable events (tenant registered/approved,
-- payment recorded, contract renewed/terminated, maintenance
-- submitted/updated, ...) powering the "Recent Activity" widget
-- on the Admin Dashboard.
--
-- Safe to run more than once — IF NOT EXISTS means it does nothing
-- if the table already exists.
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_activity_log.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes the table.
-- ============================================================

USE dorm_tenant_system;

CREATE TABLE IF NOT EXISTS activity_log (
  activity_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_type    VARCHAR(40) NOT NULL,   -- e.g. 'tenant_registered', 'payment_recorded'
  description      VARCHAR(255) NOT NULL,
  related_tenant_id INT UNSIGNED DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_tenant FOREIGN KEY (related_tenant_id) REFERENCES tenants(tenant_id) ON DELETE SET NULL,
  INDEX idx_activity_created (created_at)
) ENGINE=InnoDB;
