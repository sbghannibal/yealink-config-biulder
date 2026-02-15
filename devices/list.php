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

try {
    $stmt = $pdo->query('SELECT d.id, d.device_name, d.mac_address, d.description, d.is_active, d.created_at, d.updated_at, dt.type_name AS model_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id ORDER BY d.device_name ASC');
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to fetch devices: ' . $e->getMessage());
    $devices = [];
    $error = 'Kon devices niet ophalen.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Devices - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>
<main class="container">
    <div class="topbar">
        <h2>Devices</h2>
        <div><a class="btn" href="/devices/create.php">➕ Nieuw Device</a></div>
    </div>

    <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Naam</th>
                    <th>Model</th>
                    <th>MAC</th>
                    <th>Beschrijving</th>
                    <th>Actief</th>
                    <th>Aangemaakt</th>
                    <th>Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($devices) === 0): ?>
                    <tr><td colspan="8" style="text-align:center">Geen devices gevonden. <a href="/devices/create.php">Maak er een aan</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td><?php echo (int)$d['id']; ?></td>
                            <td><?php echo htmlspecialchars($d['device_name']); ?></td>
                            <td><?php echo htmlspecialchars($d['model_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($d['mac_address'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($d['description'] ?? '-'); ?></td>
                            <td><?php echo $d['is_active'] ? 'Ja' : 'Nee'; ?></td>
                            <td><?php echo htmlspecialchars($d['created_at']); ?></td>
                            <td>
                                <a class="btn" href="/devices/edit.php?id=<?php echo (int)$d['id']; ?>">Bewerken</a>
                                <a class="btn" href="/devices/delete.php?id=<?php echo (int)$d['id']; ?>" onclick="return confirm('Weet je het zeker dat je dit device wilt verwijderen?');" style="background:#dc3545;">Verwijderen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
