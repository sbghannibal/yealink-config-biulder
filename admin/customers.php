<?php
$page_title = 'Klanten';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

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

$customers = [];
$error = '';

try {
    $stmt = $pdo->query('
        SELECT c.*, 
               (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id AND d.deleted_at IS NULL) as device_count
        FROM customers c
        WHERE c.deleted_at IS NULL
        ORDER BY c.company_name ASC
    ');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to fetch customers: ' . $e->getMessage());
    $error = 'Kon klanten niet ophalen: ' . $e->getMessage();
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
        background: #f1f3f5;
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
    <h2 style="margin:0;">👥 Klanten</h2>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">
        ✅ Klant succesvol aangemaakt!
        
        <div style="margin-top:12px; display:flex; gap:8px;">
            <a class="btn" href="/admin/customers_add.php" style="background: #28a745; color: white; font-size:13px; padding:8px 12px;">➕ Nog een Klant</a>
            <a class="btn" href="/devices/create.php?customer_id=<?php echo (int)$_GET['created']; ?>" style="background: #007bff; color: white; font-size:13px; padding:8px 12px;">📱 Device Toevoegen</a>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Klant succesvol bijgewerkt!</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Klant succesvol verwijderd!</div>
<?php endif; ?>

<?php if (!isset($_GET['created'])): ?>
    <div style="margin-bottom:16px;">
        <a class="btn" href="/admin/customers_add.php" style="background: #28a745; color: white;">➕ Nieuwe Klant</a>
    </div>
<?php endif; ?>

<div class="card">
    <?php if (empty($customers)): ?>
        <div style="padding: 40px; text-align: center;">
            <p style="color: #6c757d; font-size: 16px;">
                Geen klanten gevonden. 
                <a href="/admin/customers_add.php" style="color: #007bff; text-decoration: none;">Maak er een aan →</a>
            </p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Klantcode</th>
                    <th>Bedrijfsnaam</th>
                    <th>Contactpersoon</th>
                    <th>Email</th>
                    <th>Telefoon</th>
                    <th>Devices</th>
                    <th>Status</th>
                    <th>Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><strong>#<?php echo (int)$c['id']; ?></strong></td>
                        <td><code style="background: #f1f3f5; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($c['customer_code']); ?></code></td>
                        <td><?php echo htmlspecialchars($c['company_name']); ?></td>
                        <td><?php echo htmlspecialchars($c['contact_person'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['email'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['phone'] ?? '-'); ?></td>
                        <td>
                            <span class="badge info"><?php echo (int)$c['device_count']; ?> device(s)</span>
                        </td>
                        <td>
                            <?php if ($c['is_active']): ?>
                                <span class="badge success">✓ Actief</span>
                            <?php else: ?>
                                <span class="badge" style="background: #dc3545;">✗ Inactief</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a class="btn" href="/admin/customers_edit.php?id=<?php echo (int)$c['id']; ?>" style="background: #007bff; color: white;">✏️ Bewerken</a>
                                <a class="btn" href="/admin/customers_delete.php?id=<?php echo (int)$c['id']; ?>" onclick="return confirm('Weet je zeker dat je deze klant wilt verwijderen?');" style="background: #dc3545; color: white;">🗑️ Verwijderen</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
