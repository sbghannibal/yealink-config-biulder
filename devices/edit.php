<?php
$page_title = 'Device bewerken';
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
    echo 'Toegang geweigerd.';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

$device_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($device_id <= 0) {
    header('Location: /devices/list.php');
    exit;
}

// Fetch device with model name
try {
    $stmt = $pdo->prepare('SELECT d.*, dt.type_name AS model_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ? LIMIT 1');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        header('Location: /devices/list.php?notfound=1');
        exit;
    }
} catch (Exception $e) {
    error_log('devices/edit fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen device. Controleer logs.';
    exit;
}

// Fetch device types for dropdown
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $types = [];
    error_log('devices/edit fetch types error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $name = trim((string)($_POST['device_name'] ?? ''));
        $device_type_id = (int)($_POST['device_type_id'] ?? 0);
        $mac_raw = trim((string)($_POST['mac_address'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;

        if ($name === '' || $device_type_id <= 0) {
            $error = 'Vul minimaal naam en model in.';
        } else {
            $mac = null;
            if ($mac_raw !== '') {
                $normalized = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac_raw));
                if (!preg_match('/^[0-9A-F]{12}$/', $normalized)) {
                    $error = 'Ongeldig MAC-adres.';
                } else {
                    $mac = implode(':', str_split($normalized, 2));
                }
            }

            if ($error === '') {
                try {
                    if ($mac !== null) {
                        $chk = $pdo->prepare('SELECT id FROM devices WHERE mac_address = ? AND id != ? LIMIT 1');
                        $chk->execute([$mac, $device_id]);
                        if ($chk->fetch()) {
                            $error = 'Er bestaat al een device met dit MAC-adres.';
                        }
                    }

                    if ($error === '') {
                        $stmt = $pdo->prepare('UPDATE devices SET device_name = ?, device_type_id = ?, mac_address = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$name, $device_type_id, $mac, $description, $is_active, $device_id]);

                        // audit
                        try {
                            $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                            $alog->execute([
                                $admin_id,
                                'update_device',
                                'device',
                                $device_id,
                                json_encode($device),
                                json_encode(['device_name' => $name, 'device_type_id' => $device_type_id, 'mac_address' => $mac, 'description' => $description, 'is_active' => $is_active]),
                                $_SERVER['REMOTE_ADDR'] ?? null,
                                $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]);
                        } catch (Exception $e) {
                            error_log('devices/edit audit error: ' . $e->getMessage());
                        }

                        $success = 'Device bijgewerkt.';

                        // Refresh device
                        $stmt = $pdo->prepare('SELECT d.*, dt.type_name AS model_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ? LIMIT 1');
                        $stmt->execute([$device_id]);
                        $device = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                } catch (Exception $e) {
                    error_log('devices/edit update error: ' . $e->getMessage());
                    $error = 'Kon device niet bijwerken. Controleer logs.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../admin/_header.php';
?>

<main class="container" style="max-width:900px; margin-top:20px;">
    <h2>Device bewerken</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$device_id; ?>">

            <div class="form-group">
                <label>Naam</label>
                <input name="device_name" type="text" required value="<?php echo htmlspecialchars($_POST['device_name'] ?? $device['device_name']); ?>">
            </div>

            <div class="form-group">
                <label>Model</label>
                <select name="device_type_id" required>
                    <option value="">-- Kies model --</option>
                    <?php foreach ($types as $t): ?>
                        <?php $sel = ((isset($_POST['device_type_id']) && $_POST['device_type_id'] == $t['id']) || (!isset($_POST['device_type_id']) && $device['device_type_id'] == $t['id'])) ? 'selected' : ''; ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($t['type_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>MAC-adres (optioneel)</label>
                <input name="mac_address" type="text" placeholder="00:11:22:33:44:55" value="<?php echo htmlspecialchars($_POST['mac_address'] ?? $device['mac_address']); ?>">
            </div>

            <div class="form-group">
                <label>Beschrijving</label>
                <textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? $device['description']); ?></textarea>
            </div>

            <div class="form-group">
                <label>Actief</label>
                <?php $cur_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : (int)$device['is_active']; ?>
                <select name="is_active">
                    <option value="1" <?php echo $cur_active ? 'selected' : ''; ?>>Ja</option>
                    <option value="0" <?php echo !$cur_active ? 'selected' : ''; ?>>Nee</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">Opslaan</button>
                <a class="btn" href="/devices/list.php" style="background:#6c757d;">Terug naar lijst</a>
                <a class="btn" href="/devices/delete.php?id=<?php echo (int)$device_id; ?>" style="background:#dc3545;">Verwijderen</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
