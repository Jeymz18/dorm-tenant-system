USE dorm_tenant_system;

-- Migration: remove the unused 'Declined' approval status
-- 'Declined' duplicated 'Rejected' and was never set by any code path.
-- Run once on existing databases:
--   mysql -u root -p dorm_tenant_system < database/migration_remove_declined_status.sql

UPDATE tenants SET approval_status = 'Rejected' WHERE approval_status = 'Declined';

ALTER TABLE tenants
  MODIFY approval_status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending';
