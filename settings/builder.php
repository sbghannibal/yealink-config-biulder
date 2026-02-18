<?php
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission required to access builder
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF token ensure
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';
$preview = '';
$loaded_config = null;

// Load PABX list and device types
try {
    $pstmt = $pdo->query('SELECT id, pabx_name FROM pabx WHERE is_active = 1 ORDER BY pabx_name ASC');
    $pabx_list = $pstmt->fetchAll(PDO::FETCH_ASSOC);

    $tstmt = $pdo->query('SELECT id, type_name FROM device_types ORDER BY type_name ASC');
    $device_types = $tstmt->fetchAll(PDO::FETCH_ASSOC);

    // Load global variables
    $vstmt = $pdo->query('SELECT var_name, var_value FROM variables');
    $variables = $vstmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    error_log('builder load error: ' . $e->getMessage());
    $pabx_list = $device_types = [];
    $variables = [];
    $error = 'Kon builder-gegevens niet ophalen.';
}

// Helper: apply variables in template content using {{VAR_NAME}} syntax
function apply_variables($content, $variables) {
    if (empty($variables)) return $content;
    return preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', function($m) use ($variables) {
        $key = $m[1];
        return array_key_exists($key, $variables) ? $variables[$key] : $m[0];
    }, $content);
}

