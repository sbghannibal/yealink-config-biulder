<?php
$page_title = 'Variabelen';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.variables.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $var_name = trim($_POST['var_name'] ?? '');
        $var_value = trim($_POST['var_value'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($var_name === '' || $var_value === '') {
            $error = 'Vul variabele naam en waarde in.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO variables (var_name, var_value, description) VALUES (?, ?, ?)');
                $stmt->execute([$var_name, $var_value, $description ?: null]);
                $success = 'Variabele aangemaakt.';
            } catch (Exception $e) {
                error_log('variables create error: ' . $e->getMessage());
                $error = 'Kon variabele niet aanmaken (mogelijk bestaat deze al).';
            }
        }
    }
}

// Handle update/delete via POST with action param
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do']) && in_array($_POST['do'], ['update','delete'], true)) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) $error = 'Ongeldige ID.';
        else {
            if ($_POST['do'] === 'update') {
                $var_name = trim($_POST['var_name'] ?? '');
                $var_value = trim($_POST['var_value'] ?? '');
                $description = trim($_POST['description'] ?? '');
                if ($var_name === '' || $var_value === '') {
                    $error = 'Vul variabele naam en waarde in.';
                } else {
                    try {
                        $stmt = $pdo->prepare('UPDATE variables SET var_name = ?, var_value = ?, description = ? WHERE id = ?');
                        $stmt->execute([$var_name, $var_value, $description ?: null, $id]);
                        $success = 'Variabele bijgewerkt.';
                    } catch (Exception $e) {
                        error_log('variables update error: ' . $e->getMessage());
                        $error = 'Kon variabele niet bijwerken.';
                    }
                }
            } elseif ($_POST['do'] === 'delete') {
                try {
                    $del = $pdo->prepare('DELETE FROM variables WHERE id = ?');
                    $del->execute([$id]);
                    $success = 'Variabele verwijderd.';
                } catch (Exception $e) {
                    error_log('variables delete error: ' . $e->getMessage());
                    $error = 'Kon variabele niet verwijderen.';
                }
            }
        }
    }
}

// Fetch all variables
try {
    $stmt = $pdo->query('SELECT id, var_name, var_value, description, created_at FROM variables ORDER BY var_name ASC');
    $vars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('variables fetch error: ' . $e->getMessage());
    $vars = [];
    $error = $error ?: 'Kon variabelen niet ophalen.';
}

require_once __DIR__ . '/_header.php';
?>
<style>.mono { font-family: monospace; }</style>

<h2>Globale variabelen (gebruik in config met {{VARNAME}})</h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="card" style="margin-bottom:16px;">
        <h3>Nieuwe variabele</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Naam (bijv. SERVER_IP) — alleen A-Z0-9_</label>
                <input name="var_name" type="text" pattern="[A-Z0-9_]+" placeholder="SERVER_IP" required>
            </div>
            <div class="form-group">
                <label>Waarde</label>
                <input name="var_value" type="text" required>
            </div>
            <div class="form-group">
                <label>Beschrijving (optioneel)</label>
                <input name="description" type="text">
            </div>
            <button class="btn" type="submit">Aanmaken</button>
        </form>
    </section>

    <section class="card">
        <h3>Bestaande variabelen</h3>
        <?php if (empty($vars)): ?>
            <p>Geen variabelen gevonden.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>ID</th><th>Naam</th><th>Waarde</th><th>Beschrijving</th><th>Aangemaakt</th><th>Acties</th></tr></thead>
                <tbody>
                    <?php foreach ($vars as $v): ?>
                        <tr>
                            <td><?php echo (int)$v['id']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($v['var_name']); ?></td>
                            <td><?php echo htmlspecialchars($v['var_value']); ?></td>
                            <td><?php echo htmlspecialchars($v['description'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($v['created_at']); ?></td>
                            <td>
                                <button class="btn" onclick="document.getElementById('edit-<?php echo (int)$v['id']; ?>').style.display='block';">Bewerken</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('Weet je zeker dat je deze variabele wilt verwijderen?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="do" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                    <button class="btn" type="submit" style="background:#dc3545;">Verwijderen</button>
                                </form>

                                <!-- inline edit form, hidden by default -->
                                <div id="edit-<?php echo (int)$v['id']; ?>" style="display:none; margin-top:8px; padding:8px; border:1px solid #eee; background:#fafafa;">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="do" value="update">
                                        <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                        <div class="form-group">
                                            <label>Naam</label>
                                            <input name="var_name" type="text" value="<?php echo htmlspecialchars($v['var_name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Waarde</label>
                                            <input name="var_value" type="text" value="<?php echo htmlspecialchars($v['var_value']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Beschrijving</label>
                                            <input name="description" type="text" value="<?php echo htmlspecialchars($v['description']); ?>">
                                        </div>
                                        <div style="display:flex; gap:8px;">
                                            <button class="btn" type="submit">Opslaan</button>
                                            <button type="button" class="btn" onclick="document.getElementById('edit-<?php echo (int)$v['id']; ?>').style.display='none';" style="background:#6c757d;">Annuleer</button>
                                        </div>
                                    </form>
                                </div>

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
