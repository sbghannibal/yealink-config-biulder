<?php
$page_title = 'Gebruikers';
session_start();
require_once __DIR__ . '/../settings/database.php';
require_once __DIR__ . '/../includes/rbac.php';

// Zorg dat gebruiker is ingelogd
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

// Optionele permissie-check (pas permission string aan indien gewenst)
if (!has_permission($pdo, $admin_id, 'admin.users.view')) {
    http_response_code(403);
    header('Location: /access_denied.php');
    exit;
}

$error = '';
try {
    $stmt = $pdo->query('SELECT id, username, email, is_active, created_at FROM admins ORDER BY username ASC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Kon gebruikers niet ophalen.';
    error_log('users.php error: ' . $e->getMessage());
}

require_once __DIR__ . '/_header.php';
?>

    <h2>Gebruikers</h2>
    <p><a class="btn" href="/admin/users_create.php">➕ Nieuwe gebruiker</a></p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Gebruikersnaam</th>
                    <th>E-mail</th>
                    <th>Actief</th>
                    <th>Aangemaakt</th>
                    <th>Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" style="text-align:center">Geen gebruikers gevonden.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo (int)$u['id']; ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><?php echo $u['is_active'] ? 'Ja' : 'Nee'; ?></td>
                            <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                            <td>
                                <a class="btn" href="/admin/users_edit.php?id=<?php echo (int)$u['id']; ?>">Bewerken</a>
                                <a class="btn" href="/admin/users_delete.php?id=<?php echo (int)$u['id']; ?>" style="background:#dc3545;" onclick="return confirm('Weet je het zeker?');">Verwijderen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php require_once __DIR__ . '/_footer.php'; ?>
