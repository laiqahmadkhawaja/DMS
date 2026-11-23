<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

echo "=== PARTNER MANAGEMENT & KHATA WORKFLOW TEST ===\n\n";

// 1. Verify Partners exist
echo "[1] Checking seeded partners:\n";
$partners = get_partners(false);
assert(!empty($partners), "Partners list should not be empty");
foreach ($partners as $p) {
    echo "  - ID {$p['id']}: {$p['partner_name']} ({$p['urdu_name']}) | Role: {$p['role']} | Bal: {$p['current_balance']}\n";
}

$moazzam = get_partner_by_id(1);
$rehman  = get_partner_by_id(2);
$central = get_partner_by_id(3);

assert($moazzam !== null, "Moazzam should exist");
assert($rehman !== null, "Rehman should exist");
assert($central !== null, "Central partner should exist");
echo "  [PASS] All 3 partners verified.\n\n";

// Test inside transaction so database remains clean
db()->beginTransaction();

try {
    // 2. Test Shared Expense: Moazzam pays Rs. 5,000 out of pocket, split 50/50
    echo "[2] Testing Out-of-Pocket Expense Paid by Moazzam (Rs. 5,000, 50/50 split):\n";
    $moazzamStartBal = (float)$moazzam['current_balance'];
    $rehmanStartBal  = (float)$rehman['current_balance'];

    // Create an expense
    $stmtExp = db()->prepare("
        INSERT INTO expenses (
            expense_number, expense_date, expense_category_id, amount,
            paid_from_account_id, payment_mode, paid_by_type, paid_by_partner_id,
            split_type, description, created_by, created_at
        ) VALUES (
            'TEST-EXP-001', CURDATE(), 1, 5000.00,
            1001, 'cash', 'partner', 1,
            'equal_50_50', 'Test Office Supplies paid by Moazzam', 1, NOW()
        )
    ");
    $stmtExp->execute();
    $testExpId = (int)db()->lastInsertId();

    sync_expense_partner_transactions($testExpId, [
        'amount'              => 5000.00,
        'expense_date'        => date('Y-m-d'),
        'paid_by_type'        => 'partner',
        'paid_by_partner_id'  => 1,
        'split_type'          => 'equal_50_50',
        'split_details'       => null,
        'description'         => 'Test Office Supplies paid by Moazzam',
        'category_name'       => 'Office Supplies'
    ]);

    $moazzamAfterExp = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 1");
    $rehmanAfterExp  = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 2");

    // Moazzam paid 5000 (credit) and owes 2500 (debit) => net +2500
    // Rehman owes 2500 (debit) => net -2500
    echo "  - Moazzam Balance: Start: {$moazzamStartBal} -> After: {$moazzamAfterExp} (Diff: " . ($moazzamAfterExp - $moazzamStartBal) . ")\n";
    echo "  - Rehman Balance: Start: {$rehmanStartBal} -> After: {$rehmanAfterExp} (Diff: " . ($rehmanAfterExp - $rehmanStartBal) . ")\n";

    assert(abs(($moazzamAfterExp - $moazzamStartBal) - 2500.00) < 0.01, "Moazzam balance should increase by 2,500");
    assert(abs(($rehmanAfterExp - $rehmanStartBal) - (-2500.00)) < 0.01, "Rehman balance should decrease by 2,500");
    echo "  [PASS] Out-of-pocket 50/50 split verified.\n\n";

    // 3. Test Central Cash Expense: Rs. 6,000 paid by Central Pool for CCTV (50/50 split)
    echo "[3] Testing Central Pool Expense (CCTV Installation Rs. 6,000, 50/50 split):\n";
    $moazzamPreCCTV = $moazzamAfterExp;
    $rehmanPreCCTV  = $rehmanAfterExp;

    $stmtExp2 = db()->prepare("
        INSERT INTO expenses (
            expense_number, expense_date, expense_category_id, amount,
            paid_from_account_id, payment_mode, paid_by_type, paid_by_partner_id,
            split_type, description, created_by, created_at
        ) VALUES (
            'TEST-EXP-002', CURDATE(), 1, 6000.00,
            1001, 'cash', 'central_cash', NULL,
            'equal_50_50', 'Test CCTV Installation', 1, NOW()
        )
    ");
    $stmtExp2->execute();
    $testExp2Id = (int)db()->lastInsertId();

    sync_expense_partner_transactions($testExp2Id, [
        'amount'              => 6000.00,
        'expense_date'        => date('Y-m-d'),
        'paid_by_type'        => 'central_cash',
        'paid_by_partner_id'  => null,
        'split_type'          => 'equal_50_50',
        'split_details'       => null,
        'description'         => 'Test CCTV Installation',
        'category_name'       => 'Office Maintenance'
    ]);

    $moazzamAfterCCTV = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 1");
    $rehmanAfterCCTV  = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 2");

    echo "  - Moazzam Balance: {$moazzamPreCCTV} -> {$moazzamAfterCCTV} (Diff: " . ($moazzamAfterCCTV - $moazzamPreCCTV) . ")\n";
    echo "  - Rehman Balance: {$rehmanPreCCTV} -> {$rehmanAfterCCTV} (Diff: " . ($rehmanAfterCCTV - $rehmanPreCCTV) . ")\n";

    assert(abs(($moazzamAfterCCTV - $moazzamPreCCTV) - (-3000.00)) < 0.01, "Moazzam should be debited 3,000");
    assert(abs(($rehmanAfterCCTV - $rehmanPreCCTV) - (-3000.00)) < 0.01, "Rehman should be debited 3,000");
    echo "  [PASS] Central cash shared expense verified.\n\n";

    // 4. Test Cash Drawing by Rehman (Rs. 2,000)
    echo "[4] Testing Partner Cash Drawing (Rehman takes Rs. 2,000):\n";
    $rehmanPreDraw = $rehmanAfterCCTV;

    $drawTxnId = record_partner_drawing(
        2, // Rehman
        2000.00,
        date('Y-m-d'),
        'cash',
        1001,
        'Test personal advance taken from Central Pool'
    );
    $rehmanAfterDraw = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 2");
    echo "  - Rehman Balance: {$rehmanPreDraw} -> {$rehmanAfterDraw} (Diff: " . ($rehmanAfterDraw - $rehmanPreDraw) . ")\n";
    assert(abs(($rehmanAfterDraw - $rehmanPreDraw) - (-2000.00)) < 0.01, "Rehman should be debited 2,000 for drawing");
    echo "  [PASS] Cash drawing verified.\n\n";

    // 5. Test Inter-Partner Settlement (Rehman pays Moazzam Rs. 4,000)
    echo "[5] Testing Inter-Partner Settlement (Rehman settles Rs. 4,000 with Moazzam):\n";
    $moazzamPreSettle = $moazzamAfterCCTV;
    $rehmanPreSettle  = $rehmanAfterDraw;

    record_partner_settlement(
        2, // Rehman pays
        1, // Moazzam receives
        4000.00,
        date('Y-m-d'),
        'Direct cash settlement to clear balance'
    );

    $moazzamAfterSettle = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 1");
    $rehmanAfterSettle  = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = 2");

    echo "  - Rehman (Payer) Balance: {$rehmanPreSettle} -> {$rehmanAfterSettle} (Credited +4,000)\n";
    echo "  - Moazzam (Receiver) Balance: {$moazzamPreSettle} -> {$moazzamAfterSettle} (Debited -4,000)\n";

    assert(abs(($rehmanAfterSettle - $rehmanPreSettle) - 4000.00) < 0.01, "Rehman credited 4,000");
    assert(abs(($moazzamAfterSettle - $moazzamPreSettle) - (-4000.00)) < 0.01, "Moazzam debited 4,000");
    echo "  [PASS] Inter-partner settlement verified.\n\n";

    // 6. Test Partner Khata Statement Function
    echo "[6] Testing get_partner_statement():\n";
    $stmtRehman = get_partner_statement(2, date('Y-m-01'), date('Y-m-d'));
    assert(!empty($stmtRehman['transactions']), "Rehman statement should have transactions");
    echo "  - Total statement transactions for Rehman: " . count($stmtRehman['transactions']) . "\n";
    echo "  - Closing balance from statement: " . $stmtRehman['closing_balance'] . " vs DB: {$rehmanAfterSettle}\n";
    assert(abs($stmtRehman['closing_balance'] - $rehmanAfterSettle) < 0.01, "Closing balance must match DB balance");
    echo "  [PASS] Statement generation verified.\n\n";

    // 7. Test Partner Sales & Profit Summary
    echo "[7] Testing get_partner_sales_profit_summary():\n";
    $summaryMoazzam = get_partner_sales_profit_summary(1, '2026-01-01', '2026-12-31');
    $summaryRehman  = get_partner_sales_profit_summary(2, '2026-01-01', '2026-12-31');
    assert(isset($summaryMoazzam['net_sales']), "Moazzam summary must have net_sales");
    assert(isset($summaryRehman['net_sales']), "Rehman summary must have net_sales");
    echo "  - Moazzam SMS Sales: Gross: {$summaryMoazzam['gross_sales']}, Net: {$summaryMoazzam['net_sales']}, Profit: {$summaryMoazzam['gross_profit']}\n";
    echo "  - Rehman Ujab Sales: Gross: {$summaryRehman['gross_sales']}, Net: {$summaryRehman['net_sales']}, Profit: {$summaryRehman['gross_profit']}\n";
    echo "  [PASS] Sales & profit analytics verified.\n\n";

    db()->rollBack();
    echo "=== ALL 7 TESTS PASSED SUCCESSFULLY! Database rolled back cleanly. ===\n";

} catch (Exception $e) {
    db()->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
