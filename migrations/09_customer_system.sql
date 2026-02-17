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

-- Add customer_id to devices table
ALTER TABLE devices 
    ADD COLUMN customer_id INT NULL AFTER id,
    ADD INDEX idx_customer_id (customer_id),
    ADD CONSTRAINT fk_devices_customer 
        FOREIGN KEY (customer_id) 
        REFERENCES customers(id) 
        ON DELETE SET NULL;

-- Migrate existing PABX data to customers (if pabx_id exists)
INSERT INTO customers (customer_code, company_name, notes, created_at)
SELECT 
    CONCAT('MIGRATED_', p.id) as customer_code,
    COALESCE(p.pabx_name, CONCAT('Customer ', p.id)) as company_name,
    CONCAT('Migrated from PABX. IP: ', COALESCE(p.pabx_ip, 'N/A')) as notes,
    p.created_at
FROM pabx p
WHERE NOT EXISTS (SELECT 1 FROM customers c WHERE c.customer_code = CONCAT('MIGRATED_', p.id));

-- Update devices to use customer_id based on old pabx_id
UPDATE devices d
INNER JOIN pabx p ON d.pabx_id = p.id
INNER JOIN customers c ON c.customer_code = CONCAT('MIGRATED_', p.id)
SET d.customer_id = c.id
WHERE d.pabx_id IS NOT NULL;

-- Add customer management permissions to roles
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, 'admin.customers.manage'
FROM roles r
WHERE r.name IN ('super_admin', 'admin');
