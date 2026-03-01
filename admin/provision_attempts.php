<?php
$page_title = 'Provision Attempts';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.audit.view')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF token for model mapping form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error   = '';
$success = '';

// Handle model mapping save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mapping') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $model     = strtoupper(trim($_POST['model'] ?? ''));
        $device_type_id = (int)($_POST['device_type_id'] ?? 0);
        if ($model === '' || $device_type_id <= 0) {
            $error = __('error.provision_mapping_fields_required');
        } else {
            try {
                $pdo->prepare('
                    INSERT INTO device_model_mappings (model, device_type_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE device_type_id = VALUES(device_type_id), updated_at = NOW()
                ')->execute([$model, $device_type_id]);
                $success = __('success.provision_mapping_saved');
            } catch (Exception $e) {
                error_log('provision_attempts save_mapping error: ' . $e->getMessage());
                $error = __('error.provision_mapping_save_failed');
            }
        }
    }
}

// Handle model mapping delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_mapping') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $map_id = (int)($_POST['mapping_id'] ?? 0);
        if ($map_id > 0) {
            try {
                $pdo->prepare('DELETE FROM device_model_mappings WHERE id = ?')->execute([$map_id]);
                $success = __('success.provision_mapping_deleted');
            } catch (Exception $e) {
                error_log('provision_attempts delete_mapping error: ' . $e->getMessage());
            }
        }
    }
}

// Filters
$filter_status = trim($_GET['status']  ?? '');
$filter_model  = trim($_GET['model']   ?? '');
$filter_mac    = trim($_GET['mac']     ?? '');

// Normalise MAC filter: accept colon or no-colon
$filter_mac_norm = strtoupper(preg_replace('/[^0-9A-F]/i', '', $filter_mac));

$where   = [];
$params  = [];

if ($filter_status !== '') {
    $where[]  = 'pa.status = ?';
    $params[] = $filter_status;
}
if ($filter_model !== '') {
    $where[]  = 'pa.device_model LIKE ?';
    $params[] = '%' . $filter_model . '%';
}
if ($filter_mac_norm !== '') {
    $where[]  = 'pa.mac_normalized LIKE ?';
    $params[] = '%' . $filter_mac_norm . '%';
}

// Force view: only show __mac__.boot buckets
$where[]  = "pa.requested_filename = '__mac__.boot'";

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Load latest attempt per mac_normalized (or per row if mac is null)
try {
    $sql = "
        SELECT pa.*,
               d.id         AS existing_device_id,
               d.device_name,
               dca.is_active AS has_active_config,
               dmm.device_type_id AS suggested_type_id,
               dt2.type_name      AS suggested_type_name
        FROM provision_attempts pa
        LEFT JOIN devices d
          ON d.mac_address COLLATE utf8mb4_unicode_ci = pa.mac_formatted COLLATE utf8mb4_unicode_ci
         AND d.is_active = 1
        LEFT JOIN device_config_assignments dca ON dca.device_id = d.id AND dca.is_active = 1
        LEFT JOIN device_model_mappings dmm
          ON dmm.model COLLATE utf8mb4_unicode_ci = pa.device_model COLLATE utf8mb4_unicode_ci
        LEFT JOIN device_types dt2 ON dt2.id = dmm.device_type_id
        $where_sql
        ORDER BY pa.last_seen_at DESC
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('provision_attempts.php fetch error: ' . $e->getMessage());
    $attempts = [];
    $error = 'Could not load provision attempts.';
}

// Load device types for mapping form
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $device_types = [];
}

