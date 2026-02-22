<?php
$page_title = 'Instellingen';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

// $pdo is already available from database.php

function get_setting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row['setting_value'];
    } else {
        return $default;
    }
}

function upsert_setting($pdo, $key, $value) {
    $stmt = $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
        VALUES (?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ');
    $stmt->execute([$key, $value]);
}

$error = '';
$success = '';

// Generate CSRF token only if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $title_nl = trim($_POST['dashboard_title_nl'] ?? '');
        $text_nl = trim($_POST['dashboard_text_nl'] ?? '');
        
        $title_en = trim($_POST['dashboard_title_en'] ?? '');
        $text_en = trim($_POST['dashboard_text_en'] ?? '');
        
        $title_fr = trim($_POST['dashboard_title_fr'] ?? '');
        $text_fr = trim($_POST['dashboard_text_fr'] ?? '');

        if ($title_nl === '') $title_nl = 'Welkom bij Yealink Config Builder';

        try {
            $pdo->beginTransaction();
            
            upsert_setting($pdo, 'dashboard_title_nl', $title_nl);
            upsert_setting($pdo, 'dashboard_text_nl', $text_nl);
            
            upsert_setting($pdo, 'dashboard_title_en', $title_en);
            upsert_setting($pdo, 'dashboard_text_en', $text_en);
            
            upsert_setting($pdo, 'dashboard_title_fr', $title_fr);
            upsert_setting($pdo, 'dashboard_text_fr', $text_fr);
            
            $pdo->commit();
            $success = 'Instellingen opgeslagen.';
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('settings save error: ' . $e->getMessage());
            $error = 'Kon instellingen niet opslaan: ' . $e->getMessage();
        }
    }
}

$dashboard_title_nl = get_setting($pdo, 'dashboard_title_nl', 'Welkom bij Yealink Config Builder');
$dashboard_text_nl  = get_setting($pdo, 'dashboard_text_nl', "Gebruik het menu om devices en configuraties te beheren.");

$dashboard_title_en = get_setting($pdo, 'dashboard_title_en', 'Welcome to Yealink Config Builder');
$dashboard_text_en  = get_setting($pdo, 'dashboard_text_en', "Use the menu to manage devices and configurations.");

$dashboard_title_fr = get_setting($pdo, 'dashboard_title_fr', 'Bienvenue dans Yealink Config Builder');
$dashboard_text_fr  = get_setting($pdo, 'dashboard_text_fr', "Utilisez le menu pour gérer les appareils et les configurations.");

require_once __DIR__ . '/_header.php';
?>

<style>
.tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #ddd;
}
.tab {
    padding: 10px 20px;
    cursor: pointer;
    background: #f5f5f5;
    border: none;
    border-radius: 5px 5px 0 0;
    font-size: 16px;
    transition: all 0.3s;
}
.tab:hover {
    background: #e0e0e0;
}
.tab.active {
    background: #007bff;
    color: white;
}
.tab-content {
    display: none;
    animation: fadeIn 0.3s;
}
.tab-content.active {
    display: block;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<div class="container">
    <h1>⚙️ <?php echo __('nav.settings'); ?></h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            
            <div class="tabs">
                <button type="button" class="tab active" onclick="switchTab(event, 'nl')">🇳🇱 Nederlands</button>
                <button type="button" class="tab" onclick="switchTab(event, 'en')">🇺🇸 English</button>
                <button type="button" class="tab" onclick="switchTab(event, 'fr')">🇫🇷 Français</button>
            </div>

            <div id="tab-nl" class="tab-content active">
                <h3>🇳🇱 Nederlandse versie</h3>
                <div class="form-group">
                    <label><?php echo __('form.dashboard_title'); ?></label>
                    <input name="dashboard_title_nl" type="text" value="<?php echo htmlspecialchars($dashboard_title_nl); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo __('form.dashboard_text'); ?></label>
                    <textarea name="dashboard_text_nl" rows="8"><?php echo htmlspecialchars($dashboard_text_nl); ?></textarea>
                </div>
            </div>

            <div id="tab-en" class="tab-content">
                <h3>🇺🇸 English version</h3>
                <div class="form-group">
                    <label><?php echo __('form.dashboard_title'); ?></label>
                    <input name="dashboard_title_en" type="text" value="<?php echo htmlspecialchars($dashboard_title_en); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo __('form.dashboard_text'); ?></label>
                    <textarea name="dashboard_text_en" rows="8"><?php echo htmlspecialchars($dashboard_text_en); ?></textarea>
                </div>
            </div>

            <div id="tab-fr" class="tab-content">
                <h3>🇫🇷 Version française</h3>
                <div class="form-group">
                    <label><?php echo __('form.dashboard_title'); ?></label>
                    <input name="dashboard_title_fr" type="text" value="<?php echo htmlspecialchars($dashboard_title_fr); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo __('form.dashboard_text'); ?></label>
                    <textarea name="dashboard_text_fr" rows="8"><?php echo htmlspecialchars($dashboard_text_fr); ?></textarea>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center;">
            <button class="btn" type="submit" style="height: 45px;"><?php echo __('button.save'); ?></button>
            <a class="btn" href="/admin/dashboard.php" style="background:#6c757d; height: 45px; display: flex; align-items: center;"><?php echo __('button.back'); ?></a>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(event, lang) {
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    document.getElementById('tab-' + lang).classList.add('active');
    event.target.classList.add('active');
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
