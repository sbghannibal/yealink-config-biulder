<?php
$page_title = 'Download Tokens';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission required to manage tokens
if (!has_permission($pdo, $admin_id, 'admin.tokens.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Helper to build full token URL
function build_token_url($token) {
    $host = ($_SERVER['HTTPS'] ?? '') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https://' . ($_SERVER['HTTP_HOST'] ?? '')
        : 'http://' . ($_SERVER['HTTP_HOST'] ?? '');
    return rtrim($host, '/') . '/download.php?token=' . urlencode($token);
}

// Handle actions: create, revoke, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST;
    if (!hash_equals($csrf, $posted['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $posted['action'] ?? '';

        if ($action === 'create') {
            $config_version_id = !empty($posted['config_version_id']) ? (int)$posted['config_version_id'] : null;
            $expires_hours = !empty($posted['expires_hours']) ? max(1, (int)$posted['expires_hours']) : 24;
            $mac_address = trim($posted['mac_address'] ?? '') ?: null;
            $device_model = trim($posted['device_model'] ?? '') ?: null;
            $pabx_id = !empty($posted['pabx_id']) ? (int)$posted['pabx_id'] : null;

            if (!$config_version_id) {
                $error = 'Geef een config versie ID op.';
            } else {
                try {
                    $token = bin2hex(random_bytes(24));
                    $expires_at = date('Y-m-d H:i:s', time() + $expires_hours * 3600);
                    $ins = $pdo->prepare('INSERT INTO download_tokens (token, config_version_id, mac_address, device_model, pabx_id, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                    $ins->execute([$token, $config_version_id, $mac_address, $device_model, $pabx_id, $expires_at, $admin_id]);
                    $newId = $pdo->lastInsertId();

                    // Audit
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'create_download_token',
                            'download_token',
                            $newId,
                            json_encode(['token' => $token, 'config_version_id' => $config_version_id, 'expires_at' => $expires_at]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('tokens create audit error: ' . $e->getMessage());
                    }

                    $success = 'Token aangemaakt. URL: ' . build_token_url($token);
                } catch (Exception $e) {
                    error_log('tokens create error: ' . $e->getMessage());
                    $error = 'Kon token niet aanmaken.';
                }
            }
        }

        if ($action === 'revoke') {
            $id = !empty($posted['id']) ? (int)$posted['id'] : 0;
            if ($id <= 0) {
                $error = 'Ongeldig token ID.';
            } else {
                try {
                    // Set expiry to now so token is invalid
                    $stmt = $pdo->prepare('UPDATE download_tokens SET expires_at = NOW() WHERE id = ?');
                    $stmt->execute([$id]);

                    // Audit
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'revoke_download_token',
                            'download_token',
                            $id,
                            null,
                            json_encode(['revoked_at' => date('c')]),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('tokens revoke audit error: ' . $e->getMessage());
                    }

                    $success = 'Token ingetrokken.';
                } catch (Exception $e) {
                    error_log('tokens revoke error: ' . $e->getMessage());
                    $error = 'Kon token niet intrekken.';
                }
            }
        }

        if ($action === 'delete') {
            $id = !empty($posted['id']) ? (int)$posted['id'] : 0;
            if ($id <= 0) {
                $error = 'Ongeldig token ID.';
            } else {
                try {
                    // Optionally capture old before delete
                    $stmt = $pdo->prepare('SELECT * FROM download_tokens WHERE id = ? LIMIT 1');
                    $stmt->execute([$id]);
                    $old = $stmt->fetch(PDO::FETCH_ASSOC);

                    $del = $pdo->prepare('DELETE FROM download_tokens WHERE id = ?');
                    $del->execute([$id]);

                    // Audit
                    try {
                        $alog = $pdo->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $alog->execute([
                            $admin_id,
                            'delete_download_token',
                            'download_token',
                            $id,
                            json_encode($old),
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (Exception $e) {
                        error_log('tokens delete audit error: ' . $e->getMessage());
                    }

                    $success = 'Token verwijderd.';
                } catch (Exception $e) {
                    error_log('tokens delete error: ' . $e->getMessage());
                    $error = 'Kon token niet verwijderen.';
                }
            }
        }
    }
}

// Fetch tokens list (latest 200)
try {
    $stmt = $pdo->prepare('SELECT dt.*, cv.version_number, cv.pabx_id AS cv_pabx_id, cv.device_type_id AS cv_device_type_id, a.username AS creator FROM download_tokens dt LEFT JOIN config_versions cv ON dt.config_version_id = cv.id LEFT JOIN admins a ON dt.created_by = a.id ORDER BY dt.created_at DESC LIMIT 200');
    $stmt->execute();
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('tokens list error: ' . $e->getMessage());
    $tokens = [];
}

// Fetch list of config versions for create form
try {
    $cvstmt = $pdo->query('SELECT id, pabx_id, device_type_id, version_number FROM config_versions ORDER BY created_at DESC LIMIT 200');
    $config_versions = $cvstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $config_versions = [];
}

require_once __DIR__ . '/_header.php';
?>
<style>
    .small { font-size:90%; color:#666; }
    table { width:100%; border-collapse:collapse; }
    table th, table td { padding:8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
    .actions form { display:inline; }
</style>

<h2>Download Tokens</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo nl2br(htmlspecialchars($success)); ?></div><?php endif; ?>

    <section class="card" style="margin-bottom:16px;">
        <h3>Maak nieuw token</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label>Config versie ID</label>
                <select name="config_version_id" required>
                    <option value="">-- Kies versie --</option>
                    <?php foreach ($config_versions as $cv): ?>
                        <option value="<?php echo (int)$cv['id']; ?>">
                            ID <?php echo (int)$cv['id']; ?> — PABX: <?php echo (int)$cv['pabx_id']; ?> — Type: <?php echo (int)$cv['device_type_id']; ?> — v<?php echo (int)$cv['version_number']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Geldigheid (uur)</label>
                <input name="expires_hours" type="number" min="1" value="24" required>
            </div>

            <div class="form-group">
                <label>Optioneel MAC-adres (alleen voor overzicht)</label>
                <input name="mac_address" type="text" placeholder="00:11:22:33:44:55">
            </div>

            <div class="form-group">
                <label>Optioneel apparaat-model</label>
                <input name="device_model" type="text" placeholder="T46P">
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">Genereer token</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h3>Recente tokens</h3>
        <?php if (empty($tokens)): ?>
            <p class="small">Geen tokens gevonden.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Token</th>
                        <th>Config v</th>
                        <th>MAC / Model</th>
                        <th>Geldig tot</th>
                        <th>Used</th>
                        <th>Aangemaakt door</th>
                        <th>Aangemaakt</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $t): 
                        $is_active = (empty($t['used_at']) && (!empty($t['expires_at']) ? strtotime($t['expires_at']) > time() : true));
                    ?>
                        <tr>
                            <td><?php echo (int)$t['id']; ?></td>
                            <td style="max-width:320px; word-break:break-all;">
                                <?php echo htmlspecialchars($t['token']); ?><br>
                                <a href="<?php echo htmlspecialchars(build_token_url($t['token'])); ?>" target="_blank">Open</a>
                            </td>
                            <td><?php echo htmlspecialchars($t['version_number'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($t['mac_address'] ?? '-') . ' / ' . htmlspecialchars($t['device_model'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($t['expires_at'] ?? '-'); ?> <?php if ($is_active): ?><span style="color:green;">(actief)</span><?php else: ?><span style="color:#666;">(inactief)</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($t['used_at'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($t['creator'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($t['created_at'] ?? '-'); ?></td>
                            <td class="actions">
                                <?php if ($is_active): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                        <button class="btn" type="submit" style="background:#f0ad4e;">Intrekken</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" style="display:inline" onsubmit="return confirm('Weet je zeker dat je dit token wilt verwijderen?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <button class="btn" type="submit" style="background:#dc3545;">Verwijderen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
