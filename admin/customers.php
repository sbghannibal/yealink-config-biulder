<?php
$page_title = 'Klanten';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/partner_access.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Check permission - using a new permission or fallback to admin permission
if (!has_permission($pdo, $admin_id, 'customers.view')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Require role + partner assignment (or be owner)
require_any_role($pdo, $admin_id);
require_partner_or_owner($pdo, $admin_id);


$customers = [];
$error = '';

// Pagination + search
$q = trim((string)($_GET['q'] ?? ''));
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, [10, 25, 100], true)) $per_page = 25;

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$offset = ($page - 1) * $per_page;
$total_customers = 0;

try {
    $allowed = get_allowed_customer_ids_for_admin($pdo, $admin_id);
    $params  = [];
    $filter  = build_customer_filter($allowed, 'c.id', $params);

    // Search filter (company_name, customer_code, contact_person, email, phone)
    $search_sql = '';
    if ($q !== '') {
        $search_sql = " AND (c.company_name LIKE ? OR c.customer_code LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    // Total count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers c WHERE c.deleted_at IS NULL' . $filter . $search_sql);
    $stmt->execute($params);
    $total_customers = (int)$stmt->fetchColumn();

    // Page rows
    $params_page = $params;
    $params_page[] = $per_page;
    $params_page[] = $offset;

    $stmt = $pdo->prepare('
        SELECT c.*,
               (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id AND d.deleted_at IS NULL) as device_count
        FROM customers c
        WHERE c.deleted_at IS NULL' . $filter . $search_sql . '
        ORDER BY c.company_name ASC
        LIMIT ? OFFSET ?
    ');
    $stmt->execute($params_page);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Failed to fetch customers: " . $e->getMessage());
    $error = "Kon klanten niet ophalen: " . $e->getMessage();
}

require_once __DIR__ . '/_header.php';
?>

<style>
    .badge { 
        display: inline-block; 
        padding: 4px 8px; 
        font-size: 11px; 
        border-radius: 3px; 
        background: #6c757d; 
        color: white; 
        margin-left: 4px; 
    }
    .badge.success { background: #28a745; }
    .badge.warning { background: #ffc107; color: #000; }
    .badge.info { background: #17a2b8; }
    
    .action-buttons { 
        display: flex; 
        gap: 6px; 
        flex-wrap: wrap; 
        align-items: center;
    }
    .action-buttons .btn { 
        font-size: 12px; 
        padding: 6px 10px; 
        white-space: nowrap;
        text-decoration: none;
    }
    
    table tbody tr:nth-child(even) { background: #f8f9fa; }
    table tbody tr:hover { background: #e9ecef; }
    
    .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .topbar h2 { margin: 0; }
    
    .card {
        background: white;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    table th {
        background: #5d00b8; color: white;
        padding: 12px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
    }
    
    table td {
        padding: 10px 12px;
        border-bottom: 1px solid #dee2e6;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
    }
    
    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
</style>

<div style="margin-bottom:20px;">
    <h2 style="margin:0;">👥 <?php echo __('page.customers.title'); ?></h2>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">
        ✅ <?php echo __('message.customer_created'); ?>!
        
        <div style="margin-top:12px; display:flex; gap:8px;">
            <a class="btn" href="/admin/customers_add.php" style="background: #28a745; color: white; font-size:13px; padding:8px 12px;">➕ <?php echo __('page.customers.create'); ?></a>
            <a class="btn" href="/devices/create.php?customer_id=<?php echo (int)$_GET['created']; ?>" style="background: #007bff; color: white; font-size:13px; padding:8px 12px;">📱 Device Toevoegen</a>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success"><?php echo __('message.customer_updated'); ?>!</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success"><?php echo __('message.customer_deleted'); ?>!</div>
<?php endif; ?>

<?php if (!isset($_GET['created'])): ?>
    <div style="margin-bottom:16px;">
        <a class="btn" href="/admin/customers_add.php" style="background: #28a745; color: white;">➕ <?php echo __('page.customers.create'); ?></a>
    </div>
<?php endif; ?>


<form method="get" style="margin-bottom:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <input type="text"
           name="q"
           value="<?php echo htmlspecialchars($q); ?>"
           placeholder="<?php echo __('common.search'); ?>..."
           style="padding:8px 10px; min-width:260px; border:1px solid #ced4da; border-radius:4px;">

    <label style="display:flex; gap:8px; align-items:center;">
        <span><?php echo __('common.per_page'); ?></span>
        <select name="per_page" onchange="this.form.submit()" style="padding:7px 10px; border:1px solid #ced4da; border-radius:4px;">
            <?php foreach ([10,25,100] as $n): ?>
                <option value="<?php echo $n; ?>" <?php echo ($per_page === $n) ? 'selected' : ''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <button class="btn" type="submit" style="background:#007bff;color:#fff;">
        <?php echo __('common.search'); ?>
    </button>

    <?php if ($q !== ''): ?>
        <a class="btn" href="/admin/customers.php?per_page=<?php echo (int)$per_page; ?>" style="background:#6c757d;color:#fff;">
            <?php echo __('common.clear'); ?>
        </a>
    <?php endif; ?>

    <input type="hidden" name="page" value="1">
</form>

<?php
$total_pages = (int)ceil(($total_customers ?: 0) / $per_page);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

$start = ($total_customers === 0) ? 0 : ($offset + 1);
$end   = min($offset + $per_page, $total_customers);

function build_customers_url($overrides = []) {
    $params = array_merge($_GET, $overrides);
    if (isset($params['q']) && trim((string)$params['q']) === '') unset($params['q']);
    return '/admin/customers.php?' . http_build_query($params);
}
?>

<div style="margin:10px 0; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; color:#6c757d;">
    <div>
        <?php echo $start; ?>-<?php echo $end; ?> <?php echo __('common.of'); ?> <?php echo (int)$total_customers; ?>
    </div>
    <div style="display:flex; gap:8px; align-items:center;">
        <a class="btn" href="<?php echo htmlspecialchars(build_customers_url(['page' => max(1, $page - 1)])); ?>"
           style="background:#6c757d;color:#fff;<?php echo ($page <= 1) ? 'opacity:.5;pointer-events:none;' : ''; ?>">
            ← <?php echo __('common.prev'); ?>
        </a>

        <span><?php echo __('common.page'); ?> <?php echo (int)$page; ?> <?php echo __('common.of'); ?> <?php echo (int)$total_pages; ?></span>

        <a class="btn" href="<?php echo htmlspecialchars(build_customers_url(['page' => min($total_pages, $page + 1)])); ?>"
           style="background:#6c757d;color:#fff;<?php echo ($page >= $total_pages) ? 'opacity:.5;pointer-events:none;' : ''; ?>">
            <?php echo __('common.next'); ?> →
        </a>
    </div>
</div>

<div class="card">
    <?php if (empty($customers)): ?>
        <div style="padding: 40px; text-align: center;">
            <p style="color: #6c757d; font-size: 16px;">
                <?php echo __('label.no_results'); ?>. 
                <a href="/admin/customers_add.php" style="color: #007bff; text-decoration: none;"><?php echo __('page.customers.create'); ?> →</a>
            </p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?php echo __('table.id'); ?></th>
                    <th><?php echo __('form.customer_code'); ?></th>
                    <th><?php echo __('form.company_name'); ?></th>
                    <th><?php echo __('label.contact_person'); ?></th>
                    <th><?php echo __('table.email'); ?></th>
                    <th><?php echo __('form.phone'); ?></th>
                    <th><?php echo __('label.devices_count'); ?></th>
                    <th><?php echo __('table.status'); ?></th>
                    <th><?php echo __('table.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><strong>#<?php echo (int)$c['id']; ?></strong></td>
                        <td><code style="background: #5d00b8; color: white; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($c['customer_code']); ?></code></td>
                        <td><?php echo htmlspecialchars($c['company_name']); ?></td>
                        <td><?php echo htmlspecialchars($c['contact_person'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['email'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['phone'] ?? '-'); ?></td>
                        <td>
                            <span class="badge info"><?php echo (int)$c['device_count']; ?> device(s)</span>
                        </td>
                        <td>
                            <?php if ($c['is_active']): ?>
                                <span class="badge success">✓ <?php echo __('status.active'); ?></span>
                            <?php else: ?>
                                <span class="badge" style="background: #dc3545;">✗ <?php echo __('status.inactive'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a class="btn" href="/admin/customers_edit.php?id=<?php echo (int)$c['id']; ?>" style="background: #007bff; color: white;">✏️ <?php echo __('button.edit'); ?></a>
                                <a class="btn" href="/admin/customers_delete.php?id=<?php echo (int)$c['id']; ?>" onclick="return confirm('<?php echo __('confirm.delete_customer'); ?>');" style="background: #dc3545; color: white;">🗑️ <?php echo __('button.delete'); ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
