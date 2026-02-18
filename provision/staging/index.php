<?php
/**
 * Yealink Staging Provisioning
 *
 * Stage 1: Download boot configuration with certificate URLs
 * Phone will then download certificates and full config
 */

require_once __DIR__ . '/../../config/database.php';

// HTTP Basic Authentication for staging provisioning
$auth_username = getenv('STAGING_AUTH_USER') ?: 'provisioning';
$auth_password = getenv('STAGING_AUTH_PASS') ?: '';

// Check if authentication is configured
if (!empty($auth_password)) {
    // Require authentication
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) ||
        $_SERVER['PHP_AUTH_USER'] !== $auth_username ||
        $_SERVER['PHP_AUTH_PW'] !== $auth_password) {

        header('WWW-Authenticate: Basic realm="Yealink Staging Provisioning"');
        http_response_code(401);
        echo "Authentication required";
        error_log("Staging auth failed - User: " . ($_SERVER['PHP_AUTH_USER'] ?? 'none'));
        exit;
    }
}

// Security: Verify User-Agent is from Yealink device
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_yealink = false;

// Check if User-Agent contains Yealink identifier
if (stripos($user_agent, 'yealink') !== false) {
    $is_yealink = true;
}

// Allow bypass for testing with specific parameter (only for Owner in development)
$allow_test = isset($_GET['allow_test']) && $_GET['allow_test'] === getenv('STAGING_TEST_TOKEN');

if (!$is_yealink && !$allow_test) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "# Error: Access denied - Invalid device type\n";
    error_log("Staging rejected - Non-Yealink User-Agent: $user_agent from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

// Log the device type for monitoring
if ($is_yealink) {
    error_log("Staging request from Yealink device - UA: $user_agent");
}

// Get MAC from request
$mac = null;

// Method 1: From path (e.g., /staging/001565aabb20.boot)
if (preg_match('/([0-9a-f]{12})\.boot$/i', $_SERVER['REQUEST_URI'], $matches)) {
    $mac = strtoupper($matches[1]);
}

// Method 2: From query parameter
if (!$mac && isset($_GET['mac'])) {
    $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $_GET['mac']));
}

// Log request
error_log("Staging request - MAC: " . ($mac ?? 'UNKNOWN'));

if (!$mac || strlen($mac) !== 12) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "# Error: Invalid or missing MAC address\n";
    exit;
}

try {
    // Format MAC for database lookup: 001565AABB20 -> 00:15:65:AA:BB:20
    $mac_formatted = strtoupper(
        substr($mac, 0, 2) . ':' .
        substr($mac, 2, 2) . ':' .
        substr($mac, 4, 2) . ':' .
        substr($mac, 6, 2) . ':' .
        substr($mac, 8, 2) . ':' .
        substr($mac, 10, 2)
    );

    // Check if device exists and is active
    $stmt = $pdo->prepare('
        SELECT id, device_name FROM devices
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
        error_log("Staging rejected - Device not found: $mac");
        exit;
    }

    // Get server URL for full provisioning
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $server_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu');

    // Generate boot configuration
    // Using heredoc with single quotes to prevent variable interpolation
    // Placeholders are replaced with str_replace() for clarity and security
    $boot_config = <<<'CONFIG'
#!version:1.0.0.1

[DEVICE_INFO]
device_mac={{DEVICE_MAC}}

[CONFIG_FILES]
# Download certificate configuration from separate file
static.config_files={{SERVER_URL}}/provision/staging/certificates.php

[AUTO_PROVISION]
# Full provisioning configuration (device validation happens here)
static.auto_provision.url={{SERVER_URL}}/provision/
static.auto_provision.enable=1

# Reboot to apply provisioning
feature.reboot_on_new_config=1

[NETWORK]
# Force HTTPS for provisioning
static.provisioning.protocol=https

CONFIG;

    // Replace placeholders
    $boot_config = str_replace(
        ['{{DEVICE_MAC}}', '{{SERVER_URL}}'],
        [$mac_formatted, $server_url],
        $boot_config
    );

    // Log staging request
    $log_stmt = $pdo->prepare('
        INSERT INTO provision_logs
        (device_id, mac_address, ip_address, user_agent, provisioned_at)
        VALUES (?, ?, ?, ?, NOW())
    ');
    $log_stmt->execute([
        $device['id'],
        $mac_formatted,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // Return boot configuration
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower($mac) . '.boot"');
    echo $boot_config;

} catch (Exception $e) {
    error_log('Staging provisioning error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "# Error: Server error\n";
}
?>
