-- =====================================================================
-- Dorm Tenant Management System — Database Schema
-- =====================================================================
-- How to run:
--   phpMyAdmin  -> New -> Import -> choose this file
--   OR command line: mysql -u root -p < schema.sql
--
-- This file DROPS and recreates the database, so it's safe to re-run
-- any time you want to reset to a clean state with demo data.
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
INSERT INTO users (first_name, last_name, email, password_hash, role, is_active)
VALUES ('System', 'Admin', 'admin@dorm.edu',
        '$2b$12$iuxfSC7swH3oeF0qNsxIduPKTXpcvyxUHpD1c0kYANCow.zRZ75yu',
        'admin', 1);

-- Demo tenant account (already approved + assigned, so you have something
-- to look at immediately instead of starting from an empty dashboard)
--   email:    angel@student.dorm.edu
--   password: Tenant@123
INSERT INTO users (first_name, last_name, email, password_hash, age, phone, role, is_active)
VALUES ('Angel', 'Benitez', 'angel@student.dorm.edu',
        '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6',
        20, '+63 917 123 4567', 'tenant', 1);

INSERT INTO dorm_rooms (room_number, room_type, capacity, monthly_rate, floor_number, status, description) VALUES
  ('101', 'Studio', 1, 5500.00, 1, 'Available', 'With CR'),
  ('102', 'Studio', 1, 5500.00, 1, 'Occupied',  'With CR'),
  ('103', '1BR',    2, 6500.00, 1, 'Available', NULL),
  ('201', '2BR',    4, 8000.00, 2, 'Available', NULL),
  ('301', '2BR',    4, 8000.00, 3, 'Available', 'Corner unit');

INSERT INTO tenants (user_id, room_id, checkin_date, status, approval_status, emergency_contact, emergency_phone)
VALUES (2, 2, '2025-08-15', 'Active', 'Approved', 'Vilma Ayado', '+63 918 987 6543');

INSERT INTO contracts (tenant_id, room_id, monthly_rent, security_deposit, contract_start, contract_end, contract_status)
VALUES (1, 2, 5500.00, 5500.00, '2025-08-15', '2026-12-15', 'Active');

INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, payment_date, due_date, payment_status, payment_method)
VALUES
  (1, 1, 5500.00, 'December 2025', '2025-12-29', '2025-12-30', 'Paid', 'GCash'),
  (1, 1, 5500.00, 'January 2026',  '2026-01-30', '2026-01-30', 'Paid', 'GCash'),
  (1, 1, 5500.00, 'February 2026', '2026-02-28', '2026-02-28', 'Paid', 'Cash');

-- Plain ASCII only: the documented "mysql -u root -p < schema.sql" import
-- path uses the client's default character set, which on some Windows/
-- XAMPP setups is cp850 rather than utf8mb4 (unlike the live app's PDO
-- connection, which always requests utf8mb4) — non-ASCII literals here
-- would get corrupted on import in that case.
INSERT INTO activity_log (activity_type, description, related_tenant_id)
VALUES
  ('tenant_registered', 'Angel Benitez registered as a new tenant', 1),
  ('contract_created', 'Contract created for Angel Benitez (Room 102)', 1),
  ('payment_recorded', 'Payment of PHP 5,500.00 recorded for Angel Benitez (February 2026)', 1);

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
INSERT INTO users (first_name, last_name, email, password_hash, role, is_active)
SELECT 'System', 'Super Admin', 'superadmin@dorm.edu',
       '$2y$10$ZDDTT7ptNMjCbtTxfH9kmudDl83EGgBETbTqHDsfIeYPn7Jm3DDJ6',
       'super_admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'superadmin@dorm.edu');

USE dorm_tenant_system;

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','admin','tenant') NOT NULL DEFAULT 'tenant';

