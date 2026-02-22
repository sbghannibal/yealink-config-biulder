<?php
$page_title = 'Rol Verwijderen';
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

$system_roles = ['owner', 'admin', 'user'];

$role_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($role_id <= 0) {
    header('Location: /admin/roles.php');
    exit;
}

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
    error_log('roles_delete fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen rol.';
    exit;
}

// Prevent deletion of system roles
if (in_array(strtolower($role['role_name']), $system_roles)) {
    header('Location: /admin/roles.php?error=system_role');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        try {
            $pdo->beginTransaction();

            // Check if role is in use; reassign to 'user' role if needed
            $stmt = $pdo->prepare('SELECT admin_id FROM admin_roles WHERE role_id = ?');
            $stmt->execute([$role_id]);
            $affected_admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($affected_admins)) {
                // Find the 'user' role id
                $stmt = $pdo->prepare("SELECT id FROM roles WHERE LOWER(role_name) = 'user' LIMIT 1");
                $stmt->execute();
                $user_role = $stmt->fetch(PDO::FETCH_ASSOC);
                $user_role_id = $user_role ? (int)$user_role['id'] : null;

                // Remove this role from affected admins
                $stmt = $pdo->prepare('DELETE FROM admin_roles WHERE role_id = ?');
                $stmt->execute([$role_id]);

                // Reassign to user role if found
                if ($user_role_id) {
                    $ins = $pdo->prepare('INSERT IGNORE INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
                    foreach ($affected_admins as $aid) {
                        $ins->execute([(int)$aid, $user_role_id]);
                    }
                }
            }

            // Delete role permissions
            $stmt = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $stmt->execute([$role_id]);

            // Delete role
            $stmt = $pdo->prepare('DELETE FROM roles WHERE id = ?');
            $stmt->execute([$role_id]);

            $pdo->commit();

            header('Location: /admin/roles.php?deleted=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('roles_delete error: ' . $e->getMessage());
            $error = 'Kon rol niet verwijderen.';
        }
    }
}

// Count admins using this role
$admin_count = 0;
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_roles WHERE role_id = ?');
    $stmt->execute([$role_id]);
    $admin_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('roles_delete count error: ' . $e->getMessage());
}

require_once __DIR__ . '/_header.php';
?>

<h2>🗑️ <?php echo __('page.roles.delete'); ?></h2>

<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="card">
    <p><strong>Let op:</strong> Je staat op het punt om de volgende rol te verwijderen:</p>

    <div style="background:#f8f9fa; padding:16px; border-radius:4px; margin:16px 0;">
        <p><strong><?php echo __('form.name'); ?>:</strong> <?php echo htmlspecialchars($role['role_name']); ?></p>
        <p><strong><?php echo __('form.description'); ?>:</strong> <?php echo htmlspecialchars($role['description'] ?? '-'); ?></p>
        <?php if ($admin_count > 0): ?>
            <p style="color:#dc3545;"><strong>⚠️ Let op:</strong> <?php echo $admin_count; ?> gebruiker(s) hebben deze rol. Ze worden automatisch toegewezen aan de 'user' rol.</p>
        <?php endif; ?>
    </div>

    <p style="color:#dc3545;"><strong>Deze actie kan niet ongedaan worden gemaakt!</strong></p>

    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$role_id; ?>">

        <div style="display:flex; gap:8px;">
            <button class="btn" type="submit" style="background:#dc3545; color:white;"><?php echo __('label.yes'); ?>, <?php echo __('button.delete'); ?></button>
            <a class="btn" href="/admin/roles.php" style="background:#6c757d;"><?php echo __('button.cancel'); ?></a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
