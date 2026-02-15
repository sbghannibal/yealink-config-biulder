<?php
/**
 * Database Configuration
 */

// Database credentials
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'admin_yealink');
define('DB_PASS', getenv('DB_PASS') ?: 'fpujknRqxQtmXDtq6x8m');
define('DB_NAME', getenv('DB_NAME') ?: 'admin_yealink');

// Create PDO connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Include other config files
include_once __DIR__ . '/constants.php';
?>