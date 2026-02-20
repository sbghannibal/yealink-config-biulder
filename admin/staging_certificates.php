<?php
$page_title = 'Staging Certificates';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

$cert_dir = __DIR__ . '/../provision/staging/certificates';

// Ensure directory exists
if (!is_dir($cert_dir)) {
    mkdir($cert_dir, 0755, true);
}

$error = '';
$success = '';

// Get current authentication settings
$current_auth_user = getenv('STAGING_AUTH_USER') ?: 'provisioning';
$current_auth_pass = getenv('STAGING_AUTH_PASS') ?: '';
$current_test_token = getenv('STAGING_TEST_TOKEN') ?: '';
$auth_enabled = !empty($current_auth_pass);

// Check if user is Owner
$stmt_roles = $pdo->prepare('
    SELECT r.role_name 
    FROM admin_roles ar
    JOIN roles r ON r.id = ar.role_id
    WHERE ar.admin_id = ?
');
$stmt_roles->execute([$admin_id]);
$admin_roles = $stmt_roles->fetchAll(PDO::FETCH_COLUMN);
$is_owner = in_array('Owner', $admin_roles);

// Handle authentication settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_auth') {
        $new_username = trim($_POST['auth_username'] ?? '');
        $new_password = trim($_POST['auth_password'] ?? '');
        $new_test_token = trim($_POST['test_token'] ?? '');
        
        if (empty($new_username)) {
            $error = 'Username cannot be empty';
        } else {
            // Update .env file
            $env_file = __DIR__ . '/../.env';
            $env_content = file_exists($env_file) ? file_get_contents($env_file) : '';
            
            // Remove existing STAGING_AUTH entries
            $env_lines = explode("\n", $env_content);
            $new_lines = [];
            foreach ($env_lines as $line) {
                if (strpos($line, 'STAGING_AUTH_USER=') !== 0 && 
                    strpos($line, 'STAGING_AUTH_PASS=') !== 0 &&
                    strpos($line, 'STAGING_TEST_TOKEN=') !== 0) {
                    $new_lines[] = $line;
                }
            }
            
            // Add new credentials
            $new_lines[] = '';
            $new_lines[] = '# Staging Provisioning Authentication';
            $new_lines[] = 'STAGING_AUTH_USER=' . $new_username;
            $new_lines[] = 'STAGING_AUTH_PASS=' . $new_password;
            $new_lines[] = 'STAGING_TEST_TOKEN=' . $new_test_token;
            
            if (file_put_contents($env_file, implode("\n", $new_lines))) {
                $success = 'Authentication settings updated successfully. Changes will take effect on next request.';
                $current_auth_user = $new_username;
                $current_auth_pass = $new_password;
                $current_test_token = $new_test_token;
                $auth_enabled = !empty($new_password);
            } else {
                $error = 'Failed to update .env file. Check file permissions.';
            }
        }
    }
    
    if ($action === 'upload_ca' && isset($_FILES['ca_cert'])) {
        $file = $_FILES['ca_cert'];
        
        if ($file['type'] !== 'application/x-x509-ca-cert' && 
            $file['type'] !== 'text/plain' &&
            !preg_match('/\.crt$/i', $file['name'])) {
            $error = 'Invalid certificate format';
        } else {
            $target = $cert_dir . '/ca.crt';
            if (move_uploaded_file($file['tmp_name'], $target)) {
                chmod($target, 0644);
                $success = 'CA certificate uploaded successfully';
            } else {
                $error = 'Failed to upload certificate';
            }
        }
    }
    
    if ($action === 'upload_server' && isset($_FILES['server_cert'])) {
        $file = $_FILES['server_cert'];
        
        if ($file['type'] !== 'application/x-x509-ca-cert' && 
            $file['type'] !== 'text/plain' &&
            !preg_match('/\.crt$/i', $file['name'])) {
            $error = 'Invalid certificate format';
        } else {
            $target = $cert_dir . '/server.crt';
            if (move_uploaded_file($file['tmp_name'], $target)) {
                chmod($target, 0644);
                $success = 'Server certificate uploaded successfully';
            } else {
                $error = 'Failed to upload certificate';
            }
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<h2>Staging Certificates</h2>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card">
    <h3>🔐 Authentication Settings</h3>
    <p>Configure HTTP Basic Authentication for staging provisioning endpoints</p>
    
    <?php if ($auth_enabled): ?>
        <div class="alert alert-success" style="margin-bottom: 16px;">
            ✅ Authentication is <strong>ENABLED</strong>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" style="margin-bottom: 16px;">
            ⚠️ Authentication is <strong>DISABLED</strong> - Anyone can access staging files!
        </div>
    <?php endif; ?>
    
    <form method="post">
        <input type="hidden" name="action" value="update_auth">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="auth_username" value="<?php echo htmlspecialchars($current_auth_user); ?>" required>
            <small>Username for HTTP Basic Authentication</small>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="auth_password" value="<?php echo htmlspecialchars($current_auth_pass); ?>" placeholder="Enter password to enable authentication">
            <small>Leave empty to disable authentication (NOT RECOMMENDED)</small>
        </div>
        <div class="form-group">
            <label>Test Token (Optional)</label>
            <input type="text" name="test_token" value="<?php echo htmlspecialchars($current_test_token); ?>" placeholder="For testing without Yealink device">
            <small>Generate with: <code>openssl rand -hex 32</code> - Allows testing from browser/curl</small>
        </div>
        <button class="btn" type="submit">Update Authentication</button>
    </form>
    
    <div style="margin-top: 16px; padding: 12px; background: #f0f0f0; border-radius: 4px;">
        <strong>Current Settings:</strong><br>
        Username: <code><?php echo htmlspecialchars($current_auth_user); ?></code><br>
        Password: <code><?php echo $auth_enabled ? '••••••••' : '(not set)'; ?></code><br>
        Test Token: <code><?php echo !empty($current_test_token) ? '••••••••' : '(not set)'; ?></code><br>
        Status: <?php echo $auth_enabled ? '✅ Active' : '❌ Inactive'; ?>
    </div>
    
    <?php if ($is_owner): ?>
    <div style="margin-top: 16px;">
        <a href="/admin/staging_credentials.php" class="btn" style="background: #dc3545;">
            🔑 View Credentials (Owner Only)
        </a>
    </div>
    <?php endif; ?>
</div>

<div class="alert alert-info" style="margin-top: 16px;">
    <strong>🛡️ User-Agent Verificatie</strong><br>
    Alleen Yealink apparaten kunnen staging bestanden downloaden. Downloads van browsers of andere tools worden geblokkeerd, tenzij de test token wordt gebruikt.
</div>

<div class="card" style="margin-top: 16px;">
    <h3>1️⃣ Upload Root CA Certificate</h3>
    <p>Upload Yealink Root CA certificate (ca.crt)</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_ca">
        <div class="form-group">
            <label>CA Certificate File (.crt)</label>
            <input type="file" name="ca_cert" accept=".crt" required>
            <small>Root certificate authority certificate - get from Yealink</small>
        </div>
        <button class="btn" type="submit">Upload CA Certificate</button>
    </form>
    
    <?php if (file_exists($cert_dir . '/ca.crt')): ?>
        <p style="margin-top: 12px;">✅ CA Certificate: <strong>Present</strong></p>
    <?php else: ?>
        <p style="margin-top: 12px;">❌ CA Certificate: <strong>Missing</strong></p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>2️⃣ Upload Server Certificate</h3>
    <p>Upload shared server certificate (server.crt) - used by ALL devices</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_server">
        <div class="form-group">
            <label>Server Certificate File (.crt)</label>
            <input type="file" name="server_cert" accept=".crt" required>
            <small>Shared certificate for all phones (prevents MAC spoofing attacks)</small>
        </div>
        <button class="btn" type="submit">Upload Server Certificate</button>
    </form>
    
    <?php if (file_exists($cert_dir . '/server.crt')): ?>
        <p style="margin-top: 12px;">✅ Server Certificate: <strong>Present</strong></p>
    <?php else: ?>
        <p style="margin-top: 12px;">❌ Server Certificate: <strong>Missing</strong></p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>🔒 Security Architecture</h3>
    <div style="background: #f0f0f0; padding: 16px; border-radius: 4px; margin-bottom: 16px;">
        <h4 style="margin-top: 0;">Why Shared Certificates?</h4>
        <p style="margin-bottom: 8px;">
            <strong>❌ Device-specific certificates per MAC zijn ONVEILIG:</strong>
        </p>
        <ul style="margin-left: 20px; margin-bottom: 12px;">
            <li>Aanvallers kunnen MAC-adressen faken in de aanvraag</li>
            <li>Ze kunnen dan certificaten van andere devices downloaden</li>
            <li>Dit geeft ongeautoriseerde toegang tot provisioning</li>
        </ul>
        
        <p style="margin-bottom: 8px;">
            <strong>✅ Onze oplossing: Gedeelde certificaten + Authenticatie:</strong>
        </p>
        <ul style="margin-left: 20px;">
            <li><strong>Staging fase:</strong> HTTP Basic Auth beschermt toegang tot certificaten</li>
            <li><strong>Shared certificates:</strong> Alle devices gebruiken dezelfde certificaten</li>
            <li><strong>Device validatie:</strong> Echte MAC-validatie gebeurt in fase 2 (volledige provisioning) via database</li>
            <li><strong>Resultaat:</strong> MAC faken helpt niet zonder geldige login credentials</li>
        </ul>
    </div>
    
    <div style="background: #d4edda; padding: 16px; border-radius: 4px; border-left: 4px solid #28a745;">
        <h4 style="margin-top: 0; color: #155724;">Security Layers</h4>
        <ol style="margin: 0;">
            <li><strong>Authenticatie:</strong> Username/password vereist voor staging downloads</li>
            <li><strong>Gedeelde certificaten:</strong> Voorkomen MAC-spoofing aanvallen</li>
            <li><strong>Database validatie:</strong> Device moet actief zijn in database</li>
            <li><strong>IP logging:</strong> Alle provisioning pogingen worden gelogd</li>
            <li><strong>HTTPS in productie:</strong> Versleuteld verkeer voor fase 2</li>
        </ol>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>ℹ️ How It Works</h3>
    <ol>
        <li><strong>Configure authentication</strong> - Set username/password voor staging toegang</li>
        <li><strong>Upload CA certificate</strong> - Root certificate authority</li>
        <li><strong>Upload server certificate</strong> - Gedeeld certificaat voor alle phones</li>
        <li><strong>Phone downloads boot config</strong> - Met HTTP Basic Auth credentials</li>
        <li><strong>Phone downloads certificates</strong> - CA + Server cert (gedeeld)</li>
        <li><strong>Phone connects for full provisioning</strong> - Via HTTPS met certificaten</li>
        <li><strong>Device validation</strong> - MAC-adres wordt gevalideerd via database</li>
    </ol>
    
    <div style="margin-top: 16px; padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
        <strong>⚠️ Belangrijk:</strong> Device-specifieke certificaten per MAC zijn verwijderd om MAC-spoofing aanvallen te voorkomen. 
        Alle devices gebruiken nu dezelfde gedeelde certificaten, en authenticatie gebeurt via username/password.
    </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
