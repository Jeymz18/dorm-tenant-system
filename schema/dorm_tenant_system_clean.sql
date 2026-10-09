-- =====================================================================
-- Dorm Tenant Management System — Clean Database Schema
-- =====================================================================
-- How to run:
--   phpMyAdmin  -> New -> Import -> choose this file
--   OR command line: mysql -u root -p < schema.sql
--
-- This file DROPS and recreates the database, so it's safe to re-run
-- any time you want to reset to an empty database with no sample records.
-- =====================================================================

DROP DATABASE IF EXISTS dorm_tenant_system;
CREATE DATABASE dorm_tenant_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dorm_tenant_system;

-- ---------------------------------------------------------------------
-- 1. USERS — login + profile for super admins, admins, and tenants.
--    (One login system, one table, separated by `role`.)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name    VARCHAR(50)  NOT NULL,
  last_name     VARCHAR(50)  NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  reset_otp_code VARCHAR(255) DEFAULT NULL,
  reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reset_otp_expires_at DATETIME DEFAULT NULL,
  reset_otp_created_at DATETIME DEFAULT NULL,
  password_changed_at DATETIME DEFAULT NULL,
  age           TINYINT UNSIGNED DEFAULT NULL,
  phone         VARCHAR(20)  DEFAULT NULL,
  role          ENUM('super_admin','admin','tenant') NOT NULL DEFAULT 'tenant',
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  last_login    DATETIME DEFAULT NULL,
  date_created  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. DORM_ROOMS — physical room inventory.
