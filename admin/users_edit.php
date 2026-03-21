<?php
$page_title = 'Gebruiker bewerken';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/partner_access.php';
require_once __DIR__ . '/../includes/i18n.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$current_admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $current_admin_id, 'admin.users.edit')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

$error = '';
$success = '';

function validate_password($password) {
    if (strlen($password) < 8) {
        return __('error.password_min_length');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return __('error.password_needs_uppercase');
    }
    if (!preg_match('/[a-z]/', $password)) {
        return __('error.password_needs_lowercase');
    }
    if (!preg_match('/[0-9]/', $password)) {
        return __('error.password_needs_number');
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return __('error.password_needs_special');
    }
    return true;
}

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Get user id to edit
$user_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: /admin/users.php');
    exit;
}

// Fetch available roles
try {
    $stmt = $pdo->query('SELECT id, role_name FROM roles ORDER BY role_name ASC');
    $all_roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_roles = [];
    error_log('users_edit fetch roles error: ' . $e->getMessage());
}

// Fetch partner companies (for the partner assignment dropdown)
$all_partner_companies = [];
$can_manage_partners = is_owner($pdo, $current_admin_id) || has_permission($pdo, $current_admin_id, 'partners.manage');
if ($can_manage_partners) {
    try {
        $stmt = $pdo->query('SELECT id, name FROM partner_companies ORDER BY name ASC');
        $all_partner_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('users_edit fetch partner companies error: ' . $e->getMessage());
    }
}

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = __('error.csrf_invalid');
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;
        $password = $_POST['password'] ?? '';
        $role_id = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int)$_POST['role_id'] : null;
        // Partner company assignment (only saved when current admin can manage partners)
        $partner_company_id = ($can_manage_partners && isset($_POST['partner_company_id']) && $_POST['partner_company_id'] !== '')
                              ? (int)$_POST['partner_company_id'] : null;
        $allowed_languages = ['nl', 'fr', 'en'];
        $preferred_language = in_array($_POST['preferred_language'] ?? '', $allowed_languages, true)
            ? $_POST['preferred_language']
            : 'nl';

        if ($username === '' || $email === '') {
            $error = __('error.fill_username_email');
        } elseif ($password !== '') {
            $pw_error = validate_password($password);
            if ($pw_error !== true) {
                $error = $pw_error;
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                // Update basic fields
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare('UPDATE admins SET username = ?, email = ?, password = ?, is_active = ?, preferred_language = ? WHERE id = ?');
                    $stmt->execute([$username, $email, $hash, $is_active, $preferred_language, $user_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET username = ?, email = ?, is_active = ?, preferred_language = ? WHERE id = ?');
                    $stmt->execute([$username, $email, $is_active, $preferred_language, $user_id]);
                }

                // Update role: remove existing then insert selected (only one role)
                $stmt = $pdo->prepare('DELETE FROM admin_roles WHERE admin_id = ?');
                $stmt->execute([$user_id]);

                if ($role_id !== null) {
                    $ins = $pdo->prepare('INSERT INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
                    $ins->execute([$user_id, $role_id]);
                }

                // Update partner company assignment (only if current admin may manage partners)
                if ($can_manage_partners) {
                    $stmt = $pdo->prepare('DELETE FROM admin_partner_company WHERE admin_id = ?');
                    $stmt->execute([$user_id]);
                    if ($partner_company_id !== null) {
                        $stmt = $pdo->prepare('INSERT INTO admin_partner_company (admin_id, partner_company_id) VALUES (?, ?)');
                        $stmt->execute([$user_id, $partner_company_id]);
                    }
                }

                $pdo->commit();

                $success = __('message.user_updated');
                // If current admin updated their own username, refresh session username
                if ($user_id === $current_admin_id) {
                    $_SESSION['username'] = $username;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('users_edit update error: ' . $e->getMessage());
                // Handle duplicate username/email gracefully
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = __('error.duplicate_username_email');
                } else {
                    $error = __('error.user_update_failed');
                }
            }
        }
    }
}

