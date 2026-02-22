<?php
session_start();
require_once __DIR__ . '/settings/database.php';
require_once __DIR__ . '/includes/i18n.php';

// Allow language switching on this page (no auth required)
$allowed_languages = ['nl', 'fr', 'en'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed_languages, true)) {
    $_SESSION['language'] = $_GET['lang'];
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $redirect);
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Get admin email from settings
$admin_email = 'admin@yealink-cfg.eu'; // Default
try {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute(['admin_email']);
    $result = $stmt->fetchColumn();
    if ($result) {
        $admin_email = $result;
    }
} catch (Exception $e) {
    // Settings table might not exist, use default
}

// Rate limit for requests (prevent spam)
$client_ip = $_SERVER['REMOTE_ADDR'];
$rate_limit_key = 'account_request_' . hash('sha256', $client_ip);

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit check
    if ($_SESSION[$rate_limit_key] >= 3) {
        $error = __('error.too_many_requests');
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $organization = trim($_POST['organization'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        
        // Validation
        if (!$full_name || !$email || !$organization) {
            $error = __('error.fill_required_fields');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = __('error.invalid_email');
        } elseif (strlen($reason) < 10) {
            $error = __('error.reason_too_short');
        } else {
            try {
                // Save request to database
                $stmt = $pdo->prepare('
                    INSERT INTO account_requests 
                    (full_name, email, organization, reason, ip_address, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $full_name,
                    $email,
                    $organization,
                    $reason,
                    $client_ip,
                    'pending'
                ]);
                
                // Send email to admin
                $subject = __('email.account_request_subject');
                $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
                $message = __('email.account_request_body', [
                    'name'        => $full_name,
                    'email'       => $email,
                    'organization' => $organization,
                    'reason'      => $reason,
                    'approve_url' => 'https://' . $host . '/admin/approve_account.php',
                ]);
                
                $headers = "From: noreply@{$_SERVER['HTTP_HOST']}\r\n";
                $headers .= "Reply-To: $email\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                
                mail($admin_email, $subject, $message, $headers);
                
                // Increment rate limit
                $_SESSION[$rate_limit_key]++;
                
                $success = __('page.request_account.success');
                
                // Clear form
                $_POST = [];
                
            } catch (Exception $e) {
                error_log('Account request error: ' . $e->getMessage());
                $error = __('error.server_error');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'nl'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo __('page.request_account.title'); ?></title>
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
        
        .lang-switcher-minimal {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        
        .lang-switcher-minimal a {
            text-decoration: none;
            font-size: 20px;
            opacity: 0.5;
            transition: opacity 0.2s;
        }
        
        .lang-switcher-minimal a:hover,
        .lang-switcher-minimal a.active {
            opacity: 1;
        }
        
        .container {
            max-width: 500px;
            width: 100%;
            margin: 20px;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #667eea;
            margin-bottom: 8px;
            font-size: 24px;
        }
        
        .header p {
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
        
        .form-group label .required {
            color: red;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 120px;
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
        
        .btn-secondary {
            background: #6c757d;
            margin-top: 12px;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
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
        
        .footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
        }
        
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .info-box {
            background: #f0f7ff;
            border-left: 4px solid #667eea;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 13px;
            color: #333;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Language switcher -->
    <div class="lang-switcher-minimal">
        <?php $current_lang = $_SESSION['language'] ?? 'nl'; ?>
        <a href="?lang=nl" class="<?php echo $current_lang === 'nl' ? 'active' : ''; ?>" title="Nederlands">🇳🇱</a>
        <a href="?lang=fr" class="<?php echo $current_lang === 'fr' ? 'active' : ''; ?>" title="Français">🇫🇷</a>
        <a href="?lang=en" class="<?php echo $current_lang === 'en' ? 'active' : ''; ?>" title="English (ENG)">🇺🇸</a>
    </div>

    <div class="container">
        <div class="header">
            <h1>📧 <?php echo __('page.account_request'); ?></h1>
            <p><?php echo __('text.request_admin_account'); ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="info-box">
            💡 <?php echo __('page.request_account.info_text'); ?>
        </div>

        <form method="post" novalidate>
            <div class="form-group">
                <label for="full_name">
                    <?php echo __('form.full_name'); ?> <span class="required">*</span>
                </label>
                <input id="full_name" name="full_name" type="text" required placeholder="<?php echo __('form.full_name_placeholder'); ?>" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">
                    <?php echo __('form.email'); ?> <span class="required">*</span>
                </label>
                <input id="email" name="email" type="email" required placeholder="<?php echo __('form.email_placeholder'); ?>" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="organization">
                    <?php echo __('form.organization'); ?> <span class="required">*</span>
                </label>
                <input id="organization" name="organization" type="text" required placeholder="<?php echo __('form.organization_placeholder'); ?>" value="<?php echo htmlspecialchars($_POST['organization'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="reason">
                    <?php echo __('form.reason'); ?> <span class="required">*</span>
                </label>
                <textarea id="reason" name="reason" required placeholder="<?php echo __('form.reason_placeholder'); ?>"><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                <small style="color: #666; display: block; margin-top: 4px;"><?php echo __('form.reason_hint'); ?></small>
            </div>

            <button type="submit" class="btn"><?php echo __('button.submit_request'); ?></button>
            <a href="login.php" class="btn btn-secondary" style="display: block; text-decoration: none; text-align: center;"><?php echo __('button.back_to_login'); ?></a>
        </form>

        <div class="footer">
            <p>
                <?php echo __('page.request_account.have_account'); ?>
                <a href="login.php"><?php echo __('page.request_account.login_link'); ?></a>
            </p>
        </div>
    </div>
</body>
</html>
