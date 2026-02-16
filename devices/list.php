<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

$devices = [];
$error = '';

try {
    $stmt = $pdo->query('
        SELECT d.id, d.device_name, d.mac_address, d.description, d.is_active, 
               d.created_at, d.updated_at, dt.type_name AS model_name,
               (SELECT cv.version_number FROM config_versions cv 
                JOIN device_config_assignments dca ON dca.config_version_id = cv.id 
                WHERE dca.device_id = d.id 
                ORDER BY cv.id DESC LIMIT 1) as latest_version,
               (SELECT COUNT(*) FROM config_download_history cdh 
                WHERE cdh.mac_address = d.mac_address) as download_count
        FROM devices d 
        LEFT JOIN device_types dt ON d.device_type_id = dt.id
        ORDER BY d.device_name ASC
    ');
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to fetch devices: ' . $e->getMessage());
    $error = 'Kon devices niet ophalen: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Devices - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .badge { 
            display: inline-block; 
            padding: 4px 8px; 
            font-size: 11px; 
            border-radius: 3px; 
            background: #6c757d; 
            color: white; 
            margin-left: 4px; 
        }
        .badge.success { background: #28a745; }
        .badge.warning { background: #ffc107; color: #000; }
        .badge.info { background: #17a2b8; }
        
        .action-buttons { 
            display: flex; 
            gap: 6px; 
            flex-wrap: wrap; 
            align-items: center;
        }
        .action-buttons .btn { 
            font-size: 12px; 
            padding: 6px 10px; 
            white-space: nowrap;
            text-decoration: none;
        }
        
        table tbody tr:nth-child(even) { background: #f8f9fa; }
        table tbody tr:hover { background: #e9ecef; }
        
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .topbar h2 { margin: 0; }
        
        .card {
            background: white;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            background: #f1f3f5;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>

<main class="container">
    <div class="topbar">
        <h2>📱 Devices</h2>
        <a class="btn" href="/devices/create.php" style="background: #28a745; color: white;">➕ Nieuw Device</a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <?php if (empty($devices)): ?>
            <div style="padding: 40px; text-align: center;">
                <p style="color: #6c757d; font-size: 16px;">
                    Geen devices gevonden. 
                    <a href="/devices/create.php" style="color: #007bff; text-decoration: none;">Maak er een aan →</a>
                </p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Naam</th>
                        <th>Model</th>
                        <th>MAC Adres</th>
                        <th>Laatste Config</th>
                        <th>Downloads</th>
                        <th>Status</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td><strong>#<?php echo (int)$d['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($d['device_name']); ?></td>
                            <td>
                                <?php if ($d['model_name']): ?>
                                    <span class="badge info"><?php echo htmlspecialchars($d['model_name']); ?></span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="background: #f1f3f5; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($d['mac_address'] ?? '-'); ?></code></td>
                            <td>
                                <?php if ($d['latest_version']): ?>
                                    <span class="badge success">v<?php echo (int)$d['latest_version']; ?></span>
                                <?php else: ?>
                                    <span class="badge warning">⚠️ Geen config</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: #6c757d;">
                                    <?php echo (int)($d['download_count'] ?? 0); ?>x
                                </span>
                            </td>
                            <td>
                                <?php if ($d['is_active']): ?>
                                    <span class="badge success">✓ Actief</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #dc3545;">✗ Inactief</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a class="btn" href="/devices/configure_wizard.php?device_id=<?php echo (int)$d['id']; ?>" style="background: #28a745; color: white;">⚙️ Config</a>
                                    <a class="btn" href="/devices/edit.php?id=<?php echo (int)$d['id']; ?>" style="background: #007bff; color: white;">✏️ Bewerken</a>
                                    <a class="btn" href="/devices/delete.php?id=<?php echo (int)$d['id']; ?>" onclick="return confirm('Weet je zeker dat je dit device wilt verwijderen?');" style="background: #dc3545; color: white;">🗑️ Verwijderen</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
