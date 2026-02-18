-- Customer Management System
-- Replace PABX system with Customer system

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Unique customer identifier (e.g., CUST001)',
    company_name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    address TEXT,
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_code (customer_code),
    INDEX idx_company_name (company_name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add customer_id to devices table (only if not exists)
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'devices' 
    AND COLUMN_NAME = 'customer_id'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE devices ADD COLUMN customer_id INT NULL AFTER id, ADD INDEX idx_customer_id (customer_id)',
    'SELECT "Column customer_id already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Only add foreign key if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'devices' 
    AND CONSTRAINT_NAME = 'fk_devices_customer'
);

SET @sql_fk = IF(@fk_exists = 0,
    'ALTER TABLE devices ADD CONSTRAINT fk_devices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL',
    'SELECT "Foreign key fk_devices_customer already exists" AS message'
);

PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- Create a default customer if none exist
INSERT IGNORE INTO customers (customer_code, company_name, notes, is_active)
VALUES ('DEFAULT', 'Standaard Klant', 'Automatisch aangemaakt voor bestaande devices', TRUE);
