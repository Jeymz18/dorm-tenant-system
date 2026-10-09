-- Add a timestamp for tenant-visible maintenance request updates.
-- Safe to run more than once.
USE dorm_tenant_system;

ALTER TABLE maintenance_requests
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  AFTER date_submitted;