<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/rbac.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['admin_id'];
$username = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Yealink Config Builder</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header>
        <div class="container">
            <div class="navbar">
                <h1>🔧 Yealink Config Builder</h1>
                <nav>
                    <a href="index.php">Dashboard</a>
                    <a href="admin/dashboard.php">Admin</a>
                    <a href="devices/list.php">Devices</a>
                    <a href="config/builder.php">Config Builder</a>
                    <a href="logout.php">Logout</a>
                </nav>
            </div>
        </div>
    </header>

    <main class="container">
        <h2>Welkom, <?php echo htmlspecialchars($username); ?>!</h2>

        <div class="card">
            <h3>Dashboard</h3>
            <p>Welkom bij de Yealink Config Builder. Hier kun je:</p>
            <ul>
                <li>Devices beheren</li>
                <li>Configuraties aanmaken en bewerken</li>
                <li>Versies beheren</li>
                <li>Download tokens genereren</li>
            </ul>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h3>📱 Devices</h3>
                <p>Beheer je Yealink devices</p>
                <a href="devices/list.php" class="btn">Naar Devices</a>
            </div>

            <div class="card">
                <h3>⚙️ Config Builder</h3>
                <p>Bouw en beheer configuraties</p>
                <a href="config/builder.php" class="btn">Config Builder</a>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Yealink Config Builder. All rights reserved.</p>
    </footer>
</body>
</html>
