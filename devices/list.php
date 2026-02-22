<?php
$page_title = 'Devices';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

$devices = [];
$device_types = [];
$error = '';

// Pagination parameters
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$per_page = in_array($per_page, [10, 25, 50, 100]) ? $per_page : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $per_page;

// Search and filters
$search_customer = $_GET['search_customer'] ?? '';
$filter_type = isset($_GET['filter_type']) && $_GET['filter_type'] !== '' ? (int)$_GET['filter_type'] : null;

$total_count = 0;

try {
    // Count total devices matching filters
    $count_sql = "SELECT COUNT(*) as total
        FROM devices d
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE d.deleted_at IS NULL";
    
    $count_params = [];
    
    if ($search_customer) {
        $count_sql .= " AND (c.company_name LIKE ? OR c.customer_code LIKE ?)";
        $search_param = '%' . $search_customer . '%';
        $count_params[] = $search_param;
        $count_params[] = $search_param;
    }
    if ($filter_type) {
        $count_sql .= " AND d.device_type_id = ?";
        $count_params[] = $filter_type;
    }
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Fetch devices with pagination
    $sql = "SELECT d.id, d.device_name, d.mac_address, d.description, d.is_active, 
               d.created_at, d.updated_at, dt.type_name AS model_name,
               c.customer_code, c.company_name,
               (SELECT cv.version_number FROM config_versions cv 
                JOIN device_config_assignments dca ON dca.config_version_id = cv.id 
                WHERE dca.device_id = d.id 
                ORDER BY cv.id DESC LIMIT 1) as latest_version,
               (SELECT dca.config_version_id FROM device_config_assignments dca 
                WHERE dca.device_id = d.id 
                ORDER BY dca.assigned_at DESC LIMIT 1) as config_version_id,
               (SELECT COUNT(*) FROM provision_logs pl 
                WHERE pl.device_id = d.id) as download_count
        FROM devices d 
        LEFT JOIN device_types dt ON d.device_type_id = dt.id
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE d.deleted_at IS NULL";
    
    $params = [];
    
    if ($search_customer) {
        $sql .= " AND (c.company_name LIKE ? OR c.customer_code LIKE ?)";
        $search_param = '%' . $search_customer . '%';
        $params[] = $search_param;
        $params[] = $search_param;
    }
    if ($filter_type) {
        $sql .= " AND d.device_type_id = ?";
        $params[] = $filter_type;
    }
    
    $sql .= " ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
    $params[] = (int)$per_page;
    $params[] = (int)$offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch distinct device types for filter dropdown
    $types_stmt = $pdo->prepare('
        SELECT DISTINCT dt.id, dt.type_name 
        FROM device_types dt
        INNER JOIN devices d ON d.device_type_id = dt.id
        ORDER BY dt.type_name ASC
    ');
    $types_stmt->execute();
    $device_types = $types_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to fetch devices: ' . $e->getMessage());
    $error = 'Kon devices niet ophalen: ' . $e->getMessage();
}

// Calculate pagination
$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;
$page = min($page, $total_pages);

require_once __DIR__ . '/../admin/_header.php';
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
        
        .btn {
            text-decoration: none !important;
        }
        
        .btn:hover {
            text-decoration: none !important;
        }
        
        /* Dropdown button styles */
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-primary {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            white-space: nowrap;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: #218838;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Dropdown wrapper */
        .dropdown-wrapper {
            position: relative;
            display: inline-block;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            min-width: 180px;
            z-index: 1000;
            margin-top: 4px;
        }

        .dropdown-wrapper:hover .dropdown-menu,
        .dropdown-wrapper:focus-within .dropdown-menu {
            display: block;
        }

        .dropdown-item {
            display: block;
            padding: 8px 12px;
            color: #212529;
            text-decoration: none;
            font-size: 13px;
            white-space: nowrap;
            transition: background 0.2s;
        }

        .dropdown-item:hover {
            background: #f8f9fa;
        }

        .dropdown-item.disabled {
            color: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .dropdown-item.disabled:hover {
            background: transparent;
        }

        .dropdown-item-danger {
            color: #dc3545;
        }

        .dropdown-item-danger:hover {
            background: #f8d7da;
        }

        .dropdown-divider {
            height: 1px;
            background: #dee2e6;
            margin: 4px 0;
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
            background: #6C2483; /* Proximus purple */
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #5a1d6e;
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
        
        .filter-controls {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .filter-controls input[type="text"],
        .filter-controls select {
            padding: 10px 14px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .filter-controls input[type="text"] {
            flex: 1;
            min-width: 250px;
            max-width: 400px;
        }
        
        .filter-controls input[type="text"]:focus,
        .filter-controls select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        
        .filter-controls select {
            min-width: 180px;
        }
        
        .clear-filters-btn {
            padding: 10px 16px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .clear-filters-btn:hover {
            background: #5a6268;
        }
        
        .no-results {
            padding: 40px;
            text-align: center;
            color: #6c757d;
            font-size: 16px;
        }
        
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 14px;
        }
        
        .pagination-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .pagination-controls a,
        .pagination-controls span {
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
            background: white;
            font-size: 14px;
        }
        
        .pagination-controls span {
            background: #007bff;
            color: white;
            font-weight: 600;
        }
        
        .pagination-controls a:hover {
            background: #f1f3f5;
        }
        
        .pagination-controls a.disabled {
            color: #6c757d;
            pointer-events: none;
            opacity: 0.5;
        }
        
        .per-page-selector {
            display: flex;
            gap: 6px;
            align-items: center;
            font-size: 14px;
        }
        
        .per-page-selector select {
            padding: 6px 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        
        @media (max-width: 768px) {
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-controls input[type="text"],
            .filter-controls select {
                width: 100%;
                max-width: none;
            }
            
            .pagination {
                flex-direction: column;
            }
            
            .pagination-controls {
                flex-wrap: wrap;
                justify-content: center;
            }

            .dropdown-menu {
                right: auto;
                left: 0;
            }
        }
    </style>

    <div style="margin-bottom:20px;">
        <h2 style="margin:0;">📱 <?php echo __('nav.devices'); ?></h2>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="filter-controls">
        <form method="get" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; flex: 1;">
            <input type="text" name="search_customer" placeholder="<?php echo htmlspecialchars(__('devices.search.placeholder')); ?>" 
                   value="<?php echo htmlspecialchars($search_customer); ?>" 
                   aria-label="<?php echo htmlspecialchars(__('devices.search.placeholder')); ?>">
            <select name="filter_type" aria-label="<?php echo htmlspecialchars(__('devices.filter.all_models')); ?>">
                <option value=""><?php echo __('devices.filter.all_models'); ?></option>
                <?php foreach ($device_types as $type): ?>
                    <option value="<?php echo (int)$type['id']; ?>" <?php echo $filter_type == $type['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['type_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
            <button type="submit" class="btn" style="background: #007bff; color: white; height: 42px; padding: 10px 16px; display: inline-flex; align-items: center;"><?php echo __('button.search'); ?></button>
            <a class="btn" href="/devices/create.php" style="background: #28a745; color: white; height: 42px; padding: 10px 16px; display: inline-flex; align-items: center; text-decoration: none;"><?php echo __('devices.new'); ?></a>
            <?php if ($search_customer || $filter_type): ?>
                <a href="/devices/list.php?per_page=<?php echo $per_page; ?>" class="clear-filters-btn">
                    <?php echo __('devices.clear_filters'); ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <?php if ($total_count === 0): ?>
            <div style="padding: 40px; text-align: center;">
                <p style="color: #6c757d; font-size: 16px;">
                    <?php if ($search_customer || $filter_type): ?>
                        <?php echo __('devices.no_results_filtered'); ?>
                        <a href="/devices/list.php" style="color: #007bff; text-decoration: none;"><?php echo __('devices.no_results_clear'); ?></a>
                    <?php else: ?>
                        <?php echo __('devices.no_results'); ?>
                        <a href="/devices/create.php" style="color: #007bff; text-decoration: none;"><?php echo __('devices.create_first'); ?></a>
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th><?php echo __('table.id'); ?></th>
                        <th><?php echo __('table.name'); ?></th>
                        <th><?php echo __('table.customer'); ?></th>
                        <th><?php echo __('table.model'); ?></th>
                        <th><?php echo __('table.mac_address'); ?></th>
                        <th><?php echo __('table.latest_config'); ?></th>
                        <th><?php echo __('table.downloads'); ?></th>
                        <th><?php echo __('table.status'); ?></th>
                        <th><?php echo __('table.actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="devicesTableBody">
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td><strong>#<?php echo (int)$d['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($d['device_name']); ?></td>
                            <td>
                                <?php if ($d['company_name']): ?>
                                    <span style="font-weight: 500;"><?php echo htmlspecialchars($d['company_name']); ?></span>
                                    <?php if ($d['customer_code']): ?>
                                        <br><small style="color: #6c757d;"><?php echo htmlspecialchars($d['customer_code']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #6c757d;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d['model_name']): ?>
                                    <span class="badge info"><?php echo htmlspecialchars($d['model_name']); ?></span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="background: #f1f3f5; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($d['mac_address'] ?? '-'); ?></code></td>
                            <td>
                                <?php if ($d['latest_version']): ?>
                                    <span class="badge success">v<?php echo (int)$d['latest_version']; ?></span>
                                <?php else: ?>
                                    <span class="badge warning"><?php echo __('devices.status.no_config'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: #6c757d;">
                                    <?php echo (int)($d['download_count'] ?? 0); ?>x
                                </span>
                            </td>
                            <td>
                                <?php if ($d['is_active']): ?>
                                    <span class="badge success">✓ <?php echo __('status.active'); ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background: #dc3545;">✗ <?php echo __('status.inactive'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <!-- Primary action: Always visible -->
                                    <a class="btn btn-primary" 
                                       href="/devices/configure_wizard.php?device_id=<?php echo (int)$d['id']; ?>">
                                        <?php echo __('devices.action.initialize'); ?>
                                    </a>

                                    <!-- Dropdown for secondary actions -->
                                    <div class="dropdown-wrapper">
                                        <button class="btn btn-secondary dropdown-toggle" type="button">
                                            <?php echo __('devices.action.more'); ?>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php if ($d['config_version_id']): ?>
                                                <a class="dropdown-item" 
                                                   href="/settings/builder.php?device_id=<?php echo (int)$d['id']; ?>">
                                                    <?php echo __('devices.action.edit_config'); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="dropdown-item disabled" 
                                                      title="<?php echo htmlspecialchars(__('devices.tooltip.edit_config_disabled')); ?>">
                                                    <?php echo __('devices.action.edit_config'); ?>
                                                </span>
                                            <?php endif; ?>

                                            <a class="dropdown-item" 
                                               href="/devices/edit.php?id=<?php echo (int)$d['id']; ?>">
                                                <?php echo __('devices.action.edit_phone'); ?>
                                            </a>

                                            <?php if ($d['config_version_id']): ?>
                                                <a class="dropdown-item" 
                                                   href="/download_device_config.php?device_id=<?php echo (int)$d['id']; ?>&mac=<?php echo urlencode($d['mac_address']); ?>"
                                                   title="<?php echo htmlspecialchars(str_replace('{device_name}', $d['device_name'], __('devices.tooltip.download'))); ?>">
                                                    <?php echo __('devices.action.download'); ?>
                                                </a>
                                            <?php endif; ?>

                                            <div class="dropdown-divider"></div>

                                            <a class="dropdown-item dropdown-item-danger" 
                                               href="/devices/delete.php?id=<?php echo (int)$d['id']; ?>"
                                               onclick="return confirm('<?php echo htmlspecialchars(__('devices.confirm.delete')); ?>');">
                                                <?php echo __('devices.action.delete'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($total_count > 0): ?>
    <div class="pagination">
        <div class="pagination-info">
            <?php echo __('pagination.showing', [
                'from' => (($page - 1) * $per_page) + 1,
                'to' => min($page * $per_page, $total_count),
                'total' => $total_count
            ]); ?>
        </div>
        
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <div class="per-page-selector">
                <span><?php echo __('pagination.per_page'); ?></span>
                <select onchange="window.location.href='?page=1&per_page=' + this.value + '<?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>'">
                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                        <option value="<?php echo $pp; ?>" <?php echo $pp == $per_page ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <a href="?page=1&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>"><?php echo __('pagination.first'); ?></a>
                    <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>"><?php echo __('pagination.prev'); ?></a>
                <?php else: ?>
                    <a class="disabled"><?php echo __('pagination.first'); ?></a>
                    <a class="disabled"><?php echo __('pagination.prev'); ?></a>
                <?php endif; ?>
                
                <span><?php echo __('pagination.page_of', ['page' => $page, 'total' => $total_pages]); ?></span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>"><?php echo __('pagination.next'); ?></a>
                    <a href="?page=<?php echo $total_pages; ?>&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>"><?php echo __('pagination.last'); ?></a>
                <?php else: ?>
                    <a class="disabled"><?php echo __('pagination.next'); ?></a>
                    <a class="disabled"><?php echo __('pagination.last'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>


<?php require_once __DIR__ . '/../admin/_footer.php'; ?>
