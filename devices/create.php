<?php
$page_title = 'Nieuw device';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

// Ensure logged in
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Fetch device types
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('devices/create fetch types error: ' . $e->getMessage());
    $types = [];
}

// Fetch customers
try {
    $stmt = $pdo->query('SELECT id, customer_code, company_name FROM customers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY company_name ASC');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('devices/create fetch customers error: ' . $e->getMessage());
    $customers = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $name = trim((string)($_POST['device_name'] ?? ''));
        $device_type_id = (int)($_POST['device_type_id'] ?? 0);
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $mac_raw = trim((string)($_POST['mac_address'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $device_type_id <= 0 || empty($customer_id)) {
            $error = __('error.device_fields_required');
        } else {
            // Normalize MAC if provided
            $mac = null;
            if ($mac_raw !== '') {
                $normalized = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac_raw));
                if (!preg_match('/^[0-9A-F]{12}$/', $normalized)) {
                    $error = __('error.invalid_mac_format');
                } else {
                    $mac = implode(':', str_split($normalized, 2));
                }
            }

            if ($error === '') {
                try {
                    // check duplicate MAC if provided
                    if ($mac !== null) {
                        $chk = $pdo->prepare('SELECT id FROM devices WHERE mac_address = ? LIMIT 1');
                        $chk->execute([$mac]);
                        if ($chk->fetch()) {
                            $error = __('error.duplicate_mac');
                        }
                    }
                } catch (Exception $e) {
                    error_log('devices/create duplicate check error: ' . $e->getMessage());
                }
            }

            if ($error === '') {
                try {
                    $stmt = $pdo->prepare('INSERT INTO devices (device_name, device_type_id, customer_id, mac_address, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())');
                    $stmt->execute([$name, $device_type_id, $customer_id, $mac, $description]);
                    $newId = $pdo->lastInsertId();

                    // audit
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'create_device',
                            'device',
                            $newId,
                            json_encode(['device_name' => $name, 'device_type_id' => $device_type_id, 'customer_id' => $customer_id, 'mac' => $mac]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('devices/create audit insert error: ' . $e->getMessage());
                    }

                    header('Location: /devices/list.php?created=' . (int)$newId);
                    exit;
                } catch (Exception $e) {
                    error_log('devices/create insert error: ' . $e->getMessage());
                    $error = 'Kon device niet aanmaken. Controleer logs.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../admin/_header.php';
?>

<main class="container" style="max-width:900px; margin-top:20px;">
    <h2>📱 <?php echo __('page.device_create.heading'); ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

            <div class="form-group">
                <label><?php echo __('form.device_name'); ?></label>
                <input name="device_name" type="text" required value="<?php echo htmlspecialchars($_POST['device_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label><?php echo __('form.model'); ?></label>
                <select name="device_type_id" required>
                    <option value="">-- <?php echo __('form.select_model'); ?> --</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo (isset($_POST['device_type_id']) && $_POST['device_type_id'] == $t['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['type_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><?php echo __('form.customer'); ?> *</label>
                <select name="customer_id" required>
                    <option value="">-- <?php echo __('form.select_customer'); ?> --</option>
                    <?php
                    $preselect_customer = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
                    foreach ($customers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $preselect_customer == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['customer_code'] . ' - ' . $c['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#666; display:block; margin-top:4px;">
                    <?php echo __('form.customer_not_found'); ?>
                    <a href="/admin/customers_add.php?return_to=devices_create" style="color:#007bff; text-decoration:none;">➕ <?php echo __('button.create_customer'); ?></a>
                </small>
            </div>

            <div class="form-group">
                <label><?php echo __('form.mac_address_optional'); ?></label>
                <input name="mac_address" type="text" placeholder="00:11:22:33:44:55" value="<?php echo htmlspecialchars($_POST['mac_address'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label><?php echo __('form.description'); ?></label>
                <textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit"><?php echo __('button.create_device'); ?></button>
                <a class="btn" href="/devices/list.php" style="background:#6c757d; align-self:center; text-decoration:none;"><?php echo __('button.cancel'); ?></a>
            </div>
        </form>
    </div>

<?php require_once __DIR__ . '/../admin/_footer.php'; ?>
