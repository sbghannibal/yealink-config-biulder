<?php
$page_title = 'Admin Dashboard';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Zorg dat gebruiker is ingelogd
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];
$username = $_SESSION['username'] ?? 'Admin';

// Get user's role(s)
$stmt = $pdo->prepare('
    SELECT r.role_name 
    FROM admin_roles ar
    JOIN roles r ON r.id = ar.role_id
    WHERE ar.admin_id = ?
');
$stmt->execute([$admin_id]);
$user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
$user_role_display = !empty($user_roles) ? implode(', ', $user_roles) : 'Geen rol';

// Determine role badge color
$role_badge_color = '#6c757d'; // default gray
if (in_array('Owner', $user_roles)) {
    $role_badge_color = '#dc3545'; // red for owner
} elseif (in_array('Expert', $user_roles)) {
    $role_badge_color = '#28a745'; // green for expert
} elseif (in_array('Tech', $user_roles)) {
    $role_badge_color = '#007bff'; // blue for tech
}

// Load dashboard settings
function get_setting($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        error_log('Error loading setting ' . $key . ': ' . $e->getMessage());
        return $default;
    }
}

$dashboard_title = get_setting($pdo, 'dashboard_title', 'Welkom bij Yealink Config Builder');
$dashboard_text = get_setting($pdo, 'dashboard_text', "Gebruik het menu om devices en configuraties te beheren.\n\nJe kunt deze tekst aanpassen via Admin → Instellingen.");

$stats = [
    'admins' => 0,
    'devices' => 0,
    'config_versions' => 0,
    'active_tokens' => 0,
    'pending_requests' => 0,
    'account_requests' => [],
    'recent_audit' => []
];

$error = '';

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

    // Pending account requests
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM account_requests WHERE status = ?');
        $stmt->execute(['pending']);
        $stats['pending_requests'] = (int) $stmt->fetchColumn();
        
        // Haal de pending requests op (max 5)
        $stmt = $pdo->prepare('SELECT * FROM account_requests WHERE status = ? ORDER BY created_at DESC LIMIT 5');
        $stmt->execute(['pending']);
        $stats['account_requests'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // account_requests tabel bestaat mogelijk niet
        error_log('Account requests error: ' . $e->getMessage());
    }

    // Recente audit logs (laatste 10)
    try {
        $stmt = $pdo->prepare('SELECT al.*, a.username FROM audit_logs al LEFT JOIN admins a ON al.admin_id = a.id ORDER BY al.created_at DESC LIMIT 10');
        $stmt->execute();
        $stats['recent_audit'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // audit_logs tabel bestaat mogelijk niet
        error_log('Audit logs error: ' . $e->getMessage());
    }
} catch (Exception $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    $error = 'Kon dashboardgegevens niet ophalen. Controleer de logs.';
}

