<?php
$page_title = 'Config Templates';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Load device types
try {
    $stmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to load device types: ' . $e->getMessage());
    $device_types = [];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $template_name = trim($_POST['template_name'] ?? '');
            $device_type_id = !empty($_POST['device_type_id']) ? (int)$_POST['device_type_id'] : null;
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $template_content = $_POST['template_content'] ?? '';
            $is_default = !empty($_POST['is_default']) ? 1 : 0;
            
            if (empty($template_name) || !$device_type_id || empty($template_content)) {
                $error = 'Vul alle verplichte velden in.';
            } else {
                try {
                    // If setting as default, unset other defaults for this device type
                    if ($is_default) {
                        $stmt = $pdo->prepare('UPDATE config_templates SET is_default = 0 WHERE device_type_id = ?');
                        $stmt->execute([$device_type_id]);
                    }
                    
                    $stmt = $pdo->prepare('
                        INSERT INTO config_templates 
                        (template_name, device_type_id, category, description, template_content, is_default, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $template_name,
                        $device_type_id,
                        $category,
                        $description,
                        $template_content,
                        $is_default,
                        $admin_id
                    ]);
                    
                    $success = 'Template aangemaakt.';
                } catch (Exception $e) {
                    error_log('Template create error: ' . $e->getMessage());
                    $error = 'Kon template niet aanmaken.';
                }
            }
        }
        
        if ($action === 'update') {
            $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
            $template_name = trim($_POST['template_name'] ?? '');
            $device_type_id = !empty($_POST['device_type_id']) ? (int)$_POST['device_type_id'] : null;
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $template_content = $_POST['template_content'] ?? '';
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            $is_default = !empty($_POST['is_default']) ? 1 : 0;
            
            if (!$template_id || empty($template_name) || !$device_type_id || empty($template_content)) {
                $error = 'Vul alle verplichte velden in.';
            } else {
                try {
                    // If setting as default, unset other defaults for this device type
                    if ($is_default) {
                        $stmt = $pdo->prepare('UPDATE config_templates SET is_default = 0 WHERE device_type_id = ? AND id != ?');
                        $stmt->execute([$device_type_id, $template_id]);
                    }
                    
                    $stmt = $pdo->prepare('
                        UPDATE config_templates 
                        SET template_name = ?, device_type_id = ?, category = ?, description = ?, 
                            template_content = ?, is_active = ?, is_default = ?
                        WHERE id = ?
                    ');
                    $stmt->execute([
                        $template_name,
                        $device_type_id,
                        $category,
                        $description,
                        $template_content,
                        $is_active,
                        $is_default,
                        $template_id
                    ]);
                    
                    $success = 'Template bijgewerkt.';
                } catch (Exception $e) {
                    error_log('Template update error: ' . $e->getMessage());
                    $error = 'Kon template niet bijwerken.';
                }
            }
        }
        
        if ($action === 'delete') {
            $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
            if ($template_id) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM config_templates WHERE id = ?');
                    $stmt->execute([$template_id]);
                    $success = 'Template verwijderd.';
                } catch (Exception $e) {
                    error_log('Template delete error: ' . $e->getMessage());
                    $error = 'Kon template niet verwijderen.';
                }
            }
        }
    }
}

