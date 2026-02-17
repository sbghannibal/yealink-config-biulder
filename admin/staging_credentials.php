<?php
$page_title = 'Staging Credentials';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

// Check if user has Owner role - ONLY OWNER CAN ACCESS
$stmt = $pdo->prepare('
    SELECT r.role_name 
    FROM admin_roles ar
    JOIN roles r ON r.id = ar.role_id
    WHERE ar.admin_id = ?
');
$stmt->execute([$admin_id]);
$user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('Owner', $user_roles)) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

// Get current authentication settings
$current_auth_user = getenv('STAGING_AUTH_USER') ?: 'provisioning';
$current_auth_pass = getenv('STAGING_AUTH_PASS') ?: '';
$current_test_token = getenv('STAGING_TEST_TOKEN') ?: '';
$auth_enabled = !empty($current_auth_pass);

// Get server URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$server_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu');

require_once __DIR__ . '/_header.php';
?>

<style>
    .credential-box {
        background: white;
        border: 2px solid #dc3545;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .credential-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        padding: 12px;
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .credential-label {
        font-weight: 600;
        min-width: 120px;
        color: #333;
    }
    
    .credential-value {
        font-family: 'Courier New', monospace;
        background: white;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        flex: 1;
        font-size: 14px;
    }
    
    .credential-value.masked {
        letter-spacing: 2px;
    }
    
    .copy-btn {
        background: #667eea;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        transition: background 0.2s;
    }
    
    .copy-btn:hover {
        background: #5568d3;
    }
    
    .toggle-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        transition: background 0.2s;
    }
    
    .toggle-btn:hover {
        background: #5a6268;
    }
    
    .warning-box {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 4px;
        padding: 16px;
        margin-bottom: 20px;
    }
    
    .example-box {
        background: #f0f0f0;
        padding: 16px;
        border-radius: 4px;
        margin-top: 16px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        overflow-x: auto;
    }
    
    .access-badge {
        display: inline-block;
        background: #dc3545;
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 8px;
    }
</style>

<h2>
    🔐 Staging Provisioning Credentials
    <span class="access-badge">OWNER ONLY</span>
</h2>

<div class="warning-box">
    <strong>⚠️ CONFIDENTIAL INFORMATION / VERTROUWELIJKE INFORMATIE</strong><br>
    These credentials provide access to staging provisioning endpoints. 
    Share this information only with trusted personnel. This page is accessible only to Owner role.
    <br><br>
    Deze credentials geven toegang tot de staging provisioning endpoints. 
    Deel deze informatie alleen met vertrouwde personen. Deze pagina is alleen toegankelijk voor Owner-rol.
</div>

<?php if (!$auth_enabled): ?>
    <div class="alert alert-error">
        ❌ <strong>Authenticatie is uitgeschakeld!</strong> 
        Ga naar <a href="/admin/staging_certificates.php">Staging Certificates</a> om authenticatie in te schakelen.
    </div>
<?php else: ?>
    <div class="alert alert-success">
        ✅ Authenticatie is actief
    </div>
<?php endif; ?>

<div class="credential-box">
    <h3 style="margin-top: 0;">Login Gegevens</h3>
    
    <div class="credential-row">
        <span class="credential-label">Username:</span>
        <code class="credential-value" id="username-value"><?php echo htmlspecialchars($current_auth_user); ?></code>
        <button class="copy-btn" onclick="copyToClipboard('username-value', this)">📋 Copy</button>
    </div>
    
    <div class="credential-row">
        <span class="credential-label">Password:</span>
        <code class="credential-value masked" id="password-value" data-password="<?php echo htmlspecialchars($current_auth_pass); ?>">
            <?php echo $auth_enabled ? str_repeat('•', strlen($current_auth_pass)) : '(not set)'; ?>
        </code>
        <?php if ($auth_enabled): ?>
        <button class="toggle-btn" onclick="togglePassword()" id="toggle-btn">👁️ Toon</button>
        <button class="copy-btn" onclick="copyPassword(this)">📋 Copy</button>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($current_test_token)): ?>
    <div class="credential-row">
        <span class="credential-label">Test Token:</span>
        <code class="credential-value masked" id="token-value" data-token="<?php echo htmlspecialchars($current_test_token); ?>">
            <?php echo str_repeat('•', strlen($current_test_token)); ?>
        </code>
        <button class="toggle-btn" onclick="toggleToken()" id="toggle-token-btn">👁️ Toon</button>
        <button class="copy-btn" onclick="copyToken(this)">📋 Copy</button>
    </div>
    <?php endif; ?>
