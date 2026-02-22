<?php
$page_title = 'Nieuwe gebruiker';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'admin.users.create')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF token ensure
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Fetch available roles
try {
    $stmt = $pdo->query('SELECT id, role_name FROM roles ORDER BY role_name ASC');
    $all_roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_roles = [];
    error_log('users_create fetch roles error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST;
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $username = trim($posted['username'] ?? '');
        $email = trim($posted['email'] ?? '');
        $password = $posted['password'] ?? '';
        $password_confirm = $posted['password_confirm'] ?? '';
        $role_id = isset($posted['role_id']) && $posted['role_id'] !== '' ? (int)$posted['role_id'] : null;

        if ($username === '' || $email === '' || $password === '') {
            $error = 'Vul alle vereiste velden in.';
        } elseif ($password !== $password_confirm) {
            $error = 'Wachtwoorden komen niet overeen.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare('INSERT INTO admins (username, password, email) VALUES (?, ?, ?)');
                $stmt->execute([$username, $hash, $email]);
                $newId = $pdo->lastInsertId();
                
                // Assign role if selected
                if ($role_id !== null) {
                    $stmt = $pdo->prepare('INSERT INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
                    $stmt->execute([$newId, $role_id]);
                }
                
                $pdo->commit();
                $success = 'Gebruiker aangemaakt (ID: ' . (int)$newId . ').';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('users_create error: ' . $e->getMessage());
                $error = 'Kon gebruiker niet aanmaken. Mogelijk bestaat de gebruikersnaam of e-mail al.';
            }
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

    <h2><?php echo __('page.users.create'); ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-group">
                <label><?php echo __('form.username'); ?></label>
                <input name="username" type="text" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.email'); ?></label>
                <input name="email" type="email" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.password'); ?></label>
                <input name="password" type="password" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.password_confirm'); ?></label>
                <input name="password_confirm" type="password" required>
            </div>
            <div class="form-group">
                <label><?php echo __('form.role'); ?></label>
                <?php if (empty($all_roles)): ?>
                    <p>Geen rollen geconfigureerd.</p>
                <?php else: ?>
                    <select name="role_id">
                        <option value="">-- <?php echo __('form.select_role'); ?> --</option>
                        <?php foreach ($all_roles as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>">
                                <?php echo htmlspecialchars($r['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display: block; margin-top: 4px; color: #666;">
                        Elke gebruiker heeft één primaire rol. Selecteer de rol die het beste past bij de gebruiker.
                    </small>
                <?php endif; ?>
            </div>
            <button class="btn" type="submit"><?php echo __('button.create'); ?></button>
            <a class="btn" href="/admin/users.php" style="background:#6c757d;"><?php echo __('button.cancel'); ?></a>
        </form>
    </div>
<?php require_once __DIR__ . '/_footer.php'; ?>
