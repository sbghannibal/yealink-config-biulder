<?php
$page_title = 'Bulk Find & Replace';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';
$preview_results = [];
$step = $_GET['step'] ?? 'search';

// Maak tabellen aan als ze niet bestaan
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bulk_operations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      operation_type VARCHAR(50) NOT NULL,
      search_term TEXT NOT NULL,
      replace_term TEXT NOT NULL,
      affected_configs INT DEFAULT 0,
      executed_by INT NOT NULL,
      executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      rollback_at DATETIME NULL,
      rollback_by INT NULL,
      notes TEXT NULL,
      INDEX idx_executed_at (executed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bulk_operation_details (
      id INT AUTO_INCREMENT PRIMARY KEY,
      bulk_operation_id INT NOT NULL,
      device_id INT NOT NULL,
      old_config_version_id INT NOT NULL,
      new_config_version_id INT NOT NULL,
      matches_count INT DEFAULT 0,
      INDEX idx_bulk_op (bulk_operation_id),
      INDEX idx_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    error_log('Bulk operations table creation warning: ' . $e->getMessage());
}

// PREVIEW MODE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $search_term = $_POST['search_term'] ?? '';
        $replace_term = $_POST['replace_term'] ?? '';

        if (empty($search_term)) {
            $error = 'Zoekterm is verplicht.';
        } else {
            try {
                $stmt = $pdo->query("
                    SELECT
                        cv.id as config_version_id,
                        cv.config_content,
                        cv.version_number,
                        d.id as device_id,
                        d.device_name,
                        dt.type_name
                    FROM device_config_assignments dca
                    JOIN config_versions cv ON dca.config_version_id = cv.id
                    JOIN devices d ON dca.device_id = d.id
                    LEFT JOIN device_types dt ON d.device_type_id = dt.id
                    WHERE dca.is_active = 1
                ");

                $all_configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($all_configs as $config) {
                    $matches = substr_count($config['config_content'], $search_term);
                    if ($matches > 0) {
                        $preview_results[] = [
                            'device_id' => $config['device_id'],
                            'device_name' => $config['device_name'],
                            'type_name' => $config['type_name'],
                            'config_version_id' => $config['config_version_id'],
                            'version_number' => $config['version_number'],
                            'matches' => $matches
                        ];
                    }
                }

                if (empty($preview_results)) {
                    $error = 'Geen overeenkomsten gevonden in actieve configs.';
                } else {
                    $step = 'preview';
                }
            } catch (Exception $e) {
                error_log('Bulk preview error: ' . $e->getMessage());
                $error = 'Fout bij preview: ' . $e->getMessage();
            }
        }
    }
}

// EXECUTE MODE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $search_term = $_POST['search_term'] ?? '';
        $replace_term = $_POST['replace_term'] ?? '';

        if (empty($search_term)) {
            $error = 'Zoekterm is verplicht.';
        } else {
            try {
                $pdo->beginTransaction();

                // Maak bulk operation record
                $stmt = $pdo->prepare("INSERT INTO bulk_operations (operation_type, search_term, replace_term, executed_by) VALUES ('find_replace', ?, ?, ?)");
                $stmt->execute([$search_term, $replace_term, $admin_id]);
                $bulk_op_id = $pdo->lastInsertId();

                // Haal actieve configs op
                $stmt = $pdo->query("
                    SELECT
                        cv.id as config_version_id,
                        cv.config_content,
                        cv.version_number,
                        cv.device_type_id,
                        cv.pabx_id,
                        dca.device_id
                    FROM device_config_assignments dca
                    JOIN config_versions cv ON dca.config_version_id = cv.id
                    WHERE dca.is_active = 1
                ");

                $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $affected = 0;

                foreach ($configs as $config) {
                    $matches = substr_count($config['config_content'], $search_term);
                    if ($matches > 0) {
                        // Voer replace uit
                        $new_content = str_replace($search_term, $replace_term, $config['config_content']);

                        // Gebruik pabx_id van oude config
                        $pabx_id = $config['pabx_id'] ?? 1;

                        // Maak nieuwe versie (gebruik MAX om collision te vermijden)
                        $vstmt = $pdo->prepare("SELECT COALESCE(MAX(version_number), 0) + 1 FROM config_versions WHERE device_type_id = ?");
                        $vstmt->execute([$config['device_type_id']]);
                        $new_version = (int)$vstmt->fetchColumn();
                        $stmt = $pdo->prepare("
                            INSERT INTO config_versions
                            (device_type_id, config_content, version_number, created_by, pabx_id, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $config['device_type_id'],
                            $new_content,
                            $new_version,
                            $admin_id,
                            $pabx_id
                        ]);
                        $new_config_id = $pdo->lastInsertId();

                        // Deactiveer oude assignment
                        $stmt = $pdo->prepare("UPDATE device_config_assignments SET is_active = 0 WHERE device_id = ? AND is_active = 1");
                        $stmt->execute([$config['device_id']]);

                        // Maak nieuwe assignment (actief)
                        $stmt = $pdo->prepare("
                            INSERT INTO device_config_assignments
                            (device_id, config_version_id, is_active, assigned_by, activated_at)
                            VALUES (?, ?, 1, ?, NOW())
                        ");
                        $stmt->execute([$config['device_id'], $new_config_id, $admin_id]);

                        // Log in bulk_operation_details
                        $stmt = $pdo->prepare("
                            INSERT INTO bulk_operation_details
                            (bulk_operation_id, device_id, old_config_version_id, new_config_version_id, matches_count)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$bulk_op_id, $config['device_id'], $config['config_version_id'], $new_config_id, $matches]);

                        $affected++;
                    }
                }

                // Update affected count
                $stmt = $pdo->prepare("UPDATE bulk_operations SET affected_configs = ? WHERE id = ?");
                $stmt->execute([$affected, $bulk_op_id]);

                $pdo->commit();
                $success = "$affected config(s) succesvol bijgewerkt en geactiveerd!";
                $step = 'complete';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Bulk execute error: ' . $e->getMessage());
                $error = 'Fout bij uitvoeren: ' . $e->getMessage();
            }
        }
    }
}

