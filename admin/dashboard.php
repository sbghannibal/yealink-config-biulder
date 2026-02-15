<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Zorg dat gebruiker is ingelogd
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];
$username = $_SESSION['username'] ?? 'Admin';

// Optioneel: check of gebruiker een admin-rol heeft (je kunt dit naar wens aanpassen)
// $roles = get_admin_roles($pdo, $admin_id);
// if (count($roles) === 0) { http_response_code(403); echo 'Toegang geweigerd.'; exit; }

$stats = [
    'admins' => 0,
    'devices' => 0,
    'config_versions' => 0,
    'active_tokens' => 0,
    'recent_audit' => []
];

try {
    // Totale admins
    $stmt = $pdo->query('SELECT COUNT(*) FROM admins');
    $stats['admins'] = (int) $stmt->fetchColumn();

    // Totale devices
    $stmt = $pdo->query('SELECT COUNT(*) FROM devices');
    $stats['devices'] = (int) $stmt->fetchColumn();

    // Totale config versies
    $stmt = $pdo->query('SELECT COUNT(*) FROM config_versions');
    $stats['config_versions'] = (int) $stmt->fetchColumn();

    // Actieve download tokens (niet gebruikt en nog niet verlopen)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM download_tokens WHERE expires_at > NOW() AND used_at IS NULL');
    $stmt->execute();
    $stats['active_tokens'] = (int) $stmt->fetchColumn();

    // Recente audit logs (laatste 10)
    $stmt = $pdo->prepare('SELECT al.*, a.username FROM audit_logs al LEFT JOIN admins a ON al.admin_id = a.id ORDER BY al.created_at DESC LIMIT 10');
    $stmt->execute();
    $stats['recent_audit'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    $error = 'Kon dashboardgegevens niet ophalen. Controleer de logs.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Dashboard - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 16px; margin-bottom: 20px; }
        .stat { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .stat h3 { margin: 0 0 8px 0; color: #667eea; }
        .recent-log { font-family: monospace; white-space: pre-wrap; word-break:break-word; }
        .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="navbar">
                <h1>🔧 Yealink Config Builder — Admin</h1>
                <nav>
                    <a href="/index.php">Dashboard</a>
                    <a href="/admin/dashboard.php">Admin</a>
                    <a href="/devices/list.php">Devices</a>
                    <a href="/config/builder.php">Config Builder</a>
                    <a href="/logout.php">Logout</a>
                </nav>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="topbar">
            <h2>Admin dashboard</h2>
            <div>Ingelogd als: <strong><?php echo htmlspecialchars($username); ?></strong></div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <section class="stats">
            <div class="stat">
                <h3>Gebruikers</h3>
                <p style="font-size:24px; margin:8px 0;"><?php echo $stats['admins']; ?></p>
                <p><a href="/admin/users.php">Beheer gebruikers</a></p>
            </div>

            <div class="stat">
                <h3>Devices</h3>
                <p style="font-size:24px; margin:8px 0;"><?php echo $stats['devices']; ?></p>
                <p><a href="/devices/list.php">Bekijk devices</a></p>
            </div>

            <div class="stat">
                <h3>Config versies</h3>
                <p style="font-size:24px; margin:8px 0;"><?php echo $stats['config_versions']; ?></p>
                <p><a href="/config/versions.php">Beheer versies</a></p>
            </div>

            <div class="stat">
                <h3>Actieve tokens</h3>
                <p style="font-size:24px; margin:8px 0;"><?php echo $stats['active_tokens']; ?></p>
                <p><a href="/admin/tokens.php">Bekijk tokens</a></p>
            </div>
        </section>

        <section class="card">
            <h3>Recente activiteit</h3>
            <?php if (empty($stats['recent_audit'])): ?>
                <p>Geen recente audit-logs gevonden.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Tijd</th>
                            <th>Gebruiker</th>
                            <th>Actie</th>
                            <th>Entiteit</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_audit'] as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($log['username'] ?? 'Systeem'); ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? '-'); ?></td>
                                <td class="recent-log"><?php
                                    $parts = [];
                                    if (!empty($log['old_value'])) $parts[] = 'OLD: ' . htmlspecialchars($log['old_value']);
                                    if (!empty($log['new_value'])) $parts[] = 'NEW: ' . htmlspecialchars($log['new_value']);
                                    echo implode("\n", $parts) ?: '-';
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section style="margin-top:20px;">
            <a class="btn" href="/admin/users.php">Gebruikers beheren</a>
            <a class="btn" href="/admin/roles.php">Rollen & permissies</a>
            <a class="btn" href="/admin/audit.php">Audit logs</a>
            <a class="btn" href="/devices/create.php">Nieuw device</a>
        </section>
    </main>

    <footer>
        <p style="text-align:center; margin-top:40px;">&copy; <?php echo date('Y'); ?> Yealink Config Builder</p>
    </footer>
</body>
</html>
