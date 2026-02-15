CREATE TABLE `download_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(255) UNIQUE NOT NULL,
  `mac_address` VARCHAR(17),
  `device_model` VARCHAR(64),
  `pabx_id` INT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` TIMESTAMP NULL,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  INDEX `idx_expires` (`expires_at`),
  FOREIGN KEY (`pabx_id`) REFERENCES `pabx`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;