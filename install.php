<?php
// install.php - create all required tables using $pdo from config/database.php
// Run once: php install.php
require_once __DIR__ . '/config/database.php';

$tables = [
'CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(255) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_name` VARCHAR(255) UNIQUE NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `permission` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_role_permission` (`role_id`, `permission`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `admin_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `role_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_admin_role` (`admin_id`, `role_id`),
  FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `pabx` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pabx_name` VARCHAR(255) NOT NULL,
  `pabx_ip` VARCHAR(45),
  `pabx_port` INT DEFAULT 5060,
  `pabx_type` VARCHAR(64),
  `description` TEXT,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `device_types` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type_name` VARCHAR(64) UNIQUE NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `device_name` VARCHAR(255) NOT NULL,
  `model` VARCHAR(64) NOT NULL,
  `mac_address` VARCHAR(17),
  `description` TEXT,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `config_versions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pabx_id` INT NOT NULL,
  `device_type_id` INT,
  `version_number` INT NOT NULL,
  `config_content` LONGTEXT NOT NULL,
  `changelog` TEXT,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`pabx_id`) REFERENCES `pabx`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`device_type_id`) REFERENCES `device_types`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `download_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(255) UNIQUE NOT NULL,
  `config_version_id` INT,
  `mac_address` VARCHAR(17),
  `device_model` VARCHAR(64),
  `pabx_id` INT,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`config_version_id`) REFERENCES `config_versions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pabx_id`) REFERENCES `pabx`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `config_download_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `config_version_id` INT NOT NULL,
  `mac_address` VARCHAR(17),
  `device_model` VARCHAR(64),
  `ip_address` VARCHAR(45),
  `user_agent` VARCHAR(255),
  `download_token_id` INT,
  `downloaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`config_version_id`) REFERENCES `config_versions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`download_token_id`) REFERENCES `download_tokens`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT,
  `action` VARCHAR(255) NOT NULL,
  `entity_type` VARCHAR(64),
  `entity_id` INT,
  `old_value` JSON,
  `new_value` JSON,
  `ip_address` VARCHAR(45),
  `user_agent` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `variables` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `var_name` VARCHAR(255) UNIQUE NOT NULL,
  `var_value` TEXT NOT NULL,
  `description` TEXT,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',

'CREATE TABLE IF NOT EXISTS `mappings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `button_number` INT NOT NULL,
  `button_type` VARCHAR(64) NOT NULL,
  `button_value` VARCHAR(255) NOT NULL,
  `button_label` VARCHAR(255),
  `device_model` VARCHAR(64),
  `description` TEXT,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_button_mapping` (`button_number`, `button_type`, `device_model`),
  FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
];

echo PHP_EOL . "Running install.php - creating tables..." . PHP_EOL;

$created = [];
$errors = [];

foreach ($tables as $sql) {
    try {
        $pdo->exec($sql);
        // attempt to extract table name for logging
        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $sql, $m)) {
            $created[] = $m[1];
            echo "Created/verified table: " . $m[1] . PHP_EOL;
        } else {
            echo "Executed a SQL statement." . PHP_EOL;
        }
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "Installation complete." . PHP_EOL;
if (!empty($created)) {
    echo "Tables created/verified: " . implode(', ', $created) . PHP_EOL;
}
if (!empty($errors)) {
    echo "Errors: " . PHP_EOL;
    foreach ($errors as $err) {
        echo " - " . $err . PHP_EOL;
    }
}
echo "IMPORTANT: remove or secure install.php after use." . PHP_EOL;
