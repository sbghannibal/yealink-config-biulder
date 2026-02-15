<?php
// login.php - Secure login page (uses config/database.php which must provide $pdo)
session_start();
require_once __DIR__ . '/config/database.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

/**
 * Simple rate limit (per-session):
 * - max 5 attempts in WINDOW seconds
 */
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW', 900); // 15 minutes

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// Cleanup old attempts
$_SESSION['login_attempts'] = array_filter(
    $_SESSION['login_attempts'],
    function ($ts) {
        return ($ts + LOGIN_WINDOW) >= time();
    }
);

$error = '';
$csrf_token = bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrf_token;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } elseif (count($_SESSION['login_attempts']) >= LOGIN_MAX_ATTEMPTS) {
        $error = 'Te veel maal geprobeerd. Probeer het later opnieuw.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Vul gebruikersnaam en wachtwoord in.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, username, password, is_active FROM admins WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin && $admin['is_active'] && password_verify($password, $admin['password'])) {
                    // Successful login
                    session_regenerate_id(true); // prevent session fixation
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['username'] = $admin['username'];
                    // Reset attempts
                    $_SESSION['login_attempts'] = [];
                    header('Location: index.php');
                    exit;
                } else {
                    // Failed login - record attempt timestamp
                    $_SESSION['login_attempts'][] = time();
                    $error = 'Ongeldige gebruikersnaam of wachtwoord.';
                }
            } catch (Exception $e) {
                // Don't leak DB errors to users
                $error = 'Er is een fout opgetreden. Probeer het later opnieuw.';
                error_log('Login error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login - Yealink Config Builder</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .login-container { max-width: 420px; margin: 80px auto; padding: 30px; background:#fff; border-radius:8px; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
        .login-container h1 { text-align:center; color:#667eea; margin-bottom:18px; }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🔐 Login</h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label for="username">Gebruikersnaam</label>
                <input id="username" name="username" type="text" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Wachtwoord</label>
                <input id="password" name="password" type="password" required>
            </div>

            <button type="submit" class="btn" style="width:100%;">Inloggen</button>
        </form>

        <p style="margin-top:12px; font-size:90%; color:#666;">
            Als je nog geen admin-account hebt, draai eerst <code>php seed.php</code> of maak een account in de database.
        </p>
    </div>
</body>
</html>
