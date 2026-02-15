device_types_edit.php<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.device_types.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/device_types.php');
    exit;
}

$error = '';
$success = '';

// Fetch current
try {
    $stmt = $pdo->prepare('SELECT id, type_name, description FROM device_types WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$type) {
        header('Location: /admin/device_types.php');
        exit;
    }
} catch (Exception $e) {
    error_log('device_types_edit fetch error: ' . $e->getMessage());
    $error = 'Kon model niet ophalen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag.';
    } else {
        $action = $_POST['do'] ?? 'update';
        if ($action === 'update') {
            $type_name = trim($_POST['type_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            if ($type_name === '') {
                $error = 'Modelnaam is verplicht.';
            } else {
                try {
                    $stmt = $pdo->prepare('UPDATE device_types SET type_name = ?, description = ? WHERE id = ?');
                    $stmt->execute([$type_name, $description ?: null, $id]);
                    $success = 'Model bijgewerkt.';
                    // Refresh
                    $type['type_name'] = $type_name;
                    $type['description'] = $description;
                } catch (Exception $e) {
                    error_log('device_types_edit update error: ' . $e->getMessage());
                    $error = 'Kon model niet bijwerken.';
                }
            }
        } elseif ($action === 'delete') {
            try {
                // Option: ensure no devices reference this model (if devices.model stores type_name)
                $chk = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE model = ?');
                $chk->execute([$type['type_name']]);
                $count = (int)$chk->fetchColumn();
                if ($count > 0) {
                    $error = "Kan model niet verwijderen: $count device(s) gebruiken dit model. Pas eerst devices aan.";
                } else {
                    $del = $pdo->prepare('DELETE FROM device_types WHERE id = ?');
                    $del->execute([$id]);
                    header('Location: /admin/device_types.php?deleted=1');
                    exit;
                }
            } catch (Exception $e) {
                error_log('device_types_edit delete error: ' . $e->getMessage());
                $error = 'Kon model niet verwijderen.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Bewerk model - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/_admin_nav.php'; ?>
<main class="container">
    <h2>Model bewerken</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$type['id']; ?>">
            <div class="form-group">
                <label>Model (type_name)</label>
                <input name="type_name" type="text" value="<?php echo htmlspecialchars($type['type_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Beschrijving</label>
                <input name="description" type="text" value="<?php echo htmlspecialchars($type['description']); ?>">
            </div>
            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit" name="do" value="update">Opslaan</button>
                <a class="btn" href="/admin/device_types.php" style="background:#6c757d;">Annuleren</a>
                <button class="btn" type="submit" name="do" value="delete" style="background:#dc3545;" onclick="return confirm('Weet je zeker dat je dit model wilt verwijderen?');">Verwijderen</button>
            </div>
        </form>
    </div>
</main>
</body>
</html>
