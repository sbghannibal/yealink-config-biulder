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

// Controleer permissie
if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// Zorg voor CSRF-token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Haal device types voor dropdown (indien aanwezig)
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $types = [];
    error_log('devices/create fetch types error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        // Verzamel invoer
        $name = trim((string)($_POST['device_name'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $mac = trim((string)($_POST['mac_address'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        // Validatie
        if ($name === '' || $model === '') {
            $error = 'Vul minimaal de naam en het model in.';
        } else {
            // Normaliseer MAC (optioneel)
            if ($mac !== '') {
                // Accept formats: 00:11:22:33:44:55 or 001122334455 or 00-11-22-33-44-55
                $normalized = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
                if (!preg_match('/^[0-9A-F]{12}$/', $normalized)) {
                    $error = 'Ongeldig MAC-adres. Gebruik bijv. 00:11:22:33:44:55.';
                } else {
                    // Format as XX:XX:XX:XX:XX:XX
                    $mac = implode(':', str_split($normalized, 2));
                }
            } else {
                $mac = null;
            }

            if ($error === '') {
                try {
                    // Optioneel: controleer duplicate MAC
                    if ($mac !== null) {
                        $chk = $pdo->prepare('SELECT id FROM devices WHERE mac_address = ? LIMIT 1');
                        $chk->execute([$mac]);
                        if ($chk->fetch()) {
                            $error = 'Er bestaat al een device met dit MAC-adres.';
                        }
                    }
                } catch (Exception $e) {
                    error_log('devices/create duplicate check error: ' . $e->getMessage());
                    // niet blokkeren, maar loggen
                }
            }

            if ($error === '') {
                try {
                    $stmt = $pdo->prepare('INSERT INTO devices (device_name, model, mac_address, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())');
                    $stmt->execute([$name, $model, $mac, $description]);
                    $newId = $pdo->lastInsertId();

                    // Log in audit_logs (optioneel)
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
                        // audit-fout mag niet de workflow breken
                        error_log('devices/create audit insert error: ' . $e->getMessage());
                    }

                    $success = 'Device succesvol aangemaakt.';
                    // Redirect naar lijst of bewerkpagina
                    header('Location: /devices/list.php?created=' . (int)$newId);
                    exit;
                } catch (Exception $e) {
                    error_log('devices/create insert error: ' . $e->getMessage());
                    $error = 'Kon device niet aanmaken. Controleer de logs.';
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
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nieuw device - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>
<main class="container" style="max-width:800px; margin-top:20px;">
    <h2>Nieuw device</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php
