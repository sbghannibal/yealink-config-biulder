<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.roles.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
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
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Rollen & permissies</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/_admin_nav.php'; ?>
<main class="container">
    <h2>Rollen & permissies</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="card">
        <h3>Maak nieuwe rol</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create_role">
            <div class="form-group">
                <label>Rolnaam</label>
                <input name="role_name" type="text" required>
            </div>
            <div class="form-group">
                <label>Beschrijving</label>
                <input name="description" type="text">
            </div>
            <button class="btn" type="submit">Maak rol</button>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <h3>Bestaande rollen</h3>
        <?php if (empty($roles)): ?>
            <p>Geen rollen gevonden.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>ID</th><th>Rol</th><th>Beschrijving</th><th>Permissies</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td><?php echo (int)$r['id']; ?></td>
                            <td><?php echo htmlspecialchars($r['role_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['description']); ?></td>
                            <td><?php echo htmlspecialchars(implode(', ', $permsByRole[$r['id']] ?? [])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
