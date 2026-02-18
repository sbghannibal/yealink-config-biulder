<?php
/**
 * Device-Specific Configuration with Certificates
 * Handles requests for {MAC}.cfg files
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

// Get MAC from request path (e.g., /staging/805e0cf40bb9.cfg)
$mac = null;
if (preg_match('/([0-9a-f]{12})\.cfg$/i', $_SERVER['REQUEST_URI'], $matches)) {
    $mac = strtoupper($matches[1]);
}

if (!$mac || strlen($mac) !== 12) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "# Error: Invalid MAC address\n";
    exit;
}

// Format MAC
$mac_formatted = strtoupper(
    substr($mac, 0, 2) . ':' .
    substr($mac, 2, 2) . ':' .
    substr($mac, 4, 2) . ':' .
    substr($mac, 6, 2) . ':' .
    substr($mac, 8, 2) . ':' .
    substr($mac, 10, 2)
);

try {
    // Verify device exists
    $stmt = $pdo->prepare('
        SELECT id FROM devices
        WHERE REPLACE(REPLACE(UPPER(mac_address), ":", ""), "-", "") = ?
        AND is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "# Error: Device not found or inactive\n";
        exit;
    }

    // Get server URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $server_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu');

    // Generate device configuration with certificates
    $config = <<<'CONFIG'
#!version:1.0.0.1

[DEVICE_INFO]
device_mac={{DEVICE_MAC}}

[CERTIFICATE]
# Download trusted CA certificate (shared for all devices)
static.trusted_certificates.url={{SERVER_URL}}/provision/staging/certificates/ca.crt

# Download shared server certificate (NOT device-specific to prevent MAC spoofing)
static.server_certificates.url={{SERVER_URL}}/provision/staging/certificates/server.crt

# Enable custom certificate support
static.security.dev_cert=1

[AUTO_PROVISION]
# Full provisioning configuration (device validation happens here)
static.auto_provision.url={{SERVER_URL}}/provision/
static.auto_provision.enable=1

# Reboot after certificate update
static.auto_provision.reboot_after_update=1

[NETWORK]
# Force HTTPS for provisioning
static.provisioning.protocol=https

CONFIG;

    // Replace placeholders
    $config = str_replace(
        ['{{DEVICE_MAC}}', '{{SERVER_URL}}'],
        [$mac_formatted, $server_url],
        $config
    );

    // Return configuration
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower($mac) . '.cfg"');
    echo $config;

} catch (Exception $e) {
    error_log('CFG provisioning error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "# Error: Server error\n";
}
?>
