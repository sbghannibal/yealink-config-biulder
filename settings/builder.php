<?php
session_start();
require_once __DIR__ . '/database.php';
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

// Pagination and filtering parameters
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$per_page = in_array($per_page, [10, 25, 50, 100]) ? $per_page : 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$filter_pabx = isset($_GET['filter_pabx']) && $_GET['filter_pabx'] !== '' ? (int)$_GET['filter_pabx'] : null;
$filter_device_type = isset($_GET['filter_device_type']) && $_GET['filter_device_type'] !== '' ? (int)$_GET['filter_device_type'] : null;
$sort_by = $_GET['sort_by'] ?? 'recent';

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

// Fetch config versions with pagination and filtering
$total_count = 0;
$config_versions = [];

try {
    // Build count query
    $count_sql = "SELECT COUNT(*) as total FROM config_versions cv WHERE 1=1";
    $count_params = [];
    
    if ($filter_pabx) {
        $count_sql .= " AND cv.pabx_id = ?";
        $count_params[] = $filter_pabx;
    }
    if ($filter_device_type) {
        $count_sql .= " AND cv.device_type_id = ?";
        $count_params[] = $filter_device_type;
    }
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_count = (int) $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Build main query with sorting
    $sql = "SELECT cv.id, cv.version_number, cv.pabx_id, cv.device_type_id, cv.changelog, 
                   cv.created_at, cv.is_active, p.pabx_name, dt.type_name, a.username,
                   (SELECT COUNT(*) FROM device_config_assignments dca WHERE dca.config_version_id = cv.id) as device_count
            FROM config_versions cv 
            LEFT JOIN pabx p ON cv.pabx_id = p.id 
            LEFT JOIN device_types dt ON cv.device_type_id = dt.id 
            LEFT JOIN admins a ON cv.created_by = a.id 
            WHERE 1=1";
    
    $params = [];
    
    if ($filter_pabx) {
        $sql .= " AND cv.pabx_id = ?";
        $params[] = $filter_pabx;
    }
    if ($filter_device_type) {
        $sql .= " AND cv.device_type_id = ?";
        $params[] = $filter_device_type;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'oldest':
            $sql .= " ORDER BY cv.created_at ASC";
            break;
        case 'version':
            $sql .= " ORDER BY cv.version_number DESC";
            break;
        case 'recent':
        default:
            $sql .= " ORDER BY cv.created_at DESC";
            break;
    }
    
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = (int)$per_page;
    $params[] = (int)$offset;
    
    $cvstmt = $pdo->prepare($sql);
    $cvstmt->execute($params);
    $config_versions = $cvstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Config versions fetch error: ' . $e->getMessage());
    $config_versions = [];
}

