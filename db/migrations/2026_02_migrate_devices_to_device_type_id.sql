-- 2026-02-15
-- Safe migration: migrate devices.model -> devices.device_type_id
-- IMPORTANT: BACKUP YOUR DATABASE BEFORE RUNNING THIS SCRIPT.
-- Run this on a staging/test database first and verify results using the queries provided in the PR notes.

START TRANSACTION;

-- 0) Ensure device_types table exists
CREATE TABLE IF NOT EXISTS device_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_name VARCHAR(128) NOT NULL UNIQUE,
  description TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0b) Ensure variables table exists (used by config builder)
CREATE TABLE IF NOT EXISTS variables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  var_name VARCHAR(128) NOT NULL UNIQUE,
  var_value TEXT NOT NULL,
  description TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Add device_type_id to devices (nullable for safety)
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS device_type_id INT DEFAULT NULL,
  ADD INDEX idx_device_type_id (device_type_id);

-- 2) Ensure device_types exist for distinct model strings (insert missing)
INSERT INTO device_types (type_name, description)
SELECT DISTINCT d.model, NULL
FROM devices d
LEFT JOIN device_types dt ON dt.type_name = d.model
WHERE d.model IS NOT NULL AND d.model <> '' AND dt.id IS NULL;

-- 3) Populate device_type_id by joining on device_types.type_name
UPDATE devices d
JOIN device_types dt ON dt.type_name = d.model
SET d.device_type_id = dt.id
WHERE d.model IS NOT NULL AND d.model <> '';

-- 4) Ensure fallback 'Unknown' exists
INSERT IGNORE INTO device_types (type_name, description) VALUES ('Unknown', 'Fallback type for existing devices without a model');

-- 5) Assign 'Unknown' to any devices still without a device_type_id
UPDATE devices d
JOIN device_types dt ON dt.type_name = 'Unknown'
SET d.device_type_id = dt.id
WHERE d.device_type_id IS NULL;

COMMIT;

-- VALIDATION SUGGESTIONS (run manually after migration)
-- SELECT COUNT(*) FROM device_types;
-- SELECT COUNT(*) FROM devices WHERE device_type_id IS NULL;
-- SELECT d.id, d.device_name, d.mac_address, dt.type_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id LIMIT 50;

-- OPTIONAL FINAL STEPS (UNCOMMENT & RUN ONLY AFTER YOU'VE VERIFIED BACKUP & STAGING)
-- ALTER TABLE devices MODIFY device_type_id INT NOT NULL;
-- ALTER TABLE devices ADD CONSTRAINT fk_devices_device_type FOREIGN KEY (device_type_id) REFERENCES device_types(id) ON DELETE RESTRICT ON UPDATE CASCADE;
-- ALTER TABLE devices DROP COLUMN model;
