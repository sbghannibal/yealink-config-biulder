<?php
$page_title = 'Staging Certificates';
session_start();
require_once __DIR__ . '/../config/database.php';
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

// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
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
    
    if ($action === 'generate_device_cert' && isset($_POST['mac'])) {
        $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $_POST['mac']));
        
        if (strlen($mac) !== 12) {
            $error = 'Invalid MAC address';
        } else {
            // Placeholder for future certificate generation
            // For now, just create empty file
            $cert_file = $cert_dir . '/device_' . $mac . '.crt';
            file_put_contents($cert_file, '# Device certificate for ' . $mac . "\n# To be generated\n");
            chmod($cert_file, 0644);
            $success = 'Device certificate placeholder created';
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
    <h3>1️⃣ Upload Root CA Certificate</h3>
    <p>Upload Yealink Root CA certificate (ca.crt)</p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_ca">
        <div class="form-group">
            <label>CA Certificate File (.crt)</label>
            <input type="file" name="ca_cert" accept=".crt" required>
            <small>Get from Yealink (see STAGING_PROVISIONING_SETUP.md for instructions)</small>
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
    <h3>2️⃣ Device Certificates</h3>
    <p>Create device-specific certificates for provisioning</p>
    
    <form method="post" style="margin-bottom: 16px;">
        <input type="hidden" name="action" value="generate_device_cert">
        <div class="form-group">
            <label>Device MAC Address (e.g., 00:15:65:AA:BB:20)</label>
            <input type="text" name="mac" placeholder="00:15:65:AA:BB:20" required>
        </div>
        <button class="btn" type="submit">Generate Certificate</button>
    </form>
    
    <h4>Existing Device Certificates:</h4>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="border-bottom: 1px solid #ddd;">
                <th style="text-align: left; padding: 8px;">MAC Address</th>
                <th style="text-align: left; padding: 8px;">File</th>
                <th style="text-align: left; padding: 8px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $files = glob($cert_dir . '/device_*.crt');
            if (empty($files)) {
                echo '<tr><td colspan="3" style="padding: 8px; text-align: center; color: #999;">No device certificates yet</td></tr>';
            } else {
                foreach ($files as $file) {
                    $filename = basename($file);
                    $mac = str_replace(['device_', '.crt'], '', $filename);
                    $mac_formatted = strtoupper(
                        substr($mac, 0, 2) . ':' . 
                        substr($mac, 2, 2) . ':' . 
                        substr($mac, 4, 2) . ':' . 
                        substr($mac, 6, 2) . ':' . 
                        substr($mac, 8, 2) . ':' . 
                        substr($mac, 10, 2)
                    );
                    echo '<tr style="border-bottom: 1px solid #eee;">';
                    echo '<td style="padding: 8px;">' . htmlspecialchars($mac_formatted) . '</td>';
                    echo '<td style="padding: 8px;">' . htmlspecialchars($filename) . '</td>';
                    echo '<td style="padding: 8px;">📋 Placeholder (needs generation)</td>';
                    echo '</tr>';
                }
            }
            ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>ℹ️ How It Works</h3>
    <ol>
        <li>Upload Yealink Root CA certificate</li>
        <li>Create device-specific certificate for each phone</li>
        <li>Phone downloads boot config with certificate URLs</li>
        <li>Phone downloads CA and device certificates</li>
        <li>Phone downloads full provisioning config via HTTPS</li>
    </ol>
</div>

</main>

</body>
</html>
