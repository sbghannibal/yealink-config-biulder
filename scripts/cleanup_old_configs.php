<?php
/**
 * Config Cleanup Script
 * Removes config versions older than 3 months
 */

require_once __DIR__ . '/../settings/database.php';

$dry_run = isset($argv[1]) && $argv[1] === '--dry-run';
$verbose = isset($argv[1]) && $argv[1] === '--verbose' || isset($argv[2]) && $argv[2] === '--verbose';

echo "\n=== Yealink Config Cleanup Script ===\n";
echo "Mode: " . ($dry_run ? "DRY RUN (no deletions)" : "LIVE") . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-3 months'));
    
    $stmt = $pdo->prepare('
        SELECT cv.id, cv.version_number, cv.created_at
        FROM config_versions cv
        LEFT JOIN device_config_assignments dca ON cv.id = dca.config_version_id
        WHERE cv.created_at < ?
        GROUP BY cv.id
        HAVING SUM(CASE WHEN dca.is_active = 1 THEN 1 ELSE 0 END) = 0
    ');
    $stmt->execute([$cutoff_date]);
    $configs_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($configs_to_delete) . " configs older than 3 months\n";
    
    if (empty($configs_to_delete)) {
        echo "No configs to clean up. Exiting.\n\n";
        exit(0);
    }
    
    $config_ids = array_column($configs_to_delete, 'id');
    
    if (!$dry_run) {
        $stmt = $pdo->prepare('
            INSERT INTO config_cleanup_logs (config_version_id, reason, cleaned_up_by)
            VALUES (?, ?, ?)
        ');
        
        foreach ($config_ids as $id) {
            $stmt->execute([$id, 'Automatic cleanup - older than 3 months', 1]);
        }
        
        $placeholders = implode(',', array_fill(0, count($config_ids), '?'));
        
        $stmt = $pdo->prepare("DELETE FROM device_config_assignments WHERE config_version_id IN ($placeholders)");
        $stmt->execute($config_ids);
        
        $stmt = $pdo->prepare("DELETE FROM config_versions WHERE id IN ($placeholders)");
        $stmt->execute($config_ids);
        
        echo "✓ Successfully deleted " . count($config_ids) . " config(s)\n";
    } else {
        echo "[DRY RUN] Would delete " . count($config_ids) . " config(s)\n";
    }
    
    echo "\n=== Cleanup Complete ===\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Config cleanup error: " . $e->getMessage());
    exit(1);
}
?>
