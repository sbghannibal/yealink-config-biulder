<?php
$page_title = 'Audit Logs';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.audit.view')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Filters
$filters = [];
$params = [];
if (!empty($_GET['admin_id'])) {
    $filters[] = 'al.admin_id = ?';
    $params[] = (int) $_GET['admin_id'];
}
if (!empty($_GET['action'])) {
    $filters[] = 'al.action LIKE ?';
    $params[] = '%' . $_GET['action'] . '%';
}
if (!empty($_GET['date_from'])) {
    $filters[] = 'DATE(al.created_at) >= ?';
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters[] = 'DATE(al.created_at) <= ?';
    $params[] = $_GET['date_to'];
}

$where = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';

try {
    $sql = "SELECT al.*, a.username FROM audit_logs al LEFT JOIN admins a ON al.admin_id = a.id $where ORDER BY al.created_at DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('audit.php error: ' . $e->getMessage());
    $logs = [];
    $error = 'Kon audit logs niet ophalen.';
}

require_once __DIR__ . '/_header.php';
?>
<style>.monos { font-family: monospace; white-space: pre-wrap; }</style>
<h2><?php echo __('page.audit.title'); ?></h2>

<form method="get" class="card" style="margin-bottom:16px;">
        <div class="form-group">
            <label>Admin ID</label>
            <input name="admin_id" value="<?php echo htmlspecialchars($_GET['admin_id'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label><?php echo __('table.event'); ?></label>
            <input name="action" value="<?php echo htmlspecialchars($_GET['action'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Datum van</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Datum tot</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
        </div>
        <button class="btn" type="submit"><?php echo __('label.filter'); ?></button>
    </form>

    <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="card">
        <?php if (empty($logs)): ?>
            <p><?php echo __('label.no_results'); ?></p>
        <?php else: ?>
            <table>
                <thead><tr><th>Tijd</th><th><?php echo __('table.admin'); ?></th><th><?php echo __('table.event'); ?></th><th>Entiteit</th><th><?php echo __('table.details'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($l['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($l['username'] ?? 'Systeem'); ?></td>
                            <td><?php echo htmlspecialchars($l['action']); ?></td>
                            <td><?php echo htmlspecialchars($l['entity_type'] ?? '-'); ?></td>
                            <td class="monos"><?php
                                $parts = [];
                                if (!empty($l['old_value'])) $parts[] = 'OLD: ' . $l['old_value'];
                                if (!empty($l['new_value'])) $parts[] = 'NEW: ' . $l['new_value'];
                                echo htmlspecialchars(implode("\n", $parts) ?: '-');
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php require_once __DIR__ . '/_footer.php'; ?>
