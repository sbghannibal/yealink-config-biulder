<?php
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/i18n.php';

$page_title = __('page.copy_template_variables.title');

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Permission check
$permissions = get_admin_permissions($pdo, $admin_id);
$permission_map = array_flip($permissions);
if (!isset($permission_map['admin.templates.manage'])) {
    http_response_code(403);
    echo __('error.no_permission');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$error   = '';
$success = '';

// Detect copyable columns in template_variables dynamically
function get_copy_columns(PDO $pdo): array
{
    $exclude = ['id', 'created_at', 'updated_at'];
    $stmt = $pdo->query("SHOW COLUMNS FROM template_variables");
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if (!in_array($col['Field'], $exclude, true)) {
            $cols[] = $col['Field'];
        }
    }
    return $cols;
}

// Load all templates with variable counts
function load_templates(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT
            ct.id,
            ct.template_name AS name,
            dt.type_name     AS device_type_name,
            (SELECT COUNT(*) FROM template_variables WHERE template_id = ct.id) AS var_count
        FROM config_templates ct
        LEFT JOIN device_types dt ON ct.device_type_id = dt.id
        ORDER BY ct.template_name
    ');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $source_id  = !empty($_POST['source_id']) ? (int) $_POST['source_id'] : null;
        $target_ids = !empty($_POST['target_ids']) ? array_map('intval', (array) $_POST['target_ids']) : [];
        $overwrite  = !empty($_POST['overwrite']);

        if (!$source_id) {
            $error = __('page.copy_template_variables.err_no_source');
        } elseif (empty($target_ids)) {
            $error = __('page.copy_template_variables.err_no_target');
        } else {
            // Remove source from targets if accidentally included
            $target_ids = array_values(array_filter($target_ids, fn ($id) => $id !== $source_id));
            if (empty($target_ids)) {
                $error = __('page.copy_template_variables.err_same');
            }
        }

        if (!$error) {
            try {
                $copy_cols = get_copy_columns($pdo);

                // Load source variables
                $stmt = $pdo->prepare('SELECT * FROM template_variables WHERE template_id = ? ORDER BY display_order, id');
                $stmt->execute([$source_id]);
                $source_vars = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($source_vars)) {
                    $error = __('page.copy_template_variables.no_source_vars');
                } else {
                    $total_added   = 0;
                    $total_skipped = 0;
                    $targets_done  = 0;

                    $pdo->beginTransaction();

                    foreach ($target_ids as $target_id) {
                        // Map old variable id -> new variable id (for parent_variable_id)
                        $id_map = [];

                        // First pass: insert all variables (without parent_variable_id)
                        foreach ($source_vars as $var) {
                            // Check for duplicate by var_name in target
                            $dup_stmt = $pdo->prepare('SELECT id FROM template_variables WHERE template_id = ? AND var_name = ?');
                            $dup_stmt->execute([$target_id, $var['var_name']]);
                            $existing = $dup_stmt->fetchColumn();

                            if ($existing && !$overwrite) {
                                $id_map[$var['id']] = $existing;
                                $total_skipped++;
                                continue;
                            }

                            // Build insert/update values excluding id, parent_variable_id, template_id
                            $insert_cols = array_filter($copy_cols, fn ($c) => $c !== 'parent_variable_id' && $c !== 'template_id');
                            $insert_cols = array_values($insert_cols);

                            $values = [];
                            foreach ($insert_cols as $col) {
                                $values[] = $var[$col] ?? null;
                            }

                            if ($existing && $overwrite) {
                                // UPDATE
                                $set_parts = array_map(fn ($c) => "`$c` = ?", $insert_cols);
                                $upd = $pdo->prepare(
                                    'UPDATE template_variables SET ' . implode(', ', $set_parts) .
                                    ' WHERE id = ?'
                                );
                                $upd->execute(array_merge($values, [$existing]));
                                $id_map[$var['id']] = $existing;
                                $total_added++;
                            } else {
                                // INSERT
                                $col_list = array_merge(['template_id'], $insert_cols);
                                $placeholders = implode(', ', array_fill(0, count($col_list), '?'));
                                $col_names    = implode(', ', array_map(fn ($c) => "`$c`", $col_list));
                                $ins = $pdo->prepare(
                                    "INSERT INTO template_variables ($col_names) VALUES ($placeholders)"
                                );
                                $ins->execute(array_merge([$target_id], $values));
                                $new_id = (int) $pdo->lastInsertId();
                                $id_map[$var['id']] = $new_id;
                                $total_added++;
                            }
                        }

                        // Second pass: fix parent_variable_id if column exists
                        if (in_array('parent_variable_id', $copy_cols, true)) {
                            foreach ($source_vars as $var) {
                                if (!empty($var['parent_variable_id']) && isset($id_map[$var['id']], $id_map[$var['parent_variable_id']])) {
                                    $upd = $pdo->prepare(
                                        'UPDATE template_variables SET parent_variable_id = ? WHERE id = ? AND template_id = ?'
                                    );
                                    $upd->execute([$id_map[$var['parent_variable_id']], $id_map[$var['id']], $target_id]);
                                }
                            }
                        }

                        $targets_done++;
                    }

                    $pdo->commit();
                    $success = sprintf(
                        __('page.copy_template_variables.success'),
                        $targets_done,
                        $total_added,
                        $total_skipped
                    );
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('copy_template_variables error: ' . $e->getMessage());
                $error = __('error.general') ?: 'An error occurred while copying variables.';
            }
        }
    }
}

try {
    $templates = load_templates($pdo);
} catch (Exception $e) {
    error_log('copy_template_variables load error: ' . $e->getMessage());
    $templates = [];
}

$selected_source  = !empty($_POST['source_id']) ? (int) $_POST['source_id'] : null;
$selected_targets = !empty($_POST['target_ids']) ? array_map('intval', (array) $_POST['target_ids']) : [];

// Build device type list for filters
$device_types = [];
foreach ($templates as $tpl) {
    $dt = trim((string)($tpl['device_type_name'] ?? ''));
    if ($dt !== '') {
        $device_types[$dt] = true;
    }
}
$device_types = array_keys($device_types);
sort($device_types, SORT_NATURAL | SORT_FLAG_CASE);

require_once __DIR__ . '/_header.php';
?>

<style>
.copy-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
.template-list {
    max-height: 420px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fafafa;
}
.template-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.15s;
    user-select: none;
}
.template-item:last-child { border-bottom: none; }
.template-item:hover { background: #f0f0ff; }
.template-item input[type="radio"],
.template-item input[type="checkbox"] { cursor: pointer; flex-shrink: 0; }
.template-item label { cursor: pointer; flex: 1; margin: 0; min-width: 0; }
.template-item label span:first-child { white-space: normal; word-break: break-word; }
.var-badge {
    background: #6c757d;
    color: #fff;
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 10px;
    white-space: nowrap;
}
.var-badge.has-vars { background: #28a745; }
.device-type-label { font-size: 11px; color: #999; }
.overwrite-row { display: flex; align-items: center; gap: 8px; margin-top: 16px; }

.filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 12px; }
.filter-bar input[type="search"]{
    flex: 1;
    min-width: 180px;
    padding: 8px 10px;
    border:1px solid #ddd;
    border-radius:4px;
}
.filter-bar select{
    min-width: 180px;
    padding: 8px 10px;
    border:1px solid #ddd;
    border-radius:4px;
    background:#fff;
}
.target-actions{ display:flex; gap:8px; flex-wrap:wrap; margin: 10px 0 0; }
.btn-small{ padding:6px 10px; font-size:12px; }
.btn-outline{ background:#fff; border:1px solid #6c757d; color:#333; }
.btn-outline:hover{ background:#f7f7f7; }
.muted{ color:#6c757d; font-size:12px; }

@media (max-width: 768px) {
    .copy-grid { grid-template-columns: 1fr; }
}
</style>

<h2>📋 <?php echo __('page.copy_template_variables.title'); ?></h2>

<p>
    <a href="/admin/templates.php" class="btn" style="background:#6c757d;">← <?php echo __('button.back'); ?></a>
</p>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if (empty($templates)): ?>
    <div class="card"><p style="color:#666;"><?php echo __('page.copy_template_variables.no_templates'); ?></p></div>
<?php else: ?>
<form method="POST" id="copyForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

    <div class="copy-grid">
        <!-- SOURCE -->
        <div class="card" id="sourceCard">
            <h3>📤 <?php echo __('page.copy_template_variables.source'); ?></h3>
            <p style="color:#666;font-size:13px;"><?php echo __('page.copy_template_variables.select_source'); ?></p>

            <div class="filter-bar">
                <input type="search" id="srcSearch" placeholder="Zoek sjabloon of toesteltype…" autocomplete="off">
                <select id="srcType">
                    <option value="">Alle toesteltypes</option>
                    <?php foreach ($device_types as $dt): ?>
                        <option value="<?php echo htmlspecialchars(strtolower($dt), ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($dt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="muted">Tip: bron moet variabelen hebben om te kunnen kopiëren.</div>

            <div class="template-list" id="srcList">
                <?php foreach ($templates as $tpl): ?>
                    <?php
                        $tpl_id = (int)($tpl['id'] ?? 0);
                        $tpl_name = (string)($tpl['name'] ?? '');
                        $tpl_dt = (string)($tpl['device_type_name'] ?? '');
                        $tpl_dt_l = strtolower(trim($tpl_dt));
                        $var_count = (int)($tpl['var_count'] ?? 0);
                        $disabled = ($var_count === 0);
                    ?>
                    <div class="template-item"
                         data-name="<?php echo htmlspecialchars(strtolower($tpl_name), ENT_QUOTES); ?>"
                         data-device-type="<?php echo htmlspecialchars($tpl_dt_l, ENT_QUOTES); ?>"
                         data-var-count="<?php echo $var_count; ?>"
                         onclick="document.getElementById('src_<?php echo $tpl_id; ?>').click()">
                        <input type="radio" name="source_id" id="src_<?php echo $tpl_id; ?>"
                               value="<?php echo $tpl_id; ?>"
                               <?php echo $selected_source === $tpl_id ? 'checked' : ''; ?>
                               <?php echo $disabled ? 'disabled' : ''; ?>>
                        <label for="src_<?php echo $tpl_id; ?>">
                            <span><?php echo htmlspecialchars($tpl_name); ?></span>
                            <?php if ($tpl_dt !== ''): ?>
                                <span class="device-type-label"> — <?php echo htmlspecialchars($tpl_dt); ?></span>
                            <?php endif; ?>
                        </label>
                        <span class="var-badge <?php echo $var_count > 0 ? 'has-vars' : ''; ?>">
                            <?php echo $var_count; ?> <?php echo __('page.copy_template_variables.vars'); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TARGET -->
        <div class="card" id="targetCard">
            <h3>📥 <?php echo __('page.copy_template_variables.target'); ?></h3>
            <p style="color:#666;font-size:13px;"><?php echo __('page.copy_template_variables.select_targets'); ?></p>

            <div class="filter-bar">
                <input type="search" id="tgtSearch" placeholder="Zoek sjabloon of toesteltype…" autocomplete="off">
                <select id="tgtType">
                    <option value="">Alle toesteltypes</option>
                    <?php foreach ($device_types as $dt): ?>
                        <option value="<?php echo htmlspecialchars(strtolower($dt), ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($dt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="target-actions">
                <button type="button" class="btn btn-small btn-outline" id="btnSelectAll">Selecteer alles</button>
                <button type="button" class="btn btn-small btn-outline" id="btnSelectNone">Deselecteer alles</button>
                <button type="button" class="btn btn-small btn-outline" id="btnSelectSameType">Selecteer zelfde toesteltype als bron</button>
            </div>

            <div class="template-list" id="tgtList">
                <?php foreach ($templates as $tpl): ?>
                    <?php
                        $tpl_id = (int)($tpl['id'] ?? 0);
                        $tpl_name = (string)($tpl['name'] ?? '');
                        $tpl_dt = (string)($tpl['device_type_name'] ?? '');
                        $tpl_dt_l = strtolower(trim($tpl_dt));
                        $var_count = (int)($tpl['var_count'] ?? 0);
                    ?>
                    <div class="template-item"
                         data-name="<?php echo htmlspecialchars(strtolower($tpl_name), ENT_QUOTES); ?>"
                         data-device-type="<?php echo htmlspecialchars($tpl_dt_l, ENT_QUOTES); ?>"
                         data-var-count="<?php echo $var_count; ?>"
                         onclick="document.getElementById('tgt_<?php echo $tpl_id; ?>').click()">
                        <input type="checkbox" name="target_ids[]" id="tgt_<?php echo $tpl_id; ?>"
                               value="<?php echo $tpl_id; ?>"
                               <?php echo in_array($tpl_id, $selected_targets, true) ? 'checked' : ''; ?>>
                        <label for="tgt_<?php echo $tpl_id; ?>">
                            <span><?php echo htmlspecialchars($tpl_name); ?></span>
                            <?php if ($tpl_dt !== ''): ?>
                                <span class="device-type-label"> — <?php echo htmlspecialchars($tpl_dt); ?></span>
                            <?php endif; ?>
                        </label>
                        <span class="var-badge <?php echo $var_count > 0 ? 'has-vars' : ''; ?>">
                            <?php echo $var_count; ?> <?php echo __('page.copy_template_variables.vars'); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="overwrite-row">
                <input type="checkbox" name="overwrite" id="overwrite" value="1"
                       <?php echo !empty($_POST['overwrite']) ? 'checked' : ''; ?>>
                <label for="overwrite"><?php echo __('page.copy_template_variables.overwrite'); ?></label>
            </div>
        </div>
    </div>

    <div style="margin-top: 20px;">
        <button type="submit" class="btn" id="btnCopy" onclick="return confirmCopy()" disabled>
            📋 <?php echo __('page.copy_template_variables.copy_btn'); ?>
        </button>
    </div>
</form>

<script>
(function(){
  const form = document.getElementById('copyForm');
  const btnCopy = document.getElementById('btnCopy');
  const overwrite = document.getElementById('overwrite');

  const srcSearch = document.getElementById('srcSearch');
  const srcType = document.getElementById('srcType');
  const tgtSearch = document.getElementById('tgtSearch');
  const tgtType = document.getElementById('tgtType');

  const btnSelectAll = document.getElementById('btnSelectAll');
  const btnSelectNone = document.getElementById('btnSelectNone');
  const btnSelectSameType = document.getElementById('btnSelectSameType');

  function selectedSourceId(){
    const el = form.querySelector('input[name=source_id]:checked');
    return el ? parseInt(el.value, 10) : null;
  }

  function selectedTargetIds(){
    return Array.from(form.querySelectorAll("input[name='target_ids[]']:checked")).map(x => parseInt(x.value, 10));
  }

  function selectedSourceDeviceType(){
    const srcId = selectedSourceId();
    if (!srcId) return '';
    const radio = document.getElementById('src_' + srcId);
    const row = radio ? radio.closest('.template-item') : null;
    return row ? (row.getAttribute('data-device-type') || '') : '';
  }

  function applyFilter(listEl, query, type){
    const q = (query || '').trim().toLowerCase();
    const t = (type || '').trim().toLowerCase();
    listEl.querySelectorAll('.template-item').forEach(row => {
      const name = row.getAttribute('data-name') || '';
      const dt = row.getAttribute('data-device-type') || '';
      const okQuery = !q || name.includes(q) || dt.includes(q);
      const okType = !t || dt === t;
      row.style.display = (okQuery && okType) ? '' : 'none';
    });
  }

  function refreshButtonState(){
    const srcId = selectedSourceId();
    const targets = selectedTargetIds();
    const ok = !!srcId && targets.length > 0 && !targets.includes(srcId);
    btnCopy.disabled = !ok;
  }

  btnSelectAll.addEventListener('click', () => {
    form.querySelectorAll("input[name='target_ids[]']").forEach(cb => cb.checked = true);
    const srcId = selectedSourceId();
    if (srcId) {
      const self = document.getElementById('tgt_' + srcId);
      if (self) self.checked = false;
    }
    refreshButtonState();
  });

  btnSelectNone.addEventListener('click', () => {
    form.querySelectorAll("input[name='target_ids[]']").forEach(cb => cb.checked = false);
    refreshButtonState();
  });

  btnSelectSameType.addEventListener('click', () => {
    const dt = selectedSourceDeviceType();
    form.querySelectorAll("input[name='target_ids[]']").forEach(cb => {
      const row = cb.closest('.template-item');
      const rowDt = row ? (row.getAttribute('data-device-type') || '') : '';
      cb.checked = !!dt && rowDt === dt;
    });
    const srcId = selectedSourceId();
    if (srcId) {
      const self = document.getElementById('tgt_' + srcId);
      if (self) self.checked = false;
    }
    refreshButtonState();
  });

  form.addEventListener('input', () => refreshButtonState());
  form.addEventListener('change', (e) => {
    if (e.target && e.target.matches('input[name=source_id]')) {
      const srcId = selectedSourceId();
      if (srcId) {
        const self = document.getElementById('tgt_' + srcId);
        if (self) self.checked = false;
      }
      // Re-apply same-type selection if user wants; we won’t force it.
    }
    refreshButtonState();
  });

  srcSearch.addEventListener('input', () => applyFilter(document.getElementById('srcList'), srcSearch.value, srcType.value));
  srcType.addEventListener('change', () => applyFilter(document.getElementById('srcList'), srcSearch.value, srcType.value));
  tgtSearch.addEventListener('input', () => applyFilter(document.getElementById('tgtList'), tgtSearch.value, tgtType.value));
  tgtType.addEventListener('change', () => applyFilter(document.getElementById('tgtList'), tgtSearch.value, tgtType.value));

  // Initial
  applyFilter(document.getElementById('srcList'), '', '');
  applyFilter(document.getElementById('tgtList'), '', '');
  refreshButtonState();

  window.confirmCopy = function(){
    if (btnCopy.disabled) return false;
    if (overwrite && overwrite.checked) {
      return confirm(<?php echo json_encode(__('confirm.overwrite_variables')); ?>);
    }
    return true;
  };
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
