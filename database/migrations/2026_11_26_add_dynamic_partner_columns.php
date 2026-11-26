<?php
/**
 * Migration: Add dynamic partner sharing columns to `partners` table
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

echo "Running Migration: Add dynamic partner sharing columns...\n";

try {
    $existingCols = db_fetch_all("DESCRIBE partners");
    $colNames = array_column($existingCols, 'Field');

    if (!in_array('expense_share_type', $colNames)) {
        db()->exec("
            ALTER TABLE `partners` 
            ADD COLUMN `expense_share_type` ENUM('equal', 'percentage', 'fixed') NOT NULL DEFAULT 'equal' AFTER `current_balance`
        ");
        echo "  [SUCCESS] Added `expense_share_type` column.\n";
    }

    if (!in_array('expense_share_value', $colNames)) {
        db()->exec("
            ALTER TABLE `partners` 
            ADD COLUMN `expense_share_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `expense_share_type`
        ");
        echo "  [SUCCESS] Added `expense_share_value` column.\n";
    }

    if (!in_array('profit_share_percentage', $colNames)) {
        db()->exec("
            ALTER TABLE `partners` 
            ADD COLUMN `profit_share_percentage` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `expense_share_value`
        ");
        echo "  [SUCCESS] Added `profit_share_percentage` column.\n";
    }

    if (!in_array('opening_balance', $colNames)) {
        db()->exec("
            ALTER TABLE `partners` 
            ADD COLUMN `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `profit_share_percentage`
        ");
        echo "  [SUCCESS] Added `opening_balance` column.\n";
    }

    // Set sensible defaults for initial partners if not already configured
    db()->exec("
        UPDATE `partners` 
        SET `expense_share_type` = 'equal', `expense_share_value` = 50.00 
        WHERE `partner_code` IN ('PTR-MOAZZAM', 'PTR-REHMAN') AND `expense_share_value` = 0.00
    ");

    db()->exec("
        UPDATE `partners` 
        SET `expense_share_type` = 'fixed', `expense_share_value` = 0.00 
        WHERE `role` = 'managing_partner'
    ");

    echo "[MIGRATION COMPLETE] Dynamic partner sharing schema is ready.\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
