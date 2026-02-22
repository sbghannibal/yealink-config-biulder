<?php
/**
 * Language switcher handler
 */
session_start();
require_once __DIR__ . '/../settings/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

$allowed_languages = ['nl', 'fr', 'en'];
$lang = $_GET['lang'] ?? '';

if (!in_array($lang, $allowed_languages, true)) {
    // Invalid language, redirect back
    $referer = $_SERVER['HTTP_REFERER'] ?? '/admin/dashboard.php';
    header('Location: ' . $referer);
    exit;
}

// Update session
$_SESSION['language'] = $lang;

// Update database
try {
    $stmt = $pdo->prepare('UPDATE admins SET language = ? WHERE id = ?');
    $stmt->execute([$lang, $admin_id]);
} catch (Exception $e) {
    error_log('set_language db update error: ' . $e->getMessage());
}

// Redirect back to the referring page
$referer = $_SERVER['HTTP_REFERER'] ?? '/admin/dashboard.php';

// Prevent open redirect: only allow same-origin redirects by stripping host
$parsed = parse_url($referer);
if (!empty($parsed['host'])) {
    // Use only path + query from the referer to avoid host header injection
    $safe_path = ($parsed['path'] ?? '/admin/dashboard.php');
    if (!empty($parsed['query'])) {
        $safe_path .= '?' . $parsed['query'];
    }
    $referer = $safe_path;
}

header('Location: ' . $referer);
exit;
