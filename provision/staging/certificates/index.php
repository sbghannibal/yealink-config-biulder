<?php
/**
 * Certificate Download Endpoint
 * 
 * Serves:
 * - ca.crt (Yealink Root CA)
 * - device_*.crt (Device-specific certificates)
 */

require_once __DIR__ . '/../../../config/database.php';

$cert_name = basename($_GET['cert'] ?? $_SERVER['REQUEST_URI']);
$mac = $_GET['mac'] ?? '';

// Security: only allow .crt files
if (!preg_match('/^[a-z0-9_\.]+\.crt$/i', $cert_name)) {
    http_response_code(400);
    echo "Invalid certificate name";
    exit;
}

$cert_file = __DIR__ . '/' . $cert_name;

// Validate file exists and is in this directory
if (!file_exists($cert_file) || !is_file($cert_file)) {
    http_response_code(404);
    echo "Certificate not found";
    exit;
}

// For device-specific certs, verify MAC matches
if (strpos($cert_name, 'device_') === 0 && !empty($mac)) {
    $mac_clean = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
    $cert_mac = strtoupper(str_replace(['device_', '.crt'], '', $cert_name));
    
    if ($mac_clean !== $cert_mac) {
        http_response_code(403);
        echo "MAC mismatch";
        exit;
    }
}

// Serve certificate
header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="' . $cert_name . '"');
readfile($cert_file);
?>
