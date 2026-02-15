<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission
if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF token ensure
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Get device id from GET or POST
$device_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($device_id <= 0) {
    header('Location: /devices/list.php');
    exit;
}

// Fetch device
try {
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        // Not found
        header('Location: /devices/list.php?notfound=1');
        exit;
    }
} catch (Exception $e) {
    error_log('devices/edit fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen device. Controleer logs.';
    exit;
}

// Fetch device types for dropdown (optional)
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $types = [];
    error_log('devices/edit fetch types error: ' . $e->getMessage());
}

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $name = trim((string)($_POST['device_name'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $mac_raw = trim((string)($_POST['mac_address'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;

        if ($name === '' || $model === '') {
            $error = 'Vul minimaal naam en model in.';
        } else {
            // Normalize MAC if provided
            if ($mac_raw !== '') {
                $normalized = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac_raw));
                if (!preg_match('/^[0-9A-F]{12}$/', $normalized)) {
                    $error = 'Ongeldig MAC-adres formaat.';
                } else {
                    $mac = implode(':', str_split($normalized, 2));
                }
            } else {
                $mac = null;
            }

            if ($error === '') {
                try {
                    // Check duplicate MAC (exclude current device)
                    if ($mac !== null) {
                        $chk = $pdo->prepare('SELECT id FROM devices WHERE mac_address = ? AND id != ? LIMIT 1');
                        $chk->execute([$mac, $device_id]);
                        if ($chk->fetch()) {
                            $error = 'Er bestaat al een device met dit MAC-adres.';
                        }
                    }

                    if ($error === '') {
                        $stmt = $pdo->prepare('UPDATE devices SET device_name = ?, model = ?, mac_address = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$name, $model, $mac, $description, $is_active, $device_id]);

                        // Audit log
                        try {
                            $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                            $alog->execute([
                                $admin_id,
                                'update_device',
                                'device',
                                $device_id,
                                json_encode($device),
                                json_encode(['device_name' => $name, 'model' => $model, 'mac_address' => $mac, 'description' => $description, 'is_active' => $is_active]),
                                $_SERVER['REMOTE_ADDR'] ?? null,
                                $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]);
                        } catch (Exception $e) {
                            error_log('devices/edit audit error: ' . $e->getMessage());
                        }

                        $success = 'Device bijgewerkt.';
                        // Refresh device data for display
                        $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
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
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Device bewerken - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>
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
                <select name="model" required>
                    <option value="">-- Kies model --</option>
                    <?php
                    // Show device types or fallback samples
                    if (empty($types)) {
                        $fallback = ['T19P','T21P','T23P','T27P','T29P','T41P','T46P','T48P'];
                        foreach ($fallback as $f) {
                            $sel = ((isset($_POST['model']) && $_POST['model'] === $f) || (!isset($_POST['model']) && $device['model'] === $f)) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($f) . "\" $sel>" . htmlspecialchars($f) . "</option>";
                        }
                    } else {
                        foreach ($types as $t) {
                            $value = $t['type_name'];
                            $sel = ((isset($_POST['model']) && $_POST['model'] === $value) || (!isset($_POST['model']) && $device['model'] === $value)) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($value) . '" ' . $sel . '>' . htmlspecialchars($value) . '</option>';
                        }
                    }
                    ?>
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
                <select name="is_active">
                    <?php $cur_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : (int)$device['is_active']; ?>
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
