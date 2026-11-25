<?php
/**
 * DMS - Distribution Management System
 * Migration: 2026-11-25 Server Updates & Schema Synchronization
 * 
 * Safely applies all changes from November 25, 2026:
 * - Makes orders.delivery_date nullable (prevents integrity constraint errors on order booking)
 * - Ensures all standard distribution units (CTN, BOX, PCK, PCS) exist in units table
 * - Fixes legacy carton records where unit_id was saved as piece (4) instead of carton (1)
 * - Adds pack_qty and unit breakdown columns to sales_return_items and purchase_order_items
 * - Adds delivery_runs and delivery_run_bills columns for route delivery & credit recovery sheets
 * 
 * Safe for production: zero data loss, idempotent, foreign key safe.
 * Run via CLI: php database/migrations/2026_11_25_server_updates.php
 * Run via Browser: http://<domain>/DMS/database/migrations/2026_11_25_server_updates.php
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>DMS Migration - 2026-11-25</title>';
    echo '<style>body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f8fafc;padding:30px;color:#1e293b;}
          .card{background:#fff;border-radius:10px;box-shadow:0 4px 15px rgba(0,0,0,0.08);max-width:850px;margin:0 auto;padding:25px;}
          h2{color:#0f172a;margin-top:0;border-bottom:2px solid #e2e8f0;padding-bottom:12px;}
          .log{padding:8px 12px;margin-bottom:6px;border-radius:6px;font-size:13.5px;font-family:monospace;}
          .log-success{background:#ecfdf5;color:#065f46;border-left:4px solid #10b981;}
          .log-warning{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b;}
          .log-info{background:#eff6ff;color:#1e40af;border-left:4px solid #3b82f6;}
          .log-error{background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444;}
          .summary{margin-top:20px;padding:15px;background:#f1f5f9;border-radius:8px;font-weight:600;}
          </style></head><body><div class="card"><h2>🚀 DMS Server Migration (2026-11-25 Updates)</h2>';
}

function log_step($status, $msg) {
    global $isCli;
    $time = date('H:i:s');
    if ($isCli) {
        $tag = ($status === 'success') ? '[SUCCESS]' : (($status === 'warning') ? '[SKIP/EXIST]' : (($status === 'error') ? '[ERROR]' : '[INFO]'));
        echo "{$time} {$tag} {$msg}\n";
    } else {
        $cls = 'log-' . $status;
        echo "<div class='log {$cls}'><strong>{$time}</strong> &bull; " . htmlspecialchars($msg) . "</div>";
        flush();
    }
}

try {
    $db = Database::getConnection();

    // Disable foreign key checks for safety
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("SET NAMES utf8mb4;");
    log_step('info', 'Disabled foreign key checks during migration.');

    // Helper: Safely add column if not exists
    $safeAddColumn = function($table, $column, $definition) use ($db) {
        $check = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            log_step('success', "Added column `{$table}`.`{$column}`");
            return true;
        } else {
            log_step('warning', "Column `{$table}`.`{$column}` already exists.");
            return false;
        }
    };

    // --------------------------------------------------------------------------
    // 0. USERS TABLE SECURITY & LOCKOUT COLUMNS
    // --------------------------------------------------------------------------
    log_step('info', 'Step 0: Verifying `users` security and lockout columns...');
    $safeAddColumn('users', 'phone', "VARCHAR(25) DEFAULT NULL AFTER `full_name`");
    $safeAddColumn('users', 'status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `phone`");
    $safeAddColumn('users', 'last_login_at', "DATETIME DEFAULT NULL AFTER `status`");
    $safeAddColumn('users', 'login_attempts', "INT NOT NULL DEFAULT 0 AFTER `last_login_at`");
    $safeAddColumn('users', 'locked_until', "DATETIME DEFAULT NULL AFTER `login_attempts`");
    $safeAddColumn('users', 'is_deleted', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `locked_until`");

    // --------------------------------------------------------------------------
    // 1. ORDERS TABLE
    // --------------------------------------------------------------------------
    log_step('info', 'Step 1: Updating `orders` table constraints...');
    $db->exec("ALTER TABLE `orders` MODIFY COLUMN `delivery_date` DATE NULL DEFAULT NULL;");
    log_step('success', 'Ensured `orders`.`delivery_date` is nullable (allows booking without mandatory delivery date).');

    // --------------------------------------------------------------------------
    // 2. UNITS TABLE (STANDARD DISTRIBUTION UNITS)
    // --------------------------------------------------------------------------
    log_step('info', 'Step 2: Checking standard packaging units in `units` table...');
    $db->exec("
        INSERT INTO `units` (`id`, `unit_code`, `unit_name`, `symbol`, `is_active`, `is_deleted`)
        VALUES 
            (1, 'CTN', 'Carton', 'Ctn', 1, 0),
            (2, 'BOX', 'Box', 'Box', 1, 0),
            (3, 'PCK', 'Packet', 'Pck', 1, 0),
            (4, 'PCS', 'Piece', 'Pcs', 1, 0)
        ON DUPLICATE KEY UPDATE 
            `unit_code` = VALUES(`unit_code`),
            `unit_name` = VALUES(`unit_name`),
            `symbol`    = VALUES(`symbol`),
            `is_active` = 1,
            `is_deleted` = 0;
    ");
    log_step('success', 'Verified standard packaging units (Carton, Box, Packet/Pack, Piece) in `units`.');

    // --------------------------------------------------------------------------
    // 3. CORRECT LEGACY CARTON UNIT_ID (FIX 4 -> 1)
    // --------------------------------------------------------------------------
    log_step('info', 'Step 3: Correcting legacy carton items saved with piece unit_id (4 -> 1)...');
    $stmt1 = $db->exec("UPDATE `order_items` SET `unit_id` = 1 WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;");
    $stmt2 = $db->exec("UPDATE `sales_invoice_items` SET `unit_id` = 1 WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;");
    $stmt3 = $db->exec("UPDATE `purchase_order_items` SET `unit_id` = 1 WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;");
    $stmt4 = $db->exec("UPDATE `sales_return_items` SET `unit_id` = 1 WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;");
    log_step('success', "Corrected legacy carton unit_id: order_items ($stmt1), invoice_items ($stmt2), purchase_items ($stmt3), return_items ($stmt4).");

    // --------------------------------------------------------------------------
    // 4. SALES RETURNS & SALES RETURN ITEMS
    // --------------------------------------------------------------------------
    log_step('info', 'Step 4: Verifying `sales_return_items` packaging breakdown columns...');
    $safeAddColumn('sales_return_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_id`");
    $safeAddColumn('sales_return_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `carton_qty`");
    $safeAddColumn('sales_return_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_qty`");
    $safeAddColumn('sales_return_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `rate`");

    // --------------------------------------------------------------------------
    // 5. PURCHASE ORDER ITEMS
    // --------------------------------------------------------------------------
    log_step('info', 'Step 5: Verifying `purchase_order_items` packaging breakdown columns...');
    $safeAddColumn('purchase_order_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_id`");
    $safeAddColumn('purchase_order_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `carton_qty`");
    $safeAddColumn('purchase_order_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_qty`");
    $safeAddColumn('purchase_order_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `rate`");

    // --------------------------------------------------------------------------
    // 6. DELIVERY RUNS & MANUAL CREDIT / RECOVERY SHEETS
    // --------------------------------------------------------------------------
    log_step('info', 'Step 6: Verifying `delivery_runs` and `delivery_run_bills` schema...');
    $safeAddColumn('delivery_runs', 'area_id', "INT(10) UNSIGNED DEFAULT NULL AFTER `salesman_id`");
    $safeAddColumn('delivery_runs', 'run_type', "VARCHAR(20) NOT NULL DEFAULT 'load_sheet' AFTER `load_sheet_id`");

    // Allow null invoice_id in delivery_run_bills for manual customer balance recovery sheets
    $db->exec("ALTER TABLE `delivery_run_bills` MODIFY COLUMN `invoice_id` BIGINT(20) UNSIGNED DEFAULT NULL;");
    log_step('success', 'Ensured `delivery_run_bills`.`invoice_id` is nullable (supports manual recovery sheets).');

    $safeAddColumn('delivery_run_bills', 'customer_id', "INT(10) UNSIGNED DEFAULT NULL AFTER `invoice_id`");
    $safeAddColumn('delivery_run_bills', 'bill_number', "VARCHAR(50) DEFAULT NULL AFTER `customer_id`");
    $safeAddColumn('delivery_run_bills', 'prev_balance', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `bill_number`");
    $safeAddColumn('delivery_run_bills', 'bill_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `prev_balance`");
    $safeAddColumn('delivery_run_bills', 'total_due', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `bill_amount`");

    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    log_step('info', 'Re-enabled foreign key checks.');
    log_step('success', 'All 2026-11-25 server updates completed successfully with ZERO data loss!');

    if (!$isCli) {
        echo '<div class="summary" style="color:#065f46;background:#d1fae5;border:1px solid #a7f3d0;">
                ✔ Migration completed successfully! Your live server database is completely up to date.
              </div></div></body></html>';
    }

} catch (Exception $e) {
    if (isset($db)) {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }
    log_step('error', 'Migration failed: ' . $e->getMessage());
    if (!$isCli) {
        echo '<div class="summary" style="color:#991b1b;background:#fee2e2;border:1px solid #fecaca;">
                ✖ Migration Error: ' . htmlspecialchars($e->getMessage()) . '
              </div></div></body></html>';
    }
}
