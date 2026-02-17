<?php
require_once __DIR__ . '/../config/database.php';

// Get MAC address from request
$mac = null;

// Method 1: From URL path (e.g., /provision/001565123456.cfg)
if (preg_match('/([0-9a-f]{12})\.cfg$/i', $_SERVER['REQUEST_URI'], $matches)) {
    $mac = strtoupper($matches[1]);
}

// Method 2: From query parameter
if (!$mac && isset($_GET['MAC'])) {
    $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $_GET['MAC']));
}

// Log the request
error_log("Provisioning request from IP: " . $_SERVER['REMOTE_ADDR'] . " MAC: " . ($mac ?? 'UNKNOWN'));

if (!$mac) {
    http_response_code(400);
    exit('MAC address required');
}

// Format MAC with colons for database lookup: 001565AABB20 -> 00:15:65:AA:BB:20
$mac_formatted = substr($mac, 0, 2) . ':' . substr($mac, 2, 2) . ':' . substr($mac, 4, 2) . ':' . 
                 substr($mac, 6, 2) . ':' . substr($mac, 8, 2) . ':' . substr($mac, 10, 2);

try {
    // Find device by MAC (with colons)
    $stmt = $pdo->prepare('
        SELECT d.*, dca.config_version_id
        FROM devices d
        LEFT JOIN device_config_assignments dca ON d.id = dca.device_id
        WHERE d.mac_address = ?
        ORDER BY dca.assigned_at DESC
        LIMIT 1
    ');
    $stmt->execute([$mac_formatted]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        error_log("Device not found for MAC: $mac (formatted: $mac_formatted)");
        http_response_code(404);
        exit('Device not found');
    }
    
    if (!$device['config_version_id']) {
        error_log("No config assigned for device: $mac");
        http_response_code(404);
        exit('No configuration assigned');
    }
    
    // Get config content
    $stmt = $pdo->prepare('SELECT config_content FROM config_versions WHERE id = ?');
    $stmt->execute([$device['config_version_id']]);
    $config = $stmt->fetchColumn();
    
    if (!$config) {
        error_log("Config not found for device: $mac");
        http_response_code(404);
        exit('Configuration not found');
    }
    
    // Log successful provision
    $stmt = $pdo->prepare('
        INSERT INTO provision_logs (device_id, ip_address, provisioned_at)
        VALUES (?, ?, NOW())
    ');
    $stmt->execute([$device['id'], $_SERVER['REMOTE_ADDR']]);
    
    // Return config
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $mac . '.cfg"');
    echo $config;
    
} catch (Exception $e) {
    error_log("Provisioning error: " . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
