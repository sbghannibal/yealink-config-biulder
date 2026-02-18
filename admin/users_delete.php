<?php
$page_title = 'Gebruiker verwijderen';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$current_admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $current_admin_id, 'admin.users.delete')) {
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

$user_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: /admin/users.php');
    exit;
}

// Prevent deleting the currently logged-in user
if ($user_id === $current_admin_id) {
    $error = 'Je kunt jezelf niet verwijderen.';
}

// If POST => perform deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM admins WHERE id = ?');
            $stmt->execute([$user_id]);
            $success = 'Gebruiker verwijderd.';
            header('Location: /admin/users.php?deleted=1');
            exit;
        } catch (Exception $e) {
            error_log('users_delete error: ' . $e->getMessage());
            $error = 'Kon gebruiker niet verwijderen.';
        }
    }
}

// Fetch user for confirmation display
try {
    $stmt = $pdo->prepare('SELECT id, username, email FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: /admin/users.php');
        exit;
    }
} catch (Exception $e) {
    error_log('users_delete fetch user error: ' . $e->getMessage());
    header('Location: /admin/users.php');
    exit;
}

require_once __DIR__ . '/_header.php';
?>

<h2>Gebruiker verwijderen</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <p>Weet je zeker dat je de gebruiker <strong><?php echo htmlspecialchars($user['username']); ?></strong> (<?php echo htmlspecialchars($user['email']); ?>) wilt verwijderen?</p>

        <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
            <button class="btn" type="submit" style="background:#dc3545;">Ja, verwijder</button>
        </form>

        <a class="btn" href="/admin/users.php" style="background:#6c757d; margin-left:8px;">Annuleren</a>
    </div>
</main>
</body>
</html>