$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;
$page = min($page, $total_pages);

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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Config Builder - Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/builder.css">
    <style>
        .main-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
            align-items: start;
            margin-top: 20px;
        }
        
        @media (max-width: 992px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .editor-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .sidebar {
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/../admin/_header.php')) include __DIR__ . '/../admin/_header.php'; ?>
<main class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>🛠️ Config Builder</h2>
        <a class="btn" href="/settings/builder.php" style="background: #6c757d;">🔄 Reset</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo nl2br(htmlspecialchars($success)); ?></div><?php endif; ?>

    <div class="main-grid">
        <!-- Sidebar with Filters -->
        <aside class="sidebar">
            <!-- Device Search -->
            <div class="filter-section">
                <h4>🔍 Zoek Device</h4>
                <div class="device-search">
                    <input type="text" 
                           id="deviceSearch" 
                           placeholder="Typ device of klant naam..." 
                           autocomplete="off">
                    <span class="search-icon">🔍</span>
                    <div class="device-results" id="deviceResults" style="display: none;"></div>
                </div>
                <div id="selectedDeviceInfo" style="display: none;"></div>
            </div>

            <!-- PABX Filter -->
            <div class="filter-section">
                <h4>Filter op PABX</h4>
                <select id="filterPabx" onchange="applyFilters()">
                    <option value="">Alle PABX'en</option>
                    <?php foreach ($pabx_list as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo $filter_pabx == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['pabx_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Device Type Filter -->
            <div class="filter-section">
                <h4>Filter op Type</h4>
                <select id="filterDeviceType" onchange="applyFilters()">
                    <option value="">Alle types</option>
                    <?php foreach ($device_types as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $filter_device_type == $t['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['type_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sort Options -->
            <div class="filter-section">
                <h4>Sorteren</h4>
                <select id="sortBy" onchange="applyFilters()">
                    <option value="recent" <?php echo $sort_by === 'recent' ? 'selected' : ''; ?>>Meest recent</option>
                    <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oudste eerst</option>
                    <option value="version" <?php echo $sort_by === 'version' ? 'selected' : ''; ?>>Versie nummer</option>
                </select>
            </div>

            <!-- Available Variables -->
            <div class="filter-section">
                <h4>Beschikbare Variabelen</h4>
                <?php if (empty($variables)): ?>
                    <p style="font-size: 12px; color: #6c757d;">Geen variabelen</p>
                <?php else: ?>
                    <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($variables as $k => $v): ?>
                            <div style="font-size: 11px; margin-bottom: 6px;">
                                <code style="background: #f1f3f5; padding: 2px 4px; border-radius: 2px;">{{<?php echo htmlspecialchars($k); ?>}}</code>
                                <br>
                                <small style="color: #6c757d;"><?php echo htmlspecialchars($v); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <div>
            <!-- Config Editor Form -->
            <div class="editor-section">
                <?php if ($loaded_config): ?>
                    <div class="selected-device-info">
                        <strong>✓ Config geladen:</strong> Versie #<?php echo (int)$loaded_config['id']; ?> (v<?php echo (int)$loaded_config['version_number']; ?>)
                        <br><small>Aangepast: <?php echo htmlspecialchars($loaded_config['created_at']); ?></small>
                    </div>
                <?php endif; ?>

                <form method="post" id="configForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="save_config">

                    <div class="form-group">
                        <label>PABX *</label>
                        <select name="pabx_id" id="formPabx" required>
                            <option value="">-- Kies PABX --</option>
                            <?php foreach ($pabx_list as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo (isset($_POST['pabx_id']) && $_POST['pabx_id'] == $p['id']) || ($loaded_config && $loaded_config['pabx_id'] == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['pabx_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Device Type *</label>
                        <select name="device_type_id" id="formDeviceType" required>
                            <option value="">-- Kies type --</option>
                            <?php foreach ($device_types as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo (isset($_POST['device_type_id']) && $_POST['device_type_id'] == $t['id']) || ($loaded_config && $loaded_config['device_type_id'] == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Changelog</label>
                        <input name="changelog" type="text" placeholder="Beschrijf de wijzigingen..." 
                               value="<?php echo htmlspecialchars($_POST['changelog'] ?? ($loaded_config ? 'Aangepast van versie #' . $loaded_config['id'] : '')); ?>">
                    </div>

                    <div class="form-group">
                        <label>Config Inhoud *</label>
                        <div class="config-editor">
                            <textarea name="config_content" 
                                      id="configContent" 
                                      required 
                                      oninput="updateCharCounter(); debouncePreview();"><?php echo htmlspecialchars($_POST['config_content'] ?? ($loaded_config ? $loaded_config['config_content'] : "server={{SERVER_IP}}\nport={{SERVER_PORT}}\nntp={{NTP_SERVER}}")); ?></textarea>
                            <div class="char-counter" id="charCounter">0 tekens</div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px;">
                        <button class="btn" type="submit" style="background: #28a745;">💾 Opslaan als nieuwe versie</button>
                        <button class="btn" type="button" onclick="showLivePreview()" style="background: #17a2b8;">👁️ Preview</button>
                    </div>
                </form>

                <!-- Live Preview Section -->
                <div id="livePreview" class="preview-panel" style="display: none;">
                    <h4>Preview (met variabelen)</h4>
                    <pre id="previewContent"></pre>
                </div>
            </div>

            <!-- Config Versions Table -->
            <div class="editor-section" style="margin-top: 20px;">
                <h3>📋 Config Versies (<?php echo $total_count; ?>)</h3>
                
                <?php if ($total_count === 0): ?>
                    <div style="padding: 40px; text-align: center; color: #6c757d;">
                        <?php if ($filter_pabx || $filter_device_type): ?>
                            Geen configs gevonden met de geselecteerde filters.
                        <?php else: ?>
                            Nog geen config versies aangemaakt.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="config-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 80px;">Versie</th>
                                    <th>PABX</th>
                                    <th>Type</th>
                                    <th>Changelog</th>
                                    <th style="width: 130px;">Datum</th>
                                    <th style="width: 80px;">Devices</th>
                                    <th style="width: 80px;">Status</th>
                                    <th style="width: 200px;">Acties</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($config_versions as $cv): ?>
                                    <tr>
                                        <td><strong>#<?php echo (int)$cv['id']; ?></strong></td>
                                        <td><span class="badge info">v<?php echo (int)$cv['version_number']; ?></span></td>
                                        <td><?php echo htmlspecialchars($cv['pabx_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($cv['type_name'] ?? 'N/A'); ?></td>
                                        <td><small><?php echo htmlspecialchars($cv['changelog'] ?? '-'); ?></small></td>
                                        <td><small><?php echo date('d-m-Y H:i', strtotime($cv['created_at'])); ?></small></td>
                                        <td><?php echo (int)($cv['device_count'] ?? 0); ?>x</td>
                                        <td>
                                            <?php if ($cv['is_active']): ?>
                                                <span class="badge success">✓ Actief</span>
                                            <?php else: ?>
                                                <span class="badge">Inactief</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                                    <input type="hidden" name="action" value="load_config">
                                                    <input type="hidden" name="load_config_id" value="<?php echo (int)$cv['id']; ?>">
                                                    <button type="submit" class="btn-sm btn-load" title="Laden">📝 Laden</button>
                                                </form>
                                                <button class="btn-sm btn-copy" onclick="copyConfig(<?php echo (int)$cv['id']; ?>)" title="Kopiëren">📋</button>
                                                <button class="btn-sm btn-stats" onclick="showStats(<?php echo (int)$cv['id']; ?>)" title="Statistieken">📊</button>
                                                <button class="btn-sm btn-delete" onclick="deleteConfig(<?php echo (int)$cv['id']; ?>)" title="Verwijderen">🗑️</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrapper">
                            <div class="pagination-info">
                                Toont <?php echo (($page - 1) * $per_page) + 1; ?> tot <?php echo min($page * $per_page, $total_count); ?> van <?php echo $total_count; ?>
                            </div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <div style="display: flex; gap: 4px; align-items: center; font-size: 13px;">
                                    <span>Per pagina:</span>
                                    <select id="perPage" onchange="applyFilters()" style="padding: 4px;">
                                        <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                            <option value="<?php echo $pp; ?>" <?php echo $pp == $per_page ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="pagination-controls">
                                    <?php if ($page > 1): ?>
                                        <button onclick="goToPage(1)">« Eerste</button>
                                        <button onclick="goToPage(<?php echo $page - 1; ?>)">‹ Vorige</button>
                                    <?php else: ?>
                                        <button disabled>« Eerste</button>
                                        <button disabled>‹ Vorige</button>
                                    <?php endif; ?>
                                    
                                    <button class="active">Pagina <?php echo $page; ?> / <?php echo $total_pages; ?></button>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <button onclick="goToPage(<?php echo $page + 1; ?>)">Volgende ›</button>
                                        <button onclick="goToPage(<?php echo $total_pages; ?>)">Laatste »</button>
                                    <?php else: ?>
                                        <button disabled>Volgende ›</button>
                                        <button disabled>Laatste »</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Statistics Modal -->
<div class="modal-overlay" id="statsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📊 Config Statistieken</h3>
            <button class="modal-close" onclick="closeModal('statsModal')">&times;</button>
        </div>
        <div class="modal-body" id="statsModalContent">
            <div style="text-align: center; padding: 20px;">
                <div class="spinner"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Configuration constants
const CONFIG = {
    SEARCH_DEBOUNCE_MS: 300,
    PREVIEW_DEBOUNCE_MS: 1000
};

const csrfToken = <?php echo json_encode($csrf); ?>;
const variables = <?php echo json_encode($variables); ?>;

// Device search with debounce
let searchTimeout;
document.getElementById('deviceSearch')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        document.getElementById('deviceResults').style.display = 'none';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch(`/settings/builder-actions.php?action=search_devices&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    displayDeviceResults(data.devices);
                }
            })
            .catch(e => console.error('Search error:', e));
    }, CONFIG.SEARCH_DEBOUNCE_MS);
});

function displayDeviceResults(devices) {
    const results = document.getElementById('deviceResults');
    
    if (devices.length === 0) {
        results.innerHTML = '<div class="no-results">Geen devices gevonden</div>';
        results.style.display = 'block';
        return;
    }
    
    results.innerHTML = devices.map(d => {
        const deviceJson = JSON.stringify(d).replace(/"/g, '&quot;');
        return `
        <div class="device-item" data-device='${deviceJson}'>
            <strong>${escapeHtml(d.device_name)}</strong>
            <small>
                ${d.company_name ? escapeHtml(d.company_name) : ''} 
                ${d.customer_code ? '(' + escapeHtml(d.customer_code) + ')' : ''}
                | ${escapeHtml(d.type_name || 'Unknown')} 
                | ${escapeHtml(d.mac_address || '')}
            </small>
        </div>
    `;
    }).join('');
    results.style.display = 'block';
    
    // Add event listeners to device items
    results.querySelectorAll('.device-item').forEach(item => {
        item.addEventListener('click', function() {
            const deviceData = JSON.parse(this.getAttribute('data-device').replace(/&quot;/g, '"'));
            selectDevice(deviceData);
        });
    });
}

function selectDevice(device) {
    document.getElementById('deviceResults').style.display = 'none';
    document.getElementById('deviceSearch').value = device.device_name;
    
    // Show selected device info
    const info = document.getElementById('selectedDeviceInfo');
    info.innerHTML = `
        <div class="selected-device-info">
            <strong>✓ Device geselecteerd:</strong> ${escapeHtml(device.device_name)}<br>
            <small>
                Type: ${escapeHtml(device.type_name || 'Unknown')} | 
                MAC: ${escapeHtml(device.mac_address || 'N/A')}
                ${device.company_name ? ' | Klant: ' + escapeHtml(device.company_name) : ''}
            </small>
        </div>
    `;
    info.style.display = 'block';
    
    // Auto-fill device type filter
    if (device.device_type_id) {
        document.getElementById('filterDeviceType').value = device.device_type_id;
        document.getElementById('formDeviceType').value = device.device_type_id;
        applyFilters();
    }
}

// Click outside to close search results
document.addEventListener('click', function(e) {
    if (!e.target.closest('.device-search')) {
        document.getElementById('deviceResults').style.display = 'none';
    }
});

// Character counter
function updateCharCounter() {
    const textarea = document.getElementById('configContent');
    const counter = document.getElementById('charCounter');
    if (textarea && counter) {
        const length = textarea.value.length;
        counter.textContent = `${length} tekens`;
    }
}

// Live preview with debounce
let previewTimeout;
function debouncePreview() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(showLivePreview, CONFIG.PREVIEW_DEBOUNCE_MS);
}

function showLivePreview() {
    const content = document.getElementById('configContent').value;
    let preview = content;
    
    // Apply variables
    for (const [key, value] of Object.entries(variables)) {
        const regex = new RegExp('\\{\\{\\s*' + key + '\\s*\\}\\}', 'g');
        preview = preview.replace(regex, value);
    }
    
    document.getElementById('previewContent').textContent = preview;
    document.getElementById('livePreview').style.display = 'block';
}

// Filter and pagination functions
function applyFilters() {
    const pabx = document.getElementById('filterPabx').value;
    const deviceType = document.getElementById('filterDeviceType').value;
    const sortBy = document.getElementById('sortBy').value;
    const perPage = document.getElementById('perPage')?.value || 25;
    
    const params = new URLSearchParams();
    params.set('page', '1');
    params.set('per_page', perPage);
    if (pabx) params.set('filter_pabx', pabx);
    if (deviceType) params.set('filter_device_type', deviceType);
    if (sortBy) params.set('sort_by', sortBy);
    
    window.location.href = '/settings/builder.php?' + params.toString();
}

function goToPage(page) {
    const params = new URLSearchParams(window.location.search);
    params.set('page', page);
    window.location.href = '/settings/builder.php?' + params.toString();
}

// Copy config
function copyConfig(configId) {
    if (!confirm('Wil je deze config kopiëren als een nieuwe versie?')) return;
    
    const formData = new FormData();
    formData.append('action', 'copy_config');
    formData.append('config_id', configId);
    formData.append('csrf_token', csrfToken);
    
    fetch('/settings/builder-actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Fout: ' + data.error);
        }
    })
    .catch(e => {
        console.error('Copy error:', e);
        alert('Er is een fout opgetreden bij het kopiëren.');
    });
}

// Delete config
function deleteConfig(configId) {
    if (!confirm('Weet je zeker dat je deze config wilt verwijderen? Dit kan niet ongedaan worden gemaakt.')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_config');
    formData.append('config_id', configId);
    formData.append('csrf_token', csrfToken);
    
    fetch('/settings/builder-actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Fout: ' + data.error);
        }
    })
    .catch(e => {
        console.error('Delete error:', e);
        alert('Er is een fout opgetreden bij het verwijderen.');
    });
}

// Show stats modal
function showStats(configId) {
    const modal = document.getElementById('statsModal');
    modal.classList.add('active');
    
    fetch(`/settings/builder-actions.php?action=get_stats&config_id=${configId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                displayStats(data);
            } else {
                document.getElementById('statsModalContent').innerHTML = 
                    `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
            }
        })
        .catch(e => {
            console.error('Stats error:', e);
            document.getElementById('statsModalContent').innerHTML = 
                '<div class="alert alert-error">Fout bij ophalen statistieken</div>';
        });
}

function displayStats(data) {
    const config = data.config;
    const devices = data.devices;
    
    let html = `
        <div style="margin-bottom: 20px;">
            <h4>Config Versie #${config.id} - v${config.version_number}</h4>
            <p><strong>PABX:</strong> ${escapeHtml(config.pabx_name || 'N/A')}</p>
            <p><strong>Type:</strong> ${escapeHtml(config.type_name || 'N/A')}</p>
            <p><strong>Aangemaakt:</strong> ${escapeHtml(config.created_at)} door ${escapeHtml(config.username || 'Unknown')}</p>
            <p><strong>Downloads:</strong> ${data.download_count}x</p>
        </div>
        
        <h4>Toegewezen aan ${data.device_count} device(s)</h4>
    `;
    
    if (devices.length === 0) {
        html += '<p style="color: #6c757d;">Deze config is nog niet toegewezen aan devices.</p>';
    } else {
        html += '<div style="max-height: 300px; overflow-y: auto; margin-top: 12px;">';
        html += '<table class="config-table"><thead><tr><th>Device</th><th>Type</th><th>Klant</th><th>Toegewezen</th></tr></thead><tbody>';
        
        devices.forEach(d => {
            html += `
                <tr>
                    <td>
                        <strong>${escapeHtml(d.device_name)}</strong><br>
                        <small style="color: #6c757d;">${escapeHtml(d.mac_address || 'N/A')}</small>
                    </td>
                    <td>${escapeHtml(d.type_name || 'N/A')}</td>
                    <td>
                        ${d.company_name ? escapeHtml(d.company_name) : '-'}
                        ${d.customer_code ? '<br><small style="color: #6c757d;">' + escapeHtml(d.customer_code) + '</small>' : ''}
                    </td>
                    <td><small>${escapeHtml(d.assigned_at)}</small></td>
                </tr>
            `;
        });
        
        html += '</tbody></table></div>';
    }
    
    document.getElementById('statsModalContent').innerHTML = html;
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});

// Utility function
function escapeHtml(text) {
    if (text == null) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateCharCounter();
});
</script>
</body>
</html>