// ROLLBACK MODE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rollback') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $bulk_op_id = (int)($_POST['bulk_op_id'] ?? 0);

        if ($bulk_op_id <= 0) {
            $error = 'Ongeldige operatie ID.';
        } else {
            try {
                $pdo->beginTransaction();

                // Haal details op
                $stmt = $pdo->prepare("SELECT * FROM bulk_operation_details WHERE bulk_operation_id = ?");
                $stmt->execute([$bulk_op_id]);
                $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($details as $detail) {
                    // Deactiveer nieuwe versie
                    $stmt = $pdo->prepare("UPDATE device_config_assignments SET is_active = 0 WHERE device_id = ? AND config_version_id = ?");
                    $stmt->execute([$detail['device_id'], $detail['new_config_version_id']]);

                    // Heractiveer oude versie (rij bestaat al, update is_active)
                    $stmt = $pdo->prepare("UPDATE device_config_assignments SET is_active = 1, activated_at = NOW() WHERE device_id = ? AND config_version_id = ?");
                    $stmt->execute([$detail['device_id'], $detail['old_config_version_id']]);
                }

                // Mark als rolled back
                $stmt = $pdo->prepare("UPDATE bulk_operations SET rollback_at = NOW(), rollback_by = ? WHERE id = ?");
                $stmt->execute([$admin_id, $bulk_op_id]);

                $pdo->commit();
                $success = 'Rollback succesvol uitgevoerd! Alle devices zijn teruggezet.';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Rollback error: ' . $e->getMessage());
                $error = 'Fout bij rollback: ' . $e->getMessage();
            }
        }
    }
}

