<?php
/**
 * DMS - Phase 1 Automated Verification Suite
 * Executes all Phase 1 gate tests via PHP CLI
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name} - Details: {$details}\n";
    }
}

echo "=================================================================\n";
echo "DMS PHASE 1 AUTOMATED VERIFICATION SUITE\n";
echo "=================================================================\n\n";

// 1. Database Connection
echo "1. Database Connection & Schema Verification:\n";
assert_test("PDO Connection Successful", Database::testConnection());

$tables = db_fetch_all("SHOW TABLES");
$tableNames = array_map(fn($t) => reset($t), $tables);
$coreTables = [
    'users', 'roles', 'permissions', 'role_permissions', 'user_roles', 'settings', 'audit_logs',
    'areas', 'bookers', 'salesmen', 'suppliers', 'products', 'product_categories', 'units', 'warehouses', 'customers',
    'orders', 'order_items', 'load_sheets', 'load_sheet_items', 'load_sheet_orders',
    'sales_invoices', 'sales_invoice_items', 'delivery_runs', 'delivery_run_bills',
    'settlements', 'settlement_items', 'cash_submissions', 'sales_returns', 'sales_return_items',
    'stock_movements', 'stock_balances', 'purchase_orders', 'goods_receipts',
    'accounts', 'journal_entries', 'journal_entry_lines', 'customer_payments', 'supplier_payments', 'expenses'
];

$missingTables = array_diff($coreTables, $tableNames);
assert_test("All 40 Core Tables Exist in Database", empty($missingTables), "Missing: " . implode(', ', $missingTables));

// 2. Settings & Business Configuration
echo "\n2. Configuration & Settings:\n";
$distName = get_setting('distributor_name');
assert_test("Client Name matches Master PRD (Muzzam Enterprises)", $distName === 'Muzzam Enterprises', "Got: {$distName}");
$currency = get_setting('currency_symbol', 'Rs.');
assert_test("Default Currency set to Rs.", $currency === 'Rs.', "Got: {$currency}");

// 3. User Authentication & Security
echo "\n3. Authentication & Rate Limiting:\n";
$loginSuccess = attempt_login('admin', 'admin123');
assert_test("Admin Login with 'admin123' succeeds", $loginSuccess['success']);
assert_test("Admin user session initialized", !empty($_SESSION['user_id']) && $_SESSION['username'] === 'admin');
assert_test("Admin possesses 'admin' role", in_array('admin', $_SESSION['roles'] ?? []));

$loginFail = attempt_login('admin', 'wrongpass999');
assert_test("Invalid password correctly rejected", !$loginFail['success']);

// 4. Permissions
echo "\n4. Permission Enforcement:\n";
assert_test("Superadmin has permission: orders_create", has_permission('orders_create'));
assert_test("Superadmin has permission: settlements_reconcile", has_permission('settlements_reconcile'));

// 5. Document Numbering Generator
echo "\n5. Document Numbering Generator:\n";
$ordNum = generate_document_number(PREFIX_ORDER, 'orders', 'order_number');
assert_test("Order Number Format Valid", (bool)preg_match('/^ORD-\d{4}-\d{5}$/', $ordNum), "Generated: {$ordNum}");

$invNum = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');
assert_test("Invoice Number Format Valid", (bool)preg_match('/^INV-\d{4}-\d{5}$/', $invNum), "Generated: {$invNum}");

$setNum = generate_document_number(PREFIX_SETTLEMENT, 'settlements', 'settlement_number');
assert_test("Settlement Number Format Valid", (bool)preg_match('/^SET-\d{4}-\d{5}$/', $setNum), "Generated: {$setNum}");

// 6. Double-Entry Accounting Engine
echo "\n6. Double-Entry Accounting Engine:\n";
try {
    $balancedEntryId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'manual_journal',
        'narration'      => 'Test Balanced Entry'
    ], [
        ['account_id' => ACC_CASH_MAIN, 'debit' => 5000.00, 'credit' => 0.00],
        ['account_id' => ACC_SALES,     'debit' => 0.00,    'credit' => 5000.00]
    ]);
    assert_test("Balanced Journal Entry (Debit 5000 == Credit 5000) Posted Successfully", $balancedEntryId > 0);
} catch (Exception $e) {
    assert_test("Balanced Journal Entry Posted Successfully", false, $e->getMessage());
}

// Test rejection of unbalanced entry
$unbalancedCaught = false;
try {
    post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'manual_journal',
        'narration'      => 'Test Unbalanced Entry'
    ], [
        ['account_id' => ACC_CASH_MAIN, 'debit' => 5000.00, 'credit' => 0.00],
        ['account_id' => ACC_SALES,     'debit' => 0.00,    'credit' => 4500.00] // Unbalanced by 500
    ]);
} catch (Exception $e) {
    $unbalancedCaught = true;
}
assert_test("Unbalanced Journal Entry (5000 vs 4500) Correctly Blocked & Thrown", $unbalancedCaught);

// 7. Audit Log
echo "\n7. Audit Logging:\n";
log_audit('test_audit', 'system_test', 1, 'Phase 1 verification test execution');
$auditLog = db_fetch_one("SELECT * FROM audit_logs WHERE action = 'test_audit' ORDER BY id DESC LIMIT 1");
assert_test("Audit Log recorded in database", !empty($auditLog) && $auditLog['action'] === 'test_audit');

// 8. Offline Asset Integrity
echo "\n8. Offline Vendor Assets (No CDN):\n";
assert_test("bootstrap.min.css exists locally", file_exists(BASE_PATH . '/assets/bootstrap/bootstrap.min.css'));
assert_test("bootstrap.bundle.min.js exists locally", file_exists(BASE_PATH . '/assets/bootstrap/bootstrap.bundle.min.js'));
assert_test("bootstrap-icons.css exists locally", file_exists(BASE_PATH . '/assets/icons/bootstrap-icons.css'));
assert_test("bootstrap-icons.woff2 exists locally", file_exists(BASE_PATH . '/assets/icons/fonts/bootstrap-icons.woff2'));

echo "\n=================================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo ">> PHASE 1 COMPLETION GATE PASSED WITH 100% SUCCESS <<\n";
    exit(0);
} else {
    echo ">> PHASE 1 COMPLETION GATE FAILED <<\n";
    exit(1);
}
