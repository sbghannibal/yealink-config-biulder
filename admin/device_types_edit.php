<?php
$page_title = 'Edit Device Type';
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

// Haal huidig record met device count
try {
    $stmt = $pdo->prepare('
        SELECT 
            dt.id, 
            dt.type_name, 
            dt.description, 
            dt.created_at, 
            dt.updated_at,
            COUNT(d.id) as device_count
        FROM device_types dt
        LEFT JOIN devices d ON dt.id = d.device_type_id
        WHERE dt.id = ?
        GROUP BY dt.id, dt.type_name, dt.description, dt.created_at, dt.updated_at
        LIMIT 1
    ');
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
                    $error = "Cannot delete - $count device(s) are using this type. Please reassign those devices first.";
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
require_once __DIR__ . '/_header.php';
?>

    <h2>Edit Device Type</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$type['id']; ?>">

            <div class="form-group">
                <label>Type Name</label>
                <input name="type_name" type="text" required value="<?php echo htmlspecialchars($type['type_name']); ?>">
            </div>

            <div class="form-group">
                <label>Description</label>
                <input name="description" type="text" value="<?php echo htmlspecialchars($type['description'] ?? ''); ?>">
            </div>

            <div style="display:flex; gap:8px; margin-top:16px;">
                <button class="btn" type="submit" name="do" value="update">Save Changes</button>
                <a class="btn btn-secondary" href="/admin/device_types.php">Cancel</a>

                <?php 
                $device_count = (int)($type['device_count'] ?? 0);
                if ($device_count > 0): 
                ?>
                    <button class="btn btn-danger" type="button" disabled title="Cannot delete - devices are using this type" style="opacity:0.5;cursor:not-allowed;">
                        Delete (<?php echo $device_count; ?> device<?php echo $device_count !== 1 ? 's' : ''; ?> in use)
                    </button>
                <?php else: ?>
                    <button class="btn btn-danger" type="submit" name="do" value="delete" onclick="return confirm('Are you sure you want to delete this device type? This action cannot be undone.');">
                        Delete
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top:20px;">
        <h4>Device Type Information</h4>
        <table style="width:auto;">
            <tr>
                <td style="padding:8px;font-weight:600;">ID:</td>
                <td style="padding:8px;"><?php echo (int)$type['id']; ?></td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">Devices Using:</td>
                <td style="padding:8px;">
                    <?php if ($device_count > 0): ?>
                        <span style="display:inline-block;background:#667eea;color:white;padding:4px 10px;border-radius:16px;font-weight:600;">
                            <?php echo $device_count; ?> device<?php echo $device_count !== 1 ? 's' : ''; ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#999;">0 devices</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">Created:</td>
                <td style="padding:8px;"><?php echo htmlspecialchars($type['created_at']); ?></td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">Last Updated:</td>
                <td style="padding:8px;"><?php echo htmlspecialchars($type['updated_at']); ?></td>
            </tr>
        </table>
        <p style="margin-top:16px;color:#666;font-size:14px;">
            <strong>Note:</strong> Device types that are in use cannot be deleted. You must first reassign or delete all devices using this type.
        </p>
    </div>
</main>
</body>
</html>
