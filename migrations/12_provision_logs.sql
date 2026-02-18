CREATE TABLE IF NOT EXISTS `provision_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `device_id` INT NULL,
  `mac_address` VARCHAR(17),
  `ip_address` VARCHAR(45),
  `user_agent` VARCHAR(255),
  `provisioned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_device_id` (`device_id`),
  INDEX `idx_provisioned_at` (`provisioned_at`),
  INDEX `idx_mac_address` (`mac_address`),
  FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