-- ---------------------------------------------------------------------
CREATE TABLE dorm_rooms (
  room_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_number  VARCHAR(10)  NOT NULL,
  room_type    VARCHAR(50)  NOT NULL,        -- Studio / Single / 2BR ...
  capacity     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  monthly_rate DECIMAL(10,2) NOT NULL,
  floor_number TINYINT NOT NULL DEFAULT 1,
  status       ENUM('Available','Occupied','Under Maintenance','Reserved')
               NOT NULL DEFAULT 'Available',
  description  TEXT,
  date_added   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_room_number (room_number),
  INDEX idx_room_status (status),
  INDEX idx_room_type (room_type)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. TENANTS — a tenant "profile" attached to a user account.
--    Kept separate from `users` because not every user is a tenant,
--    and tenancy has its own lifecycle (approval, check-in/out).
-- ---------------------------------------------------------------------
CREATE TABLE tenants (
  tenant_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  room_id           INT UNSIGNED DEFAULT NULL,
  checkin_date      DATE DEFAULT NULL,
  checkout_date     DATE DEFAULT NULL,
  status            ENUM('Pending','Active','Evicted','Checked Out')
                    NOT NULL DEFAULT 'Pending',
  tenant_type       ENUM('Student','Employee') NOT NULL DEFAULT 'Student',
  key_returned      BOOLEAN NOT NULL DEFAULT FALSE,
  approval_status   ENUM('Pending','Approved','Rejected')
                    NOT NULL DEFAULT 'Pending',
  contact_number    VARCHAR(20) DEFAULT NULL,
  emergency_contact_name VARCHAR(100) DEFAULT NULL,
  emergency_contact_phone VARCHAR(20) DEFAULT NULL,
  rejection_reason  TEXT DEFAULT NULL,
  emergency_contact VARCHAR(100),
  emergency_phone   VARCHAR(20),
  date_registered   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_tenant_room FOREIGN KEY (room_id) REFERENCES dorm_rooms(room_id) ON DELETE SET NULL,
  UNIQUE KEY uq_tenant_user (user_id),
  INDEX idx_tenant_status (status),
  INDEX idx_tenant_approval (approval_status),
  INDEX idx_tenant_room (room_id)
) ENGINE=InnoDB;

-- (page, tenant) pairs an admin cleared from the Registration/Approval,
-- Track Status, or Check-in/Check-out views. Hides the row from that
-- one page only — the tenant/payment/contract data underneath is
-- untouched, so reports still see everything.
CREATE TABLE dismissed_records (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page         VARCHAR(40) NOT NULL,
  tenant_id    INT UNSIGNED NOT NULL,
  dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dismissed (page, tenant_id),
  CONSTRAINT fk_dismissed_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ROOM_RESERVATIONS — applicant requests to hold a room before tenant registration.
-- ---------------------------------------------------------------------
CREATE TABLE room_reservations (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_name   VARCHAR(120) NOT NULL,
  email            VARCHAR(255) NOT NULL,
  phone            VARCHAR(20) NOT NULL,
  room_id          INT UNSIGNED NOT NULL,
  reservation_date DATE NOT NULL,
  status           ENUM('Pending','Confirmed','Cancelled','Converted') NOT NULL DEFAULT 'Pending',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_room FOREIGN KEY (room_id) REFERENCES dorm_rooms(room_id),
  INDEX idx_reservation_status (status),
  INDEX idx_reservation_room_status (room_id, status),
  INDEX idx_reservation_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. CONTRACTS — one row per lease term.
--    Split out from "payments" (see note below) because one contract
--    covers MANY monthly payments — the prototype's own "Payment
--    History" screen shows several months per tenant under one lease.
-- ---------------------------------------------------------------------
CREATE TABLE contracts (
  contract_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id        INT UNSIGNED NOT NULL,
  room_id          INT UNSIGNED NOT NULL,
  monthly_rent     DECIMAL(10,2) NOT NULL,
  security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
  contract_start   DATE NOT NULL,
  contract_end     DATE NOT NULL,
  contract_status  ENUM('Active','Expiring Soon','Expired','Terminated')
                   NOT NULL DEFAULT 'Active',
  contract_file    VARCHAR(255) DEFAULT NULL, -- uploaded PDF path
  -- Renewal / termination trail. A renewal REUSES this row (new
  -- contract_end, same contract_id) so the lease keeps one continuous
  -- payment history instead of splitting across two contracts.
  renewal_requested_at DATETIME DEFAULT NULL,  -- tenant asked to renew, admin hasn't acted yet
  last_renewed_at      DATETIME DEFAULT NULL,
  renewal_count        INT UNSIGNED NOT NULL DEFAULT 0,
  terminated_at        DATETIME DEFAULT NULL,
  termination_reason   TEXT DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contract_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_room   FOREIGN KEY (room_id)   REFERENCES dorm_rooms(room_id),
  INDEX idx_contract_status (contract_status),
  INDEX idx_contract_end (contract_end)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. PAYMENTS — one row per monthly payment, linked to its contract.
-- ---------------------------------------------------------------------
CREATE TABLE payments (
  payment_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id       INT UNSIGNED NOT NULL,
  tenant_id         INT UNSIGNED NOT NULL,
  payment_amount    DECIMAL(10,2) NOT NULL,
  payment_for_month VARCHAR(20)  DEFAULT NULL,  -- e.g. "June 2026"
  payment_date      DATE DEFAULT NULL,
  paid_at           DATETIME DEFAULT NULL,
  due_date          DATE DEFAULT NULL,
  payment_status    ENUM('Pending','Paid','Overdue','Failed') NOT NULL DEFAULT 'Pending',
  payment_method    VARCHAR(50) DEFAULT NULL,     -- Cash / GCash / Bank Transfer ...
  reference_no      VARCHAR(100) DEFAULT NULL,
  receipt_file      VARCHAR(255) DEFAULT NULL,
  paymongo_checkout_id VARCHAR(100) DEFAULT NULL, -- set when payment_method = GCash (PayMongo Checkout Session id)
  paymongo_payment_id  VARCHAR(100) DEFAULT NULL, -- the actual PayMongo Payment id, filled in by the webhook
  webhook_received_at  TIMESTAMP NULL DEFAULT NULL,
  reminder_sent     BOOLEAN NOT NULL DEFAULT FALSE,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_contract FOREIGN KEY (contract_id) REFERENCES contracts(contract_id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  INDEX idx_payment_status (payment_status),
  INDEX idx_payment_due (due_date),
  UNIQUE INDEX idx_payment_paymongo_checkout (paymongo_checkout_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. MAINTENANCE_REQUESTS
-- ---------------------------------------------------------------------
CREATE TABLE maintenance_requests (
  maintenance_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         INT UNSIGNED NOT NULL,
  room_id           INT UNSIGNED NOT NULL,
  issue_title       VARCHAR(100) NOT NULL,
  issue_description TEXT,
  priority_level    ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
  status            ENUM('Pending','Ongoing','Completed') NOT NULL DEFAULT 'Pending',
  is_resolved       BOOLEAN NOT NULL DEFAULT FALSE,
  assigned_to       VARCHAR(100) DEFAULT NULL,   -- e.g. "Plumber Team"
  photo_file        VARCHAR(255) DEFAULT NULL,
  date_submitted    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_resolved     DATE DEFAULT NULL,
  updated_at        TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_maint_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  CONSTRAINT fk_maint_room   FOREIGN KEY (room_id)   REFERENCES dorm_rooms(room_id),
  INDEX idx_maint_status (status),
  INDEX idx_maint_priority (priority_level)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. NOTIFICATIONS — announcements / reminders / expiry alerts.
--    Not in the original data dictionary (only implied by the DFD's
--    "Notification Logs" store) — added so Notification Management
--    is actually functional.
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id        INT UNSIGNED NOT NULL,
  type             ENUM('Announcement','Payment Reminder','Contract Expiry Alert') NOT NULL,
  subject          VARCHAR(150) NOT NULL,
  message          TEXT NOT NULL,
  target_type      ENUM('all','room','tenant') NOT NULL DEFAULT 'all',
  target_value     VARCHAR(100) DEFAULT NULL,   -- room_number OR tenant_id, depending on target_type
  source_key       VARCHAR(120) DEFAULT NULL,
  date_sent        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_sender FOREIGN KEY (sender_id) REFERENCES users(user_id),
  INDEX idx_notif_type (type),
  INDEX idx_notif_target (target_type, target_value),
  UNIQUE KEY uq_notifications_source_key (source_key)
) ENGINE=InnoDB;

CREATE TABLE notification_reads (
  notification_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  read_at         TIMESTAMP NULL DEFAULT NULL,
  seen_at         TIMESTAMP NULL DEFAULT NULL,
  is_resolved     BOOLEAN NOT NULL DEFAULT FALSE,
  is_dismissed    BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (notification_id, user_id),
  CONSTRAINT fk_notification_read_notification FOREIGN KEY (notification_id) REFERENCES notifications(notification_id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_read_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_notification_reads_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE maintenance_reads (
  maintenance_id  INT UNSIGNED NOT NULL,
  tenant_id       INT UNSIGNED NOT NULL,
  seen_updated_at TIMESTAMP NOT NULL,
  PRIMARY KEY (maintenance_id, tenant_id),
  CONSTRAINT fk_maintenance_read_request FOREIGN KEY (maintenance_id) REFERENCES maintenance_requests(maintenance_id) ON DELETE CASCADE,
  CONSTRAINT fk_maintenance_read_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  INDEX idx_maintenance_reads_tenant (tenant_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  audit_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  action      VARCHAR(100) NOT NULL,
  details     TEXT NOT NULL,
  ip_address  VARCHAR(45) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE system_bugs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  role        ENUM('super_admin','admin','tenant','guest') NOT NULL DEFAULT 'guest',
  description TEXT NOT NULL,
  page_url    VARCHAR(500) NOT NULL DEFAULT '',
  image_path  VARCHAR(255) DEFAULT NULL,
  ip_address  VARCHAR(45) DEFAULT NULL,
  status      ENUM('Pending','In Progress','Resolved') NOT NULL DEFAULT 'Pending',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_system_bugs_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_system_bugs_status (status),
  INDEX idx_system_bugs_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. REPORTS — a log of generated reports (for Reports & Analytics).
-- ---------------------------------------------------------------------
CREATE TABLE reports (
  report_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_type    ENUM('Occupancy','Payment','Tenant','Maintenance') NOT NULL,
  generated_by   INT UNSIGNED NOT NULL,
  date_generated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  period_start   DATE DEFAULT NULL,
  period_end     DATE DEFAULT NULL,
  total_records  INT DEFAULT 0,
  file_path      VARCHAR(255) DEFAULT NULL,
  CONSTRAINT fk_report_user FOREIGN KEY (generated_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. ACTIVITY_LOG — a running feed of notable events, powering the
--    "Recent Activity" widget on the Admin Dashboard.
-- ---------------------------------------------------------------------
CREATE TABLE activity_log (
  activity_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_type    VARCHAR(40) NOT NULL,   -- e.g. 'tenant_registered', 'payment_recorded'
  description      VARCHAR(255) NOT NULL,
  related_tenant_id INT UNSIGNED DEFAULT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_tenant FOREIGN KEY (related_tenant_id) REFERENCES tenants(tenant_id) ON DELETE SET NULL,
  INDEX idx_activity_created (created_at)
) ENGINE=InnoDB;

-- =====================================================================
-- SEED / DEMO DATA
-- =====================================================================

-- Default admin account
--   email:    admin@dorm.edu
--   password: Admin@123   (change this after your first login!)

-- Demo tenant account (already approved + assigned, so you have something
-- to look at immediately instead of starting from an empty dashboard)
--   email:    angel@student.dorm.edu
--   password: Tenant@123





-- Plain ASCII only: the documented "mysql -u root -p < schema.sql" import
-- path uses the client's default character set, which on some Windows/
-- XAMPP setups is cp850 rather than utf8mb4 (unlike the live app's PDO
-- connection, which always requests utf8mb4) — non-ASCII literals here
-- would get corrupted on import in that case.

-- migration_add_audit_and_sticky_notifications.sql
USE dorm_tenant_system;

ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS source_key VARCHAR(120) DEFAULT NULL AFTER target_value,
  ADD UNIQUE KEY IF NOT EXISTS uq_notifications_source_key (source_key);

CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  read_at         TIMESTAMP NULL DEFAULT NULL,
  seen_at         TIMESTAMP NULL DEFAULT NULL,
  is_resolved     BOOLEAN NOT NULL DEFAULT FALSE,
  is_dismissed    BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (notification_id, user_id),
  CONSTRAINT fk_notification_read_notification FOREIGN KEY (notification_id) REFERENCES notifications(notification_id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_read_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_notification_reads_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  action      VARCHAR(100) NOT NULL,
  details     TEXT NOT NULL,
  ip_address  VARCHAR(45) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB;

ALTER TABLE notification_reads
  ADD COLUMN IF NOT EXISTS seen_at TIMESTAMP NULL DEFAULT NULL AFTER read_at,
  ADD COLUMN IF NOT EXISTS is_resolved BOOLEAN NOT NULL DEFAULT FALSE AFTER seen_at,
  ADD COLUMN IF NOT EXISTS is_dismissed BOOLEAN NOT NULL DEFAULT FALSE AFTER is_resolved;

ALTER TABLE notification_reads
  MODIFY COLUMN read_at TIMESTAMP NULL DEFAULT NULL;

UPDATE notification_reads
SET seen_at = read_at, is_resolved = TRUE
WHERE seen_at IS NULL AND is_resolved = FALSE;

UPDATE notification_reads nr
JOIN notifications n ON n.notification_id = nr.notification_id
SET nr.is_dismissed = TRUE
WHERE n.type = 'Announcement' AND nr.is_resolved = TRUE AND nr.is_dismissed = FALSE;

ALTER TABLE maintenance_requests
  ADD COLUMN IF NOT EXISTS is_resolved BOOLEAN NOT NULL DEFAULT FALSE AFTER status;

UPDATE maintenance_requests SET is_resolved = (status = 'Completed');

CREATE TABLE IF NOT EXISTS maintenance_reads (
  maintenance_id  INT UNSIGNED NOT NULL,
  tenant_id       INT UNSIGNED NOT NULL,
  seen_updated_at TIMESTAMP NOT NULL,
  PRIMARY KEY (maintenance_id, tenant_id),
  CONSTRAINT fk_maintenance_read_request FOREIGN KEY (maintenance_id) REFERENCES maintenance_requests(maintenance_id) ON DELETE CASCADE,
  CONSTRAINT fk_maintenance_read_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  INDEX idx_maintenance_reads_tenant (tenant_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO maintenance_reads (maintenance_id, tenant_id, seen_updated_at)
SELECT maintenance_id, tenant_id, updated_at FROM maintenance_requests WHERE updated_at IS NOT NULL;

-- Initial Super Admin account. Change this password after the first login.

USE dorm_tenant_system;

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','admin','tenant') NOT NULL DEFAULT 'tenant';

-- Add bug reports without changing existing application data.
USE dorm_tenant_system;

CREATE TABLE IF NOT EXISTS system_bugs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  role        ENUM('super_admin','admin','tenant','guest') NOT NULL DEFAULT 'guest',
  description TEXT NOT NULL,
  page_url    VARCHAR(500) NOT NULL DEFAULT '',
  status      ENUM('Pending','In Progress','Resolved') NOT NULL DEFAULT 'Pending',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_system_bugs_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_system_bugs_status (status),
  INDEX idx_system_bugs_created (created_at)
) ENGINE=InnoDB;

-- (The system_bugs table above already has these exact column and foreign-key
-- definitions, so the old DROP/ADD FOREIGN KEY ALTER was removed: it failed on a
-- fresh import and stopped everything after it.)


  -- Store the successful payment confirmation time for invoice receipts.
USE dorm_tenant_system;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS paid_at DATETIME DEFAULT NULL AFTER payment_date;

UPDATE payments
SET paid_at = TIMESTAMP(payment_date, '00:00:00')
WHERE payment_status = 'Paid' AND payment_date IS NOT NULL AND paid_at IS NULL;

-- Reserve a room for applicants before they register as tenants. This table is linked to dorm_rooms and allows tracking of reservation status.
USE dorm_tenant_system;

CREATE TABLE IF NOT EXISTS room_reservations (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  applicant_name   VARCHAR(120) NOT NULL,
  email            VARCHAR(255) NOT NULL,
  phone            VARCHAR(20) NOT NULL,
  room_id          INT UNSIGNED NOT NULL,
  reservation_date DATE NOT NULL,
  status           ENUM('Pending','Confirmed','Cancelled','Converted') NOT NULL DEFAULT 'Pending',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_room FOREIGN KEY (room_id) REFERENCES dorm_rooms(room_id),
  INDEX idx_reservation_status (status),
  INDEX idx_reservation_room_status (room_id, status),
  INDEX idx_reservation_email (email)
) ENGINE=InnoDB;

-- =====================================================================
-- EXTRA DEMO DATA FOR PRESENTATION
-- All demo tenants log in with password:  Tenant@123
-- (admin@dorm.edu / Admin@123 and superadmin@dorm.edu are unchanged.)
-- Everything below looks things up by email / room number, so it does
-- not depend on auto-increment ids. Safe to leave out if you want a
-- nearly empty system.
-- =====================================================================
USE dorm_tenant_system;

-- ---------- More rooms ----------

UPDATE dorm_rooms SET status = 'Occupied' WHERE room_number IN ('101', '103', '201');

-- ---------- Demo user accounts (tenants) ----------

-- ---------- Tenant profiles ----------

UPDATE tenants SET rejection_reason = 'Incomplete requirements: valid ID was not submitted.' WHERE user_id = (SELECT user_id FROM users WHERE email = 'oscar.ramos@student.dorm.edu');

-- ---------- Contracts ----------

-- ---------- Payments (mix of Paid / Pending / Overdue) ----------

-- ---------- Maintenance requests ----------

-- ---------- Notifications ----------

-- ---------- Room reservations ----------

-- ---------- Recent activity feed ----------

-- ---------- Sample issue reports (admin Issue Reports page) ----------

-- ---------- Generated reports log ----------

