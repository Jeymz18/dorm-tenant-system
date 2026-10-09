-- Adds contract renewal / termination tracking to the contracts table.
-- Run once against your existing database:
--   mysql -u root -p dorm_tenant_system < database/migration_add_contract_renewal.sql
--
-- Every column added here is nullable (or has a default), so existing
-- rows stay valid and nothing that already works changes behaviour.
--
-- Safe to re-run: uses IF NOT EXISTS, so it's a no-op if these columns
-- already came from the current database/schema.sql (a fresh import
-- already includes them).

ALTER TABLE contracts
  -- Set when the TENANT asks for a renewal from their portal; cleared
  -- again the moment an admin actually renews (or terminates) the lease.
  ADD COLUMN IF NOT EXISTS renewal_requested_at DATETIME DEFAULT NULL AFTER contract_file,
  -- When the lease was last extended, and how many times in total.
  -- The contract row itself is reused on renewal (same contract_id) so
  -- the tenant's payment history stays attached to one lease.
  ADD COLUMN IF NOT EXISTS last_renewed_at      DATETIME DEFAULT NULL AFTER renewal_requested_at,
  ADD COLUMN IF NOT EXISTS renewal_count        INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_renewed_at,
  -- Paper trail for an admin-ended lease.
  ADD COLUMN IF NOT EXISTS terminated_at        DATETIME DEFAULT NULL AFTER renewal_count,
  ADD COLUMN IF NOT EXISTS termination_reason   TEXT DEFAULT NULL AFTER terminated_at;

-- Contracts whose end date has already passed were previously left
-- sitting at 'Active' forever (nothing moved them along automatically).
-- Bring existing rows in line with what the app now maintains on its own.
UPDATE contracts
   SET contract_status = 'Expired'
 WHERE contract_status IN ('Active', 'Expiring Soon')
   AND contract_end < CURDATE();

UPDATE contracts
   SET contract_status = 'Expiring Soon'
 WHERE contract_status = 'Active'
   AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY);
