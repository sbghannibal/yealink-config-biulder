<?php
/**
 * Access Denied Page
 * Shows a friendly error message when user doesn't have permission
 * Auto-redirects to index.php after 10 seconds
 */
session_start();

// Get referrer or default to index
$redirect_url = '/index.php';
$redirect_delay = 10; // seconds

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="<?php echo $redirect_delay; ?>;url=<?php echo htmlspecialchars($redirect_url); ?>">
    <title>Toegang Geweigerd - Yealink Config Builder</title>
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
            padding: 20px;
        }
        
        .error-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 48px;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        
        .error-icon {
            font-size: 80px;
            margin-bottom: 24px;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        h1 {
            color: #333;
            font-size: 32px;
            margin-bottom: 16px;
            font-weight: 700;
        }
        
        .error-code {
            color: #dc3545;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        
        p {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .redirect-info {
            margin-top: 24px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 14px;
            color: #666;
        }
        
        .countdown {
            font-weight: 600;
            color: #667eea;
        }
        
        @media (max-width: 768px) {
            .error-container {
                padding: 32px 24px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .error-icon {
                font-size: 60px;
            }
        }
    </style>
    <script>
        let countdown = <?php echo $redirect_delay; ?>;
        
        function updateCountdown() {
            const element = document.getElementById('countdown');
            if (element) {
                element.textContent = countdown;
            }
            
            if (countdown > 0) {
                countdown--;
                setTimeout(updateCountdown, 1000);
            } else {
                window.location.href = '<?php echo htmlspecialchars($redirect_url); ?>';
            }
        }
        
        window.addEventListener('DOMContentLoaded', updateCountdown);
    </script>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">☎️🚫</div>
        <h1>Oeps! Toegang Geweigerd</h1>
        <div class="error-code">HTTP 403 - Forbidden</div>
        <p>
            Je hebt geen toestemming om deze pagina te bekijken. 
            Deze functie is alleen beschikbaar voor gebruikers met specifieke rechten.
        </p>
        <p>
            Neem contact op met een beheerder als je denkt dat je toegang zou moeten hebben tot deze functionaliteit.
        </p>
        
        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="btn">
            Terug naar Dashboard
        </a>
        
        <div class="redirect-info">
            ⏱️ Je wordt automatisch doorgestuurd over <span class="countdown" id="countdown"><?php echo $redirect_delay; ?></span> seconden...
        </div>
    </div>
</body>
</html>
