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
        error_log("Config not found for device: $mac");
        http_response_code(404);
        exit('Configuration not found');
    }

    $config = $config_data['config_content'];

    // Device-specific variables for variable substitution
    $device_variables = [
        'DEVICE_MAC' => $mac_formatted,
        'DEVICE_MAC_PLAIN' => $mac,
        'DEVICE_NAME' => $device['device_name'] ?? '',
        'DEVICE_ID' => $device['id'],
    ];

    // Add PABX variables if available
    if ($config_data['pabx_id']) {
        $device_variables['PABX_NAME'] = $config_data['pabx_name'] ?? '';
        $device_variables['PABX_IP'] = $config_data['pabx_ip'] ?? '';
        $device_variables['PABX_PORT'] = $config_data['pabx_port'] ?? '';
        $device_variables['PABX_TYPE'] = $config_data['pabx_type'] ?? '';
    }

    // Apply variables to config content
    $config = apply_variables_to_content($config, $device_variables);

    // Ensure reboot_after_update is set in final config
    if (strpos($config, 'static.auto_provision.reboot_after_update') === false) {
        // Add reboot flag if not already present
        $config .= "\n# Force reboot after provisioning\nstatic.auto_provision.reboot_after_update=1\n";
    }

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
?>