</div>

<div class="alert alert-info" style="margin-bottom: 20px;">
    <strong>🛡️ User-Agent Verificatie Actief</strong><br>
    Alleen Yealink apparaten kunnen de staging bestanden downloaden. Downloads van browsers of andere tools worden automatisch geblokkeerd.
    <?php if (!empty($current_test_token)): ?>
    <br><br>
    <strong>Voor testen:</strong> Gebruik de test token met <code>?allow_test=TOKEN</code> parameter om toegang te krijgen zonder Yealink device.
    <?php else: ?>
    <br><br>
    <em>💡 Tip: Stel STAGING_TEST_TOKEN in via Staging Certificates pagina om te kunnen testen zonder Yealink device.</em>
    <?php endif; ?>
</div>

<div class="card">
    <h3>📡 Provisioning URLs</h3>
    <p>Gebruik deze URLs voor het configureren van Yealink telefoons:</p>
    
    <h4 style="margin-top: 20px;">Boot Configuration URL (per device):</h4>
    <div class="example-box">
http://<?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD@<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu'); ?>/provision/staging/<strong>MAC_ADDRESS</strong>.boot

<em style="color: #666;"># Vervang MAC_ADDRESS met het device MAC (zonder colons), bijv: 001565AABB20</em>
    </div>
    
    <h4 style="margin-top: 20px;">DHCP Option 66 Configuratie:</h4>
    <div class="example-box">
http://<?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD@<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu'); ?>/provision/staging/
    </div>
    
    <h4 style="margin-top: 20px;">Alternatief: Aparte velden in telefoon:</h4>
    <div class="example-box">
Server URL: http://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yealink-cfg.eu'); ?>/provision/staging/
Username:   <?php echo htmlspecialchars($current_auth_user); ?>

Password:   (zie boven)
    </div>
</div>

<div class="card">
    <h3>🧪 Test Credentials</h3>
    <p>Test de credentials met deze curl commando's:</p>
    
    <h4>Test 1: Boot Configuration</h4>
    <div class="example-box">
curl -u <?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD \
  <?php echo $server_url; ?>/provision/staging/001565AABB20.boot
    </div>
    
    <h4 style="margin-top: 16px;">Test 2: CA Certificate</h4>
    <div class="example-box">
curl -u <?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD \
  <?php echo $server_url; ?>/provision/staging/certificates/ca.crt
    </div>
    
    <h4 style="margin-top: 16px;">Test 3: Server Certificate</h4>
    <div class="example-box">
curl -u <?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD \
  <?php echo $server_url; ?>/provision/staging/certificates/server.crt
    </div>
    
    <?php if (!empty($current_test_token)): ?>
    <h4 style="margin-top: 16px;">Test 4: Met Test Token (Browser/curl zonder Yealink User-Agent)</h4>
    <div class="example-box">
# Boot config met test token
curl -u <?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD \
  "<?php echo $server_url; ?>/provision/staging/001565AABB20.boot?allow_test=<?php echo htmlspecialchars($current_test_token); ?>"

