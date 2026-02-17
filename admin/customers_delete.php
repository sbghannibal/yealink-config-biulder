<?php
$page_title = 'Klant Verwijderen';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'customers.delete')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$customer_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);

if ($customer_id <= 0) {
    header('Location: /admin/customers.php');
    exit;
}

// Fetch customer
try {
    $stmt = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id) as device_count FROM customers c WHERE c.id = ? LIMIT 1');
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        header('Location: /admin/customers.php?notfound=1');
        exit;
    }
} catch (Exception $e) {
    error_log('admin/customers_delete fetch error: ' . $e->getMessage());
    echo 'Fout bij ophalen klant. Controleer logs.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        try {
            // Delete customer (devices will have customer_id set to NULL due to ON DELETE SET NULL)
            $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
            $stmt->execute([$customer_id]);

            // audit
            try {
                $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $alog->execute([
                    $admin_id,
                    'delete_customer',
                    'customer',
                    $customer_id,
                    json_encode($customer),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('admin/customers_delete audit error: ' . $e->getMessage());
            }

            header('Location: /admin/customers.php?deleted=1');
            exit;
        } catch (Exception $e) {
            error_log('admin/customers_delete error: ' . $e->getMessage());
            $error = 'Kon klant niet verwijderen. Controleer logs.';
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<main class="container" style="max-width:700px; margin-top:20px;">
    <h2>Klant Verwijderen</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <p><strong>Let op:</strong> Je staat op het punt om de volgende klant te verwijderen:</p>
        
        <div style="background:#f8f9fa; padding:16px; border-radius:4px; margin:16px 0;">
            <p><strong>Klantcode:</strong> <?php echo htmlspecialchars($customer['customer_code']); ?></p>
            <p><strong>Bedrijfsnaam:</strong> <?php echo htmlspecialchars($customer['company_name']); ?></p>
            <?php if ($customer['device_count'] > 0): ?>
                <p style="color:#dc3545;"><strong>⚠️ Let op:</strong> Deze klant heeft <?php echo (int)$customer['device_count']; ?> device(s). De devices blijven bestaan maar worden ontkoppeld van deze klant.</p>
            <?php endif; ?>
        </div>
        
        <p style="color:#dc3545;"><strong>Deze actie kan niet ongedaan worden gemaakt!</strong></p>
        
        <form method="post" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$customer_id; ?>">
            
            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit" style="background:#dc3545; color:white;">Ja, Verwijderen</button>
                <a class="btn" href="/admin/customers.php" style="background:#6c757d;">Annuleren</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
