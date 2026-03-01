-- Migration 15: Add proxy_ip_address to provision_attempts and dedup bucket index
-- Stores the REMOTE_ADDR (proxy/load-balancer) separately from the real client IP.

ALTER TABLE `provision_attempts`
  ADD COLUMN IF NOT EXISTS `proxy_ip_address` VARCHAR(45) NULL
    COMMENT 'Proxy/load-balancer IP (REMOTE_ADDR when X-Forwarded-For is used)'
    AFTER `ip_address`;

-- Composite index to support bucket-based dedup query performance
-- (mac_normalized, status, requested_filename)
CREATE INDEX IF NOT EXISTS `idx_dedup_bucket`
  ON `provision_attempts` (`mac_normalized`, `status`, `requested_filename`(64));
