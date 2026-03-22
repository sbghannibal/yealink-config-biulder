<?php
// Bootstrap
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/partner_access.php';
require_once __DIR__ . '/../includes/i18n.php';

// Initialize language for this admin (prevents fallback to nl)
if (isset($_SESSION['admin_id'])) {
    $_SESSION['language'] = get_user_language($pdo, (int)$_SESSION['admin_id']);
}

$page_title = __('nav.partner_companies');

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Only Owner or admins with partners.manage may access this page
if (!is_owner($pdo, $admin_id) && !has_permission($pdo, $admin_id, 'partners.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error   = '';
$success = '';

// Handle POST actions (create / edit / toggle active)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                $error = 'Naam is verplicht.';
            } else {
                try {
                    $is_master = isset($_POST["is_master"]) ? 1 : 0;

                    $stmt = $pdo->prepare('INSERT INTO partner_companies (name, is_active, is_master) VALUES (?, 1, ?)');
                    $stmt->execute([$name, $is_master]);

                    $new_id = (int)$pdo->lastInsertId();
                    if ($is_master === 1 && $new_id > 0) {
                        $stmt = $pdo->prepare('UPDATE partner_companies SET is_master = 0 WHERE id <> ?' );
                        $stmt->execute([$new_id]);
                    }

                    $success = 'Partner bedrijf aangemaakt.';
                } catch (Exception $e) {
                    error_log('partners create error: ' . $e->getMessage());
                    $error = 'Kon partner niet aanmaken.';
                }
            }
        }

        if ($action === 'edit') {
            $pid  = (int)($_POST['partner_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
            if (!$pid || $name === '') {
                $error = 'Ongeldige invoer.';
            } else {
                try {
                    $is_master = isset($_POST["is_master"]) ? 1 : 0;

                    $stmt = $pdo->prepare('UPDATE partner_companies SET name = ?, is_active = ?, is_master = ? WHERE id = ?' );
                    $stmt->execute([$name, $is_active, $is_master, $pid]);

                    if ($is_master === 1) {
                        $stmt = $pdo->prepare('UPDATE partner_companies SET is_master = 0 WHERE id <> ?' );
                        $stmt->execute([$pid]);
                    }

                    $success = 'Partner bedrijf bijgewerkt.';
                } catch (Exception $e) {
                    error_log('partners edit error: ' . $e->getMessage());
                    $error = 'Kon partner niet bijwerken.';
                }
            }
        }
    }
}

// Fetch all partners
$partners = [];
try {
    $stmt = $pdo->query('SELECT id, name, is_active, is_master, created_at FROM partner_companies ORDER BY name ASC');
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('partners list error: ' . $e->getMessage());
    $error = 'Kon partnerlijst niet ophalen.';
}

// If editing a specific partner, load its data
$edit_partner = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($partners as $p) {
        if ((int)$p['id'] === $edit_id) {
            $edit_partner = $p;
            break;
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<style>
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
    .topbar h2 { margin:0; }
    .card { background:#fff; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:24px; }
    .card-body { padding:20px; }
    table { width:100%; border-collapse:collapse; }
    table th { background:#5d00b8; color:#fff; padding:10px 12px; text-align:left; font-weight:600; }
    table td { padding:10px 12px; border-bottom:1px solid #dee2e6; }
    table tbody tr:nth-child(even) { background:#f8f9fa; }
    table tbody tr:hover { background:#e9ecef; }
    .badge { display:inline-block; padding:3px 8px; font-size:11px; border-radius:3px; color:#fff; }
    .badge-success { background:#28a745; }
    .badge-danger  { background:#dc3545; }
    .alert { padding:12px 16px; border-radius:4px; margin-bottom:16px; }
    .alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .form-group { margin-bottom:16px; }
    .form-group label { display:block; font-weight:600; margin-bottom:6px; }
    .form-group input, .form-group select { width:100%; padding:8px 10px; border:1px solid #ced4da; border-radius:4px; font-size:14px; }
    .action-buttons { display:flex; gap:6px; }
</style>

<div class="topbar">
    <h2>🤝 <?php echo __('nav.partner_companies'); ?></h2>
    <a class="btn" href="/admin/partner_rights.php" style="background:#007bff;color:#fff;">🔑 <?php echo __('nav.partner_rights'); ?></a>
</div>

<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<!-- Create / Edit form -->
<div class="card">
    <div class="card-body">
        <h3 style="margin-top:0;"><?php echo $edit_partner ? __('partners.edit_title') : __('partners.new_title'); ?></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action"     value="<?php echo $edit_partner ? 'edit' : 'create'; ?>">
            <?php if ($edit_partner): ?>
                <input type="hidden" name="partner_id" value="<?php echo (int)$edit_partner['id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label><?php echo __('form.name'); ?> *</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($edit_partner['name'] ?? ''); ?>" required maxlength="255">
            </div>

              <div class="form-group">
                  <label>
                      <input type="checkbox" name="is_master" value="1" <?php echo (!empty($edit_partner) && (int)$edit_partner['is_master'] === 1) ? 'checked' : ''; ?>>
                        <?php echo __('partners.master_partner_hint'); ?>
                  </label>
              </div>


            <?php if ($edit_partner): ?>
            <div class="form-group">
                <label><?php echo __('form.active'); ?></label>
                <select name="is_active">
                    <option value="1" <?php echo $edit_partner['is_active'] ? 'selected' : ''; ?>><?php echo __('common.yes'); ?></option>
                    <option value="0" <?php echo !$edit_partner['is_active'] ? 'selected' : ''; ?>><?php echo __('common.no'); ?></option>
                </select>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn" style="background:#28a745;color:#fff;">
                <?php echo $edit_partner ? __('button.save') : __('button.create'); ?>
            </button>
            <?php if ($edit_partner): ?>
                <a class="btn" href="/admin/partners.php" style="background:#6c757d;color:#fff;"><?php echo __('button.cancel'); ?></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Partner list -->
<div class="card">
    <?php if (empty($partners)): ?>
        <div style="padding:40px;text-align:center;color:#6c757d;"><?php echo __('partners.none_created'); ?></div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo __('form.name'); ?></th>
                    <th><?php echo __('table.status'); ?></th>
                    <th><?php echo __('partners.master'); ?></th>
                    <th><?php echo __('table.created_at'); ?></th>
                    <th><?php echo __('table.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($partners as $p): ?>
                <tr>
                    <td><?php echo (int)$p['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                    <td>
                        <?php if ($p['is_active']): ?>
                            <span class="badge badge-success"><?php echo __('status.active'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?php echo __('status.inactive'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo ((int)$p['is_master'] === 1) ? '✓' : '—'; ?></td>
                    <td><?php echo htmlspecialchars($p['created_at']); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a class="btn" href="/admin/partners.php?edit=<?php echo (int)$p['id']; ?>" style="background:#007bff;color:#fff;font-size:12px;padding:6px 10px;">✏️ <?php echo __('button.edit'); ?></a>
                            <a class="btn" href="/admin/partner_rights.php?partner_id=<?php echo (int)$p['id']; ?>" style="background:#6c757d;color:#fff;font-size:12px;padding:6px 10px;">🔑 <?php echo __('nav.partner_rights'); ?></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
