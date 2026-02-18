<?php
$page_title = 'Nieuw Device';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'devices.manage')) {
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

try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $types = [];
    error_log('devices/create fetch types error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF)';
    } else {
        $name = trim($_POST['device_name'] ?? '');
        $device_type_id = (int)($_POST['device_type_id'] ?? 0);
        $mac = trim($_POST['mac_address'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if ($name === '' || $device_type_id <= 0) {
            $error = 'Vul minimaal naam en model in.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO devices (device_name, device_type_id, mac_address, description, is_active) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$name, $device_type_id, $mac ?: null, $desc ?: null]);
                $success = 'Device aangemaakt.';
            } catch (Exception $e) {
                error_log('devices/create insert error: ' . $e->getMessage());
                $error = 'Kon device niet aanmaken.';
            }
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<h2>Nieuw Device</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-group">
                <label>Naam</label>
                <input name="device_name" type="text" required>
            </div>
            <div class="form-group">
                <label>Model</label>
                <select name="device_type_id" required>
                    <option value="">-- Kies model --</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['type_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>MAC-adres (optioneel)</label>
                <input name="mac_address" type="text" placeholder="00:11:22:33:44:55">
            </div>
            <div class="form-group">
                <label>Beschrijving</label>
                <textarea name="description"></textarea>
            </div>
            <button class="btn" type="submit">Aanmaken</button>
            <a class="btn" href="/devices/list.php" style="background:#6c757d;">Annuleren</a>
        </form>
    </div>
</main>
</body>
</html>