// Haal recente operaties op voor rollback lijst
try {
    $stmt = $pdo->prepare("
        SELECT bo.*, a.username as executed_by_name, a2.username as rollback_by_name
        FROM bulk_operations bo
        LEFT JOIN admins a ON bo.executed_by = a.id
        LEFT JOIN admins a2 ON bo.rollback_by = a2.id
        ORDER BY bo.executed_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recent_operations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_operations = [];
}

require_once __DIR__ . '/_header.php';
?>

<style>
    .preview-table { margin-top: 20px; }
    .preview-table td { padding: 8px; }
    .match-badge {
        background: #ffc107;
        color: #000;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: bold;
    }
    .step-indicator {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        padding: 16px;
        background: #f8f9fa;
        border-radius: 8px;
    }
    .step {
        flex: 1;
        text-align: center;
        padding: 12px;
        background: white;
        border-radius: 4px;
        border: 2px solid #dee2e6;
    }
    .step.active {
        border-color: #667eea;
        background: #e7f3ff;
    }
    .step.complete {
        border-color: #28a745;
        background: #d4edda;
    }
</style>

<h2>🔍 Bulk Find &amp; Replace</h2>

<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="step-indicator">
    <div class="step <?php echo $step === 'search' ? 'active' : ''; ?>">
        <strong>1. Zoeken</strong><br>
        <small>Voer zoek- en vervangterm in</small>
    </div>
    <div class="step <?php echo $step === 'preview' ? 'active' : ''; ?>">
        <strong>2. Preview</strong><br>
        <small>Bekijk getroffen configs</small>
    </div>
    <div class="step <?php echo $step === 'complete' ? 'active complete' : ''; ?>">
        <strong>3. Voltooid</strong><br>
        <small>Wijzigingen toegepast</small>
    </div>
</div>

<?php if ($step === 'search'): ?>
<div class="card">
    <h3>Stap 1: Zoek &amp; Vervang</h3>
    <p>Zoek een tekststring in alle actieve configs en vervang deze.</p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="action" value="preview">

        <div class="form-group">
            <label>Zoekterm *</label>
            <input type="text" name="search_term" required placeholder="bijv. https://yealink-cfg.eu/download/file/rom1.rom" style="width:100%;">
            <small>Let op: case-sensitive</small>
        </div>

        <div class="form-group">
            <label>Vervangterm</label>
            <input type="text" name="replace_term" placeholder="bijv. https://yealink-cfg.eu/download/file/rom2.rom" style="width:100%;">
        </div>

        <button class="btn" type="submit">🔍 Preview</button>
    </form>
</div>
<?php endif; ?>

<?php if ($step === 'preview' && !empty($preview_results)): ?>
<div class="card">
    <h3>Stap 2: Preview resultaten</h3>
    <p><strong><?php echo count($preview_results); ?> config(s)</strong> bevatten de zoekterm.</p>

    <table class="preview-table">
        <thead>
            <tr>
                <th>Device</th>
                <th>Type</th>
                <th>Versie</th>
                <th>Matches</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($preview_results as $result): ?>
            <tr>
                <td><?php echo htmlspecialchars($result['device_name']); ?></td>
                <td><?php echo htmlspecialchars($result['type_name']); ?></td>
                <td>v<?php echo (int)$result['version_number']; ?></td>
                <td><span class="match-badge"><?php echo (int)$result['matches']; ?>×</span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:20px;" onsubmit="return confirm('Weet je zeker dat je <?php echo count($preview_results); ?> config(s) wilt bijwerken? Er wordt automatisch een nieuwe versie aangemaakt en geactiveerd.');">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="action" value="execute">
        <input type="hidden" name="search_term" value="<?php echo htmlspecialchars($_POST['search_term'] ?? ''); ?>">
        <input type="hidden" name="replace_term" value="<?php echo htmlspecialchars($_POST['replace_term'] ?? ''); ?>">

        <button class="btn" type="submit" style="background:#28a745;">✅ Uitvoeren</button>
        <a class="btn" href="?step=search" style="background:#6c757d;">Annuleren</a>
    </form>
</div>
<?php endif; ?>

<?php if ($step === 'complete'): ?>
<div class="card">
    <h3>✅ Voltooid!</h3>
    <p><?php echo htmlspecialchars($success); ?></p>
    <a class="btn" href="?step=search">Nieuwe operatie</a>
    <a class="btn" href="/settings/device_mapping.php" style="background:#6c757d;">Naar Device Mapping</a>
</div>
<?php endif; ?>

<div class="card" style="margin-top:24px;">
    <h3>🔄 Recente operaties &amp; Rollback</h3>
    <?php if (empty($recent_operations)): ?>
        <p>Geen recente operaties gevonden.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Zoekterm &rarr; Vervangterm</th>
                    <th>Configs</th>
                    <th>Uitgevoerd door</th>
                    <th>Status</th>
                    <th>Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_operations as $op): ?>
                <tr>
                    <td><?php echo htmlspecialchars($op['executed_at']); ?></td>
                    <td>
                        <code style="font-size:11px;"><?php echo htmlspecialchars(substr($op['search_term'], 0, 40)); ?></code>
                        &rarr;
                        <code style="font-size:11px;"><?php echo htmlspecialchars(substr($op['replace_term'], 0, 40)); ?></code>
                    </td>
                    <td><?php echo (int)$op['affected_configs']; ?></td>
                    <td><?php echo htmlspecialchars($op['executed_by_name']); ?></td>
                    <td>
                        <?php if ($op['rollback_at']): ?>
                            <span style="color:#dc3545;">Rolled back door <?php echo htmlspecialchars($op['rollback_by_name']); ?></span>
                        <?php else: ?>
                            <span style="color:#28a745;">Actief</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$op['rollback_at']): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Weet je zeker dat je deze operatie wilt terugdraaien? Alle <?php echo (int)$op['affected_configs']; ?> devices krijgen hun vorige config terug.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="rollback">
                                <input type="hidden" name="bulk_op_id" value="<?php echo (int)$op['id']; ?>">
                                <button class="btn" type="submit" style="background:#dc3545; font-size:12px; padding:4px 8px;">↩️ Rollback</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
