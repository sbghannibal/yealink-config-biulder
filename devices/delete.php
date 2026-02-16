<?php
$page_title = 'Device verwijderen';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Determine device id from GET or POST
$device_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($device_id <= 0) {
    header('Location: /devices/list.php');
    exit;
}

// Fetch device for confirmation/display
try {
    $stmt = $pdo->prepare('SELECT d.id, d.device_name, d.mac_address, d.description, dt.type_name AS model_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ? LIMIT 1');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        // Device not found
        header('Location: /devices/list.php?notfound=1');
        exit;
    }
} catch (Exception $e) {
    error_log('devices/delete fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen device. Controleer logs.';
    exit;
}

// Handle POST => perform deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        try {
            $pdo->beginTransaction();

            // Optional: record delete in audit_logs with old_value
            try {
                $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $alog->execute([
                    $admin_id,
                    'delete_device',
                    'device',
                    $device_id,
                    json_encode($device),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('devices/delete audit error: ' . $e->getMessage());
                // continue deletion even if audit fails
            }

            // Delete device (cascade or manual related cleanup if needed)
            $del = $pdo->prepare('DELETE FROM devices WHERE id = ?');
            $del->execute([$device_id]);

            $pdo->commit();

            // Redirect back to list with success flag
            header('Location: /devices/list.php?deleted=' . (int)$device_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('devices/delete error: ' . $e->getMessage());
            $error = 'Kon device niet verwijderen. Controleer logs.';
        }
    }
}

require_once __DIR__ . '/../admin/_header.php';
?>

<main class="container" style="max-width:800px; margin-top:20px;">
    <h2>Device verwijderen</h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <p>Weet je zeker dat je het volgende device wilt verwijderen?</p>
        <ul>
            <li><strong>Naam:</strong> <?php echo htmlspecialchars($device['device_name']); ?></li>
            <li><strong>Model:</strong> <?php echo htmlspecialchars($device['model_name'] ?? '-'); ?></li>
            <li><strong>MAC:</strong> <?php echo htmlspecialchars($device['mac_address'] ?? '-'); ?></li>
            <li><strong>Beschrijving:</strong> <?php echo htmlspecialchars($device['description'] ?? '-'); ?></li>
        </ul>

        <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
            <button class="btn" type="submit" style="background:#dc3545;">Ja, verwijder</button>
        </form>

        <a class="btn" href="/devices/list.php" style="background:#6c757d; margin-left:8px;">Annuleren</a>
    </div>
</main>
</body>
</html>
