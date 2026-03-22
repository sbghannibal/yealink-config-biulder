<?php
/**
 * Yealink Action URL Event Receiver
 *
 * Receives phone events like:
 * - ip_change
 * - register_failed
 *
 * Example:
 * /yealink/event.php?event=ip_change&ip=$ip&mac=$mac&fw=$firmware
 */

require_once __DIR__ . '/../settings/database.php';

// Optional token check (set in env if you want)
$expectedToken = getenv('YEALINK_TOKEN') ?: '';
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

if ($expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$event = isset($_GET['event']) ? trim((string) $_GET['event']) : '';
$ip    = isset($_GET['ip'])    ? trim((string) $_GET['ip'])    : null;
$mac   = isset($_GET['mac'])   ? trim((string) $_GET['mac'])   : null;
$fw    = isset($_GET['fw'])    ? trim((string) $_GET['fw'])    : null;

if ($event === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing event\n";
    exit;
}

// Keep it bounded
if ($mac !== null && strlen($mac) > 64) $mac = substr($mac, 0, 64);
if ($ip  !== null && strlen($ip)  > 64) $ip  = substr($ip, 0, 64);
if ($fw  !== null && strlen($fw)  > 128) $fw = substr($fw, 0, 128);
if (strlen($event) > 64) $event = substr($event, 0, 64);

$sourceIp = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
if ($ua !== null && strlen($ua) > 255) $ua = substr($ua, 0, 255);

try {
    $stmt = $pdo->prepare('
        INSERT INTO yealink_action_events
            (received_at, event, mac, ip, fw, source_ip, user_agent)
        VALUES
            (NOW(3), ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$event, $mac, $ip, $fw, $sourceIp, $ua]);
} catch (Exception $e) {
    error_log('yealink event ingest error: ' . $e->getMessage());
    // Still return 200 so the phone is not blocked/retrying forever
}

header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";