<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Zorg dat gebruiker is ingelogd
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permissie
if (!has_permission($pdo, $admin_id, 'admin.device_types.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Bepaal id
$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/device_types.php');
    exit;
}

$error = '';
$success = '';

// Haal huidig record
try {
    $stmt = $pdo->prepare('SELECT id, type_name, description, created_at, updated_at FROM device_types WHERE id = ? LIMIT 1');
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

// Verwerk POST (update of delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['do'] ?? 'update';

        if ($action === 'update') {
            $type_name = trim($_POST['type_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($type_name === '') {
                $error = 'Modelnaam is verplicht.';
            } else {
                try {
                    $upd = $pdo->prepare('UPDATE device_types SET type_name = ?, description = ?, updated_at = NOW() WHERE id = ?');
                    $upd->execute([$type_name, $description ?: null, $id]);
                    $success = 'Model bijgewerkt.';

                    // Vernieuw lokale $type waarden
                    $type['type_name'] = $type_name;
                    $type['description'] = $description;
                    $type['updated_at'] = date('Y-m-d H:i:s');
                } catch (Exception $e) {
                    error_log('device_types_edit update error: ' . $e->getMessage());
                    // Mogelijk duplicate key
                    if (strpos($e->getMessage(), 'Duplicate') !== false) {
                        $error = 'Modelnaam bestaat al.';
                    } else {
                        $error = 'Kon model niet bijwerken.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            try {
                // Controleer of er devices zijn die dit model gebruiken.
                // We ondersteunen twee mogelijke referenties voor compatibiliteit:
                // - devices.device_type_id = id (nieuwe schema)
                // - devices.model = type_name (oud schema)
                $chk = $pdo->prepare(
                    'SELECT 
                        SUM(cnt) AS total
                     FROM (
                        SELECT COUNT(*) AS cnt FROM devices WHERE device_type_id = :id
                        UNION ALL
                        SELECT COUNT(*) AS cnt FROM devices WHERE model = :type_name
                     ) x'
                );
                $chk->execute([':id' => $id, ':type_name' => $type['type_name']]);
                $count = (int) $chk->fetchColumn();

                if ($count > 0) {
                    $error = "Kan model niet verwijderen: $count device(s) gebruiken dit model. Pas eerst devices aan.";
                } else {
                    $del = $pdo->prepare('DELETE FROM device_types WHERE id = ?');
                    $del->execute([$id]);

                    // Audit log (best-effort)
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'delete_device_type',
                            'device_type',
                            $id,
                            json_encode($type),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('device_types_edit audit error: ' . $e->getMessage());
                    }

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
    <style>
        .container { max-width:900px; margin:20px auto; padding:0 12px; }
        .card { background:#fff; padding:16px; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/_admin_nav.php')) include __DIR__ . '/_admin_nav.php'; ?>
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
                <input name="type_name" type="text" required value="<?php echo htmlspecialchars($type['type_name']); ?>">
            </div>

            <div class="form-group">
                <label>Beschrijving</label>
                <input name="description" type="text" value="<?php echo htmlspecialchars($type['description'] ?? ''); ?>">
            </div>

            <div style="display:flex; gap:8px; margin-top:12px;">
                <button class="btn" type="submit" name="do" value="update">Opslaan</button>
                <a class="btn" href="/admin/device_types.php" style="background:#6c757d;">Annuleren</a>

                <button class="btn" type="submit" name="do" value="delete" style="background:#dc3545;" onclick="return confirm('Weet je zeker dat je dit model wilt verwijderen? Dit kan niet ongedaan gemaakt worden.');">Verwijderen</button>
            </div>
        </form>
    </div>

    <section style="margin-top:16px;">
        <div class="card">
            <h4>Info</h4>
            <p>Model ID: <strong><?php echo (int)$type['id']; ?></strong><br>
            Aangemaakt: <?php echo htmlspecialchars($type['created_at']); ?> — Laatste wijziging: <?php echo htmlspecialchars($type['updated_at']); ?></p>
            <p>Opmerking: bij het verwijderen wordt gecontroleerd of er nog apparaten zijn die dit model gebruiken. Als je een foutmelding krijgt, pas eerst die apparaten aan of wijzig hun model naar een ander type.</p>
        </div>
    </section>
</main>
</body>
</html>
