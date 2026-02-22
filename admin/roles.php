<?php
$page_title = 'Rollen & permissies';
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

// CSRF ensure
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle role creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_role') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag.';
    } else {
        $role_name = trim($_POST['role_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($role_name === '') {
            $error = 'Rolnaam is verplicht.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO roles (role_name, description) VALUES (?, ?)');
                $stmt->execute([$role_name, $description]);
                $success = 'Rol aangemaakt.';
            } catch (Exception $e) {
                error_log('roles create error: ' . $e->getMessage());
                $error = 'Kon rol niet aanmaken (mogelijk bestaat deze al).';
            }
        }
    }
}

// Fetch roles + permissions
try {
    $stmt = $pdo->query('SELECT id, role_name, description, created_at FROM roles ORDER BY role_name ASC');
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // fetch permissions grouped by role
    $permStmt = $pdo->query('SELECT role_id, permission FROM role_permissions ORDER BY permission ASC');
    $permRows = $permStmt->fetchAll(PDO::FETCH_ASSOC);
    $permsByRole = [];
    foreach ($permRows as $pr) {
        $permsByRole[$pr['role_id']][] = $pr['permission'];
    }
} catch (Exception $e) {
    $error = 'Kon rollen niet ophalen.';
    $roles = [];
}

require_once __DIR__ . '/_header.php';
?>

    <h2><?php echo __('page.roles.title'); ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="card">
        <h3><?php echo __('page.roles.create'); ?></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create_role">
            <div class="form-group">
                <label><?php echo __('form.name'); ?></label>
                <input name="role_name" type="text" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.description'); ?></label>
                <input name="description" type="text">
            </div>
            <button class="btn" type="submit"><?php echo __('button.create'); ?></button>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h3><?php echo __('page.roles.title'); ?></h3>
        <?php if (empty($roles)): ?>
            <p><?php echo __('label.no_results'); ?></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th><?php echo __('table.id'); ?></th><th><?php echo __('table.role'); ?></th><th><?php echo __('table.description'); ?></th><th><?php echo __('form.permissions'); ?></th><th><?php echo __('table.actions'); ?></th></tr>
                </thead>
                <tbody>
                    <?php
                    $system_roles = ['owner', 'admin', 'user'];
                    foreach ($roles as $r):
                        $is_system = in_array(strtolower($r['role_name']), $system_roles);
                    ?>
                        <tr>
                            <td><?php echo (int)$r['id']; ?></td>
                            <td><?php echo htmlspecialchars($r['role_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['description']); ?></td>
                            <td><?php echo htmlspecialchars(implode(', ', $permsByRole[$r['id']] ?? [])); ?></td>
                            <td>
                                <a class="btn" href="/admin/roles_edit.php?id=<?php echo (int)$r['id']; ?>" style="font-size:12px; padding:4px 10px; background:#007bff;">✏️ <?php echo __('button.edit'); ?></a>
                                <?php if ($is_system): ?>
                                    <span class="btn" style="font-size:12px; padding:4px 10px; background:#6c757d; cursor:default; opacity:0.7;" title="Systeemrol kan niet worden verwijderd">🔒 Systeem rol</span>
                                <?php else: ?>
                                    <a class="btn" href="/admin/roles_delete.php?id=<?php echo (int)$r['id']; ?>" style="font-size:12px; padding:4px 10px; background:#dc3545;" onclick="return confirm('<?php echo __('confirm.delete_role'); ?>');">🗑️ <?php echo __('button.delete'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php require_once __DIR__ . '/_footer.php'; ?>
