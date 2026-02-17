<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/generator.php';

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

// Log the request
error_log("Provisioning request from IP: " . $_SERVER['REMOTE_ADDR'] . " MAC: " . ($mac ?? 'UNKNOWN') . " User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'));

if (!$mac) {
    http_response_code(400);
    exit('MAC address required');
}

// Validate MAC format (must be 12 hex characters)
if (!preg_match('/^[0-9A-F]{12}$/', $mac)) {
    http_response_code(400);
    exit('Invalid MAC address format');
}

// Format MAC with colons for database lookup: 001565AABB20 -> 00:15:65:AA:BB:20
$mac_formatted = substr($mac, 0, 2) . ':' . substr($mac, 2, 2) . ':' . substr($mac, 4, 2) . ':' . 
                 substr($mac, 6, 2) . ':' . substr($mac, 8, 2) . ':' . substr($mac, 10, 2);

try {
    // Find device by MAC - SECURITY: Only active devices can provision
    $stmt = $pdo->prepare('
        SELECT d.*, dca.config_version_id
        FROM devices d
        LEFT JOIN device_config_assignments dca ON d.id = dca.device_id
        WHERE d.mac_address = ? AND d.is_active = 1
        ORDER BY dca.assigned_at DESC
        LIMIT 1
    ');
    $stmt->execute([$mac_formatted]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        error_log("Device not found or inactive for MAC: $mac (formatted: $mac_formatted)");
        
        // Log failed provision attempt
        try {
            $stmt = $pdo->prepare('
                INSERT INTO provision_logs (device_id, mac_address, ip_address, user_agent, provisioned_at)
                VALUES (NULL, ?, ?, ?, NOW())
            ');
            $stmt->execute([$mac_formatted, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown']);
        } catch (Exception $e) {
            error_log("Failed to log provision attempt: " . $e->getMessage());
        }
        
        http_response_code(404);
        exit('Device not found or not active');
    }
    
    // Check if provision is enabled (optional security feature)
    // Note: This column may not exist, so we'll check if it's set
    $provision_enabled = isset($device['provision_enabled']) ? $device['provision_enabled'] : true;
    if (!$provision_enabled) {
        error_log("Provisioning disabled for device MAC: $mac");
        http_response_code(403);
        exit('Provisioning disabled for this device');
    }
    
    if (!$device['config_version_id']) {
        error_log("No config assigned for device: $mac");
        
        // Log failed provision attempt
        try {
            $stmt = $pdo->prepare('
                INSERT INTO provision_logs (device_id, mac_address, ip_address, user_agent, provisioned_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$device['id'], $mac_formatted, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown']);
        } catch (Exception $e) {
            error_log("Failed to log provision attempt: " . $e->getMessage());
        }
        
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
    
    // Apply device-specific variables to the config
    $device_variables = [
        'DEVICE_NAME' => $device['device_name'] ?? '',
        'DEVICE_MAC' => $mac,
        'DEVICE_IP' => $device['ip_address'] ?? '',
        'DEVICE_MODEL' => $device['model'] ?? '',
    ];
    
    // Apply variables to config content
    $config = apply_variables_to_content($config, $device_variables);
    
    // Log successful provision
    try {
        $stmt = $pdo->prepare('
            INSERT INTO provision_logs (device_id, mac_address, ip_address, user_agent, provisioned_at)
            VALUES (?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $device['id'], 
            $mac_formatted, 
            $_SERVER['REMOTE_ADDR'], 
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the provisioning
        error_log("Failed to log successful provision: " . $e->getMessage());
    }
    
    // Return config
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $mac . '.cfg"');
    echo $config;
    
} catch (Exception $e) {
    error_log("Provisioning error: " . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}