require_once __DIR__ . '/_header.php';
?>

    <style>
        .stats { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 16px; 
            margin-bottom: 20px; 
        }
        
        .stat { 
            background: white;
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #667eea;
        }
        
        .stat h3 { 
            margin: 0 0 12px 0; 
            color: #333;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat .number {
            font-size: 32px; 
            font-weight: 700;
            color: #667eea;
            margin: 8px 0;
        }
        
        .stat p {
            margin: 8px 0 0 0;
            font-size: 13px;
        }
        
        .stat a {
            color: #667eea;
            text-decoration: none;
        }
        
        .stat a:hover {
            text-decoration: underline;
        }
        
        .stat.warning {
            border-left-color: #ffc107;
        }
        
        .stat.warning h3 {
            color: #856404;
        }
        
        .stat.warning .number {
            color: #ffc107;
        }
        
        .requests-widget {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .requests-widget h2 {
            color: #856404;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .requests-list {
            list-style: none;
            padding: 0;
            margin: 16px 0;
        }
        
        .requests-list li {
            background: white;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 8px;
            border-left: 3px solid #ffc107;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .request-info {
            flex: 1;
        }
        
        .request-info strong {
            display: block;
            color: #333;
        }
        
        .request-info small {
            display: block;
            color: #666;
            margin-top: 4px;
        }
        
        .requests-actions {
            display: flex;
            gap: 8px;
        }
        
        .requests-actions a {
            padding: 6px 12px;
            background: #ffc107;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .requests-actions a:hover {
            background: #e0a800;
        }
        
        .recent-log { 
            font-family: monospace; 
            white-space: pre-wrap; 
            word-break: break-word; 
        }
        
        .topbar { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .topbar h2 {
            margin: 0;
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .button-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .dashboard-text-box {
            margin-top: 12px;
            padding: 16px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>

    <div class="topbar">
        <div style="flex: 1;">
            <h1>📊 <?php echo htmlspecialchars($dashboard_title); ?></h1>
            <?php if (!empty($dashboard_text)): ?>
                <div class="dashboard-text-box">
                    <?php echo nl2br(htmlspecialchars($dashboard_text)); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <div style="text-align: right;">
                <div>Ingelogd als: <strong><?php echo htmlspecialchars($username); ?></strong></div>
                <div style="margin-top: 4px;">
                    <span style="background: <?php echo $role_badge_color; ?>; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                        <?php echo htmlspecialchars($user_role_display); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Account Requests Widget -->
    <?php if ($stats['pending_requests'] > 0 && !empty($stats['account_requests']) && has_permission($pdo, $admin_id, 'admin.accounts.manage')): ?>
        <div class="requests-widget">
            <h2>
                ⚠️ 
                <?php echo $stats['pending_requests']; ?> 
                Openstaande Account-Aanvra<?php echo $stats['pending_requests'] !== 1 ? 'gen' : 'ag'; ?>
            </h2>
            
            <ul class="requests-list">
                <?php foreach ($stats['account_requests'] as $req): ?>
                    <li>
                        <div class="request-info">
                            <strong><?php echo htmlspecialchars($req['full_name']); ?></strong>
                            <small>
                                📧 <?php echo htmlspecialchars($req['email']); ?> 
                                • 🏢 <?php echo htmlspecialchars($req['organization']); ?>
                            </small>
                        </div>
                        <div class="requests-actions">
                            <a href="/admin/approve_account.php?filter=pending">Beheer</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            
            <p style="margin: 0; font-size: 13px; color: #856404;">
                💡 <a href="/admin/approve_account.php?filter=pending" style="color: #856404; font-weight: 600;">Alle verzoeken bekijken →</a>
            </p>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <h3>👥 Gebruikers</h3>
            <p class="number"><?php echo $stats['admins']; ?></p>
            <?php if (has_permission($pdo, $admin_id, 'admin.users.view')): ?>
            <p><a href="/admin/users.php">Beheer gebruikers</a></p>
            <?php endif; ?>
        </div>

        <div class="stat">
            <h3>📱 Devices</h3>
            <p class="number"><?php echo $stats['devices']; ?></p>
            <?php if (has_permission($pdo, $admin_id, 'devices.view')): ?>
            <p><a href="/devices/list.php">Bekijk devices</a></p>
            <?php endif; ?>
        </div>

        <div class="stat">
            <h3>⚙️ Config Versies</h3>
            <p class="number"><?php echo $stats['config_versions']; ?></p>
            <?php if (has_permission($pdo, $admin_id, 'config.manage')): ?>
            <p><a href="/settings/builder.php">Beheer versies</a></p>
            <?php endif; ?>
        </div>

        <div class="stat">
            <h3>🔑 Actieve Tokens</h3>
            <p class="number"><?php echo $stats['active_tokens']; ?></p>
            <?php if (has_permission($pdo, $admin_id, 'admin.tokens.manage')): ?>
            <p><a href="/admin/tokens.php">Bekijk tokens</a></p>
            <?php endif; ?>
        </div>

        <?php if ($stats['pending_requests'] > 0 && has_permission($pdo, $admin_id, 'admin.accounts.manage')): ?>
            <div class="stat warning">
                <h3>📧 Account Verzoeken</h3>
                <p class="number"><?php echo $stats['pending_requests']; ?></p>
                <p><a href="/admin/approve_account.php">Goedkeuren</a></p>
            </div>
        <?php endif; ?>
    </section>

    <?php if (has_permission($pdo, $admin_id, 'admin.audit.view')): ?>
    <section class="card">
        <h2>📝 Recente Activiteit</h2>
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
    <?php endif; ?>

    <div class="button-group">
        <?php if (has_permission($pdo, $admin_id, 'admin.users.view')): ?>
        <a class="btn" href="/admin/users.php">👥 Gebruikers Beheren</a>
        <?php endif; ?>
        
        <?php if (has_permission($pdo, $admin_id, 'admin.accounts.manage')): ?>
        <a class="btn" href="/admin/approve_account.php">📧 Account Verzoeken</a>
        <?php endif; ?>
        
        <?php if (has_permission($pdo, $admin_id, 'admin.templates.manage')): ?>
        <a class="btn" href="/admin/templates.php">📋 Templates</a>
        <?php endif; ?>
        
        <?php if (has_permission($pdo, $admin_id, 'devices.create')): ?>
        <a class="btn" href="/devices/create.php">📱 Nieuw Device</a>
        <?php endif; ?>
        
        <?php if (has_permission($pdo, $admin_id, 'admin.audit.view')): ?>
        <a class="btn btn-secondary" href="/admin/audit.php">📑 Audit Logs</a>
        <?php endif; ?>
    </div>

</main>

</body>
</html>
