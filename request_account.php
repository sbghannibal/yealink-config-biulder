<?php
session_start();
require_once __DIR__ . '/config/database.php';

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
        $error = 'Je hebt te veel verzoeken ingediend. Probeer het later opnieuw.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $organization = trim($_POST['organization'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        
        // Validation
        if (!$full_name || !$email || !$organization) {
            $error = 'Vul alstublieft alle verplichte velden in.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Voer een geldig e-mailadres in.';
        } elseif (strlen($reason) < 10) {
            $error = 'Geef alstublieft meer informatie over je verzoek (minimaal 10 tekens).';
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
                $subject = 'Nieuw Account Verzoek - Yealink Config Builder';
                $message = "
Er is een nieuw verzoek ingediend voor een beheerdersaccount:

👤 Naam: $full_name
📧 E-mailadres: $email
🏢 Organisatie: $organization

📝 Reden voor verzoek:
$reason

🔗 Link om account goed te keuren:
https://{$_SERVER['HTTP_HOST']}/admin/approve_account.php

---
Dit verzoek is verzonden via het accountaanvraagformulier.
";
                
                $headers = "From: noreply@{$_SERVER['HTTP_HOST']}\r\n";
                $headers .= "Reply-To: $email\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                
                mail($admin_email, $subject, $message, $headers);
                
                // Increment rate limit
                $_SESSION[$rate_limit_key]++;
                
                $success = 'Dank je wel! Je verzoek is verzonden naar de beheerder. Je ontvangt binnenkort een e-mail.';
                
                // Clear form
                $_POST = [];
                
            } catch (Exception $e) {
                error_log('Account request error: ' . $e->getMessage());
                $error = 'Er is een fout opgetreden. Probeer het later opnieuw.';
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
    <title>Account Verzoek - Yealink Config Builder</title>
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
    <div class="container">
        <div class="header">
            <h1>📧 Account Verzoek</h1>
            <p>Vraag een beheerdersaccount aan</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="info-box">
            💡 Vul het formulier in en je verzoek wordt verzonden naar de beheerder. Je ontvangt binnenkort een e-mail met verder instructies.
        </div>

        <form method="post" novalidate>
            <div class="form-group">
                <label for="full_name">
                    Volledige Naam <span class="required">*</span>
                </label>
                <input id="full_name" name="full_name" type="text" required placeholder="bijv. John Doe" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">
                    E-mailadres <span class="required">*</span>
                </label>
                <input id="email" name="email" type="email" required placeholder="jouw@email.nl" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="organization">
                    Organisatie/Bedrijf <span class="required">*</span>
                </label>
                <input id="organization" name="organization" type="text" required placeholder="bijv. Acme Corp" value="<?php echo htmlspecialchars($_POST['organization'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="reason">
                    Reden voor Verzoek <span class="required">*</span>
                </label>
                <textarea id="reason" name="reason" required placeholder="Beschrijf waarom je een account nodig hebt..."><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                <small style="color: #666; display: block; margin-top: 4px;">Minimaal 10 tekens</small>
            </div>

            <button type="submit" class="btn">📤 Verzend Verzoek</button>
            <a href="login.php" class="btn btn-secondary" style="display: block; text-decoration: none; text-align: center;">← Terug naar Login</a>
        </form>

        <div class="footer">
            <p>
                Heb je al een account? 
                <a href="login.php">Log hier in →</a>
            </p>
        </div>
    </div>
</body>
</html>