// Load existing mappings
try {
    $stmt = $pdo->query('
        SELECT dmm.*, dt.type_name
        FROM device_model_mappings dmm
        LEFT JOIN device_types dt ON dt.id = dmm.device_type_id
        ORDER BY dmm.model ASC
    ');
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mappings = [];
}

// Distinct statuses and models for filter dropdowns
$all_statuses = ['success', 'device_not_found', 'no_active_config', 'invalid_mac', 'blocked_user_agent', 'server_error', 'db_error'];
$all_models   = [];
foreach ($attempts as $a) {
    if ($a['device_model'] && !in_array($a['device_model'], $all_models)) {
        $all_models[] = $a['device_model'];
    }
}
sort($all_models);

require_once __DIR__ . '/_header.php';
?>

<style>
.badge-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.badge-success      { background: #d4edda; color: #155724; }
.badge-not-found    { background: #f8d7da; color: #721c24; }
.badge-no-config    { background: #fff3cd; color: #856404; }
.badge-invalid      { background: #e2e3e5; color: #383d41; }
.badge-blocked      { background: #f8d7da; color: #721c24; }
.badge-error        { background: #f8d7da; color: #721c24; }
.provision-table td, .provision-table th { font-size: 13px; }
.section-title { font-size: 16px; font-weight: 600; margin: 24px 0 8px; }
</style>

<h2>📡 <?php echo __('nav.provision_attempts'); ?></h2>

<?php if ($error):   ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<!-- Filters -->
<form method="get" class="card" style="margin-bottom:16px;">
    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
        <div class="form-group" style="margin:0; flex:1; min-width:140px;">
            <label><?php echo __('label.status'); ?></label>
            <select name="status">
                <option value="">— <?php echo __('label.all_statuses'); ?> —</option>
                <?php foreach ($all_statuses as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $filter_status === $s ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0; flex:1; min-width:120px;">
            <label><?php echo __('label.model'); ?></label>
            <input name="model" value="<?php echo htmlspecialchars($filter_model); ?>" placeholder="e.g. W75DM">
        </div>
        <div class="form-group" style="margin:0; flex:1; min-width:160px;">
            <label><?php echo __('form.mac_address'); ?></label>
            <input name="mac" value="<?php echo htmlspecialchars($filter_mac); ?>" placeholder="001565AABBCC or 00:15:65:...">
        </div>
        <div style="margin-bottom:4px;">
            <button class="btn" type="submit"><?php echo __('label.filter'); ?></button>
            <a href="/admin/provision_attempts.php" class="btn" style="background:#6c757d; text-decoration:none; margin-left:4px;"><?php echo __('button.reset'); ?></a>
        </div>
    </div>
</form>

<!-- Attempts table -->
<section class="card" style="overflow-x:auto;">
    <?php if (empty($attempts)): ?>
        <p><?php echo __('label.no_results'); ?></p>
    <?php else: ?>
        <table class="provision-table">
            <thead>
                <tr>
                    <th><?php echo __('table.mac'); ?></th>
                    <th><?php echo __('label.model'); ?></th>
                    <th><?php echo __('label.ip_address'); ?></th>
                    <th><?php echo __('label.status'); ?></th>
                    <th><?php echo __('label.last_attempt'); ?></th>
                    <th><?php echo __('label.attempt_count'); ?></th>
                    <th><?php echo __('label.device_in_system'); ?></th>
                    <th><?php echo __('label.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attempts as $a): ?>
                <?php
                    $status_class = match($a['status']) {
                        'success'           => 'badge-success',
                        'device_not_found'  => 'badge-not-found',
                        'no_active_config'  => 'badge-no-config',
                        'invalid_mac'       => 'badge-invalid',
                        'blocked_user_agent'=> 'badge-blocked',
                        default             => 'badge-error',
                    };
                    $mac_display = $a['mac_formatted'] ?? ($a['mac_normalized'] ?? '—');
                    $device_exists = !empty($a['existing_device_id']);
                ?>
                <tr>
                    <td>
                        <code><?php echo htmlspecialchars($mac_display); ?></code>
                        <?php if (!empty($a['requested_filename']) && $a['requested_filename'] !== '__mac__.boot'): ?>
                            <br><small style="color:#999;"><?php echo htmlspecialchars($a['requested_filename']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $a['device_model'] ? htmlspecialchars($a['device_model']) : '—'; ?></td>
                    <td><?php echo htmlspecialchars($a['ip_address']); ?></td>
                    <td><span class="badge-status <?php echo $status_class; ?>"><?php echo htmlspecialchars($a['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($a['last_seen_at']); ?></td>
                    <td>
                        <?php echo (int)$a['attempt_count']; ?>
                        <?php if ($a['first_seen_at'] !== $a['last_seen_at']): ?>
                            <br><small style="color:#999;"><?php echo __('label.first_seen'); ?>: <?php echo htmlspecialchars(substr($a['first_seen_at'], 0, 16)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($device_exists): ?>
                            ✅ <a href="/devices/list.php?id=<?php echo (int)$a['existing_device_id']; ?>"><?php echo htmlspecialchars($a['device_name'] ?? ''); ?></a>
                            <?php if (!$a['has_active_config']): ?>
                                <br><small style="color:#856404;">⚠️ <?php echo __('label.no_active_config'); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$device_exists && $a['mac_normalized']): ?>
                            <?php
                            $create_url = '/devices/create.php?mac=' . urlencode($a['mac_formatted'] ?? $a['mac_normalized']);
                            if ($a['suggested_type_id']) {
                                $create_url .= '&device_type_id=' . (int)$a['suggested_type_id'];
                            }
                            if ($a['device_model']) {
                                $create_url .= '&device_model=' . urlencode($a['device_model']);
                            }
                            ?>
                            <a href="<?php echo htmlspecialchars($create_url); ?>" class="btn" style="font-size:12px; padding:4px 8px; text-decoration:none;">
                                ➕ <?php echo __('button.create_device'); ?>
                                <?php if ($a['suggested_type_name']): ?>
                                    <small>(<?php echo htmlspecialchars($a['suggested_type_name']); ?>)</small>
                                <?php endif; ?>
                            </a>
                        <?php elseif ($device_exists && !$a['has_active_config']): ?>
                            <a href="/settings/device_mapping.php" class="btn" style="font-size:12px; padding:4px 8px; background:#ffc107; color:#000; text-decoration:none;">
                                ⚙️ <?php echo __('button.assign_config'); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- Device model → type mappings -->
<div class="section-title">🗺️ <?php echo __('label.model_type_mappings'); ?></div>

<div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
    <!-- Add/update mapping form -->
    <div class="card" style="min-width:320px; flex:1;">
        <h3 style="margin-top:0;"><?php echo __('label.add_model_mapping'); ?></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_mapping">
            <div class="form-group">
                <label><?php echo __('label.model'); ?></label>
                <input name="model" type="text" placeholder="e.g. W75DM" style="text-transform:uppercase;" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.device_type'); ?></label>
                <select name="device_type_id" required>
                    <option value="">— <?php echo __('form.select_model'); ?> —</option>
                    <?php foreach ($device_types as $dt): ?>
                        <option value="<?php echo (int)$dt['id']; ?>"><?php echo htmlspecialchars($dt['type_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit"><?php echo __('button.save'); ?></button>
        </form>
    </div>

    <!-- Existing mappings -->
    <?php if ($mappings): ?>
    <div class="card" style="flex:2; min-width:320px;">
        <h3 style="margin-top:0;"><?php echo __('label.existing_mappings'); ?></h3>
        <table>
            <thead><tr><th><?php echo __('label.model'); ?></th><th><?php echo __('form.device_type'); ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($mappings as $m): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($m['model']); ?></code></td>
                    <td><?php echo htmlspecialchars($m['type_name'] ?? '—'); ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm(<?php echo json_encode(__('confirm.delete')); ?>);">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="delete_mapping">
                            <input type="hidden" name="mapping_id" value="<?php echo (int)$m['id']; ?>">
                            <button class="btn" style="background:#dc3545; padding:4px 8px; font-size:12px;" type="submit">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
