-- Allow "Report an Issue" submissions to carry a screenshot.
-- (report_bug.php also adds this column automatically if it is missing.)
USE dorm_tenant_system;
ALTER TABLE system_bugs
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) DEFAULT NULL AFTER page_url;
ALTER TABLE system_bugs
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) DEFAULT NULL;
