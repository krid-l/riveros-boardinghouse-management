-- Clear out test data, keep the setup.
--
-- Wipes tenants, rooms, payments, charges, complaints and announcements, and leaves the
-- tables, the admin account and the Settings page values (GCash details, SMS keys, business
-- info) exactly as they are.
--
-- phpMyAdmin: select the boardinghouse database -> SQL tab -> paste this -> Go.
-- Command line: mysql -u root boardinghouse < database/reset_data.sql
--
-- To wipe absolutely everything instead, including the admin account and settings, drop the
-- database and import database/schema_mysql.sql again.

USE boardinghouse;

-- Foreign keys are switched off for the delete so the order of the tables doesn't matter,
-- then switched straight back on.
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM payments;
DELETE FROM charges;
DELETE FROM room_transfers;
DELETE FROM complaints;
DELETE FROM tenants;
DELETE FROM announcements;
DELETE FROM rooms;

-- Tenant logins only. The admin account stays.
DELETE FROM users WHERE role = 'tenant';

SET FOREIGN_KEY_CHECKS = 1;

-- Start ids from 1 again, so the next room really is Room #1.
ALTER TABLE payments       AUTO_INCREMENT = 1;
ALTER TABLE charges        AUTO_INCREMENT = 1;
ALTER TABLE room_transfers AUTO_INCREMENT = 1;
ALTER TABLE complaints     AUTO_INCREMENT = 1;
ALTER TABLE tenants        AUTO_INCREMENT = 1;
ALTER TABLE announcements  AUTO_INCREMENT = 1;
ALTER TABLE rooms          AUTO_INCREMENT = 1;

SELECT 'Test data cleared. The admin account and your settings are untouched.' AS result;
