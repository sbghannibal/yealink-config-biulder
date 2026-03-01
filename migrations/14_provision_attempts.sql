-- Migration 14: Provision attempts structured logging with dedup, device model mappings

CREATE TABLE IF NOT EXISTS `provision_attempts` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `mac_normalized`     CHAR(12)      NULL COMMENT '12 hex uppercase, no separators',
  `mac_formatted`      VARCHAR(17)   NULL COMMENT 'AA:BB:CC:DD:EE:FF',
  `mac_source`         ENUM('uri','query','none') NOT NULL DEFAULT 'none',
  `request_uri`        VARCHAR(512)  NOT NULL DEFAULT '',
  `requested_filename` VARCHAR(128)  NULL COMMENT 'e.g. 249ad8b388c6.boot',
  `requested_ext`      VARCHAR(16)   NULL COMMENT 'boot, cfg, etc.',
  `ip_address`         VARCHAR(45)   NOT NULL DEFAULT '',
  `user_agent`         VARCHAR(512)  NOT NULL DEFAULT '',
  `device_model`       VARCHAR(64)   NULL COMMENT 'Parsed from UA, e.g. W75DM',
  `status`             VARCHAR(32)   NOT NULL DEFAULT 'unknown'
                         COMMENT 'success|device_not_found|no_active_config|invalid_mac|blocked_user_agent|server_error|db_error',
  `device_id`          INT           NULL,
  `config_version_id`  INT           NULL,
  `attempt_count`      INT           NOT NULL DEFAULT 1,
  `first_seen_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Indexes for querying latest per MAC
  INDEX `idx_mac_normalized`  (`mac_normalized`),
  INDEX `idx_mac_status`      (`mac_normalized`, `status`),
  -- Retention: delete unknown device rows older than 30 days
  INDEX `idx_device_id_date`  (`device_id`, `last_seen_at`),
  -- Status filtering
  INDEX `idx_status`          (`status`),
  INDEX `idx_last_seen`       (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Device model → device type mapping table
CREATE TABLE IF NOT EXISTS `device_model_mappings` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `model_code`     VARCHAR(64)  NOT NULL UNIQUE COMMENT 'e.g. W75DM, T46S',
  `device_type_id` INT          NOT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX `idx_model_code`    (`model_code`),
  INDEX `idx_device_type`   (`device_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
