<?php
/**
 * Download Device Config
 * Allows logged-in users to download device configurations directly
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/generator.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$admin_id = (int) $_SESSION['admin_id'];

// Check permission
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    exit('Access denied. Insufficient permissions.');
}

// Get parameters
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;
$mac_param = isset($_GET['mac']) ? trim($_GET['mac']) : null;

if (!$device_id || !$mac_param) {
    http_response_code(400);
    exit('Missing required parameters: device_id and mac');
}

try {
    // Fetch device from database
    $stmt = $pdo->prepare('
        SELECT d.*, dt.type_name as model_name,
               (SELECT dca.config_version_id 
                FROM device_config_assignments dca 
                WHERE dca.device_id = d.id 
                ORDER BY dca.assigned_at DESC 
                LIMIT 1) as config_version_id
        FROM devices d
        LEFT JOIN device_types dt ON d.device_type_id = dt.id
        WHERE d.id = ?
    ');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        http_response_code(404);
        exit('Device not found');
    }
    
    // Verify MAC address matches (security check)
    if (strtolower($device['mac_address']) !== strtolower($mac_param)) {
        error_log("MAC address mismatch for device ID $device_id: expected {$device['mac_address']}, got $mac_param");
        http_response_code(403);
        exit('MAC address verification failed');
    }
    
    // Check if device has a config assigned
    if (!$device['config_version_id']) {
        http_response_code(404);
        exit('No configuration assigned to this device');
    }
    
    // Get config content and PABX info
    $stmt = $pdo->prepare('
        SELECT cv.config_content, cv.pabx_id,
               p.pabx_name, p.pabx_ip, p.pabx_port, p.pabx_type
        FROM config_versions cv
        LEFT JOIN pabx p ON cv.pabx_id = p.id
        WHERE cv.id = ?
    ');
    $stmt->execute([$device['config_version_id']]);
    $config_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config_data || !$config_data['config_content']) {
        http_response_code(404);
        exit('Configuration not found');
    }
    
    $config_content = $config_data['config_content'];
    
    // Apply device-specific variables to the config
    // Remove colons from MAC for some variables
    $mac_no_colons = str_replace(':', '', $device['mac_address']);
    
    $device_variables = [
        'DEVICE_NAME' => $device['device_name'] ?? '',
        'DEVICE_MAC' => $mac_no_colons,
        'DEVICE_IP' => $device['ip_address'] ?? '',
        'DEVICE_MODEL' => $device['model_name'] ?? '',
    ];
    
    // Add PABX variables if available
    if ($config_data['pabx_id']) {
        $device_variables['PABX_NAME'] = $config_data['pabx_name'] ?? '';
        $device_variables['PABX_IP'] = $config_data['pabx_ip'] ?? '';
        $device_variables['PABX_PORT'] = $config_data['pabx_port'] ?? '';
        $device_variables['PABX_TYPE'] = $config_data['pabx_type'] ?? '';
    }
    
    // Apply variables to config content
    $config_content = apply_variables_to_content($config_content, $device_variables);
    
    // Apply Yealink formatting
    $config_content = apply_yealink_formatting($config_content);
    
    // Log the download in audit logs
    try {
        log_audit($pdo, $admin_id, 'config.download', 'device', $device_id, null, [
            'device_name' => $device['device_name'],
            'mac_address' => $device['mac_address'],
            'config_version_id' => $device['config_version_id']
        ]);
    } catch (Exception $e) {
        error_log("Failed to log download audit: " . $e->getMessage());
    }
    
    // Generate filename
    $filename = 'yealink_' . $mac_no_colons . '.cfg';
    
    // Send as download
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($config_content));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    echo $config_content;
    exit;
    
} catch (Exception $e) {
    error_log("Download device config error: " . $e->getMessage());
    http_response_code(500);
    exit('Server error occurred while generating configuration');
}
