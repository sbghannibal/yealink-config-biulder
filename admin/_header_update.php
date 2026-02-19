<?php
// Add this PHP code at the TOP of the header file (after session_start and requires)
// This fetches the current device and its configs if we're on device_mapping.php

$current_device = null;
$device_configs = [];
$active_config = null;

if ($current_page === 'device_mapping.php' && isset($_GET['device_id'])) {
    $selected_device_id = (int)$_GET['device_id'];
    
    try {
        // Get device info
        $stmt = $pdo->prepare('
            SELECT id, device_name, mac_address
            FROM devices WHERE id = ?
        ');
        $stmt->execute([$selected_device_id]);
        $current_device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current_device) {
            // Get all configs for this device
            $stmt = $pdo->prepare('
                SELECT cv.id, cv.version_number, dca.is_active
                FROM device_config_assignments dca
                INNER JOIN config_versions cv ON dca.config_version_id = cv.id
                WHERE dca.device_id = ?
                ORDER BY dca.is_active DESC, cv.version_number DESC
            ');
            $stmt->execute([$selected_device_id]);
            $device_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get active config
            foreach ($device_configs as $cfg) {
                if ($cfg['is_active']) {
                    $active_config = $cfg;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Header config selector error: ' . $e->getMessage());
    }
}
?>
