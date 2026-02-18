-- SQL schema for config_versions table
-- This table stores configuration versions for PABX/device type combinations
CREATE TABLE IF NOT EXISTS config_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pabx_id INT NOT NULL,
    device_type_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    config_content TEXT NOT NULL,
    changelog VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pabx_device (pabx_id, device_type_id),
    INDEX idx_active (is_active),
    FOREIGN KEY (pabx_id) REFERENCES pabx(id) ON DELETE CASCADE,
    FOREIGN KEY (device_type_id) REFERENCES device_types(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SQL schema for config_download_history table
-- This table tracks all config downloads for audit purposes
CREATE TABLE IF NOT EXISTS config_download_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_version_id INT NOT NULL,
    device_id INT,
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    user_agent TEXT,
    download_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_config_version (config_version_id),
    INDEX idx_device (device_id),
    INDEX idx_download_time (download_time),
    FOREIGN KEY (config_version_id) REFERENCES config_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
