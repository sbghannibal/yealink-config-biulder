<?php
/**
 * Builder Actions API
 * Handles AJAX requests for copy, delete, stats, device search
 */
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Geen toegang']);
    exit;
}

// CSRF validation for POST/DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ongeldige CSRF token']);
        exit;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // Search devices by name and customer
        case 'search_devices':
            $search = $_GET['q'] ?? '';
            $devices = [];
            
            if (strlen($search) >= 2) {
                $stmt = $pdo->prepare("
                    SELECT d.id, d.device_name, d.mac_address, dt.type_name, dt.id as device_type_id,
                           c.company_name, c.customer_code
                    FROM devices d
                    LEFT JOIN device_types dt ON d.device_type_id = dt.id
                    LEFT JOIN customers c ON d.customer_id = c.id
                    WHERE d.is_active = 1
                    AND (d.device_name LIKE ? OR c.company_name LIKE ? OR c.customer_code LIKE ?)
                    ORDER BY d.device_name ASC
                    LIMIT 20
                ");
                $searchParam = '%' . $search . '%';
                $stmt->execute([$searchParam, $searchParam, $searchParam]);
                $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode(['success' => true, 'devices' => $devices]);
            break;
            
        // Copy config version
        case 'copy_config':
            $config_id = (int) ($_POST['config_id'] ?? 0);
            if (!$config_id) {
                throw new Exception('Config ID ontbreekt');
            }
            
            // Get original config
            $stmt = $pdo->prepare('SELECT * FROM config_versions WHERE id = ?');
            $stmt->execute([$config_id]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$original) {
                throw new Exception('Config niet gevonden');
            }
            
            // Get next version number
            $vstmt = $pdo->prepare('
                SELECT COALESCE(MAX(version_number), 0) + 1 AS next_ver 
                FROM config_versions 
                WHERE pabx_id = ? AND device_type_id = ?
            ');
            $vstmt->execute([$original['pabx_id'], $original['device_type_id']]);
            $next_ver = (int) $vstmt->fetchColumn();
            
            // Create copy
            $ins = $pdo->prepare('
                INSERT INTO config_versions 
                (pabx_id, device_type_id, version_number, config_content, changelog, is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
            ');
            $changelog = 'Kopie van versie #' . $config_id;
            $ins->execute([
                $original['pabx_id'],
                $original['device_type_id'],
                $next_ver,
                $original['config_content'],
                $changelog,
                $admin_id
            ]);
            $new_id = $pdo->lastInsertId();
            
            // Audit log
            try {
                $alog = $pdo->prepare('
                    INSERT INTO audit_logs 
                    (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $alog->execute([
                    $admin_id,
                    'copy_config_version',
                    'config_version',
                    $new_id,
                    json_encode(['original_id' => $config_id, 'version' => $next_ver]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('Audit log error: ' . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Config gekopieerd als versie ' . $next_ver,
                'new_id' => $new_id
            ]);
            break;
            
        // Delete config version
        case 'delete_config':
            $config_id = (int) ($_POST['config_id'] ?? 0);
            if (!$config_id) {
                throw new Exception('Config ID ontbreekt');
            }
            
            // Check if assigned to any devices
            $check = $pdo->prepare('
                SELECT COUNT(*) as count 
                FROM device_config_assignments 
                WHERE config_version_id = ?
            ');
            $check->execute([$config_id]);
            $assigned_count = (int) $check->fetchColumn();
            
            if ($assigned_count > 0) {
                throw new Exception(
                    "Kan niet verwijderen: deze config is toegewezen aan $assigned_count device(s). " .
                    "Verwijder eerst de toewijzingen."
                );
            }
            
            // Delete the config
            $del = $pdo->prepare('DELETE FROM config_versions WHERE id = ?');
            $del->execute([$config_id]);
            
            // Audit log
            try {
                $alog = $pdo->prepare('
                    INSERT INTO audit_logs 
                    (admin_id, action, entity_type, entity_id, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $alog->execute([
                    $admin_id,
                    'delete_config_version',
                    'config_version',
                    $config_id,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('Audit log error: ' . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Config versie verwijderd'
            ]);
            break;
            
        // Get config statistics
        case 'get_stats':
            $config_id = (int) ($_GET['config_id'] ?? 0);
            if (!$config_id) {
                throw new Exception('Config ID ontbreekt');
            }
            
            // Get config info
            $stmt = $pdo->prepare('
                SELECT cv.*, p.pabx_name, dt.type_name, a.username
                FROM config_versions cv
                LEFT JOIN pabx p ON cv.pabx_id = p.id
                LEFT JOIN device_types dt ON cv.device_type_id = dt.id
                LEFT JOIN admins a ON cv.created_by = a.id
                WHERE cv.id = ?
            ');
            $stmt->execute([$config_id]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                throw new Exception('Config niet gevonden');
            }
            
            // Get assigned devices
            $dstmt = $pdo->prepare('
                SELECT d.id, d.device_name, d.mac_address, dt.type_name,
                       c.company_name, c.customer_code,
                       dca.assigned_at
                FROM device_config_assignments dca
                JOIN devices d ON dca.device_id = d.id
                LEFT JOIN device_types dt ON d.device_type_id = dt.id
                LEFT JOIN customers c ON d.customer_id = c.id
                WHERE dca.config_version_id = ?
                ORDER BY dca.assigned_at DESC
            ');
            $dstmt->execute([$config_id]);
            $devices = $dstmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get download count
            $dlstmt = $pdo->prepare('
                SELECT COUNT(*) as count 
                FROM config_download_history 
                WHERE config_version_id = ?
            ');
            $dlstmt->execute([$config_id]);
            $download_count = (int) $dlstmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'config' => $config,
                'devices' => $devices,
                'device_count' => count($devices),
                'download_count' => $download_count
            ]);
            break;
            
        default:
            throw new Exception('Onbekende actie');
    }
} catch (Exception $e) {
    error_log('Builder action error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
