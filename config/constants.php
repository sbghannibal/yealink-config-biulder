<?php
/**
 * Application Constants
 */

// Application settings
define('APP_NAME', 'Yealink Config Builder');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'Europe/Amsterdam');

// Session settings
define('SESSION_TIMEOUT', 3600); // 1 hour
define('SESSION_REGENERATE_INTERVAL', 300); // 5 minutes

// File upload settings
define('MAX_UPLOAD_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['cfg', 'xml', 'txt']);

// API settings
define('API_RATE_LIMIT', 100); // requests per hour
define('API_TIMEOUT', 30);

// Permissions
define('PERMISSIONS', [
    'admin.users.create' => 'Create users',
    'admin.users.edit' => 'Edit users',
    'admin.users.delete' => 'Delete users',
    'admin.roles.manage' => 'Manage roles',
    'admin.backup.create' => 'Create backups',
    'admin.backup.restore' => 'Restore backups',
    'admin.audit.view' => 'View audit logs',
    'admin.tokens.generate' => 'Generate tokens',
    'pabx.manage' => 'Manage PABX',
    'devices.manage' => 'Manage devices',
    'variables.manage' => 'Manage variables',
    'mappings.manage' => 'Manage mappings',
]);

// Set timezone
date_default_timezone_set(APP_TIMEZONE);
?>