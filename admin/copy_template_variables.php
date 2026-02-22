<?php
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.copy_template_variables.title');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
$permissions = get_admin_permissions($pdo, $admin_id);
$permission_map = array_flip($permissions);
if (!isset($permission_map['admin.templates.manage'])) {
    http_response_code(403);
    echo __('error.no_permission');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error   = '';
$success = '';

// Detect copyable columns in template_variables dynamically
function get_copy_columns(PDO $pdo): array {
    $exclude = ['id', 'created_at', 'updated_at'];
    $stmt = $pdo->query("SHOW COLUMNS FROM template_variables");
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if (!in_array($col['Field'], $exclude, true)) {
            $cols[] = $col['Field'];
        }
    }
    return $cols;
}

// Load all templates with variable counts
function load_templates(PDO $pdo): array {
    $stmt = $pdo->query('
        SELECT
            ct.id,
            ct.template_name AS name,
            dt.type_name     AS device_type_name,
            (SELECT COUNT(*) FROM template_variables WHERE template_id = ct.id) AS var_count
        FROM config_templates ct
        LEFT JOIN device_types dt ON ct.device_type_id = dt.id
        ORDER BY ct.template_name
    ');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $source_id  = !empty($_POST['source_id'])  ? (int)$_POST['source_id']  : null;
        $target_ids = !empty($_POST['target_ids'])  ? array_map('intval', (array)$_POST['target_ids']) : [];
        $overwrite  = !empty($_POST['overwrite']);

        if (!$source_id) {
            $error = __('page.copy_template_variables.err_no_source');
        } elseif (empty($target_ids)) {
            $error = __('page.copy_template_variables.err_no_target');
        } else {
            // Remove source from targets if accidentally included
            $target_ids = array_filter($target_ids, fn($id) => $id !== $source_id);
            if (empty($target_ids)) {
                $error = __('page.copy_template_variables.err_same');
            }
        }

        if (!$error) {
            try {
                $copy_cols = get_copy_columns($pdo);

                // Load source variables
                $stmt = $pdo->prepare('SELECT * FROM template_variables WHERE template_id = ? ORDER BY display_order, id');
                $stmt->execute([$source_id]);
                $source_vars = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($source_vars)) {
                    $error = __('page.copy_template_variables.no_source_vars');
                } else {
                    $total_added   = 0;
                    $total_skipped = 0;
                    $targets_done  = 0;

                    $pdo->beginTransaction();

                    foreach ($target_ids as $target_id) {
                        // Map old variable id -> new variable id (for parent_variable_id)
                        $id_map = [];

                        // First pass: insert all variables (without parent_variable_id)
                        foreach ($source_vars as $var) {
                            // Check for duplicate by var_name in target
                            $dup_stmt = $pdo->prepare('SELECT id FROM template_variables WHERE template_id = ? AND var_name = ?');
                            $dup_stmt->execute([$target_id, $var['var_name']]);
                            $existing = $dup_stmt->fetchColumn();

                            if ($existing && !$overwrite) {
                                $id_map[$var['id']] = $existing;
                                $total_skipped++;
                                continue;
                            }

                            // Build insert/update values excluding id, parent_variable_id, template_id
                            $insert_cols = array_filter($copy_cols, fn($c) => $c !== 'parent_variable_id' && $c !== 'template_id');
                            $insert_cols = array_values($insert_cols);

                            $values = [];
                            foreach ($insert_cols as $col) {
                                $values[] = $var[$col] ?? null;
                            }

                            if ($existing && $overwrite) {
                                // UPDATE
                                $set_parts = array_map(fn($c) => "`$c` = ?", $insert_cols);
                                $upd = $pdo->prepare(
                                    'UPDATE template_variables SET ' . implode(', ', $set_parts) .
                                    ' WHERE id = ?'
                                );
                                $upd->execute(array_merge($values, [$existing]));
                                $id_map[$var['id']] = $existing;
                                $total_added++;
                            } else {
                                // INSERT
                                $col_list = array_merge(['template_id'], $insert_cols);
                                $placeholders = implode(', ', array_fill(0, count($col_list), '?'));
                                $col_names    = implode(', ', array_map(fn($c) => "`$c`", $col_list));
                                $ins = $pdo->prepare(
                                    "INSERT INTO template_variables ($col_names) VALUES ($placeholders)"
                                );
                                $ins->execute(array_merge([$target_id], $values));
                                $new_id = (int)$pdo->lastInsertId();
                                $id_map[$var['id']] = $new_id;
                                $total_added++;
                            }
                        }

                        // Second pass: fix parent_variable_id if column exists
                        if (in_array('parent_variable_id', $copy_cols, true)) {
                            foreach ($source_vars as $var) {
                                if (!empty($var['parent_variable_id']) && isset($id_map[$var['id']], $id_map[$var['parent_variable_id']])) {
                                    $upd = $pdo->prepare(
                                        'UPDATE template_variables SET parent_variable_id = ? WHERE id = ? AND template_id = ?'
                                    );
                                    $upd->execute([$id_map[$var['parent_variable_id']], $id_map[$var['id']], $target_id]);
                                }
                            }
                        }

                        $targets_done++;
                    }

                    $pdo->commit();
                    $success = sprintf(
                        __('page.copy_template_variables.success'),
                        $targets_done,
                        $total_added,
                        $total_skipped
                    );
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('copy_template_variables error: ' . $e->getMessage());
                $error = __('error.general') ?: 'An error occurred while copying variables.';
            }
        }
    }
}

