<?php
$page_title = 'Config Cleanup';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'config.cleanup')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';
$preview = [];
$cleanup_log = [];
$last_cleanup = null;

// Define cleanup periods
$cleanup_periods = [
    '1 day' => ['label' => '1 Day', 'days' => 1],
    '1 week' => ['label' => '1 Week', 'days' => 7],
    '2 weeks' => ['label' => '2 Weeks', 'days' => 14],
    '1 month' => ['label' => '1 Month', 'days' => 30],
    '2 months' => ['label' => '2 Months', 'days' => 60],
    '3 months' => ['label' => '3 Months (Default)', 'days' => 90],
];

// Get selected period or default to 3 months
$selected_period = isset($_POST['cleanup_period']) ? $_POST['cleanup_period'] : '3 months';
if (!isset($cleanup_periods[$selected_period])) {
    $selected_period = '3 months';
}
$cutoff_days = $cleanup_periods[$selected_period]['days'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'preview') {
            $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . $cutoff_days . ' days'));

            // Find configs older than cutoff that are NOT active
            $stmt = $pdo->prepare('
                SELECT DISTINCT cv.id, cv.version_number, cv.created_at, dt.type_name
                FROM config_versions cv
                LEFT JOIN device_types dt ON cv.device_type_id = dt.id
                WHERE cv.created_at < ?
                AND cv.id NOT IN (
                    SELECT DISTINCT config_version_id FROM device_config_assignments WHERE is_active = 1
                )
                ORDER BY cv.created_at ASC
            ');
            $stmt->execute([$cutoff_date]);
            $preview = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($action === 'cleanup') {
            $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . $cutoff_days . ' days'));

            try {
                $pdo->beginTransaction();

                // Find ALL configs older than cutoff that are NOT active
                $stmt = $pdo->prepare('
                    SELECT DISTINCT cv.id
                    FROM config_versions cv
                    WHERE cv.created_at < ?
                    AND cv.id NOT IN (
                        SELECT DISTINCT config_version_id FROM device_config_assignments WHERE is_active = 1
                    )
                ');
                $stmt->execute([$cutoff_date]);
                $configs_to_delete = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

                $deleted_count = count($configs_to_delete);
                $period_label = $cleanup_periods[$selected_period]['label'];
                $cleanup_timestamp = date('Y-m-d H:i:s');

                if (!empty($configs_to_delete)) {
                    // Log each deleted config with same timestamp
                    $stmt = $pdo->prepare('
                        INSERT INTO config_cleanup_logs (config_version_id, reason, cleaned_up_by, cleaned_up_at)
                        VALUES (?, ?, ?, ?)
                    ');

                    foreach ($configs_to_delete as $id) {
                        $stmt->execute([
                            $id, 
                            'Deleted - Older than ' . $period_label, 
                            $admin_id,
                            $cleanup_timestamp
                        ]);
                    }

                    // Delete assignments first
                    $placeholders = implode(',', array_fill(0, count($configs_to_delete), '?'));
                    $stmt = $pdo->prepare("DELETE FROM device_config_assignments WHERE config_version_id IN ($placeholders)");
                    $stmt->execute($configs_to_delete);

                    // Delete configs
                    $stmt = $pdo->prepare("DELETE FROM config_versions WHERE id IN ($placeholders)");
                    $stmt->execute($configs_to_delete);
                } else {
                    // Log when nothing was deleted
                    $stmt = $pdo->prepare('
                        INSERT INTO config_cleanup_logs (config_version_id, reason, cleaned_up_by, cleaned_up_at)
                        VALUES (NULL, ?, ?, ?)
                    ');
                    $stmt->execute([
                        'Cleanup run - no configs older than ' . $period_label . ' found',
                        $admin_id,
                        $cleanup_timestamp
                    ]);
                }

                $pdo->commit();
                
                if ($deleted_count > 0) {
                    $success = '✓ ' . $deleted_count . ' config(s) deleted successfully! (Older than ' . $period_label . ')';
                } else {
                    $success = '✓ Cleanup completed! No configurations older than ' . $period_label . ' found to delete.';
                }
                
                // Refresh page
                header('Location: /admin/config_cleanup.php');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Cleanup error: ' . $e->getMessage());
                $error = 'Cleanup failed: ' . $e->getMessage();
            }
        }
    }
}

