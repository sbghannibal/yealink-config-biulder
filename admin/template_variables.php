<?php
$page_title = 'Template Variables';
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
    echo 'Toegang geweigerd.';
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Get template ID from query string
$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : null;

if (!$template_id) {
    header('Location: /admin/templates.php');
    exit;
}

// Load template
try {
    $stmt = $pdo->prepare('
        SELECT ct.*, dt.type_name 
        FROM config_templates ct
        LEFT JOIN device_types dt ON ct.device_type_id = dt.id
        WHERE ct.id = ?
    ');
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        $error = 'Template niet gevonden.';
        $template_id = null;
    }
} catch (Exception $e) {
    error_log('Failed to load template: ' . $e->getMessage());
    $error = 'Kon template niet laden.';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $template_id) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $var_name = trim($_POST['var_name'] ?? '');
            $var_label = trim($_POST['var_label'] ?? '');
            $var_type = $_POST['var_type'] ?? 'text';
            $default_value = trim($_POST['default_value'] ?? '');
            $is_required = !empty($_POST['is_required']) ? 1 : 0;
            $placeholder = trim($_POST['placeholder'] ?? '');
            $help_text = trim($_POST['help_text'] ?? '');
            $min_value = !empty($_POST['min_value']) ? (int)$_POST['min_value'] : null;
            $max_value = !empty($_POST['max_value']) ? (int)$_POST['max_value'] : null;
            $regex_pattern = trim($_POST['regex_pattern'] ?? '');
            $options = trim($_POST['options'] ?? '');
            $display_order = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
            
            if (empty($var_name)) {
                $error = 'Variabele naam is verplicht.';
            } else {
                // Validate options JSON if provided
                if (!empty($options)) {
                    $decoded = json_decode($options, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $error = 'Ongeldige JSON voor options.';
                    }
                }
                
                if (!$error) {
                    try {
                        $stmt = $pdo->prepare('
                            INSERT INTO template_variables 
                            (template_id, var_name, var_label, var_type, default_value, is_required, 
                             placeholder, help_text, min_value, max_value, regex_pattern, options, display_order)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $template_id,
                            $var_name,
                            $var_label ?: null,
                            $var_type,
                            $default_value ?: null,
                            $is_required,
                            $placeholder ?: null,
                            $help_text ?: null,
                            $min_value,
                            $max_value,
                            $regex_pattern ?: null,
                            $options ?: null,
                            $display_order
                        ]);
                        
                        $success = 'Variabele aangemaakt.';
                    } catch (Exception $e) {
                        error_log('Variable create error: ' . $e->getMessage());
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            $error = 'Een variabele met deze naam bestaat al voor dit template.';
                        } else {
                            $error = 'Kon variabele niet aanmaken.';
                        }
                    }
                }
            }
        }
        
        if ($action === 'update') {
            $var_id = !empty($_POST['var_id']) ? (int)$_POST['var_id'] : null;
            $var_name = trim($_POST['var_name'] ?? '');
            $var_label = trim($_POST['var_label'] ?? '');
            $var_type = $_POST['var_type'] ?? 'text';
            $default_value = trim($_POST['default_value'] ?? '');
            $is_required = !empty($_POST['is_required']) ? 1 : 0;
            $placeholder = trim($_POST['placeholder'] ?? '');
            $help_text = trim($_POST['help_text'] ?? '');
            $min_value = !empty($_POST['min_value']) ? (int)$_POST['min_value'] : null;
            $max_value = !empty($_POST['max_value']) ? (int)$_POST['max_value'] : null;
            $regex_pattern = trim($_POST['regex_pattern'] ?? '');
            $options = trim($_POST['options'] ?? '');
            $display_order = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
            
            if (!$var_id || empty($var_name)) {
                $error = 'Variabele ID en naam zijn verplicht.';
            } else {
                // Validate options JSON if provided
                if (!empty($options)) {
                    $decoded = json_decode($options, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $error = 'Ongeldige JSON voor options.';
                    }
                }
                
                if (!$error) {
                    try {
                        $stmt = $pdo->prepare('
                            UPDATE template_variables 
                            SET var_name = ?, var_label = ?, var_type = ?, default_value = ?, is_required = ?,
                                placeholder = ?, help_text = ?, min_value = ?, max_value = ?, 
                                regex_pattern = ?, options = ?, display_order = ?
                            WHERE id = ? AND template_id = ?
                        ');
                        $stmt->execute([
                            $var_name,
                            $var_label ?: null,
                            $var_type,
                            $default_value ?: null,
                            $is_required,
                            $placeholder ?: null,
                            $help_text ?: null,
                            $min_value,
                            $max_value,
                            $regex_pattern ?: null,
                            $options ?: null,
                            $display_order,
                            $var_id,
                            $template_id
                        ]);
                        
                        $success = 'Variabele bijgewerkt.';
                    } catch (Exception $e) {
                        error_log('Variable update error: ' . $e->getMessage());
                        $error = 'Kon variabele niet bijwerken.';
                    }
                }
            }
        }
        
        if ($action === 'delete') {
            $var_id = !empty($_POST['var_id']) ? (int)$_POST['var_id'] : null;
            if ($var_id) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM template_variables WHERE id = ? AND template_id = ?');
                    $stmt->execute([$var_id, $template_id]);
                    $success = 'Variabele verwijderd.';
                } catch (Exception $e) {
                    error_log('Variable delete error: ' . $e->getMessage());
                    $error = 'Kon variabele niet verwijderen.';
                }
            }
        }
    }
}

