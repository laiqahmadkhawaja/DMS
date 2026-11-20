<?php
/**
 * DMS - Phase 5 Comprehensive Automated Verification Suite
 * Tests Financials, Expenses, Customer Receipts, Supplier Payments, Chart of Accounts, and General Ledger
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/database_helpers.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permission.php';
require_once __DIR__ . '/includes/accounting_functions.php';

echo "========================================================\n";
echo "DMS PHASE 5 AUTOMATED VERIFICATION TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(string $testName, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $testName . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $testName . ($detail ? " => " . $detail : "") . "\n";
        $failCount++;
    }
}

try {
    $db = db();

    // -------------------------------------------------------------
    // TEST 1: Database tables check
    // -------------------------------------------------------------
    $expectedTables = ['accounts', 'cash_accounts', 'journal_entries', 'journal_entry_lines', 'customer_payments', 'supplier_payments', 'expenses', 'expense_categories'];
    foreach ($expectedTables as $t) {
        $count = $db->query("SHOW TABLES LIKE '{$t}'")->rowCount();
        assert_test("Database table '{$t}' exists", $count === 1);
    }

    // -------------------------------------------------------------
    // TEST 2: Chart of Accounts Seed Integrity
    // -------------------------------------------------------------
    $coreAccounts = [ACC_CASH_MAIN, ACC_PETTY_CASH, ACC_BANK_MAIN, ACC_RECEIVABLES, ACC_INVENTORY, ACC_PAYABLES, ACC_CAPITAL, ACC_SALES, ACC_COGS, 6010, 6020, 6030, 6040, 6050];
    $foundAccounts = $db->query("SELECT id FROM accounts WHERE id IN (" . implode(',', $coreAccounts) . ")")->fetchAll(PDO::FETCH_COLUMN);
    assert_test("Core system accounts seeded (14 core accounts)", count($foundAccounts) === count($coreAccounts));

    // -------------------------------------------------------------
    // TEST 3: Record Operating Expense (Fuel Rs. 3,500)
    // -------------------------------------------------------------
    $db->beginTransaction();
    $cashBefore = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CASH_MAIN]);
    $fuelBefore = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = 6010");

    $expenseNumber = generate_document_number(PREFIX_EXPENSE, 'expenses', 'expense_number');
    $stmtExp = $db->prepare("
        INSERT INTO expenses (
            expense_number, expense_date, expense_category_id, amount,
            paid_from_account_id, payment_mode, payee_name, description,
            reference_voucher, created_by, created_at
        ) VALUES (
            ?, CURDATE(), 1, 3500.00,
            ?, 'cash', 'PSO Petrol Pump GT Road', '20L Diesel for Delivery Van',
            'PSO-9842', 1, NOW()
        )
    ");
    $stmtExp->execute([$expenseNumber, ACC_CASH_MAIN]);
    $expenseId = (int)$db->lastInsertId();

    $jvExpId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'expense',
        'reference_id'   => $expenseId,
        'narration'      => "Expense: Fuel & Transport | 20L Diesel for Delivery Van"
    ], [
        [
            'account_id'  => 6010,
            'debit'       => 3500.00,
            'credit'      => 0.00,
            'description' => "Diesel fuel delivery van",
            'party_type'  => null,
            'party_id'    => null
        ],
        [
            'account_id'  => ACC_CASH_MAIN,
            'debit'       => 0.00,
            'credit'      => 3500.00,
            'description' => "Paid from Main Cash Drawer",
            'party_type'  => null,
            'party_id'    => null
        ]
    ]);
    $db->commit();

    assert_test("Expense record inserted with valid ID", $expenseId > 0 && str_starts_with($expenseNumber, 'EXP-'));
    assert_test("Expense posted journal voucher created", $jvExpId > 0);

    $cashAfter = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CASH_MAIN]);
    $fuelAfter = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = 6010");

    assert_test("Cash account decreased by expense amount (Rs. 3,500)", abs(($cashBefore - 3500.00) - $cashAfter) < 0.01, "Expected " . ($cashBefore - 3500) . " got {$cashAfter}");
    assert_test("Fuel expense account increased by Rs. 3,500", abs(($fuelBefore + 3500.00) - $fuelAfter) < 0.01, "Expected " . ($fuelBefore + 3500) . " got {$fuelAfter}");

    // -------------------------------------------------------------
    // TEST 4: Customer Payment Receipt (Recovery Rs. 5,000)
    // -------------------------------------------------------------
    $customer = db_fetch_one("SELECT id, shop_name, current_balance FROM customers LIMIT 1");
    $custBefore = (float)$customer['current_balance'];
    $cashBeforeCust = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CASH_MAIN]);

    $db->beginTransaction();
    $custPayNum = generate_document_number(PREFIX_PAYMENT, 'customer_payments', 'payment_number');
    $stmtCp = $db->prepare("
        INSERT INTO customer_payments (
            payment_number, payment_date, customer_id, salesman_id,
            amount, payment_mode, account_id, reference_no,
            remarks, created_by, created_at
        ) VALUES (
            ?, CURDATE(), ?, NULL,
            5000.00, 'cash', ?, 'REC-5001',
            'Market recovery partial payment', 1, NOW()
        )
    ");
    $stmtCp->execute([$custPayNum, $customer['id'], ACC_CASH_MAIN]);
    $custPaymentId = (int)$db->lastInsertId();

    update_customer_balance($customer['id'], 5000.00, 'decrease');

    $jvCpId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'cash_receipt',
        'reference_id'   => $custPaymentId,
        'narration'      => "Customer Payment: {$customer['shop_name']}"
    ], [
        [
            'account_id'  => ACC_CASH_MAIN,
            'debit'       => 5000.00,
            'credit'      => 0.00,
            'description' => "Cash received from {$customer['shop_name']}",
            'party_type'  => null,
            'party_id'    => null
        ],
        [
            'account_id'  => ACC_RECEIVABLES,
            'debit'       => 0.00,
            'credit'      => 5000.00,
            'description' => "Credit reduction for {$customer['shop_name']}",
            'party_type'  => 'customer',
            'party_id'    => $customer['id']
        ]
    ]);
    $db->commit();

    $custAfter = (float)db_fetch_val("SELECT current_balance FROM customers WHERE id = ?", [$customer['id']]);
    $cashAfterCust = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CASH_MAIN]);

    assert_test("Customer payment recorded with valid ID", $custPaymentId > 0 && str_starts_with($custPayNum, 'PAY-'));
    assert_test("Customer accounts receivable balance decreased by Rs. 5,000", abs(($custBefore - 5000.00) - $custAfter) < 0.01);
    assert_test("Office Cash Drawer increased by recovered Rs. 5,000", abs(($cashBeforeCust + 5000.00) - $cashAfterCust) < 0.01);

    // -------------------------------------------------------------
    // TEST 5: Supplier Payment Voucher (Factory Rs. 20,000)
    // -------------------------------------------------------------
    $supplier = db_fetch_one("SELECT id, supplier_name, current_balance FROM suppliers LIMIT 1");
    $suppBefore = (float)$supplier['current_balance'];
    $bankBefore = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_BANK_MAIN]);

    $db->beginTransaction();
    $suppPayNum = generate_document_number(PREFIX_PAYMENT, 'supplier_payments', 'payment_number');
    $stmtSp = $db->prepare("
        INSERT INTO supplier_payments (
            payment_number, payment_date, supplier_id,
            amount, payment_mode, account_id, reference_no,
            remarks, created_by, created_at
        ) VALUES (
            ?, CURDATE(), ?,
            20000.00, 'bank_transfer', ?, 'IBFT-84920',
            'Online payment against biscuit shipment', 1, NOW()
        )
    ");
    $stmtSp->execute([$suppPayNum, $supplier['id'], ACC_BANK_MAIN]);
    $suppPaymentId = (int)$db->lastInsertId();

    update_supplier_balance($supplier['id'], 20000.00, 'decrease');

    $jvSpId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'supplier_payment',
        'reference_id'   => $suppPaymentId,
        'narration'      => "Supplier Payment: {$supplier['supplier_name']}"
    ], [
        [
            'account_id'  => ACC_PAYABLES,
            'debit'       => 20000.00,
            'credit'      => 0.00,
            'description' => "Payment to {$supplier['supplier_name']}",
            'party_type'  => 'supplier',
            'party_id'    => $supplier['id']
        ],
        [
            'account_id'  => ACC_BANK_MAIN,
            'debit'       => 0.00,
            'credit'      => 20000.00,
            'description' => "Bank transfer to {$supplier['supplier_name']}",
            'party_type'  => null,
            'party_id'    => null
        ]
    ]);
    $db->commit();

    $suppAfter = (float)db_fetch_val("SELECT current_balance FROM suppliers WHERE id = ?", [$supplier['id']]);
    $bankAfter = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_BANK_MAIN]);

    assert_test("Supplier payment recorded with valid ID", $suppPaymentId > 0 && str_starts_with($suppPayNum, 'PAY-'));
    assert_test("Supplier accounts payable balance decreased by Rs. 20,000", abs(($suppBefore - 20000.00) - $suppAfter) < 0.01);
    assert_test("Bank account decreased by Rs. 20,000", abs(($bankBefore - 20000.00) - $bankAfter) < 0.01);

    // -------------------------------------------------------------
    // TEST 6: Manual Journal Voucher (Capital Injection Rs. 150,000)
    // -------------------------------------------------------------
    $db->beginTransaction();
    $bankBeforeCap = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_BANK_MAIN]);
    $capBefore = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CAPITAL]);

    $jvManualId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'manual_journal',
        'reference_id'   => null,
        'narration'      => "Owner capital addition deposited into Bank Al-Habib account"
    ], [
        [
            'account_id'  => ACC_BANK_MAIN,
            'debit'       => 150000.00,
            'credit'      => 0.00,
            'description' => "Capital funds deposited",
            'party_type'  => null,
            'party_id'    => null
        ],
        [
            'account_id'  => ACC_CAPITAL,
            'debit'       => 0.00,
            'credit'      => 150000.00,
            'description' => "Proprietor capital addition",
            'party_type'  => null,
            'party_id'    => null
        ]
    ]);
    $db->commit();

    $bankAfterCap = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_BANK_MAIN]);
    $capAfter = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CAPITAL]);

    assert_test("Manual Journal Voucher posted successfully", $jvManualId > 0);
    assert_test("Bank asset increased by Rs. 150,000 Debit", abs(($bankBeforeCap + 150000.00) - $bankAfterCap) < 0.01);
    assert_test("Owner Capital increased by Rs. 150,000 Credit", abs(($capBefore + 150000.00) - $capAfter) < 0.01);

    // -------------------------------------------------------------
    // TEST 7: Double-Entry Unbalanced Exception Validation
    // -------------------------------------------------------------
    $unbalancedCaught = false;
    try {
        post_journal_entry([
            'entry_date'     => date('Y-m-d'),
            'reference_type' => 'manual_journal',
            'reference_id'   => null,
            'narration'      => "Should fail - unbalanced"
        ], [
            [
                'account_id'  => ACC_CASH_MAIN,
                'debit'       => 1000.00,
                'credit'      => 0.00,
                'description' => "Debit"
            ],
            [
                'account_id'  => ACC_CAPITAL,
                'debit'       => 0.00,
                'credit'      => 900.00, // Imbalance of 100
                'description' => "Credit"
            ]
        ]);
    } catch (Exception $e) {
        $unbalancedCaught = true;
    }
    assert_test("Unbalanced journal entry strictly rejected by accounting engine", $unbalancedCaught);

    // -------------------------------------------------------------
    // TEST 8: General Ledger Statement Calculation
    // -------------------------------------------------------------
    $ledger = get_account_ledger(ACC_CASH_MAIN, date('Y-m-01'), date('Y-m-d'));
    assert_test("General Ledger calculation returns valid statement structure", 
        isset($ledger['account'], $ledger['lines'], $ledger['total_debit'], $ledger['total_credit'], $ledger['closing_balance']));
    assert_test("General Ledger running balance equals closing balance", 
        !empty($ledger['lines']) && abs(end($ledger['lines'])['running_balance'] - $ledger['closing_balance']) < 0.01);

    // -------------------------------------------------------------
    // TEST 9: Global General Ledger Zero Imbalance Audit
    // -------------------------------------------------------------
    $globalAudit = db_fetch_one("
        SELECT 
            COALESCE(SUM(total_debit), 0) as grand_debit,
            COALESCE(SUM(total_credit), 0) as grand_credit
        FROM journal_entries
        WHERE is_posted = 1
    ");
    $globalImbalance = abs((float)$globalAudit['grand_debit'] - (float)$globalAudit['grand_credit']);
    assert_test("Global General Ledger Zero Imbalance (Grand Debit == Grand Credit)", $globalImbalance < 0.01, "Imbalance: Rs. " . number_format($globalImbalance, 2));

} catch (Exception $e) {
    echo " [EXCEPTION] " . $e->getMessage() . "\n";
    $failCount++;
}

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Passed: {$passCount} | Failed: {$failCount}\n";
echo "--------------------------------------------------------\n";

if ($failCount === 0) {
    echo ">>> PHASE 5 VERIFICATION 100% COMPLETE AND PASSING <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. INVESTIGATION REQUIRED <<<\n";
    exit(1);
}
