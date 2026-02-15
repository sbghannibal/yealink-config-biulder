<?php
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

// Ensure CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $type_name = trim($_POST['type_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($type_name === '') {
            $error = 'Vul een modelnaam in.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO device_types (type_name, description) VALUES (?, ?)');
                $stmt->execute([$type_name, $description ?: null]);
                $success = 'Model aangemaakt.';
            } catch (Exception $e) {
                error_log('device_types create error: ' . $e->getMessage());
                $error = 'Kon model niet aanmaken (mogelijk bestaat deze al).';
            }
        }
    }
}

// Fetch list
try {
    $stmt = $pdo->query('SELECT id, type_name, description, created_at FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('device_types fetch error: ' . $e->getMessage());
    $types = [];
    $error = $error ?: 'Kon device types niet ophalen.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Device modellen - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/_admin_nav.php'; ?>
<main class="container">
    <h2>Device modellen</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="card" style="margin-bottom:16px;">
        <h3>Nieuw model</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Model (type_name)</label>
                <input name="type_name" type="text" required>
            </div>
            <div class="form-group">
                <label>Beschrijving (optioneel)</label>
                <input name="description" type="text">
            </div>
            <button class="btn" type="submit">Aanmaken</button>
        </form>
    </section>

    <section class="card">
        <h3>Bestaande modellen</h3>
        <?php if (empty($types)): ?>
            <p>Geen modellen gevonden.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>ID</th><th>Model</th><th>Beschrijving</th><th>Aangemaakt</th><th>Acties</th></tr></thead>
                <tbody>
                    <?php foreach ($types as $t): ?>
                        <tr>
                            <td><?php echo (int)$t['id']; ?></td>
                            <td><?php echo htmlspecialchars($t['type_name']); ?></td>
                            <td><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($t['created_at']); ?></td>
                            <td>
                                <a class="btn" href="/admin/device_types_edit.php?id=<?php echo (int)$t['id']; ?>">Bewerken</a>
                                <a class="btn" href="/admin/device_types_edit.php?id=<?php echo (int)$t['id']; ?>&action=delete" style="background:#dc3545;" onclick="return confirm('Weet je zeker dat je dit model wilt verwijderen?');">Verwijderen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
