-- Migration 16: Add is_master flag to partner_companies
-- Allows a partner to be designated as a "master" that can see all customers
-- across all partners (without requiring the Owner role).

-- Add is_master column (idempotent: ALTER TABLE ... IF NOT EXISTS not supported in all MariaDB
-- versions, so we use a stored-procedure workaround via a plain ALTER + ignore duplicate column error).
-- For safety we wrap in a conditional via information_schema.
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'partner_companies'
      AND COLUMN_NAME = 'is_master'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE partner_companies ADD COLUMN is_master TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set Proximus (identified by name) as the master partner.
-- Using name to avoid hardcoding an ID that may differ between environments.
-- Note: if no company named 'Proximus' exists this UPDATE is a safe no-op;
-- the is_master column is still added and can be set manually via the UI or SQL.
UPDATE partner_companies
SET is_master = 1
WHERE name = 'Proximus'
LIMIT 1;
