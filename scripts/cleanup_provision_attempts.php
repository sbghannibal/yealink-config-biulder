#!/usr/bin/env php
<?php
/**
 * Retention cleanup for provision_attempts.
 *
 * Deletes rows where device_id IS NULL and last_seen_at is older than 30 days
 * (configurable via the PROVISION_RETENTION_DAYS environment variable).
 * Known-device rows (device_id NOT NULL) are kept indefinitely.
 *
 * Recommended cron entry (daily at 03:00):
 *   0 3 * * * /usr/bin/php /path/to/yealink-config-builder/scripts/cleanup_provision_attempts.php >> /path/to/logs/cleanup.log 2>&1
 */

$retention_days = (int)(getenv('PROVISION_RETENTION_DAYS') ?: 30);

require_once __DIR__ . '/../settings/database.php';

try {
    $stmt = $pdo->prepare('
        DELETE FROM provision_attempts
        WHERE device_id IS NULL
          AND last_seen_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ');
    $stmt->execute([$retention_days]);
    $deleted = $stmt->rowCount();
    echo date('Y-m-d H:i:s') . " [cleanup_provision_attempts] Deleted $deleted rows older than {$retention_days} days (unknown devices).\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [cleanup_provision_attempts] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
