<?php
// login.php - Secure login page (uses settings/database.php which must provide $pdo)
session_start();
require_once __DIR__ . '/settings/database.php';

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

// Ensure a CSRF token exists, but don't overwrite it on POST
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

$error = '';
$success = '';

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
                    // Invalidate CSRF token after successful login
                    unset($_SESSION['csrf_token']);
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            max-width: 420px;
            width: 100%;
            margin: 20px;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #667eea;
            margin-bottom: 8px;
            font-size: 28px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .login-footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 14px;
            color: #666;
            text-align: center;
        }
        
        .login-footer p {
            margin-bottom: 12px;
            line-height: 1.5;
        }
        
        .account-request-btn {
            display: inline-block;
            margin-top: 12px;
            padding: 10px 20px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .account-request-btn:hover {
            background: #218838;
            text-decoration: none;
            color: white;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>☎️ Yealink Config</h1>
            <p>Beheerder Login</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                ✅ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label for="username">Gebruikersnaam</label>
                <input id="username" name="username" type="text" required autofocus placeholder="Voer je gebruikersnaam in">
            </div>

            <div class="form-group">
                <label for="password">Wachtwoord</label>
                <input id="password" name="password" type="password" required placeholder="Voer je wachtwoord in">
            </div>

            <button type="submit" class="btn">🔓 Inloggen</button>
        </form>

        <div class="login-footer">
            <p>
                📝 Heb je nog geen account?
            </p>
            <p>
                Neem contact op met de beheerder via:
            </p>
            <a href="request_account.php" class="account-request-btn">
                📧 Verzoek een Account
            </a>
        </div>
    </div>
    <footer style="text-align:center; padding:20px; color:#6c757d; font-size:13px;">
        <p>Yealink Config Builder &copy; <?php echo date('Y'); ?></p>
    </footer>
</body>
</html>
