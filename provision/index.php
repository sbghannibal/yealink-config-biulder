<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/provision_error.log');

require_once __DIR__ . '/../settings/database.php';

error_log("=== PROVISION REQUEST START ===");
error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
error_log("USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'NONE'));

// Security: Only allow Yealink devices
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_yealink = stripos($user_agent, 'Yealink') !== false;

error_log("Is Yealink: " . ($is_yealink ? 'YES' : 'NO'));

if (!$is_yealink) {
    error_log("Non-Yealink device blocked");
    http_response_code(403);
    exit('Access denied');
}

// Get MAC address from request
$mac = null;

// Method 1: From URL path (e.g., /provision/001565123456.cfg)
if (preg_match('/([0-9a-f]{12})\.cfg$/i', $_SERVER['REQUEST_URI'], $matches)) {
    $mac = strtoupper($matches[1]);
}

// Method 2: From query parameter
if (!$mac && isset($_GET['mac'])) {
    $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $_GET['mac']));
}

error_log("MAC extracted: " . ($mac ?? 'NONE'));

if (!$mac) {
    http_response_code(400);
    exit('MAC address required');
}

// Validate MAC format
if (!preg_match('/^[0-9A-F]{12}$/', $mac)) {
    http_response_code(400);
    exit('Invalid MAC format');
}

// Format MAC with colons
$mac_formatted = substr($mac, 0, 2) . ':' . substr($mac, 2, 2) . ':' . substr($mac, 4, 2) . ':' .
                 substr($mac, 6, 2) . ':' . substr($mac, 8, 2) . ':' . substr($mac, 10, 2);

error_log("MAC formatted: $mac_formatted");

try {
    error_log("Querying database for device...");

    // Find device by MAC and get ACTIVE config version (FIXED)
    $stmt = $pdo->prepare('
        SELECT d.*, cv.id as config_version_id, cv.config_content
        FROM devices d
        LEFT JOIN device_config_assignments dca ON d.id = dca.device_id AND dca.is_active = 1
        LEFT JOIN config_versions cv ON dca.config_version_id = cv.id
        WHERE d.mac_address = ? AND d.is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$mac_formatted]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Device found: " . ($device ? 'YES' : 'NO'));

    if (!$device) {
        error_log("Device not found or inactive");
        http_response_code(404);
        exit('Device not found');
    }

    error_log("Device ID: " . $device['id']);
    error_log("Config version ID: " . ($device['config_version_id'] ?? 'NONE'));

    if (!$device['config_version_id']) {
        error_log("No ACTIVE config version assigned");
        http_response_code(404);
        exit('No active configuration assigned');
    }

    error_log("ACTIVE config found, sending response");

    // Log provision
    try {
        $stmt = $pdo->prepare('
            INSERT INTO provision_logs (device_id, mac_address, ip_address, user_agent, provisioned_at)
            VALUES (?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$device['id'], $mac_formatted, $_SERVER['REMOTE_ADDR'], $user_agent]);
    } catch (Exception $e) {
        error_log("Failed to log: " . $e->getMessage());
    }

    // Return config file
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower($mac) . '.cfg"');
    echo $device['config_content'];

    error_log("=== PROVISION REQUEST SUCCESS - Config version {$device['config_version_id']} ===");

} catch (PDOException $e) {
    error_log("PDO Error: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    http_response_code(500);
    exit('Database error');
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    http_response_code(500);
    exit('Server error');
}
?>
