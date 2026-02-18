<?php
$page_title = 'Gebruiker bewerken';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$current_admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $current_admin_id, 'admin.users.edit')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

$error = '';
$success = '';
// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Get user id to edit
$user_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: /admin/users.php');
    exit;
}

// Fetch available roles
try {
    $stmt = $pdo->query('SELECT id, role_name FROM roles ORDER BY role_name ASC');
    $all_roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_roles = [];
    error_log('users_edit fetch roles error: ' . $e->getMessage());
}

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;
        $password = $_POST['password'] ?? '';
        $role_id = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int)$_POST['role_id'] : null;

        if ($username === '' || $email === '') {
            $error = 'Vul gebruikersnaam en e-mail in.';
        } else {
            try {
                $pdo->beginTransaction();

                // Update basic fields
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare('UPDATE admins SET username = ?, email = ?, password = ?, is_active = ? WHERE id = ?');
                    $stmt->execute([$username, $email, $hash, $is_active, $user_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET username = ?, email = ?, is_active = ? WHERE id = ?');
                    $stmt->execute([$username, $email, $is_active, $user_id]);
                }

                // Update role: remove existing then insert selected (only one role)
                $stmt = $pdo->prepare('DELETE FROM admin_roles WHERE admin_id = ?');
                $stmt->execute([$user_id]);

                if ($role_id !== null) {
                    $ins = $pdo->prepare('INSERT INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
                    $ins->execute([$user_id, $role_id]);
                }

                $pdo->commit();

                $success = 'Gebruiker succesvol bijgewerkt.';
                // If current admin updated their own username, refresh session username
                if ($user_id === $current_admin_id) {
                    $_SESSION['username'] = $username;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('users_edit update error: ' . $e->getMessage());
                // Handle duplicate username/email gracefully
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = 'Gebruikersnaam of e-mail bestaat al.';
                } else {
                    $error = 'Kon gebruiker niet bijwerken.';
                }
            }
        }
    }
}

// Fetch user data for form (fresh from DB)
try {
    $stmt = $pdo->prepare('SELECT id, username, email, is_active, created_at FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: /admin/users.php');
        exit;
    }

    // Fetch assigned role ids
    $stmt = $pdo->prepare('SELECT role_id FROM admin_roles WHERE admin_id = ?');
    $stmt->execute([$user_id]);
    $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $assigned = array_map('intval', $assigned);
} catch (Exception $e) {
    error_log('users_edit fetch user error: ' . $e->getMessage());
    $error = 'Kon gebruikersgegevens niet ophalen.';
    $user = ['id' => $user_id, 'username' => '', 'email' => '', 'is_active' => 0];
    $assigned = [];
}

require_once __DIR__ . '/_header.php';
?>

    <h2>Gebruiker bewerken</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">

            <div class="form-group">
                <label>Gebruikersnaam</label>
                <input name="username" type="text" value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>

            <div class="form-group">
                <label>E-mail</label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <div class="form-group">
                <label>Nieuw wachtwoord (leeg laten om ongewijzigd)</label>
                <input name="password" type="password" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label>Actief</label>
                <select name="is_active">
                    <option value="1" <?php echo $user['is_active'] ? 'selected' : ''; ?>>Ja</option>
                    <option value="0" <?php echo !$user['is_active'] ? 'selected' : ''; ?>>Nee</option>
                </select>
            </div>

            <div class="form-group">
                <label>Rol</label>
                <?php if (empty($all_roles)): ?>
                    <p>Geen rollen geconfigureerd.</p>
                <?php else: ?>
                    <select name="role_id">
                        <option value="">-- Selecteer een rol --</option>
                        <?php foreach ($all_roles as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>"
                                <?php echo (count($assigned) === 1 && in_array((int)$r['id'], $assigned, true)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display: block; margin-top: 4px; color: #666;">
                        Elke gebruiker heeft één primaire rol. Selecteer de rol die het beste past bij de gebruiker.
                    </small>
                <?php endif; ?>
            </div>

            <button class="btn" type="submit">Opslaan</button>
            <a class="btn" href="/admin/users.php" style="background:#6c757d;">Annuleren</a>
        </form>
    </div>
</main>
</body>
</html>
