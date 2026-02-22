<?php
$page_title = 'Device Mapping & Config Management';
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.device_mapping.title');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';
        
        // Set active config
        if ($action === 'set_active') {
            $device_id = (int)($_POST['device_id'] ?? 0);
            $config_version_id = (int)($_POST['config_version_id'] ?? 0);
            
            if ($device_id && $config_version_id) {
                try {
                    $pdo->beginTransaction();
                    
                    // Deactivate all other configs for this device
                    $stmt = $pdo->prepare('
                        UPDATE device_config_assignments 
                        SET is_active = 0, activated_at = NULL
                        WHERE device_id = ?
                    ');
                    $stmt->execute([$device_id]);
                    
                    // Activate this config
                    $stmt = $pdo->prepare('
                        UPDATE device_config_assignments 
                        SET is_active = 1, activated_at = NOW()
                        WHERE device_id = ? AND config_version_id = ?
                    ');
                    $stmt->execute([$device_id, $config_version_id]);
                    
                    // Log to history
                    $stmt = $pdo->prepare('
                        INSERT INTO config_version_history (device_id, config_version_id, is_active, activated_at, activated_by)
                        VALUES (?, ?, 1, NOW(), ?)
                    ');
                    $stmt->execute([$device_id, $config_version_id, $admin_id]);
                    
                    $pdo->commit();
                    $success = __('success.config_activated');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Set active error: ' . $e->getMessage());
                    $error = __('error.config_activate_failed');
                }
            }
        }
    }
}

// Get search/filter parameters
$search_device = isset($_GET['search_device']) ? trim($_GET['search_device']) : '';
$search_customer = isset($_GET['search_customer']) ? trim($_GET['search_customer']) : '';
$search_customer_code = isset($_GET['search_customer_code']) ? trim($_GET['search_customer_code']) : '';
$filter_type = isset($_GET['filter_type']) ? (int)$_GET['filter_type'] : 0;
$selected_device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;

// Get device types for filter
$device_types = [];
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Device types error: ' . $e->getMessage());
}

// Build search query for devices - FIXED: Use subquery to get active config info without duplicates
$devices = [];
$query = '
    SELECT 
        d.id,
        d.device_name,
        d.mac_address,
        d.is_active,
        dt.type_name,
        c.company_name,
        c.customer_code,
        (SELECT dca.config_version_id FROM device_config_assignments dca WHERE dca.device_id = d.id AND dca.is_active = 1 LIMIT 1) as config_version_id,
        (SELECT cv.version_number FROM device_config_assignments dca INNER JOIN config_versions cv ON dca.config_version_id = cv.id WHERE dca.device_id = d.id AND dca.is_active = 1 LIMIT 1) as version_number,
        (SELECT COUNT(*) FROM device_config_assignments dca WHERE dca.device_id = d.id AND dca.is_active = 1) as has_active_config
    FROM devices d
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    LEFT JOIN customers c ON d.customer_id = c.id
    WHERE d.deleted_at IS NULL
';

$params = [];

if (!empty($search_device)) {
    $query .= ' AND d.device_name LIKE ?';
    $params[] = '%' . $search_device . '%';
}

if (!empty($search_customer)) {
    $query .= ' AND c.company_name LIKE ?';
    $params[] = '%' . $search_customer . '%';
}

if (!empty($search_customer_code)) {
    $query .= ' AND c.customer_code LIKE ?';
    $params[] = '%' . $search_customer_code . '%';
}

if ($filter_type > 0) {
    $query .= ' AND d.device_type_id = ?';
    $params[] = $filter_type;
}

$query .= ' ORDER BY d.device_name ASC';

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Device search error: ' . $e->getMessage());
    $error = __('error.devices_search_failed');
    $devices = [];
}

// If device is selected, get all its configs and history
$selected_device = null;
$device_configs = [];
$device_history = [];