// Get LAST cleanup
try {
    $stmt = $pdo->query('
        SELECT 
            cleaned_up_at,
            cleaned_up_by,
            (SELECT username FROM admins WHERE id = cleaned_up_by LIMIT 1) as username
        FROM config_cleanup_logs
        ORDER BY cleaned_up_at DESC
        LIMIT 1
    ');
    $last_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($last_result) {
        // Count logs where reason contains "Deleted"
        $stmt2 = $pdo->prepare('
            SELECT COUNT(*) as deleted_count, MAX(reason) as reason
            FROM config_cleanup_logs
            WHERE cleaned_up_at = ?
            AND cleaned_up_by = ?
            AND reason LIKE "%Deleted%"
        ');
        $stmt2->execute([$last_result['cleaned_up_at'], $last_result['cleaned_up_by']]);
        $counts = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $last_cleanup = array_merge($last_result, $counts);
    }
} catch (Exception $e) {
    error_log('Error fetching last cleanup: ' . $e->getMessage());
}

// Get cleanup log - count by reason
try {
    // Get distinct sessions
    $stmt = $pdo->query('
        SELECT DISTINCT cleaned_up_at, cleaned_up_by
        FROM config_cleanup_logs
        ORDER BY cleaned_up_at DESC
        LIMIT 5
    ');
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cleanup_log = [];
    foreach ($sessions as $session) {
        // Count logs where reason contains "Deleted"
        $stmt2 = $pdo->prepare('
            SELECT 
                cleaned_up_at,
                cleaned_up_by,
                (SELECT username FROM admins WHERE id = cleaned_up_by LIMIT 1) as username,
                COUNT(*) as deleted_configs,
                MAX(reason) as reason
            FROM config_cleanup_logs
            WHERE cleaned_up_at = ?
            AND cleaned_up_by = ?
            AND reason LIKE "%Deleted%"
        ');
        $stmt2->execute([$session['cleaned_up_at'], $session['cleaned_up_by']]);
        $log_entry = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        if ($log_entry && (int)$log_entry['deleted_configs'] > 0) {
            $cleanup_log[] = $log_entry;
        } else {
            // Get the no-change entry
            $stmt3 = $pdo->prepare('
                SELECT 
                    cleaned_up_at,
                    cleaned_up_by,
                    (SELECT username FROM admins WHERE id = cleaned_up_by LIMIT 1) as username,
                    0 as deleted_configs,
                    MAX(reason) as reason
                FROM config_cleanup_logs
                WHERE cleaned_up_at = ?
                AND cleaned_up_by = ?
                AND reason NOT LIKE "%Deleted%"
            ');
            $stmt3->execute([$session['cleaned_up_at'], $session['cleaned_up_by']]);
            $no_change = $stmt3->fetch(PDO::FETCH_ASSOC);
            if ($no_change) {
                $cleanup_log[] = $no_change;
            }
        }
    }
} catch (Exception $e) {
    error_log('Error fetching cleanup log: ' . $e->getMessage());
}

// Get total stats
$total_deleted = 0;
try {
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM config_cleanup_logs WHERE reason LIKE "%Deleted%"');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_deleted = (int)$result['count'];
} catch (Exception $e) {
    error_log('Error counting total deleted: ' . $e->getMessage());
}

require_once __DIR__ . '/_header.php';
?>

<h2>⚙️ <?php echo __('page.config_cleanup.title'); ?></h2>

<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<!-- Last Cleanup Dashboard -->
<div class="card" style="background: linear-gradient(135deg, #5d00b8 0%, #764ba2 100%); color: white; margin-bottom: 20px;">
    <h3 style="color: white; margin-top: 0;">📊 <?php echo __('label.last_cleanup_summary'); ?></h3>
    
    <?php if ($last_cleanup): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div style="padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                <small style="opacity: 0.8; display: block;"><?php echo __('label.last_cleanup'); ?></small>
                <strong style="font-size: 18px; display: block; margin-top: 8px;">
                    <?php echo date('Y-m-d H:i', strtotime($last_cleanup['cleaned_up_at'])); ?>
                </strong>
                <small style="opacity: 0.8; display: block; margin-top: 4px;">
                    <?php 
                    $days_ago = floor((time() - strtotime($last_cleanup['cleaned_up_at'])) / 86400);
                    echo $days_ago === 0 ? '🟢 ' . __('label.today') : '📅 ' . $days_ago . ' ' . __('label.days_ago');
                    ?>
                </small>
            </div>
            
            <div style="padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                <small style="opacity: 0.8; display: block;"><?php echo __('label.cleaned_by'); ?></small>
                <strong style="font-size: 18px; display: block; margin-top: 8px;">
                    <?php echo htmlspecialchars($last_cleanup['username'] ?? 'System'); ?>
                </strong>
            </div>
            
            <div style="padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                <small style="opacity: 0.8; display: block;"><?php echo __('label.last_deleted'); ?></small>
                <strong style="font-size: 18px; display: block; margin-top: 8px; color: #ffeb3b;">
                    <?php echo (int)$last_cleanup['deleted_count']; ?>
                </strong>
            </div>
            
            <div style="padding: 16px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                <small style="opacity: 0.8; display: block;"><?php echo __('label.total_deleted_alltime'); ?></small>
                <strong style="font-size: 18px; display: block; margin-top: 8px;">
                    <?php echo $total_deleted; ?>
                </strong>
            </div>
        </div>
    <?php else: ?>
        <p style="margin: 0;">⏳ <?php echo __('label.no_cleanup_yet'); ?></p>
    <?php endif; ?>
</div>

<!-- Cleanup Actions -->
<div class="card">
    <h3>🧹 <?php echo __('label.execute_cleanup'); ?></h3>
    <p><?php echo __('label.remove_config_desc'); ?></p>

    <form method="post" style="display: flex; gap: 12px; margin: 16px 0; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        
        <div style="flex: 1; min-width: 200px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                <?php echo __('label.delete_configs_older_than'); ?>
            </label>
            <select name="cleanup_period" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                <?php foreach ($cleanup_periods as $key => $period): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $selected_period === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($period['label']); ?> (<?php echo $period['days']; ?> <?php echo __('label.days'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" name="action" value="preview" class="btn" style="background: #007bff; padding: 10px 24px; font-weight: 600;">
            👁️ Preview
        </button>
        <button type="submit" name="action" value="cleanup" class="btn" style="background: #dc3545; padding: 10px 24px; font-weight: 600;" onclick="return confirm('⚠️ <?php echo htmlspecialchars(__('confirm.cleanup_run')); ?>');"
>
            🗑️ <?php echo __('button.cleanup_now'); ?>
        </button>
    </form>

    <div style="background: #f0f8ff; border-left: 4px solid #007bff; padding: 12px; border-radius: 4px; margin-top: 12px;">
        <small style="color: #004085;">
            <strong>💡 Tip:</strong> <?php echo __('label.cleanup_tip'); ?>
        </small>
    </div>
</div>

<?php if (!empty($preview)): ?>
    <div class="card">
        <h3>👁️ Preview: <?php echo count($preview); ?> <?php echo __('label.configs_to_delete'); ?></h3>
        <p style="color: #666; font-size: 13px;">Configs older than <strong><?php echo htmlspecialchars($cleanup_periods[$selected_period]['label']); ?></strong> <?php echo __('label.not_active_paren'); ?></p>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                        <th style="padding: 12px; text-align: left;"><?php echo __('table.version'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php echo __('table.type'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php echo __('table.created'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php echo __('label.days_ago'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $cfg): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">v<?php echo (int)$cfg['version_number']; ?></td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($cfg['type_name'] ?? 'Unknown'); ?></td>
                            <td style="padding: 12px;"><?php echo date('Y-m-d', strtotime($cfg['created_at'])); ?></td>
                            <td style="padding: 12px;">
                                <span style="background: #ffc107; color: #333; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold;">
                                    <?php echo floor((time() - strtotime($cfg['created_at'])) / 86400); ?> <?php echo __('label.days'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Cleanup History - Last 5 only -->
<div class="card">
    <h3>📋 <?php echo __('label.cleanup_history'); ?></h3>
    <?php if (empty($cleanup_log)): ?>
        <p style="color: #999; text-align: center; padding: 20px;"><?php echo __('label.no_cleanup_history'); ?></p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                        <th style="padding: 12px; text-align: left;"><?php echo __('table.date_time'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php echo __('table.reason'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php echo __('label.deleted_count'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php echo __('table.cleaned_by'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cleanup_log as $log): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">
                                <strong><?php echo date('Y-m-d H:i', strtotime($log['cleaned_up_at'])); ?></strong>
                                <br>
                                <small style="color: #999;">
                                    <?php 
                                    $days_ago = floor((time() - strtotime($log['cleaned_up_at'])) / 86400);
                                    echo $days_ago === 0 ? __('label.today') : $days_ago . ' ' . __('label.days_ago');
                                    ?>
                                </small>
                            </td>
                            <td style="padding: 12px;">
                                <small><?php echo htmlspecialchars($log['reason']); ?></small>
                            </td>
                            <td style="padding: 12px;">
                                <?php if ((int)$log['deleted_configs'] > 0): ?>
                                    <span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                        ✓ <?php echo (int)$log['deleted_configs']; ?> <?php echo __('label.deleted_count'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="background: #d1ecf1; color: #0c5460; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                        ⓘ <?php echo __('label.no_changes'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <span style="background: #e8f4f8; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
                                    👤 <?php echo htmlspecialchars($log['username'] ?? 'System'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
    .alert {
        padding: 12px;
        margin-bottom: 16px;
        border-radius: 4px;
        border-left: 4px solid;
    }

    .alert-error {
        background: #f8d7da;
        border-color: #f5c6cb;
        color: #721c24;
    }

    .alert-success {
        background: #d4edda;
        border-color: #c3e6cb;
        color: #155724;
    }

    .btn {
        padding: 10px 16px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        color: white;
        transition: opacity 0.2s;
        white-space: nowrap;
    }

    .btn:hover {
        opacity: 0.9;
    }

    .card {
        background: white;
        padding: 16px;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }

    .card h3 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 18px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        font-weight: 600;
        text-align: left;
    }

    td {
        vertical-align: middle;
    }

    h2 {
        margin-top: 0;
        margin-bottom: 20px;
        font-size: 28px;
    }

    label {
        font-size: 13px;
        color: #333;
    }
</style>

<?php require_once __DIR__ . '/_footer.php'; ?>
