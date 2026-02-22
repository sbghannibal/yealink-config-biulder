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
        }
    </style>

    <div style="margin-bottom:20px;">
        <h2 style="margin:0;">📱 Devices</h2>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="filter-controls">
        <form method="get" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; flex: 1;">
            <input type="text" name="search_customer" placeholder="🔍 Zoek op klant naam of code..." 
                   value="<?php echo htmlspecialchars($search_customer); ?>" 
                   aria-label="Zoek klanten">
            <select name="filter_type" aria-label="Filter op model">
                <option value="">Alle modellen</option>
                <?php foreach ($device_types as $type): ?>
                    <option value="<?php echo (int)$type['id']; ?>" <?php echo $filter_type == $type['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['type_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
            <button type="submit" class="btn" style="background: #007bff; color: white; height: 42px; padding: 10px 16px; display: inline-flex; align-items: center;">Zoeken</button>
            <a class="btn" href="/devices/create.php" style="background: #28a745; color: white; height: 42px; padding: 10px 16px; display: inline-flex; align-items: center;">➕ Nieuw Device</a>
            <?php if ($search_customer || $filter_type): ?>
                <a href="/devices/list.php?per_page=<?php echo $per_page; ?>" class="clear-filters-btn">
                    ❌ Wis filters
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <?php if ($total_count === 0): ?>
            <div style="padding: 40px; text-align: center;">
                <p style="color: #6c757d; font-size: 16px;">
                    <?php if ($search_customer || $filter_type): ?>
                        Geen devices gevonden met de geselecteerde filters.
                        <a href="/devices/list.php" style="color: #007bff; text-decoration: none;">Wis filters →</a>
                    <?php else: ?>
                        Geen devices gevonden. 
                        <a href="/devices/create.php" style="color: #007bff; text-decoration: none;">Maak er een aan →</a>
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Naam</th>
                        <th>Klant</th>
                        <th>Model</th>
                        <th>MAC Adres</th>
                        <th>Laatste Config</th>
                        <th>Downloads</th>
                        <th>Status</th>
                        <th>Acties</th>
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
                                    <span class="badge warning">⚠️ Geen config</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: #6c757d;">
                                    <?php echo (int)($d['download_count'] ?? 0); ?>x
                                </span>
                            </td>
                            <td>
                                <?php if ($d['is_active']): ?>
                                    <span class="badge success">✓ Actief</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #dc3545;">✗ Inactief</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a class="btn" href="/devices/configure_wizard.php?device_id=<?php echo (int)$d['id']; ?>" style="background: #28a745; color: white;">🔧 Initialiseren</a>
                                    
                                    <?php if ($d['config_version_id']): ?>
                                        <a class="btn" href="/settings/builder.php?device_id=<?php echo (int)$d['id']; ?>" style="background: #17a2b8; color: white;">⚙️ Config Bewerken</a>
                                    <?php else: ?>
                                        <button class="btn" disabled title="Initialiseer eerst een config" style="background: #6c757d; color: white; cursor: not-allowed; opacity: 0.6;">⚙️ Config Bewerken</button>
                                    <?php endif; ?>
                                    
                                    <a class="btn" href="/devices/edit.php?id=<?php echo (int)$d['id']; ?>" style="background: #007bff; color: white;">✏️ Telefoon Bewerken</a>
                                    
                                    <?php if ($d['config_version_id']): ?>
                                        <a href="/download_device_config.php?device_id=<?php echo (int)$d['id']; ?>&mac=<?php echo urlencode($d['mac_address']); ?>" 
                                           class="btn" 
                                           title="Download config voor <?php echo htmlspecialchars($d['device_name']); ?>"
                                           style="background: #6c757d; color: white;">
                                            📥 Download
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a class="btn" href="/devices/delete.php?id=<?php echo (int)$d['id']; ?>" onclick="return confirm('Weet je zeker dat je dit device wilt verwijderen?');" style="background: #dc3545; color: white;">🗑️ Verwijderen</a>
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
            Toont <?php echo (($page - 1) * $per_page) + 1; ?> tot <?php echo min($page * $per_page, $total_count); ?> van <?php echo $total_count; ?> devices
        </div>
        
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <div class="per-page-selector">
                <span>Per pagina:</span>
                <select onchange="window.location.href='?page=1&per_page=' + this.value + '<?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>'">
                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                        <option value="<?php echo $pp; ?>" <?php echo $pp == $per_page ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <a href="?page=1&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>">« Eerste</a>
                    <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>">‹ Vorige</a>
                <?php else: ?>
                    <a class="disabled">« Eerste</a>
                    <a class="disabled">‹ Vorige</a>
                <?php endif; ?>
                
                <span>Pagina <?php echo $page; ?> van <?php echo $total_pages; ?></span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>">Volgende ›</a>
                    <a href="?page=<?php echo $total_pages; ?>&per_page=<?php echo $per_page; ?><?php echo $search_customer ? '&search_customer=' . urlencode($search_customer) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?>">Laatste »</a>
                <?php else: ?>
                    <a class="disabled">Volgende ›</a>
                    <a class="disabled">Laatste »</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>


<?php require_once __DIR__ . '/../admin/_footer.php'; ?>
