-- ============================================================
-- Clear the seeded demo tenant (Angel Benitez)
-- ============================================================
-- Run this ONCE on your existing database to remove the sample
-- tenant that came with schema.sql, so you can register/approve/
-- assign a tenant yourself from a clean slate.
--
-- What it does:
--   1. Deletes Angel's login account. Thanks to the foreign keys
--      already set up in schema.sql (ON DELETE CASCADE), this
--      automatically removes their tenant record, contract, and
--      payment history too — nothing to clean up by hand.
--   2. Frees up Room 102, which was marked Occupied because of
--      that tenant, so it shows as Available again.
--
-- How to run it (phpMyAdmin):
--   1. Open phpMyAdmin → select the dorm_tenant_system database.
--   2. Click the "SQL" tab.
--   3. Paste everything below and click "Go".
--
-- How to run it (command line):
--   mysql -u root -p dorm_tenant_system < database/clear_demo_tenant.sql
--
-- Note: the login page's "Quick Login (Demo) → Tenant" button still
-- points at angel@student.dorm.edu. After this runs, that button
-- won't work anymore (correctly so — that account is gone) until you
-- create a new tenant account of your own. That's expected.
-- ============================================================

USE dorm_tenant_system;

DELETE FROM users WHERE email = 'angel@student.dorm.edu';

UPDATE dorm_rooms SET status = 'Available' WHERE room_number = '102';