# Certificate met test token
curl -u <?php echo htmlspecialchars($current_auth_user); ?>:PASSWORD \
  "<?php echo $server_url; ?>/provision/staging/certificates/ca.crt?allow_test=<?php echo htmlspecialchars($current_test_token); ?>"
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>🔒 Security Best Practices</h3>
    <ul>
        <li>✅ Deel deze credentials alleen met vertrouwde technici</li>
        <li>✅ Gebruik een sterk wachtwoord (min. 16 karakters)</li>
        <li>✅ Verander het wachtwoord periodiek</li>
        <li>✅ Monitor de provision_logs voor ongebruikelijke activiteit</li>
        <li>✅ Overweeg IP whitelisting voor extra beveiliging</li>
        <li>❌ Deel deze pagina niet met niet-Owner gebruikers</li>
        <li>❌ Plaats credentials nooit in onbeveiligde documenten</li>
    </ul>
</div>

<div style="margin-top: 20px;">
    <a href="/admin/staging_certificates.php" class="btn">← Terug naar Certificate Management</a>
    <a href="/admin/dashboard.php" class="btn btn-secondary">Dashboard</a>
</div>

<script>
let passwordVisible = false;
let tokenVisible = false;

function togglePassword() {
    const passwordEl = document.getElementById('password-value');
    const toggleBtn = document.getElementById('toggle-btn');
    const realPassword = passwordEl.getAttribute('data-password');
    
    if (passwordVisible) {
        passwordEl.textContent = '•'.repeat(realPassword.length);
        passwordEl.classList.add('masked');
        toggleBtn.textContent = '👁️ Toon';
    } else {
        passwordEl.textContent = realPassword;
        passwordEl.classList.remove('masked');
        toggleBtn.textContent = '🙈 Verberg';
    }
    
    passwordVisible = !passwordVisible;
}

function toggleToken() {
    const tokenEl = document.getElementById('token-value');
    const toggleBtn = document.getElementById('toggle-token-btn');
    const realToken = tokenEl.getAttribute('data-token');
    
    if (tokenVisible) {
        tokenEl.textContent = '•'.repeat(realToken.length);
        tokenEl.classList.add('masked');
        toggleBtn.textContent = '👁️ Toon';
    } else {
        tokenEl.textContent = realToken;
        tokenEl.classList.remove('masked');
        toggleBtn.textContent = '🙈 Verberg';
    }
    
    tokenVisible = !tokenVisible;
}

function copyToClipboard(elementId, button) {
    const element = document.getElementById(elementId);
    const text = element.textContent.trim();
    
    navigator.clipboard.writeText(text).then(() => {
        const originalText = button.textContent;
        button.textContent = '✅ Gekopieerd!';
        button.style.background = '#28a745';
        
        setTimeout(() => {
            button.textContent = originalText;
            button.style.background = '';
        }, 2000);
    }).catch(err => {
        alert('Kopiëren mislukt: ' + err);
    });
}

function copyPassword(button) {
    const passwordEl = document.getElementById('password-value');
    const realPassword = passwordEl.getAttribute('data-password');
    
    navigator.clipboard.writeText(realPassword).then(() => {
        const originalText = button.textContent;
        button.textContent = '✅ Gekopieerd!';
        button.style.background = '#28a745';
        
        setTimeout(() => {
            button.textContent = originalText;
            button.style.background = '';
        }, 2000);
    }).catch(err => {
        alert('Kopiëren mislukt: ' + err);
    });
}

function copyToken(button) {
    const tokenEl = document.getElementById('token-value');
    const realToken = tokenEl.getAttribute('data-token');
    
    navigator.clipboard.writeText(realToken).then(() => {
        const originalText = button.textContent;
        button.textContent = '✅ Gekopieerd!';
        button.style.background = '#28a745';
        
        setTimeout(() => {
            button.textContent = originalText;
            button.style.background = '';
        }, 2000);
    }).catch(err => {
        alert('Kopiëren mislukt: ' + err);
    });
}
</script>

</main>

</body>
</html>