// Fetch user data for form (fresh from DB)
try {
    $stmt = $pdo->prepare('SELECT id, username, email, is_active, preferred_language, created_at FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: /admin/users.php');
        exit;
    }

    // Fetch assigned role ids
    $stmt = $pdo->prepare('SELECT role_id FROM admin_roles WHERE admin_id = ?');
    $stmt->execute([$user_id]);
    $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $assigned = array_map('intval', $assigned);

    // Fetch current partner company assignment for this user
    $assigned_partner_company_id = null;
    if ($can_manage_partners) {
        try {
            $stmt = $pdo->prepare('SELECT partner_company_id FROM admin_partner_company WHERE admin_id = ? LIMIT 1');
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $assigned_partner_company_id = $row ? (int)$row['partner_company_id'] : null;
        } catch (Exception $e) {
            error_log('users_edit fetch partner assignment error: ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log('users_edit fetch user error: ' . $e->getMessage());
    $error = __('error.user_fetch_failed');
    $user = ['id' => $user_id, 'username' => '', 'email' => '', 'is_active' => 0];
    $assigned = [];
}

require_once __DIR__ . '/_header.php';
?>

    <h2><?php echo __('page.users.edit'); ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">

            <div class="form-group">
                <label><?php echo __('form.username'); ?></label>
                <input name="username" type="text" value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>

            <div class="form-group">
                <label><?php echo __('form.email'); ?></label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <div class="form-group">
                <label><?php echo __('form.password'); ?> (<?php echo __('label.optional'); ?> — <?php echo __('form.password_leave_empty'); ?>)</label>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input name="password" id="password-field" type="password" autocomplete="new-password" style="flex:1; min-width:200px;" oninput="checkPasswordStrength(this.value)">
                    <button type="button" onclick="generatePassword()" style="padding:8px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">🔑 <?php echo __('button.generate_password'); ?></button>
                    <button type="button" onclick="togglePasswordVisibility()" style="padding:8px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer;">👁️</button>
                </div>
                <div id="password-strength" style="margin-top:6px; font-size:13px;"></div>
                <small style="display:block; margin-top:4px; color:#666;"><?php echo __('form.password_requirements'); ?></small>
            </div>

            <div class="form-group">
                <label><?php echo __('form.is_active'); ?></label>
                <select name="is_active">
                    <option value="1" <?php echo $user['is_active'] ? 'selected' : ''; ?>><?php echo __('label.yes'); ?></option>
                    <option value="0" <?php echo !$user['is_active'] ? 'selected' : ''; ?>><?php echo __('label.no'); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label><?php echo __('form.role'); ?></label>
                <?php if (empty($all_roles)): ?>
                    <p><?php echo __('message.no_roles_configured'); ?></p>
                <?php else: ?>
                    <select name="role_id">
                        <option value="">-- <?php echo __('form.select_role'); ?> --</option>
                        <?php foreach ($all_roles as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>"
                                <?php echo (count($assigned) === 1 && in_array((int)$r['id'], $assigned, true)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display: block; margin-top: 4px; color: #666;">
                        <?php echo __('form.role_help'); ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="preferred_language"><?php echo __('form.preferred_language'); ?></label>
                <select name="preferred_language" id="preferred_language">
                    <option value="nl" <?php echo ($user['language'] ?? 'nl') === 'nl' ? 'selected' : ''; ?>>🇳🇱 Nederlands (NL)</option>
                    <option value="fr" <?php echo ($user['language'] ?? 'nl') === 'fr' ? 'selected' : ''; ?>>🇫🇷 Français (FR)</option>
                    <option value="en" <?php echo ($user['language'] ?? 'nl') === 'en' ? 'selected' : ''; ?>>🇺🇸 English (ENG)</option>
                </select>
                <small style="display:block; margin-top:4px; color:#666;"><?php echo __('form.preferred_language_help'); ?></small>
            </div>

            <?php if ($can_manage_partners): ?>
            <div class="form-group">
                <label>Partner Bedrijf</label>
                <select name="partner_company_id">
                    <option value="">— Geen —</option>
                    <?php foreach ($all_partner_companies as $pc): ?>
                        <option value="<?php echo (int)$pc['id']; ?>"
                            <?php echo $assigned_partner_company_id === (int)$pc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="display:block; margin-top:4px; color:#666;">Koppel dit account aan een partner bedrijf. Niet-Owner accounts zonder partner zien niets.</small>
            </div>
            <?php endif; ?>

            <button class="btn" type="submit"><?php echo __('button.save'); ?></button>
            <a class="btn" href="/admin/users.php" style="background:#6c757d;"><?php echo __('button.cancel'); ?></a>
        </form>
    </div>

<script>
function generatePassword() {
    const length = Math.floor(Math.random() * 6) + 10; // 10-15
    const uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const lowercase = 'abcdefghijklmnopqrstuvwxyz';
    const numbers = '0123456789';
    const special = '!@#$%^&*()_+-=[]{}|;:,.<>?';
    const all = uppercase + lowercase + numbers + special;

    let password = '';
    password += uppercase[Math.floor(Math.random() * uppercase.length)];
    password += lowercase[Math.floor(Math.random() * lowercase.length)];
    password += numbers[Math.floor(Math.random() * numbers.length)];
    password += special[Math.floor(Math.random() * special.length)];

    for (let i = password.length; i < length; i++) {
        password += all[Math.floor(Math.random() * all.length)];
    }

    // Fisher-Yates shuffle
    const arr = password.split('');
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    password = arr.join('');

    const field = document.getElementById('password-field');
    field.value = password;
    field.type = 'text';
    checkPasswordStrength(password);
    setTimeout(() => { field.type = 'password'; }, 3000);
}

function togglePasswordVisibility() {
    const field = document.getElementById('password-field');
    field.type = field.type === 'password' ? 'text' : 'password';
}

function checkPasswordStrength(password) {
    const indicator = document.getElementById('password-strength');
    if (!password) {
        indicator.innerHTML = '';
        return;
    }
    let score = 0;
    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    let label, color;
    if (score <= 2) { label = '<?php echo __('label.password_weak'); ?>'; color = '#dc3545'; }
    else if (score <= 3) { label = '<?php echo __('label.password_fair'); ?>'; color = '#ffc107'; }
    else { label = '<?php echo __('label.password_strong'); ?>'; color = '#28a745'; }

    indicator.innerHTML = '<span style="color:' + color + '; font-weight:600;">' + label + '</span>';
}
</script>
<?php require_once __DIR__ . '/_footer.php'; ?>
