<?php
/**
 * Config Versioning System
 * Track config changes and allow rollback
 */

function create_config_version($pdo, $pabx_id, $device_type_id, $config_content, $changelog, $admin_id) {
    // Get next version number
    $stmt = $pdo->prepare('SELECT MAX(version_number) as max_version FROM config_versions WHERE pabx_id = ? AND device_type_id = ?');
    $stmt->execute([$pabx_id, $device_type_id]);
    $result = $stmt->fetch();
    $version_number = ($result['max_version'] ?? 0) + 1;
    
    // Deactivate old versions
    $stmt = $pdo->prepare('UPDATE config_versions SET is_active = FALSE WHERE pabx_id = ? AND device_type_id = ?');
    $stmt->execute([$pabx_id, $device_type_id]);
    
    // Create new version
    $stmt = $pdo->prepare('INSERT INTO config_versions (pabx_id, device_type_id, version_number, config_content, changelog, created_by, is_active)
    VALUES (?, ?, ?, ?, ?, ?, TRUE)');
    
    $stmt->execute([
        $pabx_id,
        $device_type_id,
        $version_number,
        $config_content,
        $changelog,
        $admin_id
    ]);
    
    return $pdo->lastInsertId();
}

function get_config_versions($pdo, $pabx_id, $device_type_id) {
    $stmt = $pdo->prepare('SELECT cv.*, a.username, (SELECT COUNT(*) FROM config_download_history WHERE config_version_id = cv.id) as download_count FROM config_versions cv LEFT JOIN admins a ON cv.created_by = a.id WHERE cv.pabx_id = ? AND cv.device_type_id = ? ORDER BY cv.version_number DESC');
    
    $stmt->execute([$pabx_id, $device_type_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rollback_version($pdo, $version_id, $admin_id) {
    $stmt = $pdo->prepare('SELECT * FROM config_versions WHERE id = ?');
    $stmt->execute([$version_id]);
    $version = $stmt->fetch();
    
    if (!$version) return false;
    
    // Create new version as rollback
    $changelog = 'Rollback van versie ' . $version['version_number'];
    create_config_version(
        $pdo,
        $version['pabx_id'],
        $version['device_type_id'],
        $version['config_content'],
        $changelog,
        $admin_id
    );
    
    return true;
}
?>