INSERT INTO users (first_name, last_name, email, password_hash, role, is_active)
SELECT 'System', 'Super Admin', 'superadmin@dorm.edu',
       '$2y$10$ZDDTT7ptNMjCbtTxfH9kmudDl83EGgBETbTqHDsfIeYPn7Jm3DDJ6',
       'super_admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'superadmin@dorm.edu');


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
INSERT INTO dorm_rooms (room_number, room_type, capacity, monthly_rate, floor_number, status, description) VALUES
  ('104', 'Studio', 1, 5500.00, 1, 'Occupied',          'Near the lobby'),
  ('105', 'Studio', 1, 5500.00, 1, 'Under Maintenance', 'Aircon being repaired'),
  ('202', '1BR',    2, 6500.00, 2, 'Occupied',          NULL),
  ('203', '1BR',    2, 6500.00, 2, 'Occupied',          'With balcony'),
  ('204', 'Studio', 1, 5500.00, 2, 'Available',         'With CR'),
  ('302', '2BR',    4, 8000.00, 3, 'Occupied',          NULL),
  ('303', 'Studio', 1, 5500.00, 3, 'Reserved',          'Reserved for incoming applicant'),
  ('304', '1BR',    2, 6500.00, 3, 'Available',         'Quiet corner');
UPDATE dorm_rooms SET status = 'Occupied' WHERE room_number IN ('101', '103', '201');

