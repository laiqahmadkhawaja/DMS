<?php
/**
 * DMS - Distribution Management System
 * Database Transactional Data Reset Tool
 * 
 * Safely clears all operational transactional records (orders, invoices, settlements,
 * purchases, inventory movements, journal entries, customer/supplier transactions)
 * while PRESERVING master data (users, roles, permissions, chart of accounts,
 * areas, bookers, salesmen, customers, suppliers, products, categories, units, settings).
 * 
 * Usage:
 * - Via CLI: php database/reset_data.php --confirm
 * - Via Web: Must be logged in as Admin with role_id = 1 and pass ?confirm=yes
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/database_helpers.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once INCLUDES_PATH . '/auth.php';
    require_once INCLUDES_PATH . '/permission.php';
    require_login();
    if ($_SESSION['user']['role_id'] != 1) {
        exit('Access denied: Only System Administrators can perform data reset.');
    }
    if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
        exit('Confirmation required. Append ?confirm=yes to proceed.');
    }
} else {
    global $argv;
    if (!isset($argv[1]) || $argv[1] !== '--confirm') {
        echo "WARNING: This will permanently wipe all transactional data!\n";
        echo "To execute, run: php database/reset_data.php --confirm\n";
        exit(1);
    }
}

echo $isCli ? "Starting transactional data reset...\n" : "<h3>Starting transactional data reset...</h3><pre>\n";

$pdo = db();

try {
    $pdo->beginTransaction();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // List of transactional tables to truncate
    $tablesToTruncate = [
        'order_items',
        'orders',
        'sales_invoice_items',
        'invoice_discounts',
        'sales_invoices',
        'load_sheet_items',
        'load_sheet_orders',
        'load_sheets',
        'delivery_run_bills',
        'delivery_runs',
        'settlement_items',
        'cash_submissions',
        'settlements',
        'sales_return_items',
        'sales_returns',
        'purchase_order_items',
        'purchase_orders',
        'goods_receiving_items',
        'goods_receiving_notes',
        'supplier_invoice_items',
        'supplier_invoices',
        'expenses',
        'customer_payments',
        'supplier_payments',
        'stock_movements',
        'stock_balances',
        'inventory_adjustments',
        'inventory_adjustment_items',
        'journal_entry_lines',
        'journal_entries'
    ];

    foreach ($tablesToTruncate as $tbl) {
        // Check if table exists
        $check = $pdo->query("SHOW TABLES LIKE '{$tbl}'")->fetch();
        if ($check) {
            $pdo->exec("TRUNCATE TABLE `{$tbl}`");
            echo "Truncated table: {$tbl}\n";
        }
    }

    $pdo->exec("UPDATE customers SET current_balance = opening_balance");
    echo "Reset current_balance to opening_balance in customers.\n";

    $pdo->exec("UPDATE suppliers SET current_balance = opening_balance");
    echo "Reset current_balance to opening_balance in suppliers.\n";

    // Re-create initial opening balance journal entries if opening balances exist
    // Opening balance equity account (3010)
    $customersWithOpening = $pdo->query("SELECT id, shop_name, opening_balance FROM customers WHERE opening_balance > 0 AND is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($customersWithOpening as $c) {
        $opBal = (float)$c['opening_balance'];
        if ($opBal > 0) {
            // Debit Accounts Receivable 1020, Credit Opening Balance Equity 3010
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries (entry_number, entry_date, reference_type, reference_id, narration, created_by, created_at)
                VALUES (?, CURDATE(), 'opening', ?, ?, 1, NOW())
            ");
            $entryNum = 'JV-OP-' . date('Ymd') . '-' . str_pad($c['id'], 4, '0', STR_PAD_LEFT);
            $stmt->execute([$entryNum, $c['id'], "Opening balance for customer: " . $c['shop_name']]);
            $jvId = $pdo->lastInsertId();

            // Line 1: Debit AR 1020
            $accAR = $pdo->query("SELECT id FROM accounts WHERE account_code = '1020'")->fetchColumn();
            if ($accAR) {
                $pdo->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, party_type, party_id, created_at) VALUES (?, ?, ?, 0, ?, 'customer', ?, NOW())")
                    ->execute([$jvId, $accAR, $opBal, "Opening AR balance - " . $c['shop_name'], $c['id']]);
            }

            // Line 2: Credit Equity 3010
            $accEq = $pdo->query("SELECT id FROM accounts WHERE account_code = '3010'")->fetchColumn();
            if ($accEq) {
                $pdo->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, party_type, party_id, created_at) VALUES (?, ?, 0, ?, ?, NULL, NULL, NOW())")
                    ->execute([$jvId, $accEq, $opBal, "Opening equity offset - " . $c['shop_name']]);
            }
        }
    }
    echo "Re-established opening balance journal entries for customers.\n";

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    $pdo->commit();

    echo "\nSUCCESS: Transactional data reset complete. Masters and opening balances preserved.\n";
    if (!$isCli) {
        echo "</pre><p><a href='" . APP_URL . "/dashboard.php' class='btn btn-primary'>Return to Dashboard</a></p>";
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "\nERROR: Data reset failed: " . $e->getMessage() . "\n";
    if (!$isCli) {
        echo "</pre>";
    }
    exit(1);
}
