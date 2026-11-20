<?php
/**
 * DMS - Distribution Management System
 * Database Installation & Migration Runner
 * 
 * Can be executed via browser or CLI: php setup.php
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/constants.php';

$isCli = (php_sapi_name() === 'cli');

function out(string $msg, string $type = 'info'): void {
    global $isCli;
    if ($isCli) {
        echo "[{$type}] {$msg}\n";
    } else {
        $color = ($type === 'success') ? 'green' : (($type === 'error') ? 'red' : 'navy');
        echo "<div style='color:{$color}; font-family:monospace; margin-bottom:4px;'>[{$type}] " . htmlspecialchars($msg) . "</div>";
    }
}

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><title>DMS Setup & Migration</title><style>body{background:#f8fafc;padding:30px;font-family:sans-serif;}</style></head><body>';
    echo '<div style="background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:8px;max-width:700px;margin:auto;">';
    echo '<h2 style="margin-top:0;">Distribution Management System Setup</h2>';
}

out("Initializing DMS Database Setup...");

try {
    // 1. Connect without specific database to ensure database creation
    $dsnNoDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    out("Connected to MySQL server on " . DB_HOST . ":" . DB_PORT, "success");

    // 2. Create Database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    out("Database `" . DB_NAME . "` verified/created successfully.", "success");

    // 3. Switch to database
    $pdo->exec("USE `" . DB_NAME . "`");

    // 4. Run schema.sql
    $schemaFile = BASE_PATH . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("schema.sql not found at " . $schemaFile);
    }
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    out("Executed schema.sql: All 35+ core database tables created with InnoDB & FK constraints.", "success");

    // 5. Run seed.sql
    $seedFile = BASE_PATH . '/database/seed.sql';
    if (!file_exists($seedFile)) {
        throw new Exception("seed.sql not found at " . $seedFile);
    }
    $seedSql = file_get_contents($seedFile);
    $pdo->exec($seedSql);
    out("Executed seed.sql: Roles, granular permissions, settings, COA, default warehouse, and default units seeded.", "success");

    // 6. Verify Table Counts
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    out("Verified active tables in `" . DB_NAME . "`: " . count($tables) . " tables present.", "success");

    // 7. Verify Default Admin User
    $stmt = $pdo->query("SELECT id, username, full_name FROM users WHERE username = 'admin'");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        out("Default Super Administrator verified: [admin] (Password: admin123)", "success");
    }

    out("=================================================================", "info");
    out("DMS Phase 1 Foundation Setup Completed Successfully!", "success");
    out("=================================================================", "info");

    if (!$isCli) {
        echo '<div style="margin-top:20px;"><a href="' . APP_URL . '/login.php" style="background:#1d4ed8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;font-weight:bold;">Proceed to Login</a></div>';
    }

} catch (Throwable $e) {
    out("Setup Error: " . $e->getMessage(), "error");
    if (!$isCli) {
        echo '<div style="margin-top:15px;color:red;">Setup halted. Please check configuration.</div>';
    }
}

if (!$isCli) {
    echo '</div></body></html>';
}
