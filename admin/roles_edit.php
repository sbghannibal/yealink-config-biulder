<?php
$page_title = 'Rol Bewerken';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.roles.manage')) {
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

$role_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($role_id <= 0) {
    header('Location: /admin/roles.php');
    exit;
}

// All known permissions grouped by category
$all_permissions = [
    'devices'   => ['devices.manage', 'devices.view', 'devices.delete'],
    'customers' => ['customers.view', 'customers.manage', 'customers.delete'],
    'config'    => ['config.manage', 'config.cleanup'],
    'templates' => ['admin.templates.manage'],
    'admin'     => [
        'admin.users.view', 'admin.users.manage',
        'admin.roles.manage',
        'admin.settings.edit',
        'admin.audit.view',
        'admin.variables.manage',
        'admin.device_types.manage',
        'admin.tokens.manage',
        'admin.accounts.manage',
    ],
    'system'    => ['variables.manage'],
];

$system_roles = ['owner', 'admin', 'user'];

// Fetch role
try {
    $stmt = $pdo->prepare('SELECT id, role_name, description FROM roles WHERE id = ? LIMIT 1');
    $stmt->execute([$role_id]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        header('Location: /admin/roles.php?notfound=1');
        exit;
    }
} catch (Exception $e) {
    error_log('roles_edit fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen rol.';
    exit;
}

$is_system = in_array(strtolower($role['role_name']), $system_roles);

// Fetch current permissions for this role
try {
    $stmt = $pdo->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
    $stmt->execute([$role_id]);
    $current_perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $current_perms = [];
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $description = trim($_POST['description'] ?? '');
        $selected_perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];

        // Sanitize: only allow known permissions
        $flat_perms = array_merge(...array_values($all_permissions));
        $selected_perms = array_filter($selected_perms, fn($p) => in_array($p, $flat_perms, true));

        try {
            $pdo->beginTransaction();

            // Update description (name is readonly for system roles)
            if (!$is_system) {
                $role_name = trim($_POST['role_name'] ?? $role['role_name']);
                $stmt = $pdo->prepare('UPDATE roles SET role_name = ?, description = ? WHERE id = ?');
                $stmt->execute([$role_name, $description, $role_id]);
            } else {
                $stmt = $pdo->prepare('UPDATE roles SET description = ? WHERE id = ?');
                $stmt->execute([$description, $role_id]);
            }

            // Replace permissions
            $del = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $del->execute([$role_id]);

            if (!empty($selected_perms)) {
                $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
                foreach ($selected_perms as $perm) {
                    $ins->execute([$role_id, $perm]);
                }
            }

            $pdo->commit();
            $success = 'Rol bijgewerkt.';
            $current_perms = array_values($selected_perms);
            if (!$is_system) {
                $role['role_name'] = $_POST['role_name'] ?? $role['role_name'];
            }
            $role['description'] = $description;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('roles_edit save error: ' . $e->getMessage());
            $error = 'Kon rol niet opslaan.';
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<h2>✏️ <?php echo __('page.roles.edit'); ?></h2>

<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<section class="card">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$role_id; ?>">

        <div class="form-group">
            <label><?php echo __('form.name'); ?></label>
            <?php if ($is_system): ?>
                <input type="text" value="<?php echo htmlspecialchars($role['role_name']); ?>" readonly style="background:#f8f9fa; cursor:not-allowed;">
                <small style="color:#6c757d;">Systeemrollen kunnen niet worden hernoemd.</small>
            <?php else: ?>
                <input name="role_name" type="text" value="<?php echo htmlspecialchars($role['role_name']); ?>" required>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label><?php echo __('form.description'); ?></label>
            <textarea name="description" rows="3" style="width:100%;"><?php echo htmlspecialchars($role['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label style="display:block; margin-bottom:12px; font-weight:600;"><?php echo __('form.permissions'); ?></label>
            <?php foreach ($all_permissions as $category => $perms): ?>
                <div style="margin-bottom:16px;">
                    <strong style="text-transform:capitalize; color:#667eea;"><?php echo htmlspecialchars($category); ?></strong>
                    <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($perms as $perm): ?>
                            <label style="display:flex; align-items:center; gap:6px; font-weight:normal; background:#f8f9fa; padding:6px 10px; border-radius:4px; cursor:pointer;">
                                <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($perm); ?>"
                                    <?php echo in_array($perm, $current_perms) ? 'checked' : ''; ?>>
                                <span style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($perm); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex; gap:8px; margin-top:16px;">
            <button class="btn" type="submit" style="background:#28a745;"><?php echo __('button.save'); ?></button>
            <a class="btn" href="/admin/roles.php" style="background:#6c757d;"><?php echo __('button.cancel'); ?></a>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/_footer.php'; ?>
