-- Run once on an existing database to support hashed OTPs and attempt limits.
-- This migration is safe after migration_add_reset_otp.sql has been run.
USE dorm_tenant_system;
ALTER TABLE users
  MODIFY COLUMN reset_otp_code VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_otp_code;
