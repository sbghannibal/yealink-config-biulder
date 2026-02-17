<nav style="background:#fff; padding:12px; border-bottom:1px solid #eee;">
    <div class="container" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <a href="/admin/dashboard.php">Admin</a> |
            <a href="/admin/users.php">Gebruikers</a> |
            <a href="/admin/roles.php">Rollen</a> |
            <a href="/admin/customers.php">Klanten</a> |
            <a href="/admin/settings.php">Instellingen</a> |
            <a href="/admin/audit.php">Audit</a>
        </div>
        <div>
            <a href="/index.php">Site</a> |
            <a href="/logout.php">Uitloggen (<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>)</a>
        </div>
    </div>
</nav>
