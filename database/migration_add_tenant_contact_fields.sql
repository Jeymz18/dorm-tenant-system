-- Add normalized tenant contact fields while retaining legacy emergency columns for compatibility.
ALTER TABLE tenants
  ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL AFTER tenant_type,
  ADD COLUMN emergency_contact_name VARCHAR(100) DEFAULT NULL AFTER contact_number,
  ADD COLUMN emergency_contact_phone VARCHAR(20) DEFAULT NULL AFTER emergency_contact_name;

-- Backfill the normalized fields from the existing profile data where available.
UPDATE tenants t
JOIN users u ON u.user_id = t.user_id
SET t.contact_number = u.phone,
    t.emergency_contact_name = t.emergency_contact,
    t.emergency_contact_phone = t.emergency_phone
WHERE t.contact_number IS NULL
   OR t.emergency_contact_name IS NULL
   OR t.emergency_contact_phone IS NULL;
