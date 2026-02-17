<?php
/**
 * Reusable header with dynamic navigation based on permissions
 */

// Ensure session and dependencies are loaded
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];
$current_page = basename($_SERVER['PHP_SELF']);

// Check user permissions for navigation
$can_view_devices = has_permission($pdo, $admin_id, 'devices.view');
$can_view_device_types = has_permission($pdo, $admin_id, 'admin.device_types.manage');
$can_use_wizard = has_permission($pdo, $admin_id, 'config.manage');
$can_manage_templates = has_permission($pdo, $admin_id, 'admin.templates.manage');
$can_manage_customers = has_permission($pdo, $admin_id, 'customers.view');
$can_view_accounts = has_permission($pdo, $admin_id, 'admin.accounts.manage');
$can_manage_users = has_permission($pdo, $admin_id, 'admin.users.view');
$can_edit_settings = has_permission($pdo, $admin_id, 'admin.settings.edit');

// Get current admin info including status and roles
$stmt = $pdo->prepare('SELECT username, email, is_active FROM admins WHERE id = ?');
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's role(s) for badge
$stmt = $pdo->prepare('
    SELECT r.role_name 
    FROM admin_roles ar
    JOIN roles r ON r.id = ar.role_id
    WHERE ar.admin_id = ?
');
$stmt->execute([$admin_id]);
$user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
$user_role_display = !empty($user_roles) ? implode(', ', $user_roles) : 'Geen rol';

// Determine role badge color
$role_badge_color = '#6c757d'; // default gray
if (in_array('Owner', $user_roles)) {
    $role_badge_color = '#dc3545'; // red for owner
} elseif (in_array('Expert', $user_roles)) {
    $role_badge_color = '#28a745'; // green for expert
} elseif (in_array('Tech', $user_roles)) {
    $role_badge_color = '#007bff'; // blue for tech
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?>Yealink Config Builder</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
        }
        
        .badge-common {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-left: 4px;
        }
        
        .badge-active {
            background: #28a745;
        }
        
        .badge-inactive {
            background: #dc3545;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .header-brand:hover {
            opacity: 0.9;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .header-user strong {
            display: block;
        }
        
        .header-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .header-logout:hover {
            background: rgba(255,255,255,0.3);
        }
        
        nav {
            display: flex;
            flex-wrap: wrap;
            padding: 0 24px;
        }
        
        nav a, nav button {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            padding: 12px 16px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            border-bottom: 2px solid transparent;
        }
        
        nav a:hover, nav button:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        nav a.active {
            color: white;
            border-bottom-color: #4CAF50;
            background: rgba(255,255,255,0.05);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #5568d3;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
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
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #ddd;
        }
        
        table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        table tr:hover {
            background: #fafafa;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
        }
        
        .form-group textarea {
            min-height: 200px;
            font-family: 'Courier New', monospace;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        @media (max-width: 768px) {
            .header-top {
                flex-direction: column;
                gap: 12px;
                padding: 12px;
            }
            
            .header-right {
                width: 100%;
                justify-content: space-between;
            }
            
            nav {
                width: 100%;
                border-top: 1px solid rgba(255,255,255,0.1);
            }
            
            nav a, nav button {
                padding: 10px 12px;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="header-top">
        <a href="/admin/dashboard.php" class="header-brand">
            ☎️ Yealink Config Builder
        </a>
        <div class="header-right">
            <div class="header-user">
                <div>
                    <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                    <!-- Status badge -->
                    <?php if ($admin['is_active']): ?>
                        <span class="badge badge-common badge-active">✅ Active</span>
                    <?php else: ?>
                        <span class="badge badge-common badge-inactive">⏸️ Inactive</span>
                    <?php endif; ?>
                    <!-- Role badge -->
                    <span class="badge badge-common" style="background: <?php echo $role_badge_color; ?>;">
                        <?php echo htmlspecialchars($user_role_display); ?>
                    </span>
                    <small><?php echo htmlspecialchars($admin['email']); ?></small>
                </div>
            </div>
            <form method="POST" action="/logout.php" style="display: inline;">
                <button type="submit" class="header-logout">Logout</button>
            </form>
        </div>
    </div>
    
    <nav>
        <a href="/admin/dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            📊 Dashboard
        </a>
        
        <?php if ($can_view_devices): ?>
        <a href="/devices/list.php" class="<?php echo $current_page === 'list.php' ? 'active' : ''; ?>">
            📱 Devices
        </a>
        <?php endif; ?>
        
        <?php if ($can_view_device_types): ?>
        <a href="/admin/device_types.php" class="<?php echo in_array($current_page, ['device_types.php', 'device_types_edit.php']) ? 'active' : ''; ?>">
            📦 Device Types
        </a>
        <?php endif; ?>
        
        <?php if ($can_use_wizard): ?>
        <a href="/devices/configure_wizard.php" class="<?php echo $current_page === 'configure_wizard.php' ? 'active' : ''; ?>">
            ⚙️ Config Wizard
        </a>
        <?php endif; ?>
        
        <?php if ($can_manage_templates): ?>
        <a href="/admin/templates.php" class="<?php echo $current_page === 'templates.php' ? 'active' : ''; ?>">
            📋 Templates
        </a>
        <?php endif; ?>
        
        <?php if ($can_manage_customers): ?>
        <a href="/admin/customers.php" class="<?php echo in_array($current_page, ['customers.php', 'customers_add.php', 'customers_edit.php', 'customers_delete.php']) ? 'active' : ''; ?>">
            🏢 Klanten
        </a>
        <?php endif; ?>
        
        <?php if ($can_view_accounts): ?>
        <a href="/admin/approve_account.php" class="<?php echo $current_page === 'approve_account.php' ? 'active' : ''; ?>">
            📧 Account Verzoeken
        </a>
        <?php endif; ?>
        
        <?php if ($can_manage_users): ?>
        <a href="/admin/users.php" class="<?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
            👥 Users
        </a>
        <?php endif; ?>
        
        <?php if ($can_edit_settings): ?>
        <a href="/admin/settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
            ⚙️ Settings
        </a>
        <?php endif; ?>
    </nav>
</header>

<main class="container">
