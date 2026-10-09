-- Adds PayMongo GCash tracking to the payments table.
-- Run once against your existing database:
--   mysql -u root -p dorm_tenant_system < database/migration_add_paymongo_columns.sql
--
-- Safe to re-run: uses IF NOT EXISTS, so it's a no-op if these columns
-- already came from the current database/schema.sql (a fresh import
-- already includes them).

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS paymongo_checkout_id VARCHAR(100) DEFAULT NULL AFTER receipt_file,
  ADD COLUMN IF NOT EXISTS paymongo_payment_id  VARCHAR(100) DEFAULT NULL AFTER paymongo_checkout_id,
  ADD COLUMN IF NOT EXISTS webhook_received_at  TIMESTAMP NULL DEFAULT NULL AFTER paymongo_payment_id;

-- A payment can now fail at GCash's end (declined, cancelled, timed out)
-- without a human ever touching it, so the status needs to represent that.
ALTER TABLE payments
  MODIFY payment_status ENUM('Pending','Paid','Overdue','Failed') NOT NULL DEFAULT 'Pending';

-- Lets the webhook look a payment up by checkout session id quickly.
-- NULLs don't collide under a UNIQUE index in MySQL, so this is safe
-- for the Cash/Bank Transfer/etc. rows that never get a checkout id.
CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_paymongo_checkout ON payments(paymongo_checkout_id);