// Handle actions: load_config, save_config, generate_token, preview
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST;

    if (!hash_equals($csrf, $posted['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $posted['action'] ?? 'save_config';

        // LOAD existing config for editing
        if ($action === 'load_config') {
            $config_version_id = !empty($posted['load_config_id']) ? (int)$posted['load_config_id'] : null;
            if ($config_version_id) {
                try {
                    $stmt = $pdo->prepare('SELECT * FROM config_versions WHERE id = ?');
                    $stmt->execute([$config_version_id]);
                    $loaded_config = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($loaded_config) {
                        $success = "Config versie #{$loaded_config['id']} geladen. Je kunt deze nu aanpassen.";
                    } else {
                        $error = 'Config versie niet gevonden.';
                    }
                } catch (Exception $e) {
                    error_log('Load config error: ' . $e->getMessage());
                    $error = 'Kon config niet laden.';
                }
            }
        }

        $pabx_id = !empty($posted['pabx_id']) ? (int)$posted['pabx_id'] : null;
        $device_type_id = !empty($posted['device_type_id']) ? (int)$posted['device_type_id'] : null;
        $raw_content = trim($posted['config_content'] ?? '');
        $changelog = trim($posted['changelog'] ?? '');

        // Basic validation
        if (!$pabx_id || !$device_type_id || $raw_content === '') {
            if ($action === 'save_config') {
                $error = 'Kies PABX, apparaat-type en vul config-inhoud in.';
            }
        } else {
            // If preview requested: apply variables and show
            if ($action === 'preview') {
                $preview = apply_variables($raw_content, $variables);
            }

            // Save config version
            if ($action === 'save_config') {
                try {
                    // compute next version_number for this pabx + device_type
                    $vstmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 AS next_ver FROM config_versions WHERE pabx_id = ? AND device_type_id = ?');
                    $vstmt->execute([$pabx_id, $device_type_id]);
                    $next_ver = (int) $vstmt->fetchColumn();

                    $ins = $pdo->prepare('INSERT INTO config_versions (pabx_id, device_type_id, version_number, config_content, changelog, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, NOW())');
                    $ins->execute([$pabx_id, $device_type_id, $next_ver, $raw_content, $changelog, $admin_id]);
                    $newId = $pdo->lastInsertId();

                    // Audit log
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'create_config_version',
                            'config_version',
                            $newId,
                            json_encode(['pabx_id' => $pabx_id, 'device_type_id' => $device_type_id, 'version' => $next_ver]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('builder audit error: ' . $e->getMessage());
                    }

                    $success = 'Configuratie opgeslagen als versie ' . $next_ver . '.';
                } catch (Exception $e) {
                    error_log('builder save error: ' . $e->getMessage());
                    $error = 'Kon configuratie niet opslaan.';
                }
            }

            // Generate download token for the provided content/version (optional)
            if ($action === 'generate_token') {
                $config_version_id = !empty($posted['config_version_id']) ? (int)$posted['config_version_id'] : null;
                $expires_hours = !empty($posted['expires_hours']) ? (int)$posted['expires_hours'] : 24;
                if (!$config_version_id) {
                    $error = 'Geef een config versie-id op om een token voor te genereren.';
                } else {
                    try {
                        // create token
                        $token = bin2hex(random_bytes(24));
                        $expires_at = date('Y-m-d H:i:s', time() + max(3600, $expires_hours * 3600));
                        $ins = $pdo->prepare('INSERT INTO download_tokens (token, config_version_id, mac_address, device_model, pabx_id, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                        $ins->execute([$token, $config_version_id, null, null, $pabx_id, $expires_at, $admin_id]);

                        // build token URL (best effort - adjust host as needed)
                        $host = ($_SERVER['HTTPS'] ?? '') ? 'https://' . ($_SERVER['HTTP_HOST'] ?? '') : 'http://' . ($_SERVER['HTTP_HOST'] ?? '');
                        $token_url = rtrim($host, '/') . '/download.php?token=' . $token;

                        $success = 'Download token aangemaakt. Geldig tot ' . $expires_at . ". URL: $token_url";
                    } catch (Exception $e) {
                        error_log('builder token error: ' . $e->getMessage());
                        $error = 'Kon download token niet aanmaken.';
                    }
                }
            }
        }

        // Assign config to devices (bulk)
        if ($action === 'assign_to_devices') {
            $config_version_id = !empty($posted['config_version_id']) ? (int)$posted['config_version_id'] : null;
            $device_ids = !empty($posted['device_ids']) ? $posted['device_ids'] : [];

            if (!$config_version_id || empty($device_ids)) {
                $error = 'Selecteer een configuratie en minimaal één device.';
            } else {
                try {
                    $assigned_count = 0;
                    foreach ($device_ids as $device_id) {
                        $device_id = (int) $device_id;
                        $stmt = $pdo->prepare('INSERT INTO device_config_assignments (device_id, config_version_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE config_version_id = VALUES(config_version_id), assigned_at = NOW()');
                        $stmt->execute([(int)$device_id, $config_version_id, $admin_id]);
                        $assigned_count++;
                    }

                    $success = "Configuratie toegewezen aan $assigned_count device(s).";
                } catch (Exception $e) {
                    error_log('Config assignment error: ' . $e->getMessage());
                    $error = 'Kon configuratie niet toewijzen.';
                }
            }
        }
    }
}

// Fetch recent config versions for listing
try {
    $cvstmt = $pdo->prepare('SELECT cv.id, cv.version_number, cv.created_at, p.pabx_name, dt.type_name, a.username FROM config_versions cv LEFT JOIN pabx p ON cv.pabx_id = p.id LEFT JOIN device_types dt ON cv.device_type_id = dt.id LEFT JOIN admins a ON cv.created_by = a.id ORDER BY cv.created_at DESC LIMIT 50');
    $cvstmt->execute();
    $config_versions = $cvstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $config_versions = [];
}

// Fetch devices for assignment
try {
    $dstmt = $pdo->query('SELECT d.id, d.device_name, dt.type_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id WHERE d.is_active = 1 ORDER BY d.device_name');
    $devices_list = $dstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $devices_list = [];
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Config Builder - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .split { display:grid; grid-template-columns: 1fr 420px; gap:16px; align-items:start; }
        textarea.config { width:100%; height:340px; font-family:monospace; white-space:pre; }
        .small { font-size:90%; color:#666; }
        .cv-list { max-height:340px; overflow:auto; }
        .loaded-info { background:#e8f5e9; padding:12px; border-left:4px solid #28a745; margin-bottom:16px; border-radius:4px; }
    </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_admin_nav.php')) include __DIR__ . '/../admin/_admin_nav.php'; ?>
<main class="container">
    <h2>Config Builder</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo nl2br(htmlspecialchars($success)); ?></div><?php endif; ?>

    <div class="split" style="margin-top:12px;">
        <div>
            <?php if ($loaded_config): ?>
                <div class="loaded-info">
                    <strong>✓ Config geladen:</strong> Versie #<?php echo (int)$loaded_config['id']; ?> (v<?php echo (int)$loaded_config['version_number']; ?>)<br>
                    <small>Aangepast: <?php echo htmlspecialchars($loaded_config['created_at']); ?></small>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="save_config">

                <div class="form-group">
                    <label>PABX</label>
                    <select name="pabx_id" required>
                        <option value="">-- Kies PABX --</option>
                        <?php foreach ($pabx_list as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>" <?php echo (isset($_POST['pabx_id']) && $_POST['pabx_id'] == $p['id']) || ($loaded_config && $loaded_config['pabx_id'] == $p['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['pabx_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Device type</label>
                    <select name="device_type_id" required>
                        <option value="">-- Kies type --</option>
                        <?php foreach ($device_types as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo (isset($_POST['device_type_id']) && $_POST['device_type_id'] == $t['id']) || ($loaded_config && $loaded_config['device_type_id'] == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['type_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Changelog (kort)</label>
                    <input name="changelog" type="text" value="<?php echo htmlspecialchars($_POST['changelog'] ?? ($loaded_config ? 'Aangepast van versie #' . $loaded_config['id'] : '')); ?>">
                </div>

                <div class="form-group">
                    <label>Config inhoud (gebruik placeholders zoals {{SERVER_IP}})</label>
                    <textarea name="config_content" class="config" required><?php echo htmlspecialchars($_POST['config_content'] ?? ($loaded_config ? $loaded_config['config_content'] : "server={{SERVER_IP}}\nport={{SERVER_PORT}}\nntp={{NTP_SERVER}}")); ?></textarea>
                </div>

                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <button class="btn" type="submit">Opslaan als nieuwe versie</button>
                    <button class="btn" type="submit" onclick="document.querySelector('input[name=action]').value='preview'">Voorbeeld</button>
                    <a class="btn" href="/settings/builder.php" style="background:#6c757d;">Reset</a>
                </div>
            </form>

            <?php if ($preview): ?>
                <section style="margin-top:12px;">
                    <h3>Preview (variabelen toegepast)</h3>
                    <div class="card"><pre style="white-space:pre-wrap; font-family:monospace;"><?php echo htmlspecialchars($preview); ?></pre></div>
                </section>
            <?php endif; ?>
        </div>

        <aside>
            <section class="card">
                <h3>📥 Laad bestaande config</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="load_config">
                    <div class="form-group">
                        <label>Selecteer config om te laden:</label>
                        <select name="load_config_id" required style="max-height:200px;">
                            <option value="">-- Kies versie --</option>
                            <?php foreach ($config_versions as $cv): ?>
                                <option value="<?php echo (int)$cv['id']; ?>">
                                    #<?php echo (int)$cv['id']; ?> v<?php echo (int)$cv['version_number']; ?> - 
                                    <?php echo htmlspecialchars($cv['pabx_name'] ?? 'N/A'); ?> / 
                                    <?php echo htmlspecialchars($cv['type_name'] ?? 'N/A'); ?>
                                    (<?php echo htmlspecialchars($cv['created_at']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn" type="submit" style="width:100%;">Laden</button>
                </form>
            </section>

            <section class="card" style="margin-top:12px;">
                <h3>Toewijzen aan Devices</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="assign_to_devices">
                    <div class="form-group">
                        <label>Config versie ID</label>
                        <input name="config_version_id" type="number" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Selecteer devices</label>
                        <div style="max-height:150px; overflow-y:auto; border:1px solid #ddd; padding:8px;">
                            <?php if (empty($devices_list)): ?>
                                <p class="small">Geen actieve devices gevonden.</p>
                            <?php else: ?>
                                <?php foreach ($devices_list as $dev): ?>
                                    <label style="display:block; padding:4px;">
                                        <input type="checkbox" name="device_ids[]" value="<?php echo (int)$dev['id']; ?>">
                                        <?php echo htmlspecialchars($dev['device_name']); ?>
                                        <small>(<?php echo htmlspecialchars($dev['type_name'] ?? '-'); ?>)</small>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="btn" type="submit">Toewijzen</button>
                </form>
            </section>

            <section class="card" style="margin-top:12px;">
                <h3>Maak download token</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="generate_token">
                    <div class="form-group">
                        <label>Config versie ID</label>
                        <input name="config_version_id" type="number" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Geldigheid (uur)</label>
                        <input name="expires_hours" type="number" min="1" value="24" required>
                    </div>
                    <button class="btn" type="submit">Genereer token</button>
                </form>
                <p class="small">Gebruik dit token in de download-url: <code>/download.php?token=...</code></p>
            </section>

            <section class="card" style="margin-top:12px;">
                <h3>Beschikbare variabelen</h3>
                <?php if (empty($variables)): ?>
                    <p class="small">Geen globale variabelen geconfigureerd.</p>
                <?php else: ?>
                    <ul class="small">
                        <?php foreach ($variables as $k => $v): ?>
                            <li><code>{{<?php echo htmlspecialchars($k); ?>}}</code> → <?php echo htmlspecialchars($v); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</main>
</body>
</html>