-- ---------- Demo user accounts (tenants) ----------
INSERT INTO users (first_name, last_name, email, password_hash, age, phone, role, is_active, last_login, date_created) VALUES
  ('Maria', 'Santos', 'maria.santos@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 19, '9171230001', 'tenant', 1, '2026-10-08 19:40:00', '2026-06-10 09:00:00'),
  ('Juan', 'Dela Cruz', 'juan.delacruz@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 21, '9171230002', 'tenant', 1, '2026-10-09 08:05:00', '2026-07-01 09:00:00'),
  ('Paolo', 'Reyes', 'paolo.reyes@gmail.com', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 26, '9171230003', 'tenant', 1, '2026-10-07 21:12:00', '2026-07-01 09:00:00'),
  ('Carla', 'Mendoza', 'carla.mendoza@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 20, '9171230004', 'tenant', 1, '2026-10-08 12:30:00', '2026-03-15 09:00:00'),
  ('Miguel', 'Garcia', 'miguel.garcia@gmail.com', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 28, '9171230005', 'tenant', 1, '2026-10-09 07:50:00', '2025-11-01 09:00:00'),
  ('Jasmine', 'Lim', 'jasmine.lim@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 22, '9171230006', 'tenant', 1, '2026-10-06 18:20:00', '2026-08-01 09:00:00'),
  ('Kevin', 'Tan', 'kevin.tan@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 20, '9171230007', 'tenant', 1, '2026-10-09 09:15:00', '2026-09-01 09:00:00'),
  ('Bea', 'Villanueva', 'bea.villanueva@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 19, '9171230008', 'tenant', 1, '2026-10-05 20:45:00', '2026-09-01 09:00:00'),
  ('Rico', 'Fernandez', 'rico.fernandez@gmail.com', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 30, '9171230009', 'tenant', 1, '2026-10-08 22:10:00', '2026-05-01 09:00:00'),
  ('Lara', 'Domingo', 'lara.domingo@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 23, '9171230010', 'tenant', 1, '2026-09-14 16:00:00', '2025-09-01 09:00:00'),
  ('Nina', 'Alvarez', 'nina.alvarez@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 19, '9171230011', 'tenant', 1, NULL, '2026-10-08 14:20:00'),
  ('Dennis', 'Cruz', 'dennis.cruz@gmail.com', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 27, '9171230012', 'tenant', 1, NULL, '2026-10-09 06:45:00'),
  ('Oscar', 'Ramos', 'oscar.ramos@student.dorm.edu', '$2b$12$v13v9dJQqKQn51t00TTSwOJr6Ej31mxfsbxF/mIoR3TjY3eORvEH6', 21, '9171230013', 'tenant', 1, NULL, '2026-10-02 11:05:00');

-- ---------- Tenant profiles ----------
INSERT INTO tenants (user_id, room_id, checkin_date, checkout_date, status, tenant_type, key_returned, approval_status, contact_number, emergency_contact_name, emergency_contact_phone, emergency_contact, emergency_phone, date_registered) VALUES
  ((SELECT user_id FROM users WHERE email = 'maria.santos@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '101'), '2026-06-10', NULL, 'Active', 'Student', 0, 'Approved', '9171230001', 'Rosa Santos', '9181110001', 'Rosa Santos', '9181110001', '2026-06-10 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'juan.delacruz@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '103'), '2026-07-01', NULL, 'Active', 'Student', 0, 'Approved', '9171230002', 'Pedro Dela Cruz', '9181110002', 'Pedro Dela Cruz', '9181110002', '2026-07-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'paolo.reyes@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '103'), '2026-07-01', NULL, 'Active', 'Employee', 0, 'Approved', '9171230003', 'Elena Reyes', '9181110003', 'Elena Reyes', '9181110003', '2026-07-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'carla.mendoza@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '104'), '2026-03-15', NULL, 'Active', 'Student', 0, 'Approved', '9171230004', 'Lito Mendoza', '9181110004', 'Lito Mendoza', '9181110004', '2026-03-15 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'miguel.garcia@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '202'), '2025-11-01', NULL, 'Active', 'Employee', 0, 'Approved', '9171230005', 'Teresa Garcia', '9181110005', 'Teresa Garcia', '9181110005', '2025-11-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'jasmine.lim@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '203'), '2026-08-01', NULL, 'Active', 'Student', 0, 'Approved', '9171230006', 'Henry Lim', '9181110006', 'Henry Lim', '9181110006', '2026-08-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'kevin.tan@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), '2026-09-01', NULL, 'Active', 'Student', 0, 'Approved', '9171230007', 'Grace Tan', '9181110007', 'Grace Tan', '9181110007', '2026-09-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'bea.villanueva@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), '2026-09-01', NULL, 'Active', 'Student', 0, 'Approved', '9171230008', 'Nora Villanueva', '9181110008', 'Nora Villanueva', '9181110008', '2026-09-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'rico.fernandez@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '302'), '2026-05-01', NULL, 'Active', 'Employee', 0, 'Approved', '9171230009', 'Alma Fernandez', '9181110009', 'Alma Fernandez', '9181110009', '2026-05-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'lara.domingo@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '301'), '2025-09-01', '2026-09-15', 'Checked Out', 'Student', 1, 'Approved', '9171230010', 'Cora Domingo', '9181110010', 'Cora Domingo', '9181110010', '2025-09-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'nina.alvarez@student.dorm.edu'), NULL, NULL, NULL, 'Pending', 'Student', 0, 'Pending', '9171230011', 'Ben Alvarez', '9181110011', 'Ben Alvarez', '9181110011', '2026-10-08 14:20:00'),
  ((SELECT user_id FROM users WHERE email = 'dennis.cruz@gmail.com'), NULL, NULL, NULL, 'Pending', 'Employee', 0, 'Pending', '9171230012', 'Mila Cruz', '9181110012', 'Mila Cruz', '9181110012', '2026-10-09 06:45:00'),
  ((SELECT user_id FROM users WHERE email = 'oscar.ramos@student.dorm.edu'), NULL, NULL, NULL, 'Pending', 'Student', 0, 'Rejected', '9171230013', 'Luz Ramos', '9181110013', 'Luz Ramos', '9181110013', '2026-10-02 11:05:00');
UPDATE tenants SET rejection_reason = 'Incomplete requirements: valid ID was not submitted.' WHERE user_id = (SELECT user_id FROM users WHERE email = 'oscar.ramos@student.dorm.edu');

-- ---------- Contracts ----------
INSERT INTO contracts (tenant_id, room_id, monthly_rent, security_deposit, contract_start, contract_end, contract_status, renewal_requested_at, renewal_count, created_at) VALUES
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '101'), 5500.00, 5500.00, '2026-06-10', '2027-06-10', 'Active', NULL, 0, '2026-06-10 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '103'), 6500.00, 6500.00, '2026-07-01', '2027-07-01', 'Active', NULL, 0, '2026-07-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '103'), 6500.00, 6500.00, '2026-07-01', '2027-07-01', 'Active', NULL, 0, '2026-07-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '104'), 5500.00, 5500.00, '2026-03-15', '2026-11-02', 'Expiring Soon', NULL, 0, '2026-03-15 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '202'), 6500.00, 6500.00, '2025-11-01', '2026-10-31', 'Expiring Soon', '2026-10-05 09:15:00', 0, '2025-11-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '203'), 6500.00, 6500.00, '2026-08-01', '2027-08-01', 'Active', NULL, 0, '2026-08-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), 8000.00, 8000.00, '2026-09-01', '2027-09-01', 'Active', NULL, 0, '2026-09-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'bea.villanueva@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), 8000.00, 8000.00, '2026-09-01', '2027-09-01', 'Active', NULL, 0, '2026-09-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '302'), 8000.00, 8000.00, '2026-05-01', '2027-05-01', 'Active', NULL, 0, '2026-05-01 09:00:00'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '301'), 8000.00, 8000.00, '2025-09-01', '2026-09-01', 'Expired', NULL, 0, '2025-09-01 09:00:00');

-- ---------- Payments (mix of Paid / Pending / Overdue) ----------
INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, payment_date, paid_at, due_date, payment_status, payment_method, reference_no) VALUES
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), 5500.00, 'June 2026', '2026-06-10', '2026-06-10 10:30:00', '2026-06-10', 'Paid', 'GCash', 'GC70001000'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), 5500.00, 'July 2026', '2026-07-10', '2026-07-10 10:30:00', '2026-07-10', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), 5500.00, 'August 2026', '2026-08-10', '2026-08-10 10:30:00', '2026-08-10', 'Paid', 'Bank Transfer', 'BT50001002'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), 5500.00, 'September 2026', '2026-09-10', '2026-09-10 10:30:00', '2026-09-10', 'Paid', 'GCash', 'GC70001003'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), 5500.00, 'October 2026', NULL, NULL, '2026-10-10', 'Pending', NULL, NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), 6500.00, 'July 2026', '2026-07-01', '2026-07-01 10:30:00', '2026-07-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), 6500.00, 'August 2026', '2026-08-01', '2026-08-01 10:30:00', '2026-08-01', 'Paid', 'Bank Transfer', 'BT50001006'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), 6500.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'GCash', 'GC70001007'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), 6500.00, 'October 2026', '2026-10-01', '2026-10-01 10:30:00', '2026-10-01', 'Paid', 'GCash', 'GC70001008'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com'), 6500.00, 'July 2026', '2026-07-01', '2026-07-01 10:30:00', '2026-07-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com'), 6500.00, 'August 2026', '2026-08-01', '2026-08-01 10:30:00', '2026-08-01', 'Paid', 'Bank Transfer', 'BT50001010'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com'), 6500.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'GCash', 'GC70001011'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'paolo.reyes@gmail.com'), 6500.00, 'October 2026', '2026-10-01', '2026-10-01 10:30:00', '2026-10-01', 'Paid', 'GCash', 'GC70001012'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'March 2026', '2026-03-15', '2026-03-15 10:30:00', '2026-03-15', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'April 2026', '2026-04-15', '2026-04-15 10:30:00', '2026-04-15', 'Paid', 'Bank Transfer', 'BT50001014'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'May 2026', '2026-05-15', '2026-05-15 10:30:00', '2026-05-15', 'Paid', 'GCash', 'GC70001015'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'June 2026', '2026-06-15', '2026-06-15 10:30:00', '2026-06-15', 'Paid', 'GCash', 'GC70001016'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'July 2026', '2026-07-15', '2026-07-15 10:30:00', '2026-07-15', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'August 2026', '2026-08-15', '2026-08-15 10:30:00', '2026-08-15', 'Paid', 'Bank Transfer', 'BT50001018'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'September 2026', '2026-09-15', '2026-09-15 10:30:00', '2026-09-15', 'Paid', 'GCash', 'GC70001019'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), 5500.00, 'October 2026', NULL, NULL, '2026-10-15', 'Pending', NULL, NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'November 2025', '2025-11-01', '2025-11-01 10:30:00', '2025-11-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'December 2025', '2025-12-01', '2025-12-01 10:30:00', '2025-12-01', 'Paid', 'Bank Transfer', 'BT50001022'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'January 2026', '2026-01-01', '2026-01-01 10:30:00', '2026-01-01', 'Paid', 'GCash', 'GC70001023'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'February 2026', '2026-02-01', '2026-02-01 10:30:00', '2026-02-01', 'Paid', 'GCash', 'GC70001024'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'March 2026', '2026-03-01', '2026-03-01 10:30:00', '2026-03-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'April 2026', '2026-04-01', '2026-04-01 10:30:00', '2026-04-01', 'Paid', 'Bank Transfer', 'BT50001026'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'May 2026', '2026-05-01', '2026-05-01 10:30:00', '2026-05-01', 'Paid', 'GCash', 'GC70001027'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'June 2026', '2026-06-01', '2026-06-01 10:30:00', '2026-06-01', 'Paid', 'GCash', 'GC70001028'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'July 2026', '2026-07-01', '2026-07-01 10:30:00', '2026-07-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'August 2026', '2026-08-01', '2026-08-01 10:30:00', '2026-08-01', 'Paid', 'Bank Transfer', 'BT50001030'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'GCash', 'GC70001031'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), 6500.00, 'October 2026', '2026-10-01', '2026-10-01 10:30:00', '2026-10-01', 'Paid', 'GCash', 'GC70001032'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu'), 6500.00, 'August 2026', '2026-08-01', '2026-08-01 10:30:00', '2026-08-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu'), 6500.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'Bank Transfer', 'BT50001034'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu'), 6500.00, 'October 2026', NULL, NULL, '2026-10-01', 'Overdue', NULL, NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu'), 8000.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'GCash', 'GC70001036'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu'), 8000.00, 'October 2026', '2026-10-01', '2026-10-01 10:30:00', '2026-10-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'bea.villanueva@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'bea.villanueva@student.dorm.edu'), 8000.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'Bank Transfer', 'BT50001038'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'bea.villanueva@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'bea.villanueva@student.dorm.edu'), 8000.00, 'October 2026', NULL, NULL, '2026-10-01', 'Overdue', NULL, NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), 8000.00, 'May 2026', '2026-05-01', '2026-05-01 10:30:00', '2026-05-01', 'Paid', 'GCash', 'GC70001040'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), 8000.00, 'June 2026', '2026-06-01', '2026-06-01 10:30:00', '2026-06-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), 8000.00, 'July 2026', '2026-07-01', '2026-07-01 10:30:00', '2026-07-01', 'Paid', 'Bank Transfer', 'BT50001042'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), 8000.00, 'August 2026', '2026-08-01', '2026-08-01 10:30:00', '2026-08-01', 'Paid', 'GCash', 'GC70001043'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), 8000.00, 'September 2026', '2026-09-01', '2026-09-01 10:30:00', '2026-09-01', 'Paid', 'GCash', 'GC70001044'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), 8000.00, 'October 2026', '2026-10-01', '2026-10-01 10:30:00', '2026-10-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'September 2025', '2025-09-01', '2025-09-01 10:30:00', '2025-09-01', 'Paid', 'Bank Transfer', 'BT50001046'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'October 2025', '2025-10-01', '2025-10-01 10:30:00', '2025-10-01', 'Paid', 'GCash', 'GC70001047'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'November 2025', '2025-11-01', '2025-11-01 10:30:00', '2025-11-01', 'Paid', 'GCash', 'GC70001048'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'December 2025', '2025-12-01', '2025-12-01 10:30:00', '2025-12-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'January 2026', '2026-01-01', '2026-01-01 10:30:00', '2026-01-01', 'Paid', 'Bank Transfer', 'BT50001050'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'February 2026', '2026-02-01', '2026-02-01 10:30:00', '2026-02-01', 'Paid', 'GCash', 'GC70001051'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'March 2026', '2026-03-01', '2026-03-01 10:30:00', '2026-03-01', 'Paid', 'GCash', 'GC70001052'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'April 2026', '2026-04-01', '2026-04-01 10:30:00', '2026-04-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'May 2026', '2026-05-01', '2026-05-01 10:30:00', '2026-05-01', 'Paid', 'Bank Transfer', 'BT50001054'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'June 2026', '2026-06-01', '2026-06-01 10:30:00', '2026-06-01', 'Paid', 'GCash', 'GC70001055'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'July 2026', '2026-07-01', '2026-07-01 10:30:00', '2026-07-01', 'Paid', 'GCash', 'GC70001056'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), 8000.00, 'August 2026', '2026-08-01', '2026-08-01 10:30:00', '2026-08-01', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'March 2026', '2026-03-30', '2026-03-30 10:30:00', '2026-03-30', 'Paid', 'GCash', 'GC70001058'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'April 2026', '2026-04-30', '2026-04-30 10:30:00', '2026-04-30', 'Paid', 'GCash', 'GC70001059'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'May 2026', '2026-05-30', '2026-05-30 10:30:00', '2026-05-30', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'June 2026', '2026-06-30', '2026-06-30 10:30:00', '2026-06-30', 'Paid', 'Bank Transfer', 'BT50001061'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'July 2026', '2026-07-30', '2026-07-30 10:30:00', '2026-07-30', 'Paid', 'GCash', 'GC70001062'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'August 2026', '2026-08-30', '2026-08-30 10:30:00', '2026-08-30', 'Paid', 'GCash', 'GC70001063'),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'September 2026', '2026-09-30', '2026-09-30 10:30:00', '2026-09-30', 'Paid', 'Cash', NULL),
  ((SELECT c.contract_id FROM contracts c JOIN tenants t ON t.tenant_id = c.tenant_id JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu' ORDER BY c.contract_id LIMIT 1), (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), 5500.00, 'October 2026', NULL, NULL, '2026-10-30', 'Pending', NULL, NULL);

-- ---------- Maintenance requests ----------
INSERT INTO maintenance_requests (tenant_id, room_id, issue_title, issue_description, priority_level, status, is_resolved, assigned_to, date_submitted, date_resolved) VALUES
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '101'), 'Leaking faucet in CR', 'The faucet keeps dripping even when fully closed.', 'Medium', 'Ongoing', 0, 'Plumber Team', '2026-10-02 10:15:00', NULL),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '103'), 'Broken ceiling light', 'The ceiling light flickers and then turns off.', 'Low', 'Completed', 1, 'Electrician', '2026-09-12 16:40:00', '2026-09-14'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '104'), 'Aircon not cooling', 'The aircon runs but the room stays warm.', 'High', 'Pending', 0, NULL, '2026-10-07 20:05:00', NULL),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '202'), 'Door lock jammed', 'The key gets stuck when locking the door.', 'Urgent', 'Ongoing', 0, 'Maintenance Staff', '2026-10-08 07:30:00', NULL),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), 'Wi-Fi very slow in room', 'Connection drops several times a day.', 'Medium', 'Pending', 0, NULL, '2026-10-06 21:10:00', NULL),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'jasmine.lim@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '203'), 'Clogged shower drain', 'Water pools in the shower after a few minutes.', 'Medium', 'Completed', 1, 'Plumber Team', '2026-09-20 08:25:00', '2026-09-22'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'angel@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '102'), 'Window latch broken', 'The window will not stay closed.', 'Low', 'Completed', 1, 'Carpenter', '2026-08-30 13:00:00', '2026-09-02'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), (SELECT room_id FROM dorm_rooms WHERE room_number = '302'), 'Electric outlet sparking', 'The outlet beside the bed sparks when plugging in.', 'Urgent', 'Completed', 1, 'Electrician', '2026-09-25 18:45:00', '2026-09-25'),
  ((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'bea.villanueva@student.dorm.edu'), (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), 'Mattress is damaged', 'A spring is poking through the mattress.', 'Low', 'Pending', 0, NULL, '2026-10-08 19:00:00', NULL);

