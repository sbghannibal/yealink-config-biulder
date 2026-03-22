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

$page_title = __('nav.partner_rights');

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

// Fetch all active partner companies for dropdown
$all_partners = [];
try {
    $stmt = $pdo->query('SELECT id, name FROM partner_companies ORDER BY name ASC');
    $all_partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('partner_rights list partners error: ' . $e->getMessage());
    $error = 'Kon partnerlijst niet ophalen.';
}

// Selected partner from GET/POST
$selected_partner_id = isset($_POST['partner_id']) ? (int)$_POST['partner_id']
                      : (isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0);

// Search + pagination
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$allowed_per_page = [10, 25, 100];
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 10;
}

$offset = ($page - 1) * $per_page;

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rights'])) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige aanvraag (CSRF).';
    } elseif (!$selected_partner_id) {
        $error = __('partner_rights.error_select_partner');
    } else {
        // checked_customers = array of customer_ids that should be can_view=1
        $checked = isset($_POST['checked_customers']) && is_array($_POST['checked_customers'])
                   ? array_map('intval', $_POST['checked_customers'])
                   : [];

        try {
            $pdo->beginTransaction();

            // Fetch all customers to know which rows should be removed/upserted
            $all_cust_stmt = $pdo->query('SELECT id FROM customers WHERE deleted_at IS NULL');
            $all_customer_ids = $all_cust_stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($all_customer_ids as $cid) {
                $cid = (int)$cid;

                if (in_array($cid, $checked, true)) {
                    // Upsert: can_view = 1
                    $stmt = $pdo->prepare('
                        INSERT INTO partner_company_customers (partner_company_id, customer_id, can_view)
                        VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE can_view = 1, updated_at = NOW()
                    ');
                    $stmt->execute([$selected_partner_id, $cid]);
                } else {
                    // Remove (deny-by-default)
                    $stmt = $pdo->prepare('
                        DELETE FROM partner_company_customers
                        WHERE partner_company_id = ? AND customer_id = ?
                    ');
                    $stmt->execute([$selected_partner_id, $cid]);
                }
            }

            $pdo->commit();
            $success = __('partner_rights.saved_success');
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('partner_rights save error: ' . $e->getMessage());
            $error = __('partner_rights.error_save_rights');
        }
    }
}
// Fetch customers (paginated + search) with device count
$all_customers = [];
$total_customers = 0;

try {
    $where = 'c.deleted_at IS NULL';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (c.customer_code LIKE ? OR c.company_name LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    // total count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $where");
    $count_stmt->execute($params);
    $total_customers = (int)$count_stmt->fetchColumn();

    // page items
    $sql = "
        SELECT c.id, c.customer_code, c.company_name,
               (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id AND d.deleted_at IS NULL) as device_count
        FROM customers c
        WHERE $where
        ORDER BY c.company_name ASC
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $all_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // clamp page if out of range
    $max_page = max(1, (int)ceil($total_customers / $per_page));
    if ($page > $max_page) {
        $page = $max_page;
    }
} catch (Exception $e) {
    error_log('partner_rights list customers error: ' . $e->getMessage());
    $error = __('partner_rights.error_fetch_customers');
}

// Fetch already-checked customers for the selected partner
$checked_customer_ids = [];
if ($selected_partner_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT customer_id FROM partner_company_customers
            WHERE partner_company_id = ? AND can_view = 1
        ');
        $stmt->execute([$selected_partner_id]);
        $checked_customer_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        error_log('partner_rights fetch checked error: ' . $e->getMessage());
    }
}


// Prev/Next partner navigation (based on $all_partners order)
$prev_partner_id = 0;
$next_partner_id = 0;

if (!empty($all_partners) && $selected_partner_id) {
    $ids = array_map(static function ($p) { return (int)$p['id']; }, $all_partners);
    $idx = array_search((int)$selected_partner_id, $ids, true);

    if ($idx !== false) {
        if ($idx > 0) {
            $prev_partner_id = (int)$ids[$idx - 1];
        }
        if ($idx < count($ids) - 1) {
            $next_partner_id = (int)$ids[$idx + 1];
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
    .alert { padding:12px 16px; border-radius:4px; margin-bottom:16px; }
    .alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .form-group { margin-bottom:16px; }
    .form-group label { display:block; font-weight:600; margin-bottom:6px; }
    .form-group select { padding:8px 10px; border:1px solid #ced4da; border-radius:4px; font-size:14px; min-width:300px; }
    table { width:100%; border-collapse:collapse; }
    table th { background:#5d00b8; color:#fff; padding:10px 12px; text-align:left; font-weight:600; }
    table td { padding:10px 12px; border-bottom:1px solid #dee2e6; }
    table tbody tr:nth-child(even) { background:#f8f9fa; }
    table tbody tr:hover { background:#e9ecef; }
    .select-all-bar { padding:10px 12px; background:#f1f3f5; border-bottom:1px solid #dee2e6; display:flex; gap:8px; }
</style>

<div class="topbar">
    <h2>🔑 <?php echo __('partner_rights.title_customers'); ?></h2>
    <a class="btn" href="/admin/partners.php" style="background:#6c757d;color:#fff;">← <?php echo __('partner_rights.back_to_partners'); ?></a>
</div>


            <?php if ($selected_partner_id): ?>
                <?php
                    $nav_params = [
                        'q' => $q,
                        'per_page' => $per_page,
                        'page' => 1,
                    ];
                ?>
                <div class="form-group" style="margin:0; display:flex; gap:8px;">
                    <?php if ($prev_partner_id): ?>
                        <?php $prev_qs = http_build_query(array_merge($nav_params, ['partner_id' => $prev_partner_id])); ?>
                        <a class="btn" href="/admin/partner_rights.php?<?php echo $prev_qs; ?>" style="background:#6c757d;color:#fff;">← <?php echo __('common.prev'); ?></a>
                    <?php endif; ?>

                    <?php if ($next_partner_id): ?>
                        <?php $next_qs = http_build_query(array_merge($nav_params, ['partner_id' => $next_partner_id])); ?>
                        <a class="btn" href="/admin/partner_rights.php?<?php echo $next_qs; ?>" style="background:#6c757d;color:#fff;"><?php echo __('common.next'); ?> →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<!-- Partner selector -->
<div class="card">
    <div class="card-body">
        <form method="get" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="form-group" style="margin:0;">
                <label><?php echo __('partner_rights.partner_company'); ?></label>
                <select name="partner_id" onchange="this.form.submit()">
                    <option value=""><?php echo __('partner_rights.select_partner_placeholder'); ?></option>
                    <?php foreach ($all_partners as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo $selected_partner_id === (int)$p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selected_partner_id): ?>
            <div class="form-group" style="margin:0;">
                <label><?php echo __('common.search'); ?></label>
                <input type="text"
                       name="q"
                       value="<?php echo htmlspecialchars($q); ?>"
                       placeholder="<?php echo __('partner_rights.search_placeholder'); ?>"
                       style="padding:8px 10px;border:1px solid #ced4da;border-radius:4px;font-size:14px;min-width:260px;">
            </div>

            <div class="form-group" style="margin:0;">
                <label><?php echo __('common.per_page'); ?></label>
                <select name="per_page">
                    <?php foreach ([10,25,100] as $pp): ?>
                        <option value="<?php echo $pp; ?>" <?php echo ($per_page === $pp) ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" name="page" value="1">

            <div class="form-group" style="margin:0;">
                <button type="submit" class="btn" style="background:#007bff;color:#fff;"><?php echo __('common.apply'); ?></button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($selected_partner_id && !empty($all_customers)): ?>
<div class="card">
    <form method="post">
        <input type="hidden" name="csrf_token"     value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="partner_id"     value="<?php echo $selected_partner_id; ?>">
        <input type="hidden" name="save_rights"    value="1">

        <div class="select-all-bar">
            <button type="button" onclick="toggleAll(true)"  style="padding:4px 10px;font-size:12px;cursor:pointer;">✓ <?php echo __('partner_rights.select_all'); ?></button>
            <button type="button" onclick="toggleAll(false)" style="padding:4px 10px;font-size:12px;cursor:pointer;">✗ <?php echo __('partner_rights.deselect_all'); ?></button>
            <span style="margin-left:auto;font-size:13px;color:#6c757d;"><?php echo count($checked_customer_ids); ?> <?php echo __('common.of'); ?> <?php echo (int)$total_customers; ?> <?php echo __('partner_rights.selected'); ?></span>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:50px;"><?php echo __('partner_rights.can_view'); ?></th>
                    <th><?php echo __('table.code'); ?></th>
                    <th><?php echo __('table.company_name'); ?></th>
                    <th><?php echo __('table.devices'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_customers as $c): ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox"
                               name="checked_customers[]"
                               value="<?php echo (int)$c['id']; ?>"
                               class="customer-check"
                               <?php echo in_array((int)$c['id'], $checked_customer_ids, true) ? 'checked' : ''; ?>>
                    </td>
                    <td><code style="background:#5d00b8;color:#fff;padding:2px 6px;border-radius:3px;font-size:12px;"><?php echo htmlspecialchars($c['customer_code']); ?></code></td>
                    <td><?php echo htmlspecialchars($c['company_name']); ?></td>
                    <td><?php echo (int)$c['device_count']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
            $max_page = max(1, (int)ceil($total_customers / $per_page));
            $base_params = [
                'partner_id' => $selected_partner_id,
                'q' => $q,
                'per_page' => $per_page,
            ];
        ?>
        <div style="padding:12px 16px; display:flex; align-items:center; gap:10px; border-top:1px solid #dee2e6;">
            <div style="color:#6c757d;font-size:13px;">
                <?php echo __('common.page'); ?> <?php echo (int)$page; ?> <?php echo __('common.of'); ?> <?php echo (int)$max_page; ?> — <?php echo __('common.total'); ?> <?php echo (int)$total_customers; ?>
            </div>

            <div style="margin-left:auto; display:flex; gap:8px;">
                <?php if ($page > 1): ?>
                    <?php $prev_qs = http_build_query(array_merge($base_params, ['page' => $page - 1])); ?>
                    <a class="btn" href="/admin/partner_rights.php?<?php echo $prev_qs; ?>" style="background:#6c757d;color:#fff;font-size:12px;padding:6px 10px;">← <?php echo __('common.prev'); ?></a>
                <?php endif; ?>

                <?php if ($page < $max_page): ?>
                    <?php $next_qs = http_build_query(array_merge($base_params, ['page' => $page + 1])); ?>
                    <a class="btn" href="/admin/partner_rights.php?<?php echo $next_qs; ?>" style="background:#6c757d;color:#fff;font-size:12px;padding:6px 10px;"><?php echo __('common.next'); ?> →</a>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding:16px;">
            <button type="submit" class="btn" style="background:#28a745;color:#fff;">💾 <?php echo __('partner_rights.save_rights'); ?></button>
        </div>
    </form>
</div>
<?php elseif ($selected_partner_id): ?>
    <div class="card"><div class="card-body" style="color:#6c757d;">Geen klanten gevonden.</div></div>
<?php elseif (!$selected_partner_id && !empty($all_partners)): ?>
    <div class="card"><div class="card-body" style="color:#6c757d;"><?php echo __('partner_rights.select_partner_help'); ?></div></div>
<?php elseif (empty($all_partners)): ?>
    <div class="card"><div class="card-body">Nog geen partner bedrijven. <a href="/admin/partners.php">Maak er één aan →</a></div></div>
<?php endif; ?>

<script>
function toggleAll(checked) {
    document.querySelectorAll('.customer-check').forEach(function(cb) {
        cb.checked = checked;
    });
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
