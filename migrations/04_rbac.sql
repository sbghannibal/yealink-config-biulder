CREATE TABLE `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(64) UNIQUE NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `admin_roles` (
  `admin_id` INT NOT NULL,
  `role_id` INT NOT NULL,
  PRIMARY KEY (`admin_id`, `role_id`),
  FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `role_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `permission` VARCHAR(128) NOT NULL,
  UNIQUE KEY `unique_role_perm` (`role_id`, `permission`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (name, description) VALUES
('super_admin', 'Volledige beheerdersrechten'),
('admin', 'Admin rechten'),
('editor', 'Kan variabelen en mappings bewerken'),
('viewer', 'Alleen-lezen toegang');

INSERT INTO `role_permissions` (role_id, permission) VALUES
(1, 'admin.users.create'),
(1, 'admin.users.edit'),
(1, 'admin.users.delete'),
(1, 'admin.roles.manage'),
(1, 'admin.backup.create'),
(1, 'admin.backup.restore'),
(1, 'admin.audit.view'),
(1, 'admin.tokens.generate'),
(1, 'pabx.manage'),
(1, 'devices.manage'),
(1, 'variables.manage'),
(1, 'mappings.manage'),
(2, 'pabx.manage'),
(2, 'devices.manage'),
(2, 'variables.manage'),
(2, 'mappings.manage'),
(2, 'admin.audit.view'),
(2, 'admin.tokens.generate'),
(3, 'variables.manage'),
(3, 'mappings.manage'),
(3, 'admin.audit.view'),
(4, 'admin.audit.view');