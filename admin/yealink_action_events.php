<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.yealink_action_events.title');

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

// Reuse an existing permission you already have in the portal
if (!has_permission($pdo, $admin_id, 'devices.manage')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Cleanup: delete events older than 3 days (runs on page load)
try {
    $pdo->exec("DELETE FROM yealink_action_events WHERE received_at < (NOW() - INTERVAL 3 DAY)");
} catch (Exception $e) {
    error_log('yealink_action_events cleanup error: ' . $e->getMessage());
}

$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$per_page = in_array($per_page, [10, 25, 50, 100], true) ? $per_page : 25;

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $per_page;

$event = isset($_GET['event']) ? trim((string)$_GET['event']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$params = [];
$where = '1=1';

if ($event !== '') {
    $where .= ' AND event = ?';
    $params[] = $event;
}
if ($search !== '') {
    $where .= ' AND (mac LIKE ? OR ip LIKE ? OR fw LIKE ? OR source_ip LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM yealink_action_events WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, received_at, event, mac, ip, fw, source_ip
        FROM yealink_action_events
        WHERE {$where}
        ORDER BY id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('yealink_action_events admin page error: ' . $e->getMessage());
    $rows = [];
    $total = 0;
}

$total_pages = max(1, (int)ceil($total / $per_page));

require_once __DIR__ . '/_header.php';
?>

<div style="max-width: 1200px; margin: 20px auto;">
  <h2><?php echo __('page.yealink_action_events.heading'); ?></h2>

  <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; margin: 12px 0;">
    <label>
      <?php echo __('form.event'); ?>
      <select name="event">
        <option value=""><?php echo __('form.all'); ?></option>
        <option value="ip_change" <?php echo $event==='ip_change'?'selected':''; ?>>ip_change</option>
        <option value="register_failed" <?php echo $event==='register_failed'?'selected':''; ?>>register_failed</option>
        <option value="	Startup" <?php echo $event==='	Startup'?'selected':''; ?>>	Startup</option>
      </select>
    </label>

    <label>
      <?php echo __('form.search'); ?>
      <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="MAC/IP/FW..." />
    </label>

    <label>
      <?php echo __('form.per_page'); ?>
      <select name="per_page">
        <?php foreach ([10,25,50,100] as $n): ?>
          <option value="<?php echo $n; ?>" <?php echo $per_page===$n?'selected':''; ?>><?php echo $n; ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit"><?php echo __('button.filter'); ?></button>
  </form>

  <table style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">ID</th>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;"><?php echo __('table.received_at'); ?></th>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;"><?php echo __('table.event'); ?></th>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;"><?php echo __('table.mac'); ?></th>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;"><?php echo __('table.ip'); ?></th>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;"><?php echo __('table.firmware'); ?></th>
        <th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;"><?php echo __('table.source_ip'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="padding:10px;"><?php echo __('info.no_results'); ?></td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="border-bottom:1px solid #eee; padding:8px;"><?php echo (int)$r['id']; ?></td>
            <td style="border-bottom:1px solid #eee; padding:8px;"><?php echo htmlspecialchars((string)$r['received_at']); ?></td>
            <td style="border-bottom:1px solid #eee; padding:8px;"><?php echo htmlspecialchars((string)$r['event']); ?></td>

            <td style="border-bottom:1px solid #eee; padding:8px;">
              <?php
                $rawMac = (string)($r['mac'] ?? '');
                if ($rawMac === '') {
                    echo '-';
                } else {
                    // keep only hex chars
                    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $rawMac));

                    // If it's exactly 12 hex chars, format as XX:XX:XX:XX:XX:XX
                    if (strlen($hex) === 12) {
                        $formatted = implode(':', str_split($hex, 2));
                    } else {
                        // fallback: show original, but still link with original
                        $formatted = $rawMac;
                    }

                    $url = '/devices/list.php?search_customer=' . urlencode($formatted);
                    echo '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($formatted) . '</a>';
                }
              ?>
            </td>

            <td style="border-bottom:1px solid #eee; padding:8px;"><?php echo htmlspecialchars((string)($r['ip'] ?? '')); ?></td>
            <td style="border-bottom:1px solid #eee; padding:8px;"><?php echo htmlspecialchars((string)($r['fw'] ?? '')); ?></td>
            <td style="border-bottom:1px solid #eee; padding:8px;"><?php echo htmlspecialchars((string)($r['source_ip'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top: 12px;">
    <?php for ($i=1; $i<=$total_pages; $i++): ?>
      <?php if ($i === $page): ?>
        <strong style="margin-right:6px;"><?php echo $i; ?></strong>
      <?php else: ?>
        <a style="margin-right:6px;" href="?page=<?php echo $i; ?>&per_page=<?php echo (int)$per_page; ?>&event=<?php echo urlencode($event); ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>