try {
    $templates = load_templates($pdo);
} catch (Exception $e) {
    error_log('copy_template_variables load error: ' . $e->getMessage());
    $templates = [];
}

$selected_source  = !empty($_POST['source_id'])  ? (int)$_POST['source_id']  : null;
$selected_targets = !empty($_POST['target_ids'])  ? array_map('intval', (array)$_POST['target_ids']) : [];

require_once __DIR__ . '/_header.php';
?>

<style>
    .copy-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    .template-list {
        max-height: 420px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: #fafafa;
    }
    .template-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        transition: background 0.15s;
    }
    .template-item:last-child { border-bottom: none; }
    .template-item:hover { background: #f0f0ff; }
    .template-item input[type="radio"],
    .template-item input[type="checkbox"] { cursor: pointer; flex-shrink: 0; }
    .template-item label { cursor: pointer; flex: 1; margin: 0; }
    .var-badge {
        background: #6c757d;
        color: #fff;
        font-size: 11px;
        padding: 2px 7px;
        border-radius: 10px;
        white-space: nowrap;
    }
    .var-badge.has-vars { background: #28a745; }
    .device-type-label { font-size: 11px; color: #999; }
    .overwrite-row { display: flex; align-items: center; gap: 8px; margin-top: 16px; }
    @media (max-width: 768px) {
        .copy-grid { grid-template-columns: 1fr; }
    }
</style>

<h2>📋 <?php echo __('page.copy_template_variables.title'); ?></h2>

<p>
    <a href="/admin/templates.php" class="btn" style="background:#6c757d;">← <?php echo __('button.back'); ?></a>
</p>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if (empty($templates)): ?>
    <div class="card"><p style="color:#666;"><?php echo __('page.copy_template_variables.no_templates'); ?></p></div>
<?php else: ?>
<form method="POST" id="copyForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

    <div class="copy-grid">
        <!-- SOURCE -->
        <div class="card">
            <h3>📤 <?php echo __('page.copy_template_variables.source'); ?></h3>
            <p style="color:#666;font-size:13px;"><?php echo __('page.copy_template_variables.select_source'); ?></p>
            <div class="template-list">
                <?php foreach ($templates as $tpl): ?>
                <div class="template-item" onclick="document.getElementById('src_<?php echo (int)$tpl['id']; ?>').click()">
                    <input type="radio" name="source_id" id="src_<?php echo (int)$tpl['id']; ?>"
                           value="<?php echo (int)$tpl['id']; ?>"
                           <?php echo $selected_source === (int)$tpl['id'] ? 'checked' : ''; ?>>
                    <label for="src_<?php echo (int)$tpl['id']; ?>">
                        <span><?php echo htmlspecialchars($tpl['name']); ?></span>
                        <?php if ($tpl['device_type_name']): ?>
                            <span class="device-type-label"> — <?php echo htmlspecialchars($tpl['device_type_name']); ?></span>
                        <?php endif; ?>
                    </label>
                    <span class="var-badge <?php echo $tpl['var_count'] > 0 ? 'has-vars' : ''; ?>">
                        <?php echo (int)$tpl['var_count']; ?> <?php echo __('page.copy_template_variables.vars'); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TARGET -->
        <div class="card">
            <h3>📥 <?php echo __('page.copy_template_variables.target'); ?></h3>
            <p style="color:#666;font-size:13px;"><?php echo __('page.copy_template_variables.select_targets'); ?></p>
            <div class="template-list">
                <?php foreach ($templates as $tpl): ?>
                <div class="template-item" onclick="document.getElementById('tgt_<?php echo (int)$tpl['id']; ?>').click()">
                    <input type="checkbox" name="target_ids[]" id="tgt_<?php echo (int)$tpl['id']; ?>"
                           value="<?php echo (int)$tpl['id']; ?>"
                           <?php echo in_array((int)$tpl['id'], $selected_targets, true) ? 'checked' : ''; ?>>
                    <label for="tgt_<?php echo (int)$tpl['id']; ?>">
                        <span><?php echo htmlspecialchars($tpl['name']); ?></span>
                        <?php if ($tpl['device_type_name']): ?>
                            <span class="device-type-label"> — <?php echo htmlspecialchars($tpl['device_type_name']); ?></span>
                        <?php endif; ?>
                    </label>
                    <span class="var-badge <?php echo $tpl['var_count'] > 0 ? 'has-vars' : ''; ?>">
                        <?php echo (int)$tpl['var_count']; ?> <?php echo __('page.copy_template_variables.vars'); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="overwrite-row">
                <input type="checkbox" name="overwrite" id="overwrite" value="1"
                       <?php echo !empty($_POST['overwrite']) ? 'checked' : ''; ?>>
                <label for="overwrite"><?php echo __('page.copy_template_variables.overwrite'); ?></label>
            </div>
        </div>
    </div>

    <div style="margin-top: 20px;">
        <button type="submit" class="btn" onclick="return confirmCopy()">
            📋 <?php echo __('page.copy_template_variables.copy_btn'); ?>
        </button>
    </div>
</form>

<script>
function confirmCopy() {
    var overwrite = document.getElementById('overwrite').checked;
    if (overwrite) {
        return confirm('<?php echo addslashes(__('confirm.delete') ?: 'Existing variables will be overwritten. Continue?'); ?>');
    }
    return true;
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
