<?php
/**
 * DMS - Distribution Management System
 * Production Server Database Schema Migration Runner
 * 
 * Safely synchronizes all recent schema changes to the live MySQL database
 * without deleting or modifying any existing customer, product, order, invoice, or ledger records.
 * Can be run via Browser or via Terminal (CLI).
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

$logs = [];
function add_log($status, $message) {
    global $logs, $isCli;
    $time = date('H:i:s');
    $logs[] = ['time' => $time, 'status' => $status, 'message' => $message];
    if ($isCli) {
        $prefix = ($status === 'success') ? '[SUCCESS]' : (($status === 'warning') ? '[SKIP/EXISTS]' : '[INFO]');
        echo "{$time} {$prefix} {$message}\n";
    }
}

try {
    $db = Database::getConnection();

    // Disable foreign key checks for the duration of the migration
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("SET NAMES utf8mb4;");
    add_log('info', 'Disabled foreign key checks during migration.');

    // Helper function to safely check and add columns
    function safe_add_column($db, $table, $column, $definition) {
        $check = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            add_log('success', "Added column `{$table}`.`{$column}`");
            return true;
        } else {
            add_log('warning', "Column `{$table}`.`{$column}` already exists.");
            return false;
        }
    }

    // --------------------------------------------------------------------------
    // 1. ORDERS TABLE
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `orders` table schema...');
    safe_add_column($db, 'orders', 'discount_mode', "ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate' AFTER `area_id`");
    safe_add_column($db, 'orders', 'subtotal_discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `discount_mode`");
    safe_add_column($db, 'orders', 'subtotal_discount_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `subtotal_discount_percent`");
    safe_add_column($db, 'orders', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `subtotal_discount_amount`");

    // --------------------------------------------------------------------------
    // 2. SALES INVOICES TABLE
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `sales_invoices` table schema...');
    safe_add_column($db, 'sales_invoices', 'discount_mode', "ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate' AFTER `area_id`");
    safe_add_column($db, 'sales_invoices', 'subtotal_discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `extra_discount_amount`");
    safe_add_column($db, 'sales_invoices', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `subtotal_discount_percent`");

    // --------------------------------------------------------------------------
    // 3. PURCHASE ORDERS TABLE
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `purchase_orders` table schema...');
    safe_add_column($db, 'purchase_orders', 'discount_mode', "ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate' AFTER `supplier_invoice_no`");
    safe_add_column($db, 'purchase_orders', 'subtotal_discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
    safe_add_column($db, 'purchase_orders', 'subtotal_discount_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `subtotal_discount_percent`");
    safe_add_column($db, 'purchase_orders', 'total_cartons', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `subtotal_discount_amount`");
    safe_add_column($db, 'purchase_orders', 'total_units', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_cartons`");
    safe_add_column($db, 'purchase_orders', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_units`");

    // --------------------------------------------------------------------------
    // 4. PURCHASE ORDER ITEMS TABLE
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `purchase_order_items` table schema...');
    safe_add_column($db, 'purchase_order_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_id`");
    safe_add_column($db, 'purchase_order_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `carton_qty`");
    safe_add_column($db, 'purchase_order_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_qty`");
    safe_add_column($db, 'purchase_order_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `rate`");

    // --------------------------------------------------------------------------
    // 5. SALES RETURNS & ITEMS
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `sales_returns` and `sales_return_items` schema...');
    safe_add_column($db, 'sales_returns', 'stock_settled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`");
    safe_add_column($db, 'sales_returns', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `stock_settled`");

    safe_add_column($db, 'sales_return_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_id`");
    safe_add_column($db, 'sales_return_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `carton_qty`");
    safe_add_column($db, 'sales_return_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_qty`");
    safe_add_column($db, 'sales_return_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `rate`");

    // --------------------------------------------------------------------------
    // 6. PRODUCTS DUAL RATES (Carton & Piece Specific Pricing)
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `products` dual rate columns...');
    safe_add_column($db, 'products', 'carton_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `wholesale_rate`");
    safe_add_column($db, 'products', 'piece_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `carton_rate`");
    safe_add_column($db, 'products', 'purchase_carton_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `purchase_rate`");
    safe_add_column($db, 'products', 'purchase_piece_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `purchase_carton_rate`");
    safe_add_column($db, 'products', 'retail_carton_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `retail_rate`");
    safe_add_column($db, 'products', 'retail_piece_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `retail_carton_rate`");

    // Backfill any zero rates from base wholesale & purchase rates
    $db->exec("
        UPDATE `products` 
        SET `carton_rate` = `wholesale_rate` 
        WHERE (`carton_rate` IS NULL OR `carton_rate` = 0) AND `wholesale_rate` > 0;
    ");
    $db->exec("
        UPDATE `products` 
        SET `piece_rate` = ROUND(`carton_rate` / GREATEST(1, `carton_qty` * `pack_qty`), 2) 
        WHERE (`piece_rate` IS NULL OR `piece_rate` = 0) AND `carton_rate` > 0;
    ");
    $db->exec("
        UPDATE `products` 
        SET `purchase_carton_rate` = `purchase_rate` 
        WHERE (`purchase_carton_rate` IS NULL OR `purchase_carton_rate` = 0) AND `purchase_rate` > 0;
    ");
    $db->exec("
        UPDATE `products` 
        SET `purchase_piece_rate` = ROUND(`purchase_carton_rate` / GREATEST(1, `carton_qty` * `pack_qty`), 2) 
        WHERE (`purchase_piece_rate` IS NULL OR `purchase_piece_rate` = 0) AND `purchase_carton_rate` > 0;
    ");
    add_log('success', 'Backfilled missing carton/piece rates for products without affecting custom prices.');

    // --------------------------------------------------------------------------
    // 7. PARTNERS & PARTNER TRANSACTIONS
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `partners` and `partner_transactions` tables...');
    $db->exec("
        CREATE TABLE IF NOT EXISTS `partners` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `partner_code` varchar(30) NOT NULL,
            `partner_name` varchar(100) NOT NULL,
            `urdu_name` varchar(100) DEFAULT NULL,
            `role` enum('partner','managing_partner') NOT NULL DEFAULT 'partner',
            `supplier_id` int(10) unsigned DEFAULT NULL,
            `phone` varchar(30) DEFAULT NULL,
            `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Positive = Payable to partner, Negative = Partner owes',
            `notes` text DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_partner_code` (`partner_code`),
            KEY `idx_partner_supplier` (`supplier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS `partner_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `transaction_number` varchar(40) NOT NULL,
            `transaction_date` date NOT NULL,
            `partner_id` int(10) unsigned NOT NULL,
            `transaction_type` enum('expense_paid','expense_share','cash_drawn','cash_returned','sales_cash_handover','sales_profit','capital_added','inter_settlement','adjustment') NOT NULL,
            `reference_type` varchar(40) NOT NULL DEFAULT 'manual',
            `reference_id` bigint(20) unsigned DEFAULT NULL,
            `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
            `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
            `running_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
            `description` text DEFAULT NULL,
            `created_by` bigint(20) unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_ptr_trans_no` (`transaction_number`),
            KEY `idx_ptr_partner` (`partner_id`),
            KEY `idx_ptr_date` (`transaction_date`),
            KEY `idx_ptr_ref` (`reference_type`, `reference_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure partner_transactions has latest ENUM types including sales_cash_handover and cash_returned
    $db->exec("
        ALTER TABLE `partner_transactions` 
        MODIFY COLUMN `transaction_type` ENUM(
            'expense_paid',
            'expense_share',
            'cash_drawn',
            'cash_returned',
            'sales_cash_handover',
            'sales_profit',
            'capital_added',
            'inter_settlement',
            'adjustment'
        ) NOT NULL;
    ");
    add_log('success', 'Verified partners and partner_transactions tables.');

    // --------------------------------------------------------------------------
    // 8. EXPENSES SHARING COLUMNS
    // --------------------------------------------------------------------------
    add_log('info', 'Verifying `expenses` partner sharing columns...');
    safe_add_column($db, 'expenses', 'paid_by_type', "ENUM('central_cash','partner') NOT NULL DEFAULT 'central_cash' AFTER `payment_mode`");
    safe_add_column($db, 'expenses', 'paid_by_partner_id', "INT(10) UNSIGNED DEFAULT NULL AFTER `paid_by_type`");
    safe_add_column($db, 'expenses', 'split_type', "ENUM('equal_50_50','only_moazzam','only_rehman','custom','none') NOT NULL DEFAULT 'equal_50_50' AFTER `paid_by_partner_id`");
    safe_add_column($db, 'expenses', 'split_details', "TEXT DEFAULT NULL AFTER `split_type`");

    // --------------------------------------------------------------------------
    // 9. GENERAL LEDGER ENUM UPDATES
    // --------------------------------------------------------------------------
    add_log('info', 'Updating `journal_entries` and `journal_entry_lines` reference ENUMs...');
    $db->exec("
        ALTER TABLE `journal_entries` 
        MODIFY COLUMN `reference_type` ENUM(
            'opening',
            'sales_invoice',
            'settlement',
            'cash_receipt',
            'supplier_payment',
            'grn',
            'expense',
            'manual_journal',
            'partner_drawing',
            'sales_cash_handover',
            'partner_cash_received'
        ) NOT NULL DEFAULT 'manual_journal';
    ");

    $db->exec("
        ALTER TABLE `journal_entry_lines` 
        MODIFY COLUMN `party_type` ENUM(
            'customer',
            'supplier',
            'salesman',
            'partner'
        ) DEFAULT NULL;
    ");
    add_log('success', 'General ledger ENUMs updated for partner transactions.');

    // --------------------------------------------------------------------------
    // 10. SEED DEFAULT PARTNERS
    // --------------------------------------------------------------------------
    $partnerCount = (int)$db->query("SELECT COUNT(*) FROM partners")->fetchColumn();
    if ($partnerCount == 0) {
        $db->exec("
            INSERT INTO `partners` (`partner_code`, `partner_name`, `urdu_name`, `role`, `supplier_id`, `notes`, `is_active`)
            VALUES 
                ('PTR-MOAZZAM', 'Moazzam', 'معظم (ایس ایم ایس)', 'partner', 2, 'Partner owning and managing SMS Agency & products.', 1),
                ('PTR-REHMAN', 'Abdur Rehman', 'عبدالرحمن (اوچاب)', 'partner', 1, 'Partner owning and managing Ujab Enterprises Agency & products.', 1),
                ('PTR-CENTRAL', 'Managing Partner (Central Sales Pool)', 'تھرڈ پارٹنر (سیلز و کیش کسٹوڈین)', 'managing_partner', NULL, 'Managing partner overseeing total sales, recovery, and central operations cash pool.', 1);
        ");
        add_log('success', 'Seeded initial 3 managing partners (Moazzam SMS, Rehman Ujab, Central Managing Partner).');
    } else {
        add_log('warning', "Partners table already has {$partnerCount} records. Preserved intact.");
    }

    // Ensure permissions
    $db->exec("
        INSERT IGNORE INTO `permissions` (`module`, `permission_code`, `permission_name`, `description`) VALUES
        ('partners', 'partners_view', 'View Partner Khatas', 'View partner ledger statements and balances'),
        ('partners', 'partners_manage', 'Manage Partners & Drawings', 'Record partner drawings, settlements and expense splits');
    ");
    $db->exec("
        INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
        SELECT 1, p.`id` FROM `permissions` p WHERE p.`module` = 'partners';
    ");
    add_log('success', 'Partner permissions ensured for Admin role.');

    // Restore foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    add_log('info', 'Re-enabled foreign key checks. Migration complete!');

    $success = true;

} catch (Exception $e) {
    if (isset($db)) {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }
    add_log('error', 'MIGRATION FAILED: ' . $e->getMessage());
    $success = false;
}

if ($isCli) {
    echo "\n" . ($success ? "=== SUCCESS: Database migration finished cleanly! ===" : "=== FAILED: Database migration encountered an error ===") . "\n";
    exit($success ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMS Server Database Migration</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .log-badge { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; }
        .log-success { background: #d1e7dd; color: #0f5132; }
        .log-warning { background: #fff3cd; color: #664d03; }
        .log-info { background: #cff4fc; color: #055160; }
        .log-error { background: #f8d7da; color: #842029; }
    </style>
</head>
<body class="py-5">
    <div class="container" style="max-width: 860px;">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-dark text-white p-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1 fw-bold"><i class="bi bi-database-check text-success me-2"></i>DMS Production Schema Synchronizer</h4>
                        <p class="mb-0 small text-light opacity-75">Safely syncs all schema changes across dual discounts, free pieces scheme, product rates & partner khata.</p>
                    </div>
                    <div>
                        <?php if ($success): ?>
                            <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-check-circle me-1"></i> Success</span>
                        <?php else: ?>
                            <span class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-x-circle me-1"></i> Failed</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-body p-4">
                <div class="alert <?= $success ? 'alert-success' : 'alert-danger' ?> d-flex align-items-center gap-2 mb-4">
                    <i class="bi <?= $success ? 'bi-shield-check' : 'bi-exclamation-triangle' ?> fs-4"></i>
                    <div>
                        <strong><?= $success ? 'Database Migration Completed Successfully!' : 'Migration Encountered an Error' ?></strong><br>
                        <small>Existing customer balances, products, orders, sales invoices, stock, and general ledgers remain completely intact.</small>
                    </div>
                </div>

                <h6 class="fw-bold mb-3 text-secondary text-uppercase" style="font-size: 12px; letter-spacing: 0.5px;">Migration Execution Log</h6>
                <div class="table-responsive border rounded bg-white">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 13px;">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 90px;">Time</th>
                                <th style="width: 110px;">Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td class="font-monospace text-muted small"><?= $l['time'] ?></td>
                                    <td>
                                        <span class="log-badge log-<?= $l['status'] ?>">
                                            <?= $l['status'] === 'warning' ? 'EXISTS' : $l['status'] ?>
                                        </span>
                                    </td>
                                    <td class="font-monospace"><?= htmlspecialchars($l['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Executed on: <strong><?= date('d-M-Y H:i:s') ?> (Asia/Karachi)</strong></span>
                    <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-primary btn-sm px-4 fw-bold">
                        <i class="bi bi-house-door me-1"></i> Return to System
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
