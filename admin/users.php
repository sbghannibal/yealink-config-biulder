<?php
$page_title = 'Gebruikers';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Zorg dat gebruiker is ingelogd
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Optionele permissie-check (pas permission string aan indien gewenst)
if (!has_permission($pdo, $admin_id, 'admin.users.view')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Sorteer parameters
$allowed_sort = ['id', 'username', 'email', 'is_active', 'created_at'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort) ? $_GET['sort'] : 'username';
$order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

$error = '';
try {
    $stmt = $pdo->prepare("SELECT id, username, email, is_active, created_at FROM admins ORDER BY {$sort} {$order}");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Kon gebruikers niet ophalen.';
    error_log('users.php error: ' . $e->getMessage());
}

// Helper functie voor sort links
function sort_link($column, $current_sort, $current_order) {
    $new_order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($current_sort === $column) {
        $arrow = $current_order === 'asc' ? ' ▲' : ' ▼';
    }
    return "?sort={$column}&order={$new_order}\" style=\"color: inherit; text-decoration: none; cursor: pointer;\">{$arrow}";
}

require_once __DIR__ . '/_header.php';
?>

<style>
.btn-no-underline {
    text-decoration: none !important;
}
.sortable-header {
    cursor: pointer;
    user-select: none;
}
.sortable-header:hover {
    background-color: #5599FF !important;
    color: white !important;
}
.sortable-header:hover a {
    color: white !important;
}
.sortable-header a {
    display: block;
    width: 100%;
    color: inherit;
    text-decoration: none;
}
</style>

    <h2><?php echo __('page.users.title'); ?></h2>
    <p><a class="btn btn-no-underline" href="/admin/users_create.php">➕ <?php echo __('page.users.create'); ?></a></p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th class="sortable-header"><a href="<?php echo sort_link('id', $sort, $order); ?><?php echo __('table.id'); ?></a></th>
                    <th class="sortable-header"><a href="<?php echo sort_link('username', $sort, $order); ?><?php echo __('table.username'); ?></a></th>
                    <th class="sortable-header"><a href="<?php echo sort_link('email', $sort, $order); ?><?php echo __('table.email'); ?></a></th>
                    <th class="sortable-header"><a href="<?php echo sort_link('is_active', $sort, $order); ?><?php echo __('form.is_active'); ?></a></th>
                    <th class="sortable-header"><a href="<?php echo sort_link('created_at', $sort, $order); ?><?php echo __('table.created_at'); ?></a></th>
                    <th><?php echo __('table.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" style="text-align:center"><?php echo __('label.no_results'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo (int)$u['id']; ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><?php echo $u['is_active'] ? __('label.yes') : __('label.no'); ?></td>
                            <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                            <td>
                                <a class="btn btn-no-underline" href="/admin/users_edit.php?id=<?php echo (int)$u['id']; ?>"><?php echo __('button.edit'); ?></a>
                                <a class="btn btn-no-underline" href="/admin/users_delete.php?id=<?php echo (int)$u['id']; ?>" style="background:#dc3545;" onclick="return confirm('<?php echo __('confirm.delete_user'); ?>');"><?php echo __('button.delete'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php require_once __DIR__ . '/_footer.php'; ?>
