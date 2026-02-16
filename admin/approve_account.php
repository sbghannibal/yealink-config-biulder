<?php
$page_title = 'Account Verzoeken';
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int) $_SESSION['admin_id'];

if (!has_permission($pdo, $admin_id, 'admin.manage')) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require_once __DIR__ . '/_header.php';

$error = '';
$success = '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Get admin email
$admin_email = 'admin@yealink-cfg.eu';
try {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute(['admin_email']);
    $result = $stmt->fetchColumn();
    if ($result) {
        $admin_email = $result;
    }
} catch (Exception $e) {
    // Settings table might not exist
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Ongeldige CSRF token';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Approve request
        if ($action === 'approve') {
            try {
                $request_id = (int)$_POST['request_id'];
                $suggested_username = trim($_POST['suggested_username'] ?? '');
                
                if (!$suggested_username) {
                    $error = 'Voer een gebruikersnaam in.';
                } else {
                    // Check if username already exists
                    $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
                    $stmt->execute([$suggested_username]);
                    if ($stmt->fetch()) {
                        $error = 'Deze gebruikersnaam bestaat al.';
                    } else {
                        // Get request details
                        $stmt = $pdo->prepare('SELECT * FROM account_requests WHERE id = ?');
                        $stmt->execute([$request_id]);
                        $request = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$request) {
                            $error = 'Verzoek niet gevonden.';
                        } else {
                            // Generate random password
                            $password = bin2hex(random_bytes(8));
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            
                            // Create admin account
                            $stmt = $pdo->prepare('
                                INSERT INTO admins 
                                (username, password, email, is_active, created_by, created_at)
                                VALUES (?, ?, ?, 1, ?, NOW())
                            ');
                            $stmt->execute([
                                $suggested_username,
                                $hashed_password,
                                $request['email'],
                                $admin_id
                            ]);
                            $new_admin_id = $pdo->lastInsertId();
                            
                            // Update request status
                            $stmt = $pdo->prepare('
                                UPDATE account_requests 
                                SET status = ?, approved_by = ?, approved_at = NOW()
                                WHERE id = ?
                            ');
                            $stmt->execute(['approved', $admin_id, $request_id]);
                            
                            // Send email to user
                            $subject = '✅ Account Goedgekeurd - Yealink Config Builder';
                            $message = "
Hallo {$request['full_name']},

Goed nieuws! Je verzoek voor een beheerdersaccount is goedgekeurd! 🎉

Hier zijn je inloggegevens:

🔗 Login URL: https://{$_SERVER['HTTP_HOST']}/login.php

👤 Gebruikersnaam: $suggested_username
🔑 Wachtwoord: $password

⚠️ BELANGRIJK:
- Noteer je wachtwoord op een veilige plek
- Dit e-mailbericht bevat je wachtwoord - bewaar het veilig
- Je kunt je wachtwoord wijzigen na het inloggen

Veel succes met je account!

---
Yealink Config Builder
";
                            
                            $headers = "From: noreply@{$_SERVER['HTTP_HOST']}\r\n";
                            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                            
                            mail($request['email'], $subject, $message, $headers);
                            
                            $success = "Account goedgekeurd! Login gegevens verzonden naar {$request['email']}";
                            $_POST = [];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Account approval error: ' . $e->getMessage());
                $error = 'Fout bij goedkeuren: ' . $e->getMessage();
            }
        }
        
        // Reject request
        if ($action === 'reject') {
            try {
                $request_id = (int)$_POST['request_id'];
                $rejection_reason = trim($_POST['rejection_reason'] ?? '');
                
                if (!$rejection_reason) {
                    $error = 'Geef een reden voor afwijzing.';
                } else {
                    // Get request details
                    $stmt = $pdo->prepare('SELECT * FROM account_requests WHERE id = ?');
                    $stmt->execute([$request_id]);
                    $request = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$request) {
                        $error = 'Verzoek niet gevonden.';
                    } else {
                        // Update request status
                        $stmt = $pdo->prepare('
                            UPDATE account_requests 
                            SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = NOW()
                            WHERE id = ?
                        ');
                        $stmt->execute(['rejected', $rejection_reason, $admin_id, $request_id]);
                        
                        // Send email to user
                        $subject = '❌ Account Verzoek Afgewezen - Yealink Config Builder';
                        $message = "
Hallo {$request['full_name']},

Helaas is je verzoek voor een beheerdersaccount afgewezen.

Reden:
$rejection_reason

Als je vragen hebt, neem alstublieft contact op met de beheerder.

---
Yealink Config Builder
";
                        
                        $headers = "From: noreply@{$_SERVER['HTTP_HOST']}\r\n";
                        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                        
                        mail($request['email'], $subject, $message, $headers);
                        
                        $success = "Verzoek afgewezen. Notificatie verzonden naar {$request['email']}";
                        $_POST = [];
                    }
                }
            } catch (Exception $e) {
                error_log('Account rejection error: ' . $e->getMessage());
                $error = 'Fout bij afwijzen: ' . $e->getMessage();
            }
        }
        
        // Resend credentials
        if ($action === 'resend_credentials') {
            try {
                $request_id = (int)$_POST['request_id'];
                
                // Get request details
                $stmt = $pdo->prepare('
                    SELECT ar.*, a.username 
                    FROM account_requests ar
                    LEFT JOIN admins a ON a.email = ar.email AND a.is_active = 1
                    WHERE ar.id = ? AND ar.status = ?
                ');
                $stmt->execute([$request_id, 'approved']);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$request) {
                    $error = 'Verzoek niet gevonden of niet goedgekeurd.';
                } else {
                    // Send email with username (no password resend)
                    $subject = '📧 Je Login Gegevens - Yealink Config Builder';
                    $message = "
Hallo {$request['full_name']},

Hier zijn je inloggegevens opnieuw verzonden:

🔗 Login URL: https://{$_SERVER['HTTP_HOST']}/login.php
👤 Gebruikersnaam: {$request['username']}

Als je je wachtwoord bent vergeten, gebruik alstublieft de 'Wachtwoord vergeten' functie op de login pagina.

---
Yealink Config Builder
";
                    
                    $headers = "From: noreply@{$_SERVER['HTTP_HOST']}\r\n";
                    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                    
                    mail($request['email'], $subject, $message, $headers);
                    
                    $success = "Inloggegevens opnieuw verzonden naar {$request['email']}";
                }
            } catch (Exception $e) {
                error_log('Resend credentials error: ' . $e->getMessage());
                $error = 'Fout bij opnieuw verzenden: ' . $e->getMessage();
            }
        }
        
        // Delete request
        if ($action === 'delete') {
            try {
                $request_id = (int)$_POST['request_id'];
                $stmt = $pdo->prepare('DELETE FROM account_requests WHERE id = ?');
                $stmt->execute([$request_id]);
                $success = 'Verzoek verwijderd.';
            } catch (Exception $e) {
                error_log('Delete request error: ' . $e->getMessage());
                $error = 'Fout bij verwijderen: ' . $e->getMessage();
            }
        }
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'])) {
    $filter = 'pending';
}

// Get all requests
$query = 'SELECT * FROM account_requests';
$params = [];

if ($filter !== 'all') {
    $query .= ' WHERE status = ?';
    $params[] = $filter;
}

$query .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count by status
$stmt = $pdo->query('
    SELECT status, COUNT(*) as count 
    FROM account_requests 
    GROUP BY status
');
$status_counts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status_counts[$row['status']] = $row['count'];
}
?>

<style>
    .filters {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .filter-btn {
        padding: 8px 16px;
        background: #f5f5f5;
        border: 2px solid transparent;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
    }
    
    .filter-btn:hover {
        background: #e9e9e9;
    }
    
    .filter-btn.active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 4px;
    }
    
    .badge-pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .badge-approved {
        background: #d4edda;
        color: #155724;
    }
    
    .badge-rejected {
        background: #f8d7da;
        color: #721c24;
    }
    
    .request-card {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 16px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        transition: box-shadow 0.2s;
    }
    
    .request-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .request-info {
        flex: 1;
    }
    
    .request-info h3 {
        margin-bottom: 8px;
        color: #333;
    }
    
    .request-meta {
        font-size: 13px;
        color: #666;
        margin-bottom: 12px;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    
    .request-reason {
        font-size: 14px;
        color: #555;
        padding: 12px;
        background: #f9f9f9;
        border-radius: 4px;
        margin-top: 8px;
        border-left: 3px solid #667eea;
    }
    
    .request-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        flex-wrap: wrap;
    }
    
    .btn-small {
        padding: 8px 12px;
        font-size: 13px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-approve {
        background: #28a745;
        color: white;
    }
    
    .btn-approve:hover {
        background: #218838;
    }
    
    .btn-reject {
        background: #dc3545;
        color: white;
    }
    
    .btn-reject:hover {
        background: #c82333;
    }
    
    .btn-resend {
        background: #17a2b8;
        color: white;
    }
    
    .btn-resend:hover {
        background: #138496;
    }
    
    .btn-delete {
        background: #6c757d;
        color: white;
    }
    
    .btn-delete:hover {
        background: #5a6268;
    }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 2000;
        align-items: center;
        justify-content: center;
    }
    
    .modal.active {
        display: flex;
    }
    
    .modal-content {
        background: white;
        border-radius: 8px;
        padding: 30px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    
    .modal-close {
        float: right;
        font-size: 24px;
        cursor: pointer;
        color: #999;
    }
    
    .modal-close:hover {
        color: #333;
    }
    
    .modal-body {
        clear: both;
        margin-top: 20px;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #999;
    }
    
    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
</style>

<h1>📧 Account Verzoeken</h1>

<?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card">
    <div class="filters">
        <a href="?filter=pending" class="filter-btn <?php echo $filter === 'pending' ? 'active' : ''; ?>">
            ⏳ In behandeling
            <span class="badge badge-pending"><?php echo $status_counts['pending'] ?? 0; ?></span>
        </a>
        <a href="?filter=approved" class="filter-btn <?php echo $filter === 'approved' ? 'active' : ''; ?>">
            ✅ Goedgekeurd
            <span class="badge badge-approved"><?php echo $status_counts['approved'] ?? 0; ?></span>
        </a>
        <a href="?filter=rejected" class="filter-btn <?php echo $filter === 'rejected' ? 'active' : ''; ?>">
            ❌ Afgewezen
            <span class="badge badge-rejected"><?php echo $status_counts['rejected'] ?? 0; ?></span>
        </a>
        <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
            📋 Alle
            <span class="badge" style="background: #e0e0e0;"><?php echo array_sum($status_counts); ?></span>
        </a>
    </div>

    <?php if (empty($requests)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📭</div>
            <p>Geen verzoeken gevonden.</p>
        </div>
    <?php else: ?>
        <?php foreach ($requests as $req): ?>
            <div class="request-card">
                <div class="request-info">
                    <h3>
                        <?php echo htmlspecialchars($req['full_name']); ?>
                        <span class="badge badge-<?php echo $req['status']; ?>">
                            <?php 
                            $status_label = [
                                'pending' => '⏳ In behandeling',
                                'approved' => '✅ Goedgekeurd',
                                'rejected' => '❌ Afgewezen'
                            ];
                            echo $status_label[$req['status']] ?? $req['status'];
                            ?>
                        </span>
                    </h3>
                    <div class="request-meta">
                        <span>📧 <?php echo htmlspecialchars($req['email']); ?></span>
                        <span>🏢 <?php echo htmlspecialchars($req['organization']); ?></span>
                        <span>📅 <?php echo date('d-m-Y H:i', strtotime($req['created_at'])); ?></span>
                    </div>
                    <div class="request-reason">
                        <strong>Reden:</strong><br>
                        <?php echo htmlspecialchars($req['reason']); ?>
                    </div>
                    
                    <?php if ($req['status'] === 'rejected' && $req['rejection_reason']): ?>
                        <div class="request-reason" style="border-left-color: #dc3545; background: #fff5f5;">
                            <strong>❌ Afwijzingsreden:</strong><br>
                            <?php echo htmlspecialchars($req['rejection_reason']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="request-actions">
                    <?php if ($req['status'] === 'pending'): ?>
                        <button class="btn-small btn-approve" onclick="showApproveModal(<?php echo (int)$req['id']; ?>, <?php echo htmlspecialchars(json_encode($req)); ?>)">
                            ✅ Goedkeuren
                        </button>
                        <button class="btn-small btn-reject" onclick="showRejectModal(<?php echo (int)$req['id']; ?>)">
                            ❌ Afwijzen
                        </button>
                    <?php elseif ($req['status'] === 'approved'): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="resend_credentials">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <button type="submit" class="btn-small btn-resend">📧 Opnieuw Verzenden</button>
                        </form>
                    <?php endif; ?>
                    
                    <button class="btn-small btn-delete" onclick="showDeleteModal(<?php echo (int)$req['id']; ?>)">
                        🗑️ Verwijder
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeApproveModal()">&times;</span>
        <h2>✅ Account Goedkeuren</h2>
        <form method="post" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" id="approveRequestId">
            
            <div class="form-group">
                <label for="suggested_username">Gebruikersnaam *</label>
                <input type="text" id="suggested_username" name="suggested_username" required placeholder="bijv. john.doe">
                <small style="color: #666; display: block; margin-top: 4px;">
                    💡 Dit kan de voornaam + achternaam zijn (lowercase, geen spaties)
                </small>
            </div>
            
            <p style="color: #666; font-size: 13px; margin: 16px 0;">
                ℹ️ Een willekeurig wachtwoord zal gegenereerd en per e-mail verzonden worden.
            </p>
            
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-success">✅ Goedkeuren</button>
                <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Annuleren</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeRejectModal()">&times;</span>
        <h2>❌ Account Afwijzen</h2>
        <form method="post" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rejectRequestId">
            
            <div class="form-group">
                <label for="rejection_reason">Afwijzingsreden *</label>
                <textarea id="rejection_reason" name="rejection_reason" required placeholder="Waarom wordt dit verzoek afgewezen?" style="min-height: 120px;"></textarea>
            </div>
            
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-danger">❌ Afwijzen</button>
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Annuleren</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeDeleteModal()">&times;</span>
        <h2>🗑️ Verzoek Verwijderen</h2>
        <form method="post" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="request_id" id="deleteRequestId">
            
            <p style="color: #666; margin-bottom: 16px;">
                ⚠️ Ben je zeker dat je dit verzoek wil verwijderen? Dit kan niet ongedaan gemaakt worden.
            </p>
            
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-danger">🗑️ Verwijderen</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Annuleren</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showApproveModal(requestId, requestData) {
        document.getElementById('approveRequestId').value = requestId;
        
        // Generate suggested username from full name
        const fullName = requestData.full_name.toLowerCase().trim();
        const parts = fullName.split(' ');
        const suggestedUsername = parts.length > 1 
            ? parts[0] + '.' + parts[parts.length - 1]
            : parts[0];
        
        document.getElementById('suggested_username').value = suggestedUsername;
        document.getElementById('approveModal').classList.add('active');
    }
    
    function closeApproveModal() {
        document.getElementById('approveModal').classList.remove('active');
    }
    
    function showRejectModal(requestId) {
        document.getElementById('rejectRequestId').value = requestId;
        document.getElementById('rejectModal').classList.add('active');
    }
    
    function closeRejectModal() {
        document.getElementById('rejectModal').classList.remove('active');
    }
    
    function showDeleteModal(requestId) {
        document.getElementById('deleteRequestId').value = requestId;
        document.getElementById('deleteModal').classList.add('active');
    }
    
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }
    
    // Close modals on escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeApproveModal();
            closeRejectModal();
            closeDeleteModal();
        }
    });
    
    // Close modals on background click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(event) {
            if (event.target === this) {
                this.classList.remove('active');
            }
        });
    });
</script>

</main>

</body>
</html>
