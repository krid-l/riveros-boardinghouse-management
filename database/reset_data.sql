-- database/reset_data.sql
-- Wipes tenant and money data so the system can be tested from a clean slate.
--
-- DELETED: tenants, their login accounts, payments, receipt records, charges,
--          room transfer history, complaints and announcements.
-- KEPT:    rooms (set back to vacant), the admin account(s), and settings
--          (house name, GCash details, SMS keys, schema version).
--
-- This is permanent. Take a backup first: Supabase dashboard > Database > Backups.
-- Run it in the Supabase SQL editor, or:  psql "<connection string>" -f database/reset_data.sql
--
-- Screenshots and receipt PDFs in Supabase Storage are NOT touched; delete those
-- from the Storage section if you want them gone too.

BEGIN;

DO $$
DECLARE
    t text;
BEGIN
    -- Tenant rows cascade into the rest, but clear each table explicitly so this
    -- still works if a foreign key is missing. Tables that don't exist are skipped.
    FOREACH t IN ARRAY ARRAY['payments', 'complaints', 'charges', 'room_transfers', 'announcements', 'tenants']
    LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format('DELETE FROM %I', t);
            -- Start IDs back at 1 so test data reads cleanly
            IF pg_get_serial_sequence(t, 'id') IS NOT NULL THEN
                PERFORM setval(pg_get_serial_sequence(t, 'id'), 1, false);
            END IF;
        END IF;
    END LOOP;

    -- Tenant logins go; admin accounts stay.
    DELETE FROM users WHERE role = 'tenant';

    -- Rooms stay, but nobody lives in them any more.
    UPDATE rooms SET status = 'vacant' WHERE status <> 'maintenance';
END $$;

COMMIT;

-- What is left afterwards
SELECT 'users (admins kept)' AS table_name, COUNT(*) AS row_count FROM users
UNION ALL SELECT 'rooms', COUNT(*) FROM rooms
UNION ALL SELECT 'settings', COUNT(*) FROM settings
UNION ALL SELECT 'tenants', COUNT(*) FROM tenants
UNION ALL SELECT 'payments', COUNT(*) FROM payments
UNION ALL SELECT 'charges', COUNT(*) FROM charges
UNION ALL SELECT 'room_transfers', COUNT(*) FROM room_transfers;