// Load templates
try {
    $stmt = $pdo->query('
        SELECT ct.*, dt.type_name, a.username as created_by_name
        FROM config_templates ct
        LEFT JOIN device_types dt ON ct.device_type_id = dt.id
        LEFT JOIN admins a ON ct.created_by = a.id
        ORDER BY ct.category, ct.template_name
    ');
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to load templates: ' . $e->getMessage());
    $templates = [];
}

// If editing, load template
$editing = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$edit_template = null;
if ($editing) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM config_templates WHERE id = ?');
        $stmt->execute([$editing]);
        $edit_template = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to load template: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/_header.php';
?>

<style>
    .templates-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .template-form { max-width: 800px; }
    textarea.template-content { width: 100%; height: 400px; font-family: monospace; }
    .template-card { padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 12px; }
    .template-card.inactive { opacity: 0.6; background: #f9f9f9; }
    .badge { display: inline-block; padding: 2px 8px; font-size: 11px; border-radius: 3px; background: #6c757d; color: white; }
    .badge.default { background: #28a745; }
    .badge.inactive { background: #dc3545; }
</style>

    <h2>Config Templates</h2>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div></div>
        <button class="btn" id="helpButton" style="background: #17a2b8; color: white;">
            ❓ Variable Help
        </button>
    </div>
    
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    
    <div class="templates-grid">
        <div>
            <div class="card">
                <h3><?php echo $edit_template ? 'Template Bewerken' : 'Nieuw Template'; ?></h3>
                <form method="post" class="template-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="<?php echo $edit_template ? 'update' : 'create'; ?>">
                    <?php if ($edit_template): ?>
                        <input type="hidden" name="template_id" value="<?php echo (int)$edit_template['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Template Naam *</label>
                        <input name="template_name" type="text" required value="<?php echo htmlspecialchars($edit_template['template_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Device Type *</label>
                        <select name="device_type_id" required>
                            <option value="">-- Kies type --</option>
                            <?php foreach ($device_types as $dt): ?>
                                <option value="<?php echo (int)$dt['id']; ?>" <?php echo ($edit_template && $edit_template['device_type_id'] == $dt['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dt['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Categorie</label>
                        <input name="category" type="text" value="<?php echo htmlspecialchars($edit_template['category'] ?? ''); ?>" placeholder="b.v. Basic, Advanced, Hotel">
                    </div>
                    
                    <div class="form-group">
                        <label>Beschrijving</label>
                        <textarea name="description" rows="2"><?php echo htmlspecialchars($edit_template['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Template Inhoud * <small>(gebruik {{VARIABELEN}})</small></label>
                        <textarea name="template_content" class="template-content" required><?php echo htmlspecialchars($edit_template['template_content'] ?? "[DEVICE_INFO]\ndevice_name={{DEVICE_NAME}}\ndevice_mac={{DEVICE_MAC}}\n\n[NETWORK]\ndhcp=1\n\n[SIP]\nproxy_ip={{PABX_IP}}\nproxy_port={{PABX_PORT}}"); ?></textarea>
                    </div>
                    
                    <?php if ($edit_template): ?>
                    <div class="form-group">
                        <label><input type="checkbox" name="is_active" value="1" <?php echo ($edit_template['is_active'] ?? 1) ? 'checked' : ''; ?>> Actief</label>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><input type="checkbox" name="is_default" value="1" <?php echo ($edit_template['is_default'] ?? 0) ? 'checked' : ''; ?>> Standaard template voor dit device type</label>
                    </div>
                    
                    <div style="display: flex; gap: 8px;">
                        <button class="btn" type="submit"><?php echo $edit_template ? 'Bijwerken' : 'Aanmaken'; ?></button>
                        <?php if ($edit_template): ?>
                            <a class="btn" href="/admin/templates.php" style="background: #6c757d;">Annuleren</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <div>
            <div class="card">
                <h3>Beschikbare Templates (<?php echo count($templates); ?>)</h3>
                
                <?php if (empty($templates)): ?>
                    <p style="color: #666;">Geen templates gevonden.</p>
                <?php else: ?>
                    <?php
                    $grouped = [];
                    foreach ($templates as $t) {
                        $cat = $t['category'] ?: 'Overig';
                        $grouped[$cat][] = $t;
                    }
                    ?>
                    <?php foreach ($grouped as $cat => $items): ?>
                        <h4 style="margin-top: 16px; margin-bottom: 8px; font-size: 14px; color: #666;"><?php echo htmlspecialchars($cat); ?></h4>
                        <?php foreach ($items as $t): ?>
                            <div class="template-card <?php echo !$t['is_active'] ? 'inactive' : ''; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($t['template_name']); ?></strong>
                                        <div style="font-size: 12px; color: #666;">
                                            <?php echo htmlspecialchars($t['type_name'] ?? '-'); ?>
                                            <?php if ($t['is_default']): ?><span class="badge default">DEFAULT</span><?php endif; ?>
                                            <?php if (!$t['is_active']): ?><span class="badge inactive">INACTIEF</span><?php endif; ?>
                                        </div>
                                        <?php if ($t['description']): ?>
                                            <div style="font-size: 12px; margin-top: 4px;"><?php echo htmlspecialchars($t['description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <a class="btn" href="/admin/template_variables.php?template_id=<?php echo (int)$t['id']; ?>" style="font-size: 11px; padding: 4px 8px; background: #17a2b8;">Variabelen</a>
                                        <a class="btn" href="?edit=<?php echo (int)$t['id']; ?>" style="font-size: 11px; padding: 4px 8px;">Bewerk</a>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Weet je zeker dat je dit template wilt verwijderen?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>">
                                            <button class="btn" type="submit" style="font-size: 11px; padding: 4px 8px; background: #dc3545;">Verwijder</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<script>
document.getElementById('helpButton').addEventListener('click', function() {
    window.open('/template_help.php', 'help', 'width=900,height=700');
});
</script>
</body>
</html>
