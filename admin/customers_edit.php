<?php
$page_title = 'Klant Bewerken';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.customers.manage') && !has_permission($pdo, $admin_id, 'devices.manage')) {
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

$customer_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($customer_id <= 0) {
    header('Location: /admin/customers.php');
    exit;
}

// Fetch customer
try {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        header('Location: /admin/customers.php?notfound=1');
        exit;
    }
} catch (Exception $e) {
    error_log('admin/customers_edit fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen klant. Controleer logs.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $customer_code = trim((string)($_POST['customer_code'] ?? ''));
        $company_name = trim((string)($_POST['company_name'] ?? ''));
        $contact_person = trim((string)($_POST['contact_person'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;

        if ($customer_code === '' || $company_name === '') {
            $error = 'Vul minimaal de klantcode en bedrijfsnaam in.';
        } else {
            try {
                // Check duplicate customer_code
                $chk = $pdo->prepare('SELECT id FROM customers WHERE customer_code = ? AND id != ? LIMIT 1');
                $chk->execute([$customer_code, $customer_id]);
                if ($chk->fetch()) {
                    $error = 'Er bestaat al een andere klant met deze klantcode.';
                } else {
                    $stmt = $pdo->prepare('UPDATE customers SET customer_code = ?, company_name = ?, contact_person = ?, email = ?, phone = ?, address = ?, notes = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute([$customer_code, $company_name, $contact_person, $email, $phone, $address, $notes, $is_active, $customer_id]);

                    // audit
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'update_customer',
                            'customer',
                            $customer_id,
                            json_encode($customer),
                            json_encode(['customer_code' => $customer_code, 'company_name' => $company_name, 'is_active' => $is_active]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('admin/customers_edit audit error: ' . $e->getMessage());
                    }

                    header('Location: /admin/customers.php?updated=' . (int)$customer_id);
                    exit;
                }
            } catch (Exception $e) {
                error_log('admin/customers_edit update error: ' . $e->getMessage());
                $error = 'Kon klant niet bijwerken. Controleer logs.';
            }
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<main class="container" style="max-width:900px; margin-top:20px;">
    <h2>Klant Bewerken</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$customer_id; ?>">

            <div class="form-group">
                <label>Klantcode *</label>
                <input name="customer_code" type="text" required value="<?php echo htmlspecialchars($_POST['customer_code'] ?? $customer['customer_code']); ?>">
                <small style="color: #6c757d;">Unieke identificatie voor de klant</small>
            </div>

            <div class="form-group">
                <label>Bedrijfsnaam *</label>
                <input name="company_name" type="text" required value="<?php echo htmlspecialchars($_POST['company_name'] ?? $customer['company_name']); ?>">
            </div>

            <div class="form-group">
                <label>Contactpersoon</label>
                <input name="contact_person" type="text" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? $customer['contact_person']); ?>">
            </div>

            <div class="form-group">
                <label>Email</label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? $customer['email']); ?>">
            </div>

            <div class="form-group">
                <label>Telefoon</label>
                <input name="phone" type="text" value="<?php echo htmlspecialchars($_POST['phone'] ?? $customer['phone']); ?>">
            </div>

            <div class="form-group">
                <label>Adres</label>
                <textarea name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? $customer['address']); ?></textarea>
            </div>

            <div class="form-group">
                <label>Notities</label>
                <textarea name="notes" rows="4"><?php echo htmlspecialchars($_POST['notes'] ?? $customer['notes']); ?></textarea>
            </div>

            <div class="form-group">
                <label>Actief</label>
                <?php $cur_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : (int)$customer['is_active']; ?>
                <select name="is_active">
                    <option value="1" <?php echo $cur_active ? 'selected' : ''; ?>>Ja</option>
                    <option value="0" <?php echo !$cur_active ? 'selected' : ''; ?>>Nee</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">Opslaan</button>
                <a class="btn" href="/admin/customers.php" style="background:#6c757d;">Terug naar lijst</a>
                <a class="btn" href="/admin/customers_delete.php?id=<?php echo (int)$customer_id; ?>" style="background:#dc3545;">Verwijderen</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