if ($selected_device_id > 0) {
    try {
        // Get device info
        $stmt = $pdo->prepare('
            SELECT d.id, d.device_name, d.mac_address, d.is_active, dt.type_name, c.company_name, c.customer_code
            FROM devices d
            LEFT JOIN device_types dt ON d.device_type_id = dt.id
            LEFT JOIN customers c ON d.customer_id = c.id
            WHERE d.id = ?
        ');
        $stmt->execute([$selected_device_id]);
        $selected_device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_device) {
            // Get all configs for this device (FIXED: properly show is_active status)
            $stmt = $pdo->prepare('
                SELECT 
                    cv.id,
                    cv.version_number,
                    cv.created_at,
                    cv.config_content,
                    dt.type_name,
                    dca.is_active,
                    dca.activated_at,
                    a.username as assigned_by_name
                FROM device_config_assignments dca
                INNER JOIN config_versions cv ON dca.config_version_id = cv.id
                LEFT JOIN device_types dt ON cv.device_type_id = dt.id
                LEFT JOIN admins a ON dca.assigned_by = a.id
                WHERE dca.device_id = ?
                ORDER BY dca.is_active DESC, cv.created_at DESC
            ');
            $stmt->execute([$selected_device_id]);
            $device_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get config history
            $stmt = $pdo->prepare('
                SELECT 
                    cvh.id,
                    cvh.config_version_id,
                    cvh.is_active,
                    cvh.activated_at,
                    cvh.deactivated_at,
                    cvh.duration_minutes,
                    cvh.notes,
                    cv.version_number,
                    a.username
                FROM config_version_history cvh
                INNER JOIN config_versions cv ON cvh.config_version_id = cv.id
                LEFT JOIN admins a ON cvh.activated_by = a.id
                WHERE cvh.device_id = ?
                ORDER BY cvh.activated_at DESC
                LIMIT 10
            ');
            $stmt->execute([$selected_device_id]);
            $device_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('Selected device error: ' . $e->getMessage());
        $error = __('error.device_load_failed');
    }
}

// Include header
if (file_exists(__DIR__ . '/../admin/_header.php')) {
    include __DIR__ . '/../admin/_header.php';
}
?>

<main class="container">
    <h2><?php echo __('label.device_config_management'); ?></h2>
    
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    
    <!-- Search & Filter Section -->
    <div class="card">
        <h3><?php echo __('label.search_filter_devices'); ?></h3>
        <form method="get" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 0;">
            <div>
                <label><?php echo __('form.device_name'); ?>:</label>
                <input type="text" name="search_device" placeholder="E.g. Reception Phone" value="<?php echo htmlspecialchars($search_device); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label><?php echo __('form.customer_name'); ?>:</label>
                <input type="text" name="search_customer" placeholder="E.g. Acme Corp" value="<?php echo htmlspecialchars($search_customer); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label><?php echo __('form.customer_code'); ?>:</label>
                <input type="text" name="search_customer_code" placeholder="E.g. CUST001" value="<?php echo htmlspecialchars($search_customer_code); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div>
                <label><?php echo __('form.device_type'); ?>:</label>
                <select name="filter_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value=""><?php echo __('form.all_types'); ?></option>
                    <?php foreach ($device_types as $type): ?>
                        <option value="<?php echo (int)$type['id']; ?>" <?php echo $filter_type == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['type_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 8px; align-items: flex-end;">
                <button type="submit" class="btn" style="flex: 1; background: #007bff;"><?php echo __('button.search'); ?></button>
                <a href="/settings/device_mapping.php" class="btn" style="background: #6c757d; text-decoration: none; flex: 1; text-align: center;"><?php echo __('button.clear'); ?></a>
            </div>
        </form>
    </div>

    <!-- Main Content Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
        
        <!-- Device List -->
        <div class="card">
            <h3>📱 <?php echo __('nav.devices'); ?> (<?php echo count($devices); ?> <?php echo __('label.found'); ?>)</h3>
            
            <?php if (empty($devices)): ?>
                <p style="color: #999; padding: 20px; text-align: center;"><?php echo __('label.no_devices_found_search'); ?></p>
            <?php else: ?>
                <div style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                    <?php foreach ($devices as $idx => $device): ?>
                        <div 
                            style="
                                padding: 12px;
                                border-bottom: 1px solid #eee;
                                cursor: pointer;
                                background: <?php echo $selected_device_id == $device['id'] ? '#e8f4f8' : '#fff'; ?>;
                                border-left: 4px solid <?php echo $device['has_active_config'] ? '#28a745' : '#ffc107'; ?>;
                                transition: background 0.2s;
                            "
                            onclick="window.location.href='?device_id=<?php echo (int)$device['id']; ?>&search_device=<?php echo urlencode($search_device); ?>&search_customer=<?php echo urlencode($search_customer); ?>&search_customer_code=<?php echo urlencode($search_customer_code); ?>&filter_type=<?php echo $filter_type; ?>';"
                            onmouseover="this.style.background='#f5f5f5';"
                            onmouseout="this.style.background='<?php echo $selected_device_id == $device['id'] ? '#e8f4f8' : '#fff'; ?>';"
                        >
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 4px;">
                                <strong><?php echo htmlspecialchars($device['device_name']); ?></strong>
                                <?php if ($device['has_active_config']): ?>
                                    <span style="background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">✓ <?php echo __('label.active_badge'); ?></span>
                                <?php else: ?>
                                    <span style="background: #ffc107; color: #333; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">⚠ <?php echo __('label.no_config_badge'); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <small style="color: #666; display: block; margin-bottom: 4px;">
                                <?php echo __('label.type_prefix'); ?> <?php echo htmlspecialchars($device['type_name'] ?? '-'); ?>
                            </small>
                            
                            <?php if ($device['company_name']): ?>
                                <small style="color: #666; display: block;">
                                    <?php echo __('label.customer_prefix'); ?> <?php echo htmlspecialchars($device['company_name']); ?>
                                    <?php if ($device['customer_code']): ?>
                                        (<?php echo htmlspecialchars($device['customer_code']); ?>)
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                            
                            <?php if ($device['has_active_config'] && $device['version_number']): ?>
                                <small style="color: #28a745; display: block; margin-top: 4px;">
                                    → Config v<?php echo (int)$device['version_number']; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Config Selection & Preview -->
        <div class="card">
            <h3><?php echo __('label.configuration_management'); ?></h3>
            
            <?php if (!$selected_device): ?>
                <div style="text-align: center; padding: 60px 20px; color: #999;">
                    <p style="font-size: 40px; margin: 0;">👈</p>
                    <p><?php echo __('label.select_device_to_manage'); ?></p>
                </div>
            <?php else: ?>
                <!-- Device Info -->
                <div style="margin-bottom: 16px; padding: 12px; background: #f0f8ff; border-radius: 4px; border-left: 4px solid #007bff;">
                    <strong><?php echo htmlspecialchars($selected_device['device_name']); ?></strong>
                    <br>
                    <small style="color: #666;">
                        <?php echo __('label.type_prefix'); ?> <?php echo htmlspecialchars($selected_device['type_name'] ?? '-'); ?>
                    </small>
                    <?php if ($selected_device['company_name']): ?>
                        <br>
                        <small style="color: #666;">
                            <?php echo __('label.customer_prefix'); ?> <?php echo htmlspecialchars($selected_device['company_name']); ?>
                            <?php if ($selected_device['customer_code']): ?>
                                (<?php echo htmlspecialchars($selected_device['customer_code']); ?>)
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                    <?php if ($selected_device['mac_address']): ?>
                        <br>
                        <small style="color: #666;">
                            <?php echo __('label.mac_prefix'); ?> <?php echo htmlspecialchars($selected_device['mac_address']); ?>
                        </small>
                    <?php endif; ?>
                </div>
                
                <!-- Configs List -->
                <?php if (empty($device_configs)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">
                        <?php echo __('label.no_configs_for_device'); ?>
                    </p>
                <?php else: ?>
                    <div style="max-height: 800px; overflow-y: auto;">
                        <?php foreach ($device_configs as $config): ?>
                            <div style="
                                padding: 12px;
                                margin-bottom: 12px;
                                border: 2px solid <?php echo $config['is_active'] ? '#28a745' : '#ddd'; ?>;
                                border-radius: 4px;
                                background: <?php echo $config['is_active'] ? '#f0fff4' : '#fff'; ?>;
                            ">
                                <!-- Config Header -->
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <strong><?php echo __('label.version_prefix'); ?> <?php echo (int)$config['version_number']; ?></strong>
                                    <?php if ($config['is_active']): ?>
                                        <span style="background: #28a745; color: white; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: bold;">✓ <?php echo __('label.active_badge'); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Config Details -->
                                <small style="color: #666; display: block;">
                                    <?php echo __('label.created_prefix'); ?> <?php echo date('Y-m-d H:i', strtotime($config['created_at'])); ?>
                                </small>
                                <?php if ($config['activated_at']): ?>
                                    <small style="color: #666; display: block;">
                                        <?php echo __('label.activated_prefix'); ?> <?php echo date('Y-m-d H:i', strtotime($config['activated_at'])); ?>
                                    </small>
                                <?php endif; ?>
                                
                                <!-- Preview Button -->
                                <button 
                                    type="button" 
                                    class="btn" 
                                    style="width: 100%; background: #007bff; margin: 8px 0; font-size: 12px; padding: 6px;" 
                                    onclick="
                                        const preview = document.getElementById('preview-<?php echo (int)$config['id']; ?>');
                                        preview.style.display = preview.style.display === 'none' ? 'block' : 'none';
                                    "
                                >
                                    <?php echo __('label.preview_config'); ?> (<?php echo strlen($config['config_content'] ?? ''); ?> <?php echo __('label.bytes'); ?>)
                                </button>
                                
                                <!-- Preview Content - FULL CONFIG -->
                                <div id="preview-<?php echo (int)$config['id']; ?>" style="
                                    display: none;
                                    background: #1e1e1e;
                                    color: #00ff00;
                                    padding: 12px;
                                    border-radius: 4px;
                                    max-height: 400px;
                                    overflow-y: auto;
                                    font-family: 'Courier New', monospace;
                                    font-size: 11px;
                                    white-space: pre-wrap;
                                    word-break: break-word;
                                    margin-bottom: 8px;
                                    border: 1px solid #444;
                                ">
<?php echo htmlspecialchars($config['config_content'] ?? ''); ?>
                                </div>
                                
                                <!-- Set Active Button (only for inactive configs) -->
                                <?php if (!$config['is_active']): ?>
                                    <form method="post" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="set_active">
                                        <input type="hidden" name="device_id" value="<?php echo (int)$selected_device['id']; ?>">
                                        <input type="hidden" name="config_version_id" value="<?php echo (int)$config['id']; ?>">
                                        <button type="submit" class="btn" style="width: 100%; background: #28a745; font-size: 12px; padding: 8px;">
                                            <?php echo __('button.set_active'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Config History -->
    <?php if ($selected_device && !empty($device_history)): ?>
        <div class="card" style="margin-top: 20px;">
            <h3><?php echo __('label.config_history_for'); ?> <?php echo htmlspecialchars($selected_device['device_name']); ?></h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                        <th style="padding: 10px; text-align: left;"><?php echo __('table.version'); ?></th>
                        <th style="padding: 10px; text-align: left;"><?php echo __('table.activated_at'); ?></th>
                        <th style="padding: 10px; text-align: left;"><?php echo __('table.duration'); ?></th>
                        <th style="padding: 10px; text-align: left;"><?php echo __('table.status'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($device_history as $hist): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;">v<?php echo (int)$hist['version_number']; ?></td>
                            <td style="padding: 10px;"><?php echo date('Y-m-d H:i', strtotime($hist['activated_at'])); ?></td>
                            <td style="padding: 10px;">
                                <?php if ($hist['duration_minutes']): ?>
                                    <?php echo (int)$hist['duration_minutes']; ?> <?php echo __('label.minutes'); ?>
                                <?php elseif ($hist['is_active']): ?>
                                    <?php echo __('label.currently_active'); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px;">
                                <?php echo $hist['is_active'] ? __('label.status_active_icon') : __('label.status_inactive_icon'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>


<?php require_once __DIR__ . '/../admin/_footer.php'; ?>
