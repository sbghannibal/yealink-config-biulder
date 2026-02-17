<?php
$page_title = 'Nieuwe Klant';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'customers.create')) {
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
                $chk = $pdo->prepare('SELECT id FROM customers WHERE customer_code = ? LIMIT 1');
                $chk->execute([$customer_code]);
                if ($chk->fetch()) {
                    $error = 'Er bestaat al een klant met deze klantcode.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO customers (customer_code, company_name, contact_person, email, phone, address, notes, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                    $stmt->execute([$customer_code, $company_name, $contact_person, $email, $phone, $address, $notes, $is_active]);
                    $newId = $pdo->lastInsertId();

                    // audit
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'create_customer',
                            'customer',
                            $newId,
                            json_encode(['customer_code' => $customer_code, 'company_name' => $company_name]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('admin/customers_add audit insert error: ' . $e->getMessage());
                    }

                    header('Location: /admin/customers.php?created=' . (int)$newId);
                    exit;
                }
            } catch (Exception $e) {
                error_log('admin/customers_add insert error: ' . $e->getMessage());
                $error = 'Kon klant niet aanmaken. Controleer logs.';
            }
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<main class="container" style="max-width:900px; margin-top:20px;">
    <h2>Nieuwe Klant</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

            <div class="form-group">
                <label>Klantcode *</label>
                <input name="customer_code" type="text" required value="<?php echo htmlspecialchars($_POST['customer_code'] ?? ''); ?>" placeholder="bijv. CUST001">
                <small style="color: #6c757d;">Unieke identificatie voor de klant</small>
            </div>

            <div class="form-group">
                <label>Bedrijfsnaam *</label>
                <input name="company_name" type="text" required value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Contactpersoon</label>
                <input name="contact_person" type="text" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Email</label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Telefoon</label>
                <input name="phone" type="text" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Adres</label>
                <textarea name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Notities</label>
                <textarea name="notes" rows="4"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Actief</label>
                <select name="is_active">
                    <option value="1" <?php echo (!isset($_POST['is_active']) || $_POST['is_active'] == '1') ? 'selected' : ''; ?>>Ja</option>
                    <option value="0" <?php echo (isset($_POST['is_active']) && $_POST['is_active'] == '0') ? 'selected' : ''; ?>>Nee</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">Aanmaken</button>
                <a class="btn" href="/admin/customers.php" style="background:#6c757d;">Annuleren</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
