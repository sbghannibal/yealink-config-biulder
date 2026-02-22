-- Soft Delete Migration
-- Run this migration to add soft delete support to customers, devices, and config_versions

ALTER TABLE customers ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE devices ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE config_versions ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;

CREATE INDEX idx_customers_deleted ON customers(deleted_at);
CREATE INDEX idx_devices_deleted ON devices(deleted_at);
CREATE INDEX idx_config_versions_deleted ON config_versions(deleted_at);
