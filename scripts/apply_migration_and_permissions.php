#!/usr/bin/env php
<?php
/**
 * Apply database migration and RBAC permissions
 * 
 * Usage:
 *   php scripts/apply_migration_and_permissions.php [--yes] [--sql=/path/to/migration.sql]
 * 
 * Options:
 *   --yes              Execute migration (without this, dry-run mode)
 *   --sql=<path>       Path to SQL migration file (default: db/migrations/2026_02_migrate_devices_to_device_type_id.sql)
 */

// Parse command line arguments
$options = getopt('', ['yes', 'sql::']);
$dryRun = !isset($options['yes']);
$sqlFile = $options['sql'] ?? __DIR__ . '/../db/migrations/2026_02_migrate_devices_to_device_type_id.sql';

echo PHP_EOL;
echo "==================================================================" . PHP_EOL;
echo "Database Migration and RBAC Permissions Script" . PHP_EOL;
echo "==================================================================" . PHP_EOL;
echo "SQL File: $sqlFile" . PHP_EOL;
echo "Mode: " . ($dryRun ? "DRY-RUN (use --yes to execute)" : "EXECUTE") . PHP_EOL;
echo "==================================================================" . PHP_EOL;
echo PHP_EOL;

// Verify SQL file exists
if (!file_exists($sqlFile)) {
    die("ERROR: SQL file not found: $sqlFile" . PHP_EOL);
}

// Load database configuration
require_once __DIR__ . '/../config/database.php';

if (!isset($pdo)) {
    die("ERROR: Database connection not established. Check config/database.php" . PHP_EOL);
}

// Read SQL file
$sqlContent = file_get_contents($sqlFile);
if ($sqlContent === false) {
    die("ERROR: Could not read SQL file: $sqlFile" . PHP_EOL);
}

echo "Migration SQL file loaded successfully." . PHP_EOL;
echo "File size: " . strlen($sqlContent) . " bytes" . PHP_EOL;
echo PHP_EOL;

if ($dryRun) {
    echo "DRY-RUN MODE: The following SQL would be executed:" . PHP_EOL;
    echo "--------------------------------------------------------------" . PHP_EOL;
    echo $sqlContent . PHP_EOL;
    echo "--------------------------------------------------------------" . PHP_EOL;
    echo PHP_EOL;
    echo "To execute this migration, run with --yes flag:" . PHP_EOL;
    echo "  php scripts/apply_migration_and_permissions.php --yes" . PHP_EOL;
    echo PHP_EOL;
} else {
    echo "EXECUTING MIGRATION..." . PHP_EOL;
    
    try {
        // Split SQL statements
        // NOTE: This simple parser works for our specific migration file which uses
        // START TRANSACTION/COMMIT blocks and simple semicolon-terminated statements.
        // Limitations: Does not handle semicolons in string literals, complex multi-line
        // comments, or nested transactions. The migration file must be structured accordingly.
        $statements = [];
        $lines = explode("\n", $sqlContent);
        $currentStatement = '';
        $inTransaction = false;
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Skip comments and empty lines
            if (empty($trimmed) || substr($trimmed, 0, 2) === '--') {
                continue;
            }
            
            $currentStatement .= $line . "\n";
            
            // Check for transaction boundaries
            if (stripos($trimmed, 'START TRANSACTION') !== false) {
                $inTransaction = true;
            }
            if (stripos($trimmed, 'COMMIT') !== false) {
                $inTransaction = false;
                $statements[] = $currentStatement;
                $currentStatement = '';
            }
            
            // For statements outside transaction, split by semicolon
            if (!$inTransaction && substr($trimmed, -1) === ';') {
                $statements[] = $currentStatement;
                $currentStatement = '';
            }
        }
        
        if (!empty($currentStatement)) {
            $statements[] = $currentStatement;
        }
        
        // Execute each statement block
        foreach ($statements as $idx => $statement) {
            $statement = trim($statement);
            if (empty($statement)) continue;
            
            echo "Executing statement block " . ($idx + 1) . "..." . PHP_EOL;
            $pdo->exec($statement);
            echo "  ✓ Success" . PHP_EOL;
        }
        
        echo PHP_EOL;
        echo "Migration executed successfully!" . PHP_EOL;
        echo PHP_EOL;
        
    } catch (PDOException $e) {
        echo PHP_EOL;
        echo "ERROR during migration: " . $e->getMessage() . PHP_EOL;
        echo PHP_EOL;
        exit(1);
    }
}

// RBAC Permissions - Add permissions for Admin role
echo "==================================================================" . PHP_EOL;
echo "RBAC Permissions Setup" . PHP_EOL;
echo "==================================================================" . PHP_EOL;
echo PHP_EOL;

$permissions = [
    'admin.device_types.manage',
    'admin.variables.manage',
    'variables.manage',
    'admin.tokens.manage',
];

if ($dryRun) {
    echo "DRY-RUN MODE: The following permissions would be added to Admin role:" . PHP_EOL;
    foreach ($permissions as $perm) {
        echo "  - $perm" . PHP_EOL;
    }
    echo PHP_EOL;
    echo "SQL that would be executed:" . PHP_EOL;
    echo "--------------------------------------------------------------" . PHP_EOL;
    echo "INSERT INTO role_permissions (role_id, permission)" . PHP_EOL;
    echo "SELECT r.id, p.perm FROM roles r" . PHP_EOL;
    echo "CROSS JOIN (SELECT '" . implode("' AS perm UNION SELECT '", $permissions) . "' AS perm) p" . PHP_EOL;
    echo "WHERE r.role_name = 'Admin'" . PHP_EOL;
    echo "ON DUPLICATE KEY UPDATE permission = permission;" . PHP_EOL;
    echo "--------------------------------------------------------------" . PHP_EOL;
    echo PHP_EOL;
} else {
    echo "Adding RBAC permissions for Admin role..." . PHP_EOL;
    
    try {
        // First, check if Admin role exists
        $stmt = $pdo->query("SELECT id FROM roles WHERE role_name = 'Admin' LIMIT 1");
        $adminRole = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adminRole) {
            echo "WARNING: Admin role not found. Skipping RBAC permissions." . PHP_EOL;
            echo "Please create the Admin role first (run seed.php or create manually)." . PHP_EOL;
        } else {
            $roleId = $adminRole['id'];
            echo "Admin role found (ID: $roleId)" . PHP_EOL;
            
            // Insert permissions (using INSERT IGNORE to skip duplicates)
            foreach ($permissions as $permission) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?, ?)");
                $stmt->execute([$roleId, $permission]);
                echo "  ✓ Added permission: $permission" . PHP_EOL;
            }
            
            echo PHP_EOL;
            echo "RBAC permissions setup completed!" . PHP_EOL;
        }
    } catch (PDOException $e) {
        echo PHP_EOL;
        echo "WARNING during RBAC setup: " . $e->getMessage() . PHP_EOL;
        echo "This may be expected if permissions already exist." . PHP_EOL;
    }
}

echo PHP_EOL;
echo "==================================================================" . PHP_EOL;
echo "Script completed" . ($dryRun ? " (DRY-RUN)" : "") . PHP_EOL;
echo "==================================================================" . PHP_EOL;
echo PHP_EOL;

if ($dryRun) {
    echo "REMINDER: This was a dry-run. To execute, use:" . PHP_EOL;
    echo "  php scripts/apply_migration_and_permissions.php --yes" . PHP_EOL;
    echo PHP_EOL;
    echo "IMPORTANT: Always backup your database before running migrations!" . PHP_EOL;
    echo PHP_EOL;
}
