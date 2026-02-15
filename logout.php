<?php
// logout.php - destroy session and redirect to login
session_start();

// Clear session array
$_SESSION = [];

// If session cookie exists, invalidate it
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session data on server
session_destroy();

// Regenerate session id for extra safety
if (function_exists('session_regenerate_id')) {
    @session_regenerate_id(true);
}

// Redirect to login (with optional flag so login page can show a "logged out" message)
header('Location: /login.php?logged_out=1');
exit;
?>
