<?php
/**
 * Yealink Staging Provisioning
 * 
 * Stage 1: Download boot configuration with certificate URLs
 * Phone will then download certificates and full config
 */

require_once __DIR__ . '/../../config/database.php';

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
    $boot_config = <<<'CONFIG'
#!version:1.0.0.1

[DEVICE_INFO]
device_mac={{DEVICE_MAC}}

[CERTIFICATE]
# Download trusted CA certificates
static.trusted_certificates.url={{SERVER_URL}}/provision/staging/certificates/ca.crt

# Download device-specific certificate (once available)
static.server_certificates.url={{SERVER_URL}}/provision/staging/certificates/device_{{MAC}}.crt

# Enable custom certificate support
static.security.dev_cert=1

[AUTO_PROVISION]
# Full provisioning configuration
static.auto_provision.url={{SERVER_URL}}/provision/?mac={{DEVICE_MAC}}
static.auto_provision.enable=1

# Reboot to apply provisioning
feature.reboot_on_new_config=1

[NETWORK]
# Force HTTPS for provisioning
static.provisioning.protocol=https

CONFIG;
    
    // Replace placeholders
    $boot_config = str_replace(
        ['{{DEVICE_MAC}}', '{{SERVER_URL}}', '{{MAC}}'],
        [$mac_formatted, $server_url, $mac],
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
