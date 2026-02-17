<?php
$page_title = 'Device Types';
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
    header('Location: /access_denied.php');
    exit;
}

// Ensure CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Check for success from redirect
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $success = 'Device type successfully deleted.';
}

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

// Fetch list with device counts
try {
    $stmt = $pdo->query('
        SELECT 
            dt.id, 
            dt.type_name, 
            dt.description, 
            dt.created_at,
            COUNT(d.id) as device_count
        FROM device_types dt 
        LEFT JOIN devices d ON dt.id = d.device_type_id 
        GROUP BY dt.id, dt.type_name, dt.description, dt.created_at
        ORDER BY dt.type_name ASC
    ');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('device_types fetch error: ' . $e->getMessage());
    $types = [];
    $error = $error ?: 'Kon device types niet ophalen.';
}
require_once __DIR__ . '/_header.php';
?>

    <h2>Device Types</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
        <h3>Add New Device Type</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Type Name (e.g., T40, W60P, T46S)</label>
                <input name="type_name" type="text" required placeholder="Enter device type name">
                <small style="display:block;margin-top:4px;color:#666;">Must be unique. Common examples: T40, T46S, W60P, CP960</small>
            </div>
            <div class="form-group">
                <label>Description (optional)</label>
                <input name="description" type="text" placeholder="e.g., Entry-level IP Phone, DECT Base Station">
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn" type="submit">Create Device Type</button>
                <a class="btn btn-secondary" href="/admin/dashboard.php">Back to Admin</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Existing Device Types</h3>
        <?php if (empty($types)): ?>
            <p>No device types found. Create one above to get started.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Type Name</th>
                        <th>Description</th>
                        <th>Devices Using</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $t): ?>
                        <tr style="<?php echo (int)$t['device_count'] > 0 ? 'background-color: #f0f9ff;' : ''; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($t['type_name']); ?></strong>
                                <?php if ((int)$t['device_count'] > 0): ?>
                                    <span style="display:inline-block;background:#4CAF50;color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:8px;">IN USE</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($t['description'] ?: '-'); ?></td>
                            <td>
                                <?php if ((int)$t['device_count'] > 0): ?>
                                    <span style="display:inline-block;background:#667eea;color:white;padding:4px 10px;border-radius:16px;font-weight:600;font-size:12px;">
                                        <?php echo (int)$t['device_count']; ?> device<?php echo (int)$t['device_count'] !== 1 ? 's' : ''; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#999;">0 devices</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;color:#666;"><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                            <td style="white-space:nowrap;">
                                <a class="btn" href="/admin/device_types_edit.php?id=<?php echo (int)$t['id']; ?>" style="padding:6px 12px;font-size:13px;">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
