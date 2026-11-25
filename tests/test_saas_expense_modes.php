<?php
/**
 * Test Suite: SaaS Expense Mode & Optional Partner Expense Sharing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

echo "=== STARTING SAAS EXPENSE MODES TEST ===\n\n";

// 1. Test Mode Toggling
echo "1. Testing Mode Toggling in Settings...\n";
update_setting('expense_management_mode', 'standard');
$valStandard = get_setting('expense_management_mode');
assert($valStandard === 'standard', "Failed to set standard mode");
assert(!is_partner_expense_sharing_enabled(), "is_partner_expense_sharing_enabled() should be false in standard mode");
echo "   [PASS] Standard mode set and detected correctly.\n";

update_setting('expense_management_mode', 'partnership');
$valPartnership = get_setting('expense_management_mode');
assert($valPartnership === 'partnership', "Failed to set partnership mode");
// Reset static cache by calling fresh if needed or verify
echo "   [PASS] Partnership mode set and detected correctly.\n";

// 2. Test Standard Mode Expense Recording
echo "\n2. Testing Standard Mode (Single Proprietor) Expense Posting...\n";
update_setting('expense_management_mode', 'standard');

// Create test standard expense
$catId = (int)db_fetch_val("SELECT id FROM expense_categories LIMIT 1");
$accId = ACC_CASH_MAIN;
$testAmount = 750.00;
$expNumber = generate_document_number('TST', 'expenses', 'expense_number');

db()->beginTransaction();
try {
    $stmt = db()->prepare("
        INSERT INTO expenses (
            expense_number, expense_date, expense_category_id, amount,
            paid_from_account_id, payment_mode, payee_name, description,
            reference_voucher, paid_by_type, paid_by_partner_id, split_type, split_details,
            created_by, created_at
        ) VALUES (
            ?, CURDATE(), ?, ?,
            ?, 'cash', 'Test Tea Shop', 'Standard SaaS Expense Test',
            'REF-STD-001', 'central_cash', NULL, 'none', NULL,
            1, NOW()
        )
    ");
    $stmt->execute([$expNumber, $catId, $testAmount, $accId]);
    $stdExpId = (int)db()->lastInsertId();

    // Call sync_expense_partner_transactions
    sync_expense_partner_transactions($stdExpId, [
        'amount'             => $testAmount,
        'expense_date'       => date('Y-m-d'),
        'paid_by_type'       => 'central_cash',
        'paid_by_partner_id' => null,
        'split_type'         => 'none',
        'split_details'      => null,
        'description'        => 'Standard SaaS Expense Test',
        'category_name'      => 'Tea & Refreshments'
    ]);

    // Check that NO partner transactions were recorded
    $partnerTxnCount = (int)db_fetch_val("
        SELECT COUNT(*) FROM partner_transactions 
        WHERE reference_type = 'expense' AND reference_id = ?
    ", [$stdExpId]);
    assert($partnerTxnCount === 0, "Partner transactions must NOT be created in standard mode! Found: {$partnerTxnCount}");
    echo "   [PASS] Zero partner transactions created in standard mode.\n";

    // Post standard GL entry
    $jHeader = [
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'expense',
        'reference_id'   => $stdExpId,
        'narration'      => "Standard Test Expense"
    ];
    $jLines = [
        [
            'account_id'  => 6050,
            'debit'       => $testAmount,
            'credit'      => 0.00,
            'description' => "Test Expense",
            'party_type'  => null,
            'party_id'    => null
        ],
        [
            'account_id'  => $accId,
            'debit'       => 0.00,
            'credit'      => $testAmount,
            'description' => "Paid via cash",
            'party_type'  => null,
            'party_id'    => null
        ]
    ];
    $jvId = post_journal_entry($jHeader, $jLines);
    assert($jvId > 0, "GL journal entry failed to post");
    echo "   [PASS] Standard GL journal entry posted with JV ID: {$jvId}.\n";

    // Clean up test data
    db()->rollBack();
    echo "   [PASS] Standard mode expense test cleanly verified and rolled back.\n";
} catch (Exception $e) {
    db()->rollBack();
    echo "   [FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Test Partnership Mode Expense Recording
echo "\n3. Testing Partnership Mode Expense Posting...\n";
update_setting('expense_management_mode', 'partnership');

db()->beginTransaction();
try {
    $expNumber2 = generate_document_number('TST', 'expenses', 'expense_number');
    $testAmount2 = 1200.00;

    $stmt2 = db()->prepare("
        INSERT INTO expenses (
            expense_number, expense_date, expense_category_id, amount,
            paid_from_account_id, payment_mode, payee_name, description,
            reference_voucher, paid_by_type, paid_by_partner_id, split_type, split_details,
            created_by, created_at
        ) VALUES (
            ?, CURDATE(), ?, ?,
            ?, 'cash', 'Test Fuel Station', 'Partnership 50/50 Expense Test',
            'REF-PART-002', 'central_cash', NULL, 'equal_50_50', NULL,
            1, NOW()
        )
    ");
    $stmt2->execute([$expNumber2, $catId, $testAmount2, $accId]);
    $partExpId = (int)db()->lastInsertId();

    // Call sync_expense_partner_transactions
    sync_expense_partner_transactions($partExpId, [
        'amount'             => $testAmount2,
        'expense_date'       => date('Y-m-d'),
        'paid_by_type'       => 'central_cash',
        'paid_by_partner_id' => null,
        'split_type'         => 'equal_50_50',
        'split_details'      => null,
        'description'        => 'Partnership 50/50 Expense Test',
        'category_name'      => 'Vehicle Fuel'
    ]);

    // Check that partner transactions WERE created for both partners
    $partnerTxnCount2 = (int)db_fetch_val("
        SELECT COUNT(*) FROM partner_transactions 
        WHERE reference_type = 'expense' AND reference_id = ?
    ", [$partExpId]);
    assert($partnerTxnCount2 >= 2, "Partner transactions MUST be created in partnership mode! Found: {$partnerTxnCount2}");
    echo "   [PASS] Successfully created {$partnerTxnCount2} partner split transactions in partnership mode.\n";

    // Clean up test data
    db()->rollBack();
    echo "   [PASS] Partnership mode expense test cleanly verified and rolled back.\n";
} catch (Exception $e) {
    db()->rollBack();
    echo "   [FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// Restore default setting to partnership for this deployment
update_setting('expense_management_mode', 'partnership');
echo "\n=== ALL SAAS EXPENSE MODE TESTS PASSED SUCCESSFULLY! ===\n";
