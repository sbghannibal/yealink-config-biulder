<?php
/**
 * Certificate Configuration
 * Dynamically generates certificate URLs with proper server URL
 */

require_once __DIR__ . '/../../config/database.php';

// HTTP Basic Authentication (same as boot)
$auth_username = getenv('STAGING_AUTH_USER') ?: 'provisioning';
$auth_password = getenv('STAGING_AUTH_PASS') ?: '';

if (!empty($auth_password)) {
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) ||
        $_SERVER['PHP_AUTH_USER'] !== $auth_username ||
        $_SERVER['PHP_AUTH_PW'] !== $auth_password) {
        http_response_code(401);
        exit;
    }
}

// Get server URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$server_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu');

$cert_config = <<<'CONFIG'
#!version:1.0.0.1

[CERTIFICATE]
# Download trusted CA certificate (shared for all devices)
static.trusted_certificates.url={{SERVER_URL}}/provision/staging/certificates/ca.crt

# Download shared server certificate (NOT device-specific to prevent MAC spoofing)
static.server_certificates.url={{SERVER_URL}}/provision/staging/certificates/server.crt

# Enable custom certificate support
static.security.dev_cert=1

CONFIG;

// Replace placeholder
$cert_config = str_replace('{{SERVER_URL}}', $server_url, $cert_config);

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="certificates.cfg"');
echo $cert_config;
?>
