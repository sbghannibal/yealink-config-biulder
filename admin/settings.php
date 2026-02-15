<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission required to edit settings
if (!has_permission($pdo, $admin_id, 'admin.settings.edit')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

function get_setting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function upsert_setting($pdo, $key, $value) {
    // Try update, if affected rows == 0 try insert
    $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?');
    $stmt->execute([$value, $key]);
    if ($stmt->rowCount() === 0) {
        $ins = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)');
        $ins->execute([$key, $value]);
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $title = trim($_POST['dashboard_title'] ?? '');
        $text = trim($_POST['dashboard_text'] ?? '');

        // Minimal validation
        if ($title === '') $title = 'Welkom bij Yealink Config Builder';

        try {
            $pdo->beginTransaction();
            upsert_setting($pdo, 'dashboard_title', $title);
            upsert_setting($pdo, 'dashboard_text', $text);
            $pdo->commit();
            $success = 'Instellingen opgeslagen.';
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('settings save error: ' . $e->getMessage());
            $error = 'Kon instellingen niet opslaan.';
        }
    }
}

// Load current values
$dashboard_title = get_setting($pdo, 'dashboard_title', 'Welkom bij Yealink Config Builder');
$dashboard_text  = get_setting($pdo, 'dashboard_text', "Gebruik het menu om devices en configuraties te beheren.\n\nJe kunt deze tekst aanpassen via Admin → Instellingen.");
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Instellingen - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<?php include __DIR__ . '/_admin_nav.php'; ?>
<main class="container">
    <h2>Instellingen</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-group">
                <label>Dashboard Titel</label>
                <input name="dashboard_title" type="text" value="<?php echo htmlspecialchars($dashboard_title); ?>">
            </div>

            <div class="form-group">
                <label>Dashboard Tekst (plain text, enter = nieuwe regel)</label>
                <textarea name="dashboard_text" rows="8"><?php echo htmlspecialchars($dashboard_text); ?></textarea>
            </div>

            <button class="btn" type="submit">Opslaan</button>
            <a class="btn" href="/admin/dashboard.php" style="background:#6c757d;">Terug naar admin</a>
        </form>
    </div>
</main>
</body>
</html>
