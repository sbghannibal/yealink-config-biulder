-- Migration 15: Partner system (multi-tenant access control)
-- Adds partner_companies, admin_partner_company, partner_company_customers tables

-- A) Partner companies (the MSP/reseller/partner organisations)
CREATE TABLE IF NOT EXISTS `partner_companies` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(255)  NOT NULL,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B) Link admin accounts to exactly one partner company (UNIQUE on admin_id)
CREATE TABLE IF NOT EXISTS `admin_partner_company` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id`           INT NOT NULL,
  `partner_company_id` INT NOT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uq_admin_id` (`admin_id`),
  INDEX `idx_partner_company_id` (`partner_company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C) Which customers a partner company may see
CREATE TABLE IF NOT EXISTS `partner_company_customers` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `partner_company_id` INT       NOT NULL,
  `customer_id`        INT       NOT NULL,
  `can_view`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uq_partner_customer` (`partner_company_id`, `customer_id`),
  INDEX `idx_partner_company_id`  (`partner_company_id`),
  INDEX `idx_customer_id`         (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
