<?php
// devices/create.php - improved with temporary debug output to find blank-page causes
// NOTE: Remove or disable debug display in production after fixing.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Quick helper to show debug info when ?debug=1 is used
$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    if ($debug) echo "DEBUG: not logged in\n";
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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        // fallback if random_bytes not available
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Load device types (may be empty)
$types = [];
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // log and continue with empty types (fallback will be used)
    error_log('devices/create fetch types error: ' . $e->getMessage());
    if ($debug) echo "DEBUG: device_types query failed: " . $e->getMessage() . "\n";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $name = trim((string)($_POST['device_name'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $mac = trim((string)($_POST['mac_address'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $model === '') {
            $error = 'Vul minimaal de naam en het model in.';
        } else {
            // Normalize MAC if provided
            $mac = $mac === '' ? null : strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
            if ($mac !== null && !preg_match('/^[0-9A-F]{12}$/', $mac)) {
                $error = 'Ongeldig MAC-adres.';
            } else {
                if ($mac !== null) $mac = implode(':', str_split($mac, 2));
                try {
                    // Duplicate MAC check
                    if ($mac !== null) {
                        $chk = $pdo->prepare('SELECT id FROM devices WHERE mac_address = ? LIMIT 1');
                        $chk->execute([$mac]);
                        if ($chk->fetch()) {
                            $error = 'Er bestaat al een device met dit MAC-adres.';
                        }
                    }
                } catch (Exception $e) {
                    error_log('devices/create duplicate check error: ' . $e->getMessage());
                    if ($debug) echo "DEBUG: duplicate check error: " . $e->getMessage() . "\n";
                }
            }

            if ($error === '') {
                try {
                    $ins = $pdo->prepare('INSERT INTO devices (device_name, model, mac_address, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())');
                    $ins->execute([$name, $model, $mac, $description]);
                    $newId = $pdo->lastInsertId();

                    // audit log (best-effort)
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'create_device',
                            'device',
                            $newId,
                            json_encode(['device_name' => $name, 'model' => $model, 'mac' => $mac]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('devices/create audit insert error: ' . $e->getMessage());
                    }

                    // Redirect on success
                    header('Location: /devices/list.php?created=' . (int)$newId);
                    exit;
                } catch (Exception $e) {
                    error_log('devices/create insert error: ' . $e->getMessage());
                    $error = 'Kon device niet aanmaken. Controleer logs.';
                    if ($debug) echo "DEBUG: insert error: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

// If page appears blank, view-source in browser or enable ?debug=1 to see debug output inline
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Nieuw device - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .container { max-width:900px; margin:20px auto; padding:0 12px; }
        .card { background:#fff; padding:16px; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>
<main class="container">
    <h2>Nieuw device</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

            <div class="form-group">
                <label>Naam</label>
                <input name="device_name" type="text" required value="<?php echo htmlspecialchars($_POST['device_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Model</label>
                <select name="model" required>
                    <option value="">-- Kies model --</option>
                    <?php
                    // Use device_types from DB if available, otherwise fallback list
                    if (!empty($types)) {
                        foreach ($types as $t) {
                            $sel = (isset($_POST['model']) && $_POST['model'] == $t['type_name']) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($t['type_name']) . '" ' . $sel . '>' . htmlspecialchars($t['type_name']) . '</option>';
                        }
                    } else {
                        // fallback
                        $fallback = ['T19P','T21P','T23P','T27P','T29P','T41P','T46P','T48P'];
                        foreach ($fallback as $f) {
                            $sel = (isset($_POST['model']) && $_POST['model'] === $f) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($f) . '" ' . $sel . '>' . htmlspecialchars($f) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label>MAC-adres (optioneel)</label>
                <input name="mac_address" type="text" placeholder="00:11:22:33:44:55" value="<?php echo htmlspecialchars($_POST['mac_address'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Beschrijving</label>
                <textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">Aanmaken</button>
                <a class="btn" href="/devices/list.php" style="background:#6c757d; align-self:center;">Annuleren</a>
                <?php if ($debug): ?>
                    <a class="btn" href="/devices/create.php" style="background:#f0ad4e;">Disable debug</a>
                <?php else: ?>
                    <a class="btn" href="/devices/create.php?debug=1" style="background:#17a2b8;">Enable debug</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($debug): ?>
        <section class="card" style="margin-top:12px;">
            <h3>DEBUG INFO</h3>
            <pre><?php
                echo "SESSION:\n"; var_export($_SESSION);
                echo "\n\nPOST:\n"; var_export($_POST);
                echo "\n\nTYPES (from DB):\n"; var_export($types);
            ?></pre>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
