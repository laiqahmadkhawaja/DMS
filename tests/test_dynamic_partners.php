<?php
/**
 * Test Suite: Dynamic Partner Management & Multi-Partner Expense Splitting
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/partner_functions.php';

echo "=== TESTING DYNAMIC MULTI-PARTNER SYSTEM ===\n\n";

db()->beginTransaction();

try {
    // 1. Test Creating a 3rd Equity Partner (e.g. Tariq)
    echo "[1] Testing create_partner():\n";
    $tariqId = create_partner([
        'partner_name'        => 'Tariq Mehmood',
        'urdu_name'           => 'طارق محمود (سپرنگ ایجنسی)',
        'role'                => 'partner',
        'supplier_id'         => null,
        'phone'               => '03009876543',
        'expense_share_type'  => 'equal',
        'expense_share_value' => 0.00,
        'opening_balance'     => 10000.00, // Brought 10,000 initial capital
        'notes'               => 'New partner joining equity pool.'
    ]);

    assert($tariqId > 0, "New partner ID must be > 0");
    $tariq = get_partner_by_id($tariqId);
    assert($tariq['partner_name'] === 'Tariq Mehmood', "Partner name mismatch");
    assert((float)$tariq['current_balance'] === 10000.00, "Opening balance should be 10000.00");
    echo "  [PASS] Successfully created Partner #{$tariqId}: {$tariq['partner_name']} with code {$tariq['partner_code']}\n";

    // 2. Test Equal Expense Split across 3 Partners (Moazzam, Rehman, Tariq)
    echo "\n[2] Testing calculate_expense_split() with 3 equity partners:\n";
    $split3 = calculate_expense_split(10000.00, 'equal_50_50');
    assert(count($split3) === 3, "Expected 3 shares, got: " . count($split3));
    $sum3 = array_sum($split3);
    assert(abs($sum3 - 10000.00) < 0.001, "Sum of 3 shares must equal 10000.00 exactly! Got: {$sum3}");
    echo "  3-Way Split: " . json_encode($split3) . " -> Sum: {$sum3}\n";
    echo "  [PASS] 3-partner equal division verified with zero penny discrepancy.\n";

    // 3. Test Percentage Split (50% Moazzam, 30% Rehman, 20% Tariq)
    echo "\n[3] Testing percentage-based expense splitting:\n";
    update_partner(1, ['expense_share_type' => 'percentage', 'expense_share_value' => 50.00]);
    update_partner(2, ['expense_share_type' => 'percentage', 'expense_share_value' => 30.00]);
    update_partner($tariqId, ['expense_share_type' => 'percentage', 'expense_share_value' => 20.00]);

    $splitPct = calculate_expense_split(10000.00, 'by_percentage');
    assert($splitPct[1] === 5000.00, "Partner 1 share should be 5000, got: " . ($splitPct[1] ?? 'nil'));
    assert($splitPct[2] === 3000.00, "Partner 2 share should be 3000, got: " . ($splitPct[2] ?? 'nil'));
    assert($splitPct[$tariqId] === 2000.00, "Tariq share should be 2000, got: " . ($splitPct[$tariqId] ?? 'nil'));
    $sumPct = array_sum($splitPct);
    assert(abs($sumPct - 10000.00) < 0.001, "Sum of percentage shares must equal 10000.00");
    echo "  Percentage Split: " . json_encode($splitPct) . " -> Sum: {$sumPct}\n";
    echo "  [PASS] Percentage division (50/30/20) verified.\n";

    // 4. Test Renaming Managing Partner
    echo "\n[4] Testing Managing Partner name update:\n";
    $mpBefore = get_managing_partner();
    assert($mpBefore !== null, "Managing partner must exist");
    $mpId = (int)$mpBefore['id'];

    $updated = update_partner($mpId, [
        'partner_name' => 'Chaudhry Akram Aslam',
        'urdu_name'    => 'چوہدری اکرم اسلم (سیلز و کیش کسٹوڈین)',
        'phone'        => '03217654321',
        'notes'        => 'Senior Managing Partner overseeing treasury'
    ]);
    assert($updated, "update_partner should return true");

    $mpAfter = get_managing_partner();
    assert($mpAfter['partner_name'] === 'Chaudhry Akram Aslam', "Managing partner name should be updated, got: {$mpAfter['partner_name']}");
    assert($mpAfter['urdu_name'] === 'چوہدری اکرم اسلم (سیلز و کیش کسٹوڈین)', "Urdu title mismatch");
    echo "  [PASS] Successfully renamed Managing Partner to: {$mpAfter['partner_name']} ({$mpAfter['urdu_name']})\n";

    // 5. Test Accounting Integrity (Balance Recalculation)
    echo "\n[5] Testing recalculate_partner_balance() audit integrity:\n";
    // Add transaction to Tariq
    record_partner_transaction([
        'partner_id'       => $tariqId,
        'transaction_date' => date('Y-m-d'),
        'transaction_type' => 'cash_drawn',
        'debit'            => 3500.00,
        'credit'           => 0.00,
        'description'      => "Test cash drawing"
    ]);

    $auditedBal = recalculate_partner_balance($tariqId);
    // Opening balance was +10000, drawn 3500 -> 6500.00
    assert($auditedBal === 6500.00, "Audited balance should be 6500.00, got: {$auditedBal}");
    echo "  [PASS] Audited ledger balance: Rs. {$auditedBal} verified with zero discrepancy.\n";

    // 6. Test Standard Business Mode Guard
    echo "\n[6] Testing Standard Mode Guard:\n";
    update_setting('expense_management_mode', 'standard');
    assert(!is_partner_expense_sharing_enabled(), "Should be disabled in standard mode");
    $stdSplit = calculate_expense_split(5000.00, 'equal_50_50');
    assert(empty($stdSplit), "calculate_expense_split must return empty in standard mode");
    echo "  [PASS] In standard business mode, partner calculations are cleanly bypassed.\n";

    // Restore mode
    update_setting('expense_management_mode', 'partnership');

    echo "\n======================================================\n";
    echo "ALL DYNAMIC MULTI-PARTNER TESTS PASSED 100% CLEANLY!\n";
    echo "======================================================\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test threw exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    // Rollback test changes to preserve database clean state
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    echo "Test database rolled back cleanly.\n";
}
