-- ============================================================
-- Migration: add password recovery OTP fields to users
-- ============================================================
-- Adds the two columns the "Forgot Password" OTP flow needs to
-- temporarily hold a one-time code and its expiry while a user
-- proves they own the email on file.
--
-- Safe to run more than once — IF NOT EXISTS means it does nothing
-- (instead of erroring) if a column is already there.
--
-- Run this the same way as the other migration files:
--   phpMyAdmin → dorm_tenant_system → SQL tab → paste → Go
--   or: mysql -u root -p dorm_tenant_system < database/migration_add_reset_otp.sql
--
-- Setting up fresh right now? Skip this — schema.sql already
-- includes both columns.
-- ============================================================

USE dorm_tenant_system;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS reset_otp_code VARCHAR(255) DEFAULT NULL AFTER password_hash,
  ADD COLUMN IF NOT EXISTS reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_otp_code,
  ADD COLUMN IF NOT EXISTS reset_otp_expires_at DATETIME DEFAULT NULL AFTER reset_otp_attempts,
  ADD COLUMN IF NOT EXISTS reset_otp_created_at DATETIME DEFAULT NULL AFTER reset_otp_expires_at;

ALTER TABLE users
  MODIFY COLUMN email VARCHAR(255) NOT NULL,
  MODIFY COLUMN reset_otp_code VARCHAR(255) DEFAULT NULL,
  MODIFY COLUMN reset_otp_expires_at DATETIME DEFAULT NULL,
  MODIFY COLUMN reset_otp_created_at DATETIME DEFAULT NULL;