-- ---------- Notifications ----------
INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value, date_sent) VALUES
  ((SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), 'Announcement', 'Water Interruption Notice', 'There will be no water supply on Saturday from 8:00 AM to 12:00 PM due to tank cleaning. Please store water in advance.', 'all', NULL, '2026-10-01 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), 'Announcement', 'Dorm Curfew Reminder', 'Please remember that the main gate closes at 10:00 PM. Late arrivals must inform the guard in advance.', 'all', NULL, '2026-09-15 17:00:00'),
  ((SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), 'Payment Reminder', 'October Rent Is Due', 'Your October rent is due this month. You may pay in cash at the office or through GCash on the Payments page.', 'all', NULL, '2026-10-05 08:00:00'),
  ((SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), 'Announcement', 'Room 103 Inspection', 'Routine inspection of Room 103 this Friday at 2:00 PM. Please make sure someone is present.', 'room', '103', '2026-10-06 10:30:00'),
  ((SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), 'Contract Expiry Alert', 'Your contract expires soon', 'Your contract ends on October 31, 2026. Submit a renewal request on your Services page if you wish to stay.', 'tenant', CAST((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com') AS CHAR), '2026-10-03 09:00:00'),
  ((SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), 'Contract Expiry Alert', 'Your contract expires soon', 'Your contract ends on November 2, 2026. Please contact the office if you would like to renew.', 'tenant', CAST((SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu') AS CHAR), '2026-10-04 09:00:00');

-- ---------- Room reservations ----------
INSERT INTO room_reservations (applicant_name, email, phone, room_id, reservation_date, status, created_at) VALUES
  ('Hannah Bautista', 'hannah.bautista@gmail.com', '9181230011', (SELECT room_id FROM dorm_rooms WHERE room_number = '303'), '2026-10-20', 'Confirmed', '2026-10-04 15:10:00'),
  ('Ethan Navarro',   'ethan.navarro@gmail.com',   '9181230012', (SELECT room_id FROM dorm_rooms WHERE room_number = '304'), '2026-11-01', 'Pending',   '2026-10-08 11:25:00'),
  ('Sofia Castillo',  'sofia.castillo@gmail.com',  '9181230013', (SELECT room_id FROM dorm_rooms WHERE room_number = '204'), '2026-10-15', 'Cancelled', '2026-09-28 09:40:00'),
  ('Kevin Tan',       'kevin.tan@student.dorm.edu','9171230007', (SELECT room_id FROM dorm_rooms WHERE room_number = '201'), '2026-08-25', 'Converted', '2026-08-10 13:00:00');

-- ---------- Recent activity feed ----------
INSERT INTO activity_log (activity_type, description, related_tenant_id, created_at) VALUES
  ('tenant_registered', 'Maria Santos registered as a new tenant', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), '2026-06-08 14:00:00'),
  ('contract_created', 'Contract created for Maria Santos (Room 101)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'maria.santos@student.dorm.edu'), '2026-06-10 09:00:00'),
  ('tenant_registered', 'Kevin Tan registered as a new tenant', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu'), '2026-08-27 10:30:00'),
  ('contract_created', 'Contract created for Kevin Tan (Room 201)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'kevin.tan@student.dorm.edu'), '2026-09-01 09:00:00'),
  ('checkout', 'Lara Domingo checked out of Room 301', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'lara.domingo@student.dorm.edu'), '2026-09-15 11:00:00'),
  ('maintenance_completed', 'Maintenance completed for Rico Fernandez (Electric outlet sparking)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), '2026-09-25 20:00:00'),
  ('payment_recorded', 'Payment of PHP 6,500.00 recorded for Juan Dela Cruz (October 2026)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'juan.delacruz@student.dorm.edu'), '2026-10-01 10:30:00'),
  ('payment_recorded', 'Payment of PHP 8,000.00 recorded for Rico Fernandez (October 2026)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'rico.fernandez@gmail.com'), '2026-10-01 10:45:00'),
  ('maintenance_submitted', 'Carla Mendoza submitted a maintenance request (Aircon not cooling)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'carla.mendoza@student.dorm.edu'), '2026-10-07 20:05:00'),
  ('renewal_requested', 'Miguel Garcia requested a contract renewal', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'miguel.garcia@gmail.com'), '2026-10-05 09:15:00'),
  ('tenant_registered', 'Nina Alvarez registered as a new tenant (pending approval)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'nina.alvarez@student.dorm.edu'), '2026-10-08 14:20:00'),
  ('tenant_registered', 'Dennis Cruz registered as a new tenant (pending approval)', (SELECT t.tenant_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE u.email = 'dennis.cruz@gmail.com'), '2026-10-09 06:45:00');

-- ---------- Sample issue reports (admin Issue Reports page) ----------
INSERT INTO system_bugs (user_id, role, description, page_url, status, created_at) VALUES
  (NULL, 'guest', 'After entering a wrong code the verification page shows a blank screen.', '/auth/reset_password.php', 'Pending', '2026-10-08 09:30:00'),
  ((SELECT user_id FROM users WHERE email = 'maria.santos@student.dorm.edu'), 'tenant', 'The payment receipt button does not open on my phone.', '/tenant/payments.php', 'In Progress', '2026-10-06 18:10:00'),
  ((SELECT user_id FROM users WHERE email = 'kevin.tan@student.dorm.edu'), 'tenant', 'The notification count did not update after I read a message.', '/tenant/dashboard.php', 'Resolved', '2026-09-29 20:45:00');

-- ---------- Generated reports log ----------
INSERT INTO reports (report_type, generated_by, date_generated, period_start, period_end, total_records) VALUES
  ('Occupancy',   (SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), '2026-10-01 09:00:00', '2026-09-01', '2026-09-30', 14),
  ('Payment',     (SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), '2026-10-01 09:10:00', '2026-09-01', '2026-09-30', 12),
  ('Tenant',      (SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), '2026-10-02 14:00:00', NULL,         NULL,         13),
  ('Maintenance', (SELECT user_id FROM users WHERE email = 'admin@dorm.edu'), '2026-10-05 16:30:00', '2026-09-01', '2026-10-05', 9);
