<?php
/**
 * Serve certificate files directly
 * No MAC validation needed - these are public staging certs
 */

date_default_timezone_set('UTC');

// Get requested file
$file = basename($_SERVER['REQUEST_URI']);

// Only allow .crt files
if (!preg_match('/^[a-z0-9_]+\.crt$/i', $file)) {
    http_response_code(404);
    echo "Not found";
    exit;
}

$path = __DIR__ . '/' . $file;

// Check if file exists
if (!file_exists($path)) {
    http_response_code(404);
    echo "Certificate not found";
    exit;
}

// Serve the certificate
header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
?>
