<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.variables.title');

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.variables.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $var_name = trim($_POST['var_name'] ?? '');
        $var_value = trim($_POST['var_value'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($var_name === '' || $var_value === '') {
            $error = __('error.variable_fields_required');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO variables (var_name, var_value, description) VALUES (?, ?, ?)');
                $stmt->execute([$var_name, $var_value, $description ?: null]);
                $success = __('success.variable_created');
            } catch (Exception $e) {
                error_log('variables create error: ' . $e->getMessage());
                $error = __('error.variable_create_failed');
            }
        }
    }
}

// Handle update/delete via POST with action param
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do']) && in_array($_POST['do'], ['update','delete'], true)) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) $error = __('error.invalid_id');
        else {
            if ($_POST['do'] === 'update') {
                $var_name = trim($_POST['var_name'] ?? '');
                $var_value = trim($_POST['var_value'] ?? '');
                $description = trim($_POST['description'] ?? '');
                if ($var_name === '' || $var_value === '') {
                    $error = __('error.variable_fields_required');
                } else {
                    try {
                        $stmt = $pdo->prepare('UPDATE variables SET var_name = ?, var_value = ?, description = ? WHERE id = ?');
                        $stmt->execute([$var_name, $var_value, $description ?: null, $id]);
                        $success = __('success.variable_updated');
                    } catch (Exception $e) {
                        error_log('variables update error: ' . $e->getMessage());
                        $error = __('error.variable_update_failed');
                    }
                }
            } elseif ($_POST['do'] === 'delete') {
                try {
                    $del = $pdo->prepare('DELETE FROM variables WHERE id = ?');
                    $del->execute([$id]);
                    $success = __('success.variable_deleted');
                } catch (Exception $e) {
                    error_log('variables delete error: ' . $e->getMessage());
                    $error = __('error.variable_delete_failed');
                }
            }
        }
    }
}

// Fetch all variables
try {
    $stmt = $pdo->query('SELECT id, var_name, var_value, description, created_at FROM variables ORDER BY var_name ASC');
    $vars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('variables fetch error: ' . $e->getMessage());
    $vars = [];
    $error = $error ?: __('error.variables_load_failed');
}

require_once __DIR__ . '/_header.php';
?>
<div class="container">
<style>.mono { font-family: monospace; }</style>

<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 24px; border-radius: 8px; margin-top: 80px; margin-bottom: 24px;">
    <h3 style="margin: 0 0 8px 0; font-size: 18px;"><?php echo __('label.global_variables_heading'); ?></h3>
    <p style="margin: 0; opacity: 0.9; font-size: 14px;">
        <?php echo __('label.global_variables_desc'); ?>
    </p>
</div>

<h2><?php echo __('page.variables.title'); ?> <?php echo __('label.variables_syntax_hint'); ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="card" style="margin-bottom:16px;">
        <h3><?php echo __('label.new_variable'); ?></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label><?php echo __('form.var_name_hint'); ?></label>
                <input name="var_name" type="text" pattern="[A-Z0-9_]+" placeholder="SERVER_IP" required>
            </div>
            <div class="form-group">
                <label><?php echo __('table.value'); ?></label>
                <input name="var_value" type="text" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.description'); ?> (<?php echo __('label.optional'); ?>)</label>
                <input name="description" type="text">
            </div>
            <button class="btn" type="submit"><?php echo __('button.create'); ?></button>
        </form>
    </section>

    <section class="card">
        <h3><?php echo __('label.existing_variables'); ?></h3>
        <?php if (empty($vars)): ?>
            <p><?php echo __('label.no_results'); ?></p>
        <?php else: ?>
            <table>
                <thead><tr><th><?php echo __('table.id'); ?></th><th><?php echo __('table.name'); ?></th><th><?php echo __('table.value'); ?></th><th><?php echo __('table.description'); ?></th><th><?php echo __('table.created_at'); ?></th><th><?php echo __('table.actions'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($vars as $v): ?>
                        <tr>
                            <td><?php echo (int)$v['id']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($v['var_name']); ?></td>
                            <td><?php echo htmlspecialchars($v['var_value']); ?></td>
                            <td><?php echo htmlspecialchars($v['description'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($v['created_at']); ?></td>
                            <td>
                                <button class="btn" onclick="document.getElementById('edit-<?php echo (int)$v['id']; ?>').style.display='block';"><?php echo __('button.edit'); ?></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo __('confirm.delete'); ?>');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="do" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                    <button class="btn" type="submit" style="background:#dc3545;"><?php echo __('button.delete'); ?></button>
                                </form>

                                <!-- inline edit form, hidden by default -->
                                <div id="edit-<?php echo (int)$v['id']; ?>" style="display:none; margin-top:8px; padding:8px; border:1px solid #eee; background:#fafafa;">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="do" value="update">
                                        <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                        <div class="form-group">
                                            <label><?php echo __('form.name'); ?></label>
                                            <input name="var_name" type="text" value="<?php echo htmlspecialchars($v['var_name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label><?php echo __('table.value'); ?></label>
                                            <input name="var_value" type="text" value="<?php echo htmlspecialchars($v['var_value']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label><?php echo __('form.description'); ?></label>
                                            <input name="description" type="text" value="<?php echo htmlspecialchars($v['description']); ?>">
                                        </div>
                                        <div style="display:flex; gap:8px;">
                                            <button class="btn" type="submit"><?php echo __('button.save'); ?></button>
                                            <button type="button" class="btn" onclick="document.getElementById('edit-<?php echo (int)$v['id']; ?>').style.display='none';" style="background:#6c757d;"><?php echo __('button.cancel'); ?></button>
                                        </div>
                                    </form>
                                </div>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
