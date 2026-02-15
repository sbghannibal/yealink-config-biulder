<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'admin.users.create')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF token ensure
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST;
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $username = trim($posted['username'] ?? '');
        $email = trim($posted['email'] ?? '');
        $password = $posted['password'] ?? '';
        $password_confirm = $posted['password_confirm'] ?? '';

        if ($username === '' || $email === '' || $password === '') {
            $error = 'Vul alle vereiste velden in.';
        } elseif ($password !== $password_confirm) {
            $error = 'Wachtwoorden komen niet overeen.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('INSERT INTO admins (username, password, email) VALUES (?, ?, ?)');
                $stmt->execute([$username, $hash, $email]);
                $newId = $pdo->lastInsertId();
                $success = 'Gebruiker aangemaakt (ID: ' . (int)$newId . ').';
                // Optionally assign roles via POST['roles'] (not implemented here)
            } catch (Exception $e) {
                error_log('users_create error: ' . $e->getMessage());
                $error = 'Kon gebruiker niet aanmaken. Mogelijk bestaat de gebruikersnaam of e-mail al.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Nieuwe gebruiker</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/_admin_nav.php'; ?>
<main class="container">
    <h2>Nieuwe gebruiker aanmaken</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-group">
                <label>Gebruikersnaam</label>
                <input name="username" type="text" required>
            </div>
            <div class="form-group">
                <label>E-mail</label>
                <input name="email" type="email" required>
            </div>
            <div class="form-group">
                <label>Wachtwoord</label>
                <input name="password" type="password" required>
            </div>
            <div class="form-group">
                <label>Wachtwoord bevestigen</label>
                <input name="password_confirm" type="password" required>
            </div>
            <button class="btn" type="submit">Maak gebruiker</button>
            <a class="btn" href="/admin/users.php" style="background:#6c757d;">Annuleren</a>
        </form>
    </div>
</main>
</body>
</html>
