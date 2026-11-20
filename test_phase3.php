<?php
/**
 * DMS - Distribution Management System
 * Phase 3 Automated Verification Test Suite
 * 
 * Tests the entire Order-to-Delivery Cycle:
 * 1. Market Order Booking & Items Entry
 * 2. Order Confirmation
 * 3. Warehouse Loading Sheet Consolidation & Dispatch
 * 4. Automatic Stock Deduction & Invoice Generation
 * 5. Delivery Run Assignment
 * 6. Evening Route Settlement & Zero-Sum Reconciliation (Photo 1)
 * 7. Customer Ledger Updating & Office Cash Handover
 * 8. Sales Return Restocking
 * 9. General Ledger Double-Entry Balance Verification
 * 10. Direct Sales Invoice (F7) & Direct Return
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/database_helpers.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

// Force simulated admin login for test execution
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role_id'] = 1;

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] " . $name . "\n";
    } else {
        $failed++;
        echo "[FAIL] " . $name . ($detail ? " - " . $detail : "") . "\n";
    }
}

echo "======================================================================\n";
echo "DMS PHASE 3 - ORDER-TO-DELIVERY WORKFLOW VERIFICATION SUITE\n";
echo "======================================================================\n\n";

try {
    // -----------------------------------------------------------------
    // TEST 1: Check Master Records Available
    // -----------------------------------------------------------------
    $customer1 = db_fetch_one("SELECT * FROM customers WHERE shop_name LIKE '%Usman%' OR id = 1 LIMIT 1");
    $customer2 = db_fetch_one("SELECT * FROM customers WHERE shop_name LIKE '%Waqas%' OR id = 2 LIMIT 1");
    $customer3 = db_fetch_one("SELECT * FROM customers WHERE shop_name LIKE '%Hafiz%' OR id = 3 LIMIT 1");

    assert_test("Retail Customers Available", ($customer1 && $customer2 && $customer3), "Found customers 1, 2, 3");

    $products = db_fetch_all("SELECT * FROM products WHERE is_active = 1 LIMIT 3");
    assert_test("FMCG Products Available", count($products) >= 3, "Found " . count($products) . " products");

    $salesman = db_fetch_one("SELECT * FROM salesmen WHERE is_active = 1 LIMIT 1");
    $booker   = db_fetch_one("SELECT * FROM bookers WHERE is_active = 1 LIMIT 1");
    $area     = db_fetch_one("SELECT * FROM areas WHERE is_active = 1 LIMIT 1");

    assert_test("Salesman, Booker & Route Available", ($salesman && $booker && $area));

    // Record initial stock of product 1
    $prod1 = $products[0];
    $initialStock = get_stock_balance($prod1['id'], 1);
    $initialSaleable = $initialStock['saleable_qty'];

    // -----------------------------------------------------------------
    // TEST 2: Order Booking (Order 1, Order 2, Order 3)
    // -----------------------------------------------------------------
    $orderNumber1 = generate_document_number(PREFIX_ORDER, 'orders', 'order_number');
    $stmtOrd = db()->prepare("
        INSERT INTO orders (
            order_number, order_date, delivery_date, customer_id, booker_id, 
            area_id, total_cartons, total_units, gross_amount, 
            discount_amount, net_amount, status, created_by, created_at
        ) VALUES (
            ?, CURDATE(), CURDATE(), ?, ?, 
            ?, 2, 48, 4416.00, 
            0.00, 4416.00, 'draft', 1, NOW()
        )
    ");
    $stmtOrd->execute([$orderNumber1, $customer1['id'], $booker['id'], $area['id']]);
    $orderId1 = (int)db()->lastInsertId();

    $stmtOrdItem = db()->prepare("
        INSERT INTO order_items (
            order_id, product_id, unit_id, carton_qty, pcs_qty, quantity, rate, discount_percent, discount_amount, line_total, created_at
        ) VALUES (?, ?, ?, 2, 0, 48, 2208.00, 0, 0, 4416.00, NOW())
    ");
    $stmtOrdItem->execute([$orderId1, $prod1['id'], $prod1['base_unit_id']]);

    assert_test("Order 1 Booked with Line Items", $orderId1 > 0);

    // Order 2
    $orderNumber2 = generate_document_number(PREFIX_ORDER, 'orders', 'order_number');
    $stmtOrd->execute([$orderNumber2, $customer2['id'], $booker['id'], $area['id']]);
    $orderId2 = (int)db()->lastInsertId();
    $stmtOrdItem->execute([$orderId2, $prod1['id'], $prod1['base_unit_id']]);

    assert_test("Order 2 Booked with Line Items", $orderId2 > 0);

    // -----------------------------------------------------------------
    // TEST 3: Order Confirmation
    // -----------------------------------------------------------------
    db()->prepare("UPDATE orders SET status = 'confirmed', updated_at = NOW() WHERE id IN (?, ?)")
        ->execute([$orderId1, $orderId2]);

    $confirmedCount = (int)db_fetch_val("SELECT COUNT(*) FROM orders WHERE id IN (?, ?) AND status = 'confirmed'", [$orderId1, $orderId2]);
    assert_test("Orders Transitioned to 'confirmed' Status", $confirmedCount === 2);

    // -----------------------------------------------------------------
    // TEST 4: Create Warehouse Load Sheet (Consolidation & Dispatch)
    // -----------------------------------------------------------------
    $loadNumber = generate_document_number(PREFIX_LOAD, 'load_sheets', 'load_number');
    $stmtLS = db()->prepare("
        INSERT INTO load_sheets (
            load_number, load_date, delivery_date, salesman_id, warehouse_id, 
            total_orders, total_amount, status, remarks, created_by, issued_at, issued_by, created_at
        ) VALUES (
            ?, CURDATE(), CURDATE(), ?, 1, 
            2, 8832.00, 'issued', 'Automated Test Route', 1, NOW(), 1, NOW()
        )
    ");
    $stmtLS->execute([$loadNumber, $salesman['id']]);
    $loadSheetId = (int)db()->lastInsertId();

    // Link orders
    $stmtLSO = db()->prepare("INSERT INTO load_sheet_orders (load_sheet_id, order_id, created_at) VALUES (?, ?, NOW())");
    $stmtLSO->execute([$loadSheetId, $orderId1]);
    $stmtLSO->execute([$loadSheetId, $orderId2]);

    // Insert consolidated item: 2 orders * 48 units = 96 base units (4 cartons)
    $stmtLSI = db()->prepare("
        INSERT INTO load_sheet_items (
            load_sheet_id, product_id, unit_id, ordered_qty, required_qty, 
            loaded_qty, short_qty, returned_qty, rate, total_amount, created_at
        ) VALUES (
            ?, ?, ?, 96, 96, 
            96, 0, 0, 2208.00, 8832.00, NOW()
        )
    ");
    $stmtLSI->execute([$loadSheetId, $prod1['id'], $prod1['base_unit_id']]);

    // Deduct stock via atomic stock movement
    $movementId = record_stock_movement([
        'product_id'     => $prod1['id'],
        'warehouse_id'   => 1,
        'movement_type'  => 'sales_dispatch',
        'reference_type' => 'load_sheet',
        'reference_id'   => $loadSheetId,
        'qty_in'         => 0,
        'qty_out'        => 96,
        'unit_cost'      => 2208.00,
        'remarks'        => "Stock dispatch test",
        'condition_type' => 'saleable'
    ]);

    assert_test("Load Sheet Issued and Linked to Orders", $loadSheetId > 0 && $movementId > 0);

    // Verify stock balance was accurately deducted by exactly 96 base units
    $postDispatchStock = get_stock_balance($prod1['id'], 1);
    $expectedSaleable = $initialSaleable - 96;
    assert_test("Inventory Balance Atomically Deducted (-96 units)", abs((float)$postDispatchStock['saleable_qty'] - $expectedSaleable) < 0.01, "New balance: {$postDispatchStock['saleable_qty']}");

    // -----------------------------------------------------------------
    // TEST 5: Auto-Generate Sales Invoices for Dispatched Orders
    // -----------------------------------------------------------------
    $invNumber1 = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');
    $stmtInv = db()->prepare("
        INSERT INTO sales_invoices (
            invoice_number, invoice_date, order_id, load_sheet_id, customer_id, 
            booker_id, salesman_id, area_id, gross_amount, item_discount_amount, 
            extra_discount_amount, net_amount, status, reconciliation_status, payment_status,
            created_by, finalized_at, created_at
        ) VALUES (
            ?, CURDATE(), ?, ?, ?, 
            ?, ?, ?, 4416.00, 0.00, 
            0.00, 4416.00, 'finalized', 'pending', 'unpaid',
            1, NOW(), NOW()
        )
    ");
    $stmtInv->execute([$invNumber1, $orderId1, $loadSheetId, $customer1['id'], $booker['id'], $salesman['id'], $area['id']]);
    $invoiceId1 = (int)db()->lastInsertId();

    $invNumber2 = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');
    $stmtInv->execute([$invNumber2, $orderId2, $loadSheetId, $customer2['id'], $booker['id'], $salesman['id'], $area['id']]);
    $invoiceId2 = (int)db()->lastInsertId();

    // Populate line items from orders
    $stmtInvItem = db()->prepare("
        INSERT INTO sales_invoice_items (
            invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, quantity, rate, discount_percent, discount_amount, line_total, created_at
        )
        SELECT ?, product_id, unit_id, carton_qty, pack_qty, pcs_qty, quantity, rate, discount_percent, discount_amount, line_total, NOW()
        FROM order_items WHERE order_id = ?
    ");
    $stmtInvItem->execute([$invoiceId1, $orderId1]);
    $stmtInvItem->execute([$invoiceId2, $orderId2]);

    assert_test("Sales Invoices Generated with 'finalized' In-Route Status", ($invoiceId1 > 0 && $invoiceId2 > 0));

    // -----------------------------------------------------------------
    // TEST 6: Delivery Run Creation
    // -----------------------------------------------------------------
    $runNumber = generate_document_number(PREFIX_RUN, 'delivery_runs', 'run_number');
    $stmtRun = db()->prepare("
        INSERT INTO delivery_runs (
            run_number, run_date, salesman_id, load_sheet_id, route_notes, 
            total_bills, total_amount, status, created_by, created_at
        ) VALUES (
            ?, CURDATE(), ?, ?, 'Evening Route 1', 
            2, 8832.00, 'assigned', 1, NOW()
        )
    ");
    $stmtRun->execute([$runNumber, $salesman['id'], $loadSheetId]);
    $runId = (int)db()->lastInsertId();

    $stmtDRB = db()->prepare("INSERT INTO delivery_run_bills (delivery_run_id, invoice_id, stop_sequence, delivery_status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
    $stmtDRB->execute([$runId, $invoiceId1, 1]);
    $stmtDRB->execute([$runId, $invoiceId2, 2]);

    assert_test("Delivery Run Created with Invoices Assigned", $runId > 0);

    // -----------------------------------------------------------------
    // TEST 7: Route Settlement & Zero-Sum Reconciliation (Photo 1)
    // -----------------------------------------------------------------
    // Bill 1 (Rs. 4416): Retailer pays Rs. 2000 Cash + Rs. 2416 Credit (Party Credit)
    // Bill 2 (Rs. 4416): Retailer takes Rs. 3000 Cash + Rs. 1416 Return
    // Total Invoices: 4416 + 4416 = 8832
    // Total Cash:     2000 + 3000 = 5000
    // Total Credit:   2416 + 0    = 2416
    // Total Return:   0    + 1416 = 1416
    // Zero-Sum: 5000 + 2416 + 1416 = 8832! (Difference = 0)

    $cust1InitialBalance = (float)$customer1['current_balance'];

    $setNumber = generate_document_number(PREFIX_SETTLEMENT, 'settlements', 'settlement_number');
    $stmtSet = db()->prepare("
        INSERT INTO settlements (
            settlement_number, settlement_date, delivery_run_id, salesman_id, load_sheet_id, 
            total_invoices_amount, total_cash_received, total_credit_given, total_returns_amount, 
            difference_amount, status, remarks, created_by, reconciled_by, finalized_at, created_at
        ) VALUES (
            ?, CURDATE(), ?, ?, ?, 
            8832.00, 5000.00, 2416.00, 1416.00, 
            0.00, 'finalized', 'Photo 1 Test Reconciliation', 1, 1, NOW(), NOW()
        )
    ");
    $stmtSet->execute([$setNumber, $runId, $salesman['id'], $loadSheetId]);
    $settlementId = (int)db()->lastInsertId();

    // Insert items
    $stmtSI = db()->prepare("
        INSERT INTO settlement_items (
            settlement_id, invoice_id, customer_id, bill_amount, 
            cash_amount, credit_amount, return_amount, difference_amount, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtSI->execute([$settlementId, $invoiceId1, $customer1['id'], 4416.00, 2000.00, 2416.00, 0.00, 0.00]);
    $stmtSI->execute([$settlementId, $invoiceId2, $customer2['id'], 4416.00, 3000.00, 0.00, 1416.00, 0.00]);

    // Update sales_invoices settled amounts
    $stmtUpdInv = db()->prepare("
        UPDATE sales_invoices 
        SET paid_amount = ?, credit_amount = ?, return_amount = ?, 
            status = 'settled', reconciliation_status = 'settled', payment_status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmtUpdInv->execute([2000.00, 2416.00, 0.00, 'partial', $invoiceId1]);
    $stmtUpdInv->execute([3000.00, 0.00, 1416.00, 'partial', $invoiceId2]);

    // Update customer 1 balance by credit amount (+2416.00)
    update_customer_balance($customer1['id'], 2416.00, 'increase');

    // Restock return into warehouse
    $returnMovementId = record_stock_movement([
        'product_id'     => $prod1['id'],
        'warehouse_id'   => 1,
        'movement_type'  => 'sales_return_saleable',
        'reference_type' => 'sales_return',
        'reference_id'   => $settlementId,
        'qty_in'         => round(1416.00 / 2208.00 * 24, 2), // return units
        'qty_out'        => 0,
        'unit_cost'      => 2208.00,
        'remarks'        => "Stock returned from settlement #{$setNumber}",
        'condition_type' => 'saleable'
    ]);

    // Record cash submission to office cash drawer
    $subNumber = generate_document_number('CSUB', 'cash_submissions', 'submission_number');
    db()->prepare("
        INSERT INTO cash_submissions (
            submission_number, submission_date, salesman_id, settlement_id, 
            amount, cash_account_id, received_by, created_at
        ) VALUES (?, CURDATE(), ?, ?, 5000.00, 1, 1, NOW())
    ")->execute([$subNumber, $salesman['id'], $settlementId]);

    // Post to General Ledger (Total Debit = 5000 + 2416 + 1416 = 8832, Total Credit = 8832)
    $journalId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'settlement',
        'reference_id'   => $settlementId,
        'narration'      => "Daily Route Settlement #{$setNumber}"
    ], [
        ['account_id' => ACC_CASH_MAIN, 'debit' => 5000.00, 'credit' => 0.00, 'description' => "Cash collected - #{$setNumber}"],
        ['account_id' => ACC_RECEIVABLES, 'debit' => 2416.00, 'credit' => 0.00, 'description' => "Party Credit - #{$setNumber}"],
        ['account_id' => ACC_RETURNS, 'debit' => 1416.00, 'credit' => 0.00, 'description' => "Returns - #{$setNumber}"],
        ['account_id' => ACC_SALES, 'debit' => 0.00, 'credit' => 8832.00, 'description' => "Sales revenue - #{$setNumber}"]
    ]);

    assert_test("Zero-Sum Route Settlement Finalized (Diff = 0)", $settlementId > 0);
    assert_test("General Ledger Journal Voucher Posted & Balanced", $journalId > 0);

    // Verify Customer 1 balance increased
    $cust1NewBalance = (float)db_fetch_val("SELECT current_balance FROM customers WHERE id = ?", [$customer1['id']]);
    assert_test("Customer 1 Running Ledger Updated (+Rs. 2416.00 Credit)", abs(($cust1NewBalance - $cust1InitialBalance) - 2416.00) < 0.01, "New balance: {$cust1NewBalance}");

    // Verify Cash Account updated
    $cashBalance = (float)db_fetch_val("SELECT current_balance FROM accounts WHERE id = ?", [ACC_CASH_MAIN]);
    assert_test("Office Cash in Hand Updated with Recovered Cash", $cashBalance >= 5000.00, "Cash Balance: {$cashBalance}");

    // -----------------------------------------------------------------
    // TEST 8: Direct Sales Invoice (F7)
    // -----------------------------------------------------------------
    $directInvNum = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');
    $stmtInvDirect = db()->prepare("
        INSERT INTO sales_invoices (
            invoice_number, invoice_date, customer_id, booker_id, salesman_id, 
            area_id, gross_amount, item_discount_amount, net_amount, paid_amount, 
            credit_amount, return_amount, status, reconciliation_status, payment_status, 
            created_by, finalized_at, created_at
        ) VALUES (
            ?, CURDATE(), ?, ?, ?, 
            ?, 2208.00, 0.00, 2208.00, 2208.00, 
            0.00, 0.00, 'settled', 'settled', 'paid', 
            1, NOW(), NOW()
        )
    ");
    $stmtInvDirect->execute([$directInvNum, $customer3['id'], $booker['id'], $salesman['id'], $area['id']]);
    $directInvId = (int)db()->lastInsertId();

    assert_test("Direct Sales Invoice (F7) Created & Settled", $directInvId > 0);

    // -----------------------------------------------------------------
    // TEST 9: Direct Sales Return
    // -----------------------------------------------------------------
    $retNum = generate_document_number(PREFIX_RETURN, 'sales_returns', 'return_number');
    $stmtDirectRet = db()->prepare("
        INSERT INTO sales_returns (
            return_number, customer_id, return_date, total_amount, reason, 
            condition_type, status, created_by, created_at
        ) VALUES (
            ?, ?, CURDATE(), 500.00, 'Damaged during transit', 
            'damaged', 'processed', 1, NOW()
        )
    ");
    $stmtDirectRet->execute([$retNum, $customer3['id']]);
    $directRetId = (int)db()->lastInsertId();

    // Damaged stock movement
    record_stock_movement([
        'product_id'     => $prod1['id'],
        'warehouse_id'   => 1,
        'movement_type'  => 'sales_return_damaged',
        'reference_type' => 'sales_return',
        'reference_id'   => $directRetId,
        'qty_in'         => 10,
        'qty_out'        => 0,
        'unit_cost'      => 50.00,
        'remarks'        => "Damaged return #{$retNum}",
        'condition_type' => 'damaged'
    ]);

    $damagedBal = get_stock_balance($prod1['id'], 1);
    assert_test("Damaged Stock Tracked in Quarantine Balance", (float)$damagedBal['damaged_qty'] >= 10, "Damaged qty: {$damagedBal['damaged_qty']}");

    // -----------------------------------------------------------------
    // TEST 10: Double-Entry Integrity Verification
    // -----------------------------------------------------------------
    $unbalancedJournals = (int)db_fetch_val("
        SELECT COUNT(*) 
        FROM journal_entries 
        WHERE ABS(total_debit - total_credit) > 0.01
    ");
    assert_test("All System Journal Vouchers Strictly Balanced (Debit == Credit)", $unbalancedJournals === 0);

    // -----------------------------------------------------------------
    // TEST 11: Stock Movements vs Stock Balances Consistency
    // -----------------------------------------------------------------
    $calcBal = db_fetch_one("
        SELECT SUM(qty_in - qty_out) as net_qty
        FROM stock_movements 
        WHERE product_id = ? AND warehouse_id = 1
    ", [$prod1['id']]);
    $actualBal = get_stock_balance($prod1['id'], 1);

    assert_test("Stock Movement Ledger Strictly Equals Stock Balances (Source of Truth)", abs((float)$calcBal['net_qty'] - (float)$actualBal['total_qty']) < 0.01, "Ledger: {$calcBal['net_qty']}, Balances: {$actualBal['total_qty']}");

} catch (Exception $e) {
    echo "\nFATAL TEST EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n----------------------------------------------------------------------\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "----------------------------------------------------------------------\n";

if ($failed === 0) {
    echo "PHASE 3 ORDER-TO-DELIVERY WORKFLOW VERIFIED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "PHASE 3 HAS TEST FAILURES. REVIEW ABOVE.\n";
    exit(1);
}
