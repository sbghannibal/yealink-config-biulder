<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Assign single device
        if ($action === 'assign') {
            $device_id = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;
            $config_version_id = !empty($_POST['config_version_id']) ? (int)$_POST['config_version_id'] : null;
            
            if ($device_id && $config_version_id) {
                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO device_config_assignments (device_id, config_version_id, assigned_by, assigned_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE config_version_id = VALUES(config_version_id), assigned_at = NOW()
                    ');
                    $stmt->execute([$device_id, $config_version_id, $admin_id]);
                    $success = 'Configuratie toegewezen.';
                } catch (Exception $e) {
                    error_log('Assignment error: ' . $e->getMessage());
                    $error = 'Kon toewijzing niet maken.';
                }
            }
        }
        
        // Unassign
        if ($action === 'unassign') {
            $device_id = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;
            if ($device_id) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM device_config_assignments WHERE device_id = ?');
                    $stmt->execute([$device_id]);
                    $success = 'Toewijzing verwijderd.';
                } catch (Exception $e) {
                    error_log('Unassignment error: ' . $e->getMessage());
                    $error = 'Kon toewijzing niet verwijderen.';
                }
            }
        }
        
        // Batch assign
        if ($action === 'batch_assign') {
            $device_ids = !empty($_POST['device_ids']) ? $_POST['device_ids'] : [];
            $config_version_id = !empty($_POST['batch_config_version_id']) ? (int)$_POST['batch_config_version_id'] : null;
            
            if (!empty($device_ids) && $config_version_id) {
                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO device_config_assignments (device_id, config_version_id, assigned_by, assigned_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE config_version_id = VALUES(config_version_id), assigned_at = NOW()
                    ');
                    
                    $count = 0;
                    foreach ($device_ids as $device_id) {
                        $stmt->execute([(int)$device_id, $config_version_id, $admin_id]);
                        $count++;
                    }
                    
                    $success = "Configuratie toegewezen aan $count device(s).";
                } catch (Exception $e) {
                    error_log('Batch assignment error: ' . $e->getMessage());
                    $error = 'Kon batch toewijzing niet maken.';
                }
            }
        }
        
        // Batch unassign
        if ($action === 'batch_unassign') {
            $device_ids = !empty($_POST['device_ids']) ? $_POST['device_ids'] : [];
            
            if (!empty($device_ids)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($device_ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM device_config_assignments WHERE device_id IN ($placeholders)");
                    $stmt->execute(array_map('intval', $device_ids));
                    $count = $stmt->rowCount();
                    $success = "$count toewijzing(en) verwijderd.";
                } catch (Exception $e) {
                    error_log('Batch unassignment error: ' . $e->getMessage());
                    $error = 'Kon batch verwijdering niet uitvoeren.';
                }
            }
        }
    }
}

