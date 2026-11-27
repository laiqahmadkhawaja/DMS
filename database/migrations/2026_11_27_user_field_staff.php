<?php
/**
 * Migration: Add user_id to salesmen and bookers tables
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    echo "Running migration: Add user_id to salesmen and bookers...\n";

    // 1. Check & Add user_id to salesmen
    $chkSm = db_fetch_all("SHOW COLUMNS FROM salesmen LIKE 'user_id'");
    if (empty($chkSm)) {
        db()->exec("ALTER TABLE salesmen ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_sm_user (user_id)");
        echo "  [+] Added user_id to salesmen table\n";
    } else {
        echo "  [.] user_id already exists in salesmen\n";
    }

    // 2. Check & Add user_id to bookers
    $chkBk = db_fetch_all("SHOW COLUMNS FROM bookers LIKE 'user_id'");
    if (empty($chkBk)) {
        db()->exec("ALTER TABLE bookers ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_bk_user (user_id)");
        echo "  [+] Added user_id to bookers table\n";
    } else {
        echo "  [.] user_id already exists in bookers\n";
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
