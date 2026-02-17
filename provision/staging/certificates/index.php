<?php
/**
 * Certificate Download Endpoint
 * 
 * Serves:
 * - ca.crt (Yealink Root CA)
 * - device_*.crt (Device-specific certificates)
 */

require_once __DIR__ . '/../../../config/database.php';

// HTTP Basic Authentication for certificate downloads
$auth_username = getenv('STAGING_AUTH_USER') ?: 'provisioning';
$auth_password = getenv('STAGING_AUTH_PASS') ?: '';

// Check if authentication is configured
if (!empty($auth_password)) {
    // Require authentication
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) ||
        $_SERVER['PHP_AUTH_USER'] !== $auth_username ||
        $_SERVER['PHP_AUTH_PW'] !== $auth_password) {
        
        header('WWW-Authenticate: Basic realm="Yealink Certificate Download"');
        http_response_code(401);
        echo "Authentication required";
        exit;
    }
}

// Security: Verify User-Agent is from Yealink device
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_yealink = false;

// Check if User-Agent contains Yealink identifier
if (stripos($user_agent, 'yealink') !== false) {
    $is_yealink = true;
}

// Allow bypass for testing with specific parameter (only for Owner in development)
$allow_test = isset($_GET['allow_test']) && $_GET['allow_test'] === getenv('STAGING_TEST_TOKEN');

if (!$is_yealink && !$allow_test) {
    http_response_code(403);
    echo "Access denied - Invalid device type";
    error_log("Staging cert rejected - Non-Yealink User-Agent: $user_agent from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

// Get certificate name from query parameter or path
$cert_name = $_GET['cert'] ?? '';

// If cert not in query, try to extract from path but only the filename
if (empty($cert_name)) {
    $path_parts = explode('/', $_SERVER['REQUEST_URI']);
    $cert_name = end($path_parts);
    // Remove query string if present
    $cert_name = explode('?', $cert_name)[0];
}

$cert_name = basename($cert_name);

// Security: only allow specific certificate files (no device-specific certs by MAC)
// We use shared certificates for all devices to prevent MAC address spoofing
$allowed_certs = ['ca.crt', 'server.crt'];
if (!in_array($cert_name, $allowed_certs)) {
    http_response_code(400);
    echo "Invalid certificate name. Only ca.crt and server.crt are allowed.";
    error_log("Staging cert request rejected - invalid cert: $cert_name from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

$cert_file = __DIR__ . '/' . $cert_name;

// Validate file exists and is in this directory
if (!file_exists($cert_file) || !is_file($cert_file)) {
    http_response_code(404);
    echo "Certificate not found";
    exit;
}

// Security: Verify resolved path is within certificates directory
$real_cert_path = realpath($cert_file);
$real_cert_dir = realpath(__DIR__);
if ($real_cert_path === false || strpos($real_cert_path, $real_cert_dir) !== 0) {
    http_response_code(403);
    echo "Access denied";
    exit;
}

// Serve certificate
header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="' . $cert_name . '"');
readfile($cert_file);
?>