// Load data
try {
    // Get all devices with their assigned configs
    $stmt = $pdo->query('
        SELECT d.id, d.device_name, d.mac_address, d.is_active,
               dt.type_name as device_type,
               cv.id as config_version_id, cv.version_number,
               p.pabx_name,
               dca.assigned_at
        FROM devices d
        LEFT JOIN device_types dt ON d.device_type_id = dt.id
        LEFT JOIN device_config_assignments dca ON d.id = dca.device_id
        LEFT JOIN config_versions cv ON dca.config_version_id = cv.id
        LEFT JOIN pabx p ON cv.pabx_id = p.id
        ORDER BY d.device_name
    ');
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all config versions
    $stmt = $pdo->query('
        SELECT cv.id, cv.version_number, cv.changelog,
               p.pabx_name, dt.type_name,
               (SELECT COUNT(*) FROM device_config_assignments WHERE config_version_id = cv.id) as device_count
        FROM config_versions cv
        LEFT JOIN pabx p ON cv.pabx_id = p.id
        LEFT JOIN device_types dt ON cv.device_type_id = dt.id
        WHERE cv.is_active = 1
        ORDER BY cv.created_at DESC
    ');
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistics
    $total_devices = count($devices);
    $assigned_devices = count(array_filter($devices, fn($d) => !empty($d['config_version_id'])));
    $unassigned_devices = $total_devices - $assigned_devices;
    
} catch (Exception $e) {
    error_log('Data load error: ' . $e->getMessage());
    $devices = [];
    $configs = [];
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Device Config Mapping - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .stats { display: flex; gap: 16px; margin-bottom: 16px; }
        .stat-card { flex: 1; padding: 12px; background: #f5f5f5; border-radius: 4px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #007bff; }
        .mapping-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .device-row { display: flex; justify-content: space-between; align-items: center; padding: 8px; border: 1px solid #ddd; margin-bottom: 4px; border-radius: 3px; }
        .device-row:hover { background: #f9f9f9; }
        .device-row.assigned { border-left: 3px solid #28a745; }
        .device-row.unassigned { border-left: 3px solid #ffc107; }
        .device-info { flex: 1; }
        .device-actions { display: flex; gap: 4px; }
        .config-card { padding: 12px; border: 1px solid #ddd; margin-bottom: 8px; border-radius: 4px; }
        .config-card:hover { background: #f9f9f9; }
        .badge { display: inline-block; padding: 2px 6px; font-size: 10px; border-radius: 3px; background: #6c757d; color: white; }
        .badge.success { background: #28a745; }
        .badge.warning { background: #ffc107; color: #000; }
        .batch-actions { background: #e9ecef; padding: 12px; border-radius: 4px; margin-bottom: 16px; }
        .selected-count { font-weight: bold; color: #007bff; }
    </style>
    <script>
        function selectAll(checked) {
            document.querySelectorAll('.device-checkbox').forEach(cb => cb.checked = checked);
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const count = document.querySelectorAll('.device-checkbox:checked').length;
            document.getElementById('selected-count').textContent = count;
        }
        
        function confirmBatchUnassign() {
            const count = document.querySelectorAll('.device-checkbox:checked').length;
            if (count === 0) {
                alert('Selecteer minimaal één device.');
                return false;
            }
            return confirm(`Weet je zeker dat je de toewijzing van ${count} device(s) wilt verwijderen?`);
        }
    </script>
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>
<main class="container">
    <h2>Device ↔ Config Mapping</h2>
    
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_devices; ?></div>
            <div>Totaal Devices</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#28a745;"><?php echo $assigned_devices; ?></div>
            <div>Toegewezen</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#ffc107;"><?php echo $unassigned_devices; ?></div>
            <div>Niet Toegewezen</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($configs); ?></div>
            <div>Actieve Configs</div>
        </div>
    </div>
    
    <div class="batch-actions">
        <h3>Batch Operaties <small>(<span id="selected-count" class="selected-count">0</span> geselecteerd)</small></h3>
        <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-group" style="margin:0;">
                <label>
                    <input type="checkbox" onclick="selectAll(this.checked)"> Selecteer alles
                </label>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                <label>Config Versie</label>
                <select name="batch_config_version_id">
                    <option value="">-- Kies config --</option>
                    <?php foreach ($configs as $cfg): ?>
                        <option value="<?php echo (int)$cfg['id']; ?>">
                            v<?php echo (int)$cfg['version_number']; ?> - <?php echo htmlspecialchars($cfg['type_name']); ?> (<?php echo htmlspecialchars($cfg['pabx_name']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit" name="action" value="batch_assign">Bulk Toewijzen</button>
            <button class="btn" type="submit" name="action" value="batch_unassign" style="background:#dc3545;" onclick="return confirmBatchUnassign();">Bulk Verwijderen</button>
        </form>
    </div>
    
    <div class="mapping-grid">
        <div class="card">
            <h3>Devices</h3>
            <?php if (empty($devices)): ?>
                <p>Geen devices gevonden.</p>
            <?php else: ?>
                <?php foreach ($devices as $dev): ?>
                    <div class="device-row <?php echo $dev['config_version_id'] ? 'assigned' : 'unassigned'; ?>">
                        <input type="checkbox" class="device-checkbox" name="device_ids[]" value="<?php echo (int)$dev['id']; ?>" form="batch-form" onchange="updateSelectedCount()">
                        <div class="device-info">
                            <strong><?php echo htmlspecialchars($dev['device_name']); ?></strong>
                            <small>(<?php echo htmlspecialchars($dev['device_type'] ?? '-'); ?>)</small>
                            <?php if (!$dev['is_active']): ?><span class="badge">INACTIVE</span><?php endif; ?>
                            <br>
                            <small style="color:#666;">
                                <?php if ($dev['config_version_id']): ?>
                                    ✓ Config v<?php echo (int)$dev['version_number']; ?> - <?php echo htmlspecialchars($dev['pabx_name']); ?>
                                <?php else: ?>
                                    ⚠ Geen config toegewezen
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="device-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="device_id" value="<?php echo (int)$dev['id']; ?>">
                                <?php if ($dev['config_version_id']): ?>
                                    <button class="btn" type="submit" name="action" value="unassign" style="font-size:11px;padding:4px 8px;background:#dc3545;">Verwijder</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>Config Versies</h3>
            <?php if (empty($configs)): ?>
                <p>Geen actieve configuraties gevonden.</p>
            <?php else: ?>
                <?php foreach ($configs as $cfg): ?>
                    <div class="config-card">
                        <div style="display:flex;justify-content:space-between;align-items:start;">
                            <div>
                                <strong>Versie <?php echo (int)$cfg['version_number']; ?></strong>
                                <span class="badge"><?php echo htmlspecialchars($cfg['type_name']); ?></span>
                                <br>
                                <small style="color:#666;">
                                    PABX: <?php echo htmlspecialchars($cfg['pabx_name']); ?>
                                    <?php if ($cfg['changelog']): ?><br><?php echo htmlspecialchars($cfg['changelog']); ?><?php endif; ?>
                                </small>
                                <br>
                                <small>
                                    <span class="badge success"><?php echo (int)$cfg['device_count']; ?> device(s)</span>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Hidden form for batch operations to reference -->
    <form id="batch-form" style="display:none;"></form>
</main>
</body>
</html>