// Load variables for this template
$variables = [];
if ($template_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT * FROM template_variables 
            WHERE template_id = ? 
            ORDER BY display_order, var_name
        ');
        $stmt->execute([$template_id]);
        $variables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to load variables: ' . $e->getMessage());
    }
}

// If editing, load variable
$editing = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$edit_variable = null;
if ($editing && $template_id) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM template_variables WHERE id = ? AND template_id = ?');
        $stmt->execute([$editing, $template_id]);
        $edit_variable = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to load variable: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/_header.php';
?>

<style>
    .var-types-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .var-card { padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 12px; background: #f9f9f9; }
    .type-badge { display: inline-block; padding: 2px 8px; font-size: 11px; border-radius: 3px; background: #6c757d; color: white; margin-left: 8px; }
    .form-section { background: #f5f5f5; padding: 12px; border-radius: 4px; margin-top: 12px; }
    .form-section h4 { margin-top: 0; font-size: 14px; color: #666; }
    .type-specific-fields { display: none; }
    .type-specific-fields.active { display: block; }
</style>

<script>
function updateTypeFields() {
    const varType = document.getElementById('var_type').value;
    
    // Hide all type-specific sections
    document.querySelectorAll('.type-specific-fields').forEach(el => {
        el.classList.remove('active');
    });
    
    // Show relevant sections
    if (['select', 'multiselect', 'radio', 'checkbox_group', 'boolean'].includes(varType)) {
        document.getElementById('options-section').classList.add('active');
    }
    if (['number', 'range'].includes(varType)) {
        document.getElementById('number-section').classList.add('active');
    }
    if (['password', 'text', 'textarea', 'email', 'url', 'ip_address'].includes(varType)) {
        document.getElementById('regex-section').classList.add('active');
    }
}

// Run on page load
document.addEventListener('DOMContentLoaded', updateTypeFields);
</script>

    <h2><?php echo __('page.template_variables.title'); ?>: <?php echo htmlspecialchars($template['template_name'] ?? ''); ?></h2>
    
    <p>
        <a href="/admin/templates.php" class="btn" style="background:#6c757d;">← <?php echo __('button.back'); ?></a>
        <?php if ($template): ?>
            <span style="color:#666;"><?php echo __('form.device_type'); ?>: <?php echo htmlspecialchars($template['type_name']); ?></span>
        <?php endif; ?>
    </p>
    
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    
    <?php if ($template_id): ?>
    <div class="var-types-grid">
        <div>
            <div class="card">
                <h3><?php echo $edit_variable ? 'Variabele Bewerken' : 'Nieuwe Variabele'; ?></h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="<?php echo $edit_variable ? 'update' : 'create'; ?>">
                    <?php if ($edit_variable): ?>
                        <input type="hidden" name="var_id" value="<?php echo (int)$edit_variable['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Variabele Naam * <small>(gebruik in template als {{NAAM}})</small></label>
                        <input name="var_name" type="text" required value="<?php echo htmlspecialchars($edit_variable['var_name'] ?? ''); ?>" placeholder="DEVICE_NAME">
                    </div>
                    
                    <div class="form-group">
                        <label>Label <small>(getoond aan gebruiker)</small></label>
                        <input name="var_label" type="text" value="<?php echo htmlspecialchars($edit_variable['var_label'] ?? ''); ?>" placeholder="Device Naam">
                    </div>
                    
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="var_type" id="var_type" required onchange="updateTypeFields()">
                            <?php
                            $types = [
                                'text' => 'Text - Vrije tekst invoer',
                                'textarea' => 'Textarea - Meerdere regels tekst',
                                'number' => 'Number - Numerieke waarde',
                                'range' => 'Range - Slider (0-100)',
                                'boolean' => 'Boolean - Ja/Nee dropdown',
                                'select' => 'Select - Dropdown keuze',
                                'multiselect' => 'Multiselect - Meerdere waarden',
                                'radio' => 'Radio - Radio buttons',
                                'checkbox_group' => 'Checkbox Group - Meerdere checkboxes',
                                'email' => 'Email - E-mailadres met validatie',
                                'url' => 'URL - URL met schema validatie',
                                'password' => 'Password - Verborgen wachtwoord',
                                'date' => 'Date - Datum selectie',
                                'ip_address' => 'IP Address - IP-adres validatie'
                            ];
                            
                            foreach ($types as $value => $label) {
                                $selected = ($edit_variable && $edit_variable['var_type'] === $value) ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . 
                                     htmlspecialchars($label) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Default Waarde</label>
                        <input name="default_value" type="text" value="<?php echo htmlspecialchars($edit_variable['default_value'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Placeholder</label>
                        <input name="placeholder" type="text" value="<?php echo htmlspecialchars($edit_variable['placeholder'] ?? ''); ?>" placeholder="Bijv. 192.168.1.1">
                    </div>
                    
                    <div class="form-group">
                        <label>Help Tekst <small>(getoond onder het veld)</small></label>
                        <textarea name="help_text" rows="2"><?php echo htmlspecialchars($edit_variable['help_text'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-section type-specific-fields" id="options-section">
                        <h4>Opties voor Select/Multiselect/Radio/Checkbox/Boolean</h4>
                        <div class="form-group">
                            <label>Options (JSON)</label>
                            <textarea name="options" rows="4" placeholder='[{"value":"opt1","label":"Optie 1"},{"value":"opt2","label":"Optie 2"}]'><?php echo htmlspecialchars($edit_variable['options'] ?? ''); ?></textarea>
                            <small style="color:#666;">JSON formaat: [{"value":"val","label":"Label"}] of ["val1","val2"]</small>
                        </div>
                    </div>
                    
                    <div class="form-section type-specific-fields" id="number-section">
                        <h4>Number/Range Instellingen</h4>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label>Min Waarde</label>
                                <input name="min_value" type="number" value="<?php echo $edit_variable['min_value'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Max Waarde</label>
                                <input name="max_value" type="number" value="<?php echo $edit_variable['max_value'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section type-specific-fields" id="regex-section">
                        <h4>Validatie</h4>
                        <div class="form-group">
                            <label>Regex Pattern <small>(optioneel)</small></label>
                            <input name="regex_pattern" type="text" value="<?php echo htmlspecialchars($edit_variable['regex_pattern'] ?? ''); ?>" placeholder="^[A-Z0-9]+$">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Display Volgorde</label>
                        <input name="display_order" type="number" value="<?php echo $edit_variable['display_order'] ?? 0; ?>" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label><input type="checkbox" name="is_required" value="1" <?php echo ($edit_variable['is_required'] ?? 0) ? 'checked' : ''; ?>> Verplicht veld</label>
                    </div>
                    
                    <div style="display: flex; gap: 8px;">
                        <button class="btn" type="submit"><?php echo $edit_variable ? __('button.edit') : __('button.create'); ?></button>
                        <?php if ($edit_variable): ?>
                            <a class="btn" href="?template_id=<?php echo $template_id; ?>" style="background: #6c757d;"><?php echo __('button.cancel'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <div>
            <div class="card">
                <h3>Variabelen (<?php echo count($variables); ?>)</h3>
                
                <?php if (empty($variables)): ?>
                    <p style="color: #666;">Nog geen variabelen gedefinieerd voor dit template.</p>
                <?php else: ?>
                    <?php foreach ($variables as $v): ?>
                        <div class="var-card">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div>
                                    <strong>{{<?php echo htmlspecialchars($v['var_name']); ?>}}</strong>
                                    <span class="type-badge"><?php echo htmlspecialchars($v['var_type']); ?></span>
                                    <?php if ($v['is_required']): ?><span style="color:red;margin-left:4px;">*</span><?php endif; ?>
                                    <?php if ($v['var_label']): ?>
                                        <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                            <?php echo htmlspecialchars($v['var_label']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($v['default_value']): ?>
                                        <div style="font-size: 11px; color: #999; margin-top: 2px;">
                                            Default: <?php echo htmlspecialchars($v['default_value']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($v['help_text']): ?>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px; font-style: italic;">
                                            <?php echo htmlspecialchars($v['help_text']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; gap: 4px;">
                                    <a class="btn" href="?template_id=<?php echo $template_id; ?>&edit=<?php echo (int)$v['id']; ?>" style="font-size: 11px; padding: 4px 8px;"><?php echo __('button.edit'); ?></a>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo __('confirm.delete'); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="var_id" value="<?php echo (int)$v['id']; ?>">
                                        <button class="btn" type="submit" style="font-size: 11px; padding: 4px 8px; background: #dc3545;"><?php echo __('button.delete'); ?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php require_once __DIR__ . '/_footer.php'; ?>
