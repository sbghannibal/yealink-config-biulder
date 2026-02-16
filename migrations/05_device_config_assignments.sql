-- Device to Config Assignment Mapping
-- This table maps devices to specific config versions
CREATE TABLE IF NOT EXISTS device_config_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    config_version_id INT NOT NULL,
    assigned_by INT,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(500),
    INDEX idx_device (device_id),
    INDEX idx_config_version (config_version_id),
    UNIQUE KEY unique_device_config (device_id, config_version_id),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (config_version_id) REFERENCES config_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
