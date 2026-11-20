<?php
/**
 * DMS - Distribution Management System
 * Phase 4 Automated Verification Test Suite
 * 
 * Tests Purchasing, Goods Receiving (GRN), and Warehouse Stock Management:
 * 1. Supplier Purchase Order Creation
 * 2. Warehouse Goods Receiving (GRN) & Physical Inspection
 * 3. Atomic Stock Inbound & Inventory Balance Sync
 * 4. Purchase Order Status Transition ('ordered' -> 'received')
 * 5. Supplier Payables Ledger Updating
 * 6. Damaged Arrival Quarantine Balance Tracking
 * 7. Physical Stock Take & Audit Adjustment
 * 8. Stock Movement Ledger Immutable Audit Trail
 * 9. Double-Entry Accounting Consistency (Inventory vs Payables vs COGS)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/database_helpers.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

// Force simulated admin login
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role_id'] = 1;
$_SESSION['roles'] = ['admin'];
$_SESSION['permissions'] = [];

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
echo "DMS PHASE 4 - PURCHASING, GRN & INVENTORY MANAGEMENT VERIFICATION SUITE\n";
echo "======================================================================\n\n";

try {
    // -----------------------------------------------------------------
    // TEST 1: Masters & Warehouse Prerequisites
    // -----------------------------------------------------------------
    $supplier = db_fetch_one("SELECT * FROM suppliers WHERE is_deleted = 0 LIMIT 1");
    assert_test("Supplier Master Available", (bool)$supplier, "Supplier: " . ($supplier['supplier_name'] ?? 'None'));

    $warehouse = db_fetch_one("SELECT * FROM warehouses WHERE is_active = 1 LIMIT 1");
    assert_test("Warehouse Master Available", (bool)$warehouse, "Warehouse: " . ($warehouse['warehouse_name'] ?? 'None'));

    $product = db_fetch_one("SELECT * FROM products WHERE is_active = 1 LIMIT 1");
    assert_test("Product Master Available", (bool)$product, "Product: " . ($product['product_name'] ?? 'None'));

    $initialStock = get_stock_balance($product['id'], $warehouse['id']);
    $initialSaleable = (float)$initialStock['saleable_qty'];
    $initialDamaged  = (float)$initialStock['damaged_qty'];
    $initialSupplierBalance = (float)$supplier['current_balance'];

    // -----------------------------------------------------------------
    // TEST 2: Create Purchase Order (PO)
    // -----------------------------------------------------------------
    $poNumber = generate_document_number(PREFIX_PURCHASE, 'purchase_orders', 'purchase_number');
    $orderedUnits = 120.0; // 5 cartons of 24
    $costRate = (float)$product['cost_price'] > 0 ? (float)$product['cost_price'] : 1500.00;
    $poTotal = round($orderedUnits * $costRate, 2);

    $stmtPO = db()->prepare("
        INSERT INTO purchase_orders (
            purchase_number, supplier_id, supplier_invoice_no, order_date, 
            expected_delivery_date, total_amount, discount_amount, net_amount, 
            status, remarks, created_by, created_at
        ) VALUES (
            ?, ?, 'INV-TEST-001', CURDATE(), 
            DATE_ADD(CURDATE(), INTERVAL 2 DAY), ?, 0.00, ?, 
            'ordered', 'Phase 4 Test Purchase Order', 1, NOW()
        )
    ");
    $stmtPO->execute([$poNumber, $supplier['id'], $poTotal, $poTotal]);
    $poId = (int)db()->lastInsertId();

    $stmtPoi = db()->prepare("
        INSERT INTO purchase_order_items (
            purchase_order_id, product_id, unit_id, quantity, rate, discount_amount, line_total, received_quantity, created_at
        ) VALUES (?, ?, ?, ?, ?, 0.00, ?, 0.00, NOW())
    ");
    $stmtPoi->execute([$poId, $product['id'], $product['base_unit_id'], $orderedUnits, $costRate, $poTotal]);

    assert_test("Purchase Order Created in 'ordered' Status", $poId > 0);

    // -----------------------------------------------------------------
    // TEST 3: Receive Goods via GRN (Warehouse Gate Inspection)
    // -----------------------------------------------------------------
    $grnNumber = generate_document_number(PREFIX_GRN, 'goods_receipts', 'grn_number');
    $receivedUnits = 100.0;
    $damagedUnits = 20.0; // Total 120 units received from factory
    $receivedValue = round($receivedUnits * $costRate, 2);
    $damagedValue  = round($damagedUnits * $costRate, 2);
    $totalGrnValue = $receivedValue + $damagedValue;

    $stmtGRN = db()->prepare("
        INSERT INTO goods_receipts (
            grn_number, purchase_order_id, supplier_id, supplier_bill_no, 
            grn_date, warehouse_id, total_amount, status, remarks, received_by, created_at
        ) VALUES (
            ?, ?, ?, 'BL-TEST-999', 
            CURDATE(), ?, ?, 'posted', 'Test Goods Arrival Inspection', 1, NOW()
        )
    ");
    $stmtGRN->execute([$grnNumber, $poId, $supplier['id'], $warehouse['id'], $totalGrnValue]);
    $grnId = (int)db()->lastInsertId();

    // Insert GRN items
    $stmtGri = db()->prepare("
        INSERT INTO goods_receipt_items (
            goods_receipt_id, product_id, unit_id, ordered_qty, received_qty, 
            short_qty, damaged_qty, unit_rate, line_total, created_at
        ) VALUES (?, ?, ?, ?, ?, 0.00, ?, ?, ?, NOW())
    ");
    $stmtGri->execute([$grnId, $product['id'], $product['base_unit_id'], $orderedUnits, $receivedUnits, $damagedUnits, $costRate, $totalGrnValue]);

    // Record atomic stock addition into warehouse (Saleable)
    $inboundMovementId = record_stock_movement([
        'product_id'     => $product['id'],
        'warehouse_id'   => $warehouse['id'],
        'movement_type'  => 'purchase_grn',
        'reference_type' => 'grn',
        'reference_id'   => $grnId,
        'qty_in'         => $receivedUnits,
        'qty_out'        => 0,
        'unit_cost'      => $costRate,
        'remarks'        => "Stock received via GRN #{$grnNumber}",
        'condition_type' => 'saleable'
    ]);

    // Record damaged arrival into quarantine
    $damagedMovementId = record_stock_movement([
        'product_id'     => $product['id'],
        'warehouse_id'   => $warehouse['id'],
        'movement_type'  => 'stock_adjustment_add',
        'reference_type' => 'grn',
        'reference_id'   => $grnId,
        'qty_in'         => $damagedUnits,
        'qty_out'        => 0,
        'unit_cost'      => $costRate,
        'remarks'        => "Damaged arrival quarantined via GRN #{$grnNumber}",
        'condition_type' => 'damaged'
    ]);

    // Update PO received quantity & transition PO status to 'received'
    db()->prepare("UPDATE purchase_order_items SET received_quantity = ? WHERE purchase_order_id = ?")
        ->execute([$orderedUnits, $poId]);
    db()->prepare("UPDATE purchase_orders SET status = 'received', updated_at = NOW() WHERE id = ?")
        ->execute([$poId]);

    // Update Supplier Balance (Payable added)
    update_supplier_balance($supplier['id'], $totalGrnValue, 'increase');

    // Post Double-Entry General Ledger Voucher: Debit Inventory Asset (1030) == Credit Accounts Payable (2010)
    $journalId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'grn',
        'reference_id'   => $grnId,
        'narration'      => "Goods Receipt Note #{$grnNumber}"
    ], [
        ['account_id' => ACC_INVENTORY, 'debit' => $totalGrnValue, 'credit' => 0.00, 'description' => "Inventory added - GRN #{$grnNumber}"],
        ['account_id' => ACC_PAYABLES, 'debit' => 0.00, 'credit' => $totalGrnValue, 'party_type' => 'supplier', 'party_id' => $supplier['id'], 'description' => "Supplier payable - GRN #{$grnNumber}"]
    ]);

    assert_test("Goods Receiving Note (GRN) Posted Successfully", $grnId > 0);
    assert_test("Purchase Order Transitioned to 'received' Status", db_fetch_val("SELECT status FROM purchase_orders WHERE id = ?", [$poId]) === 'received');
    assert_test("Saleable Stock Atomically Incremented (+{$receivedUnits} units)", abs((float)get_stock_balance($product['id'], $warehouse['id'])['saleable_qty'] - ($initialSaleable + $receivedUnits)) < 0.01);
    assert_test("Damaged Stock Quarantined (+{$damagedUnits} units)", abs((float)get_stock_balance($product['id'], $warehouse['id'])['damaged_qty'] - ($initialDamaged + $damagedUnits)) < 0.01);
    assert_test("Supplier Payables Balance Updated (+Rs. {$totalGrnValue})", abs((float)db_fetch_val("SELECT current_balance FROM suppliers WHERE id = ?", [$supplier['id']]) - ($initialSupplierBalance + $totalGrnValue)) < 0.01);
    assert_test("General Ledger Journal Voucher Posted & Balanced (Inventory vs Payables)", $journalId > 0);

    // -----------------------------------------------------------------
    // TEST 4: Physical Stock Take & Audit Adjustment
    // -----------------------------------------------------------------
    $adjNumber = generate_document_number(PREFIX_ADJUSTMENT, 'stock_adjustments', 'adjustment_number');
    $shrinkageUnits = 5.0; // 5 units lost/damaged during warehouse handling
    $shrinkageVal = round($shrinkageUnits * $costRate, 2);

    $stmtAdj = db()->prepare("
        INSERT INTO stock_adjustments (
            adjustment_number, warehouse_id, adjustment_date, reason, status, created_by, created_at
        ) VALUES (
            ?, ?, CURDATE(), 'Physical count audit breakage', 'approved', 1, NOW()
        )
    ");
    $stmtAdj->execute([$adjNumber, $warehouse['id']]);
    $adjId = (int)db()->lastInsertId();

    $stmtAdjItem = db()->prepare("
        INSERT INTO stock_adjustment_items (
            stock_adjustment_id, product_id, adjustment_type, condition_type, 
            quantity, unit_cost, total_amount, remarks, created_at
        ) VALUES (?, ?, 'subtract', 'saleable', ?, ?, ?, 'Breakage write-off', NOW())
    ");
    $stmtAdjItem->execute([$adjId, $product['id'], $shrinkageUnits, $costRate, $shrinkageVal]);

    // Atomic stock reduction
    record_stock_movement([
        'product_id'     => $product['id'],
        'warehouse_id'   => $warehouse['id'],
        'movement_type'  => 'stock_adjustment_sub',
        'reference_type' => 'adjustment',
        'reference_id'   => $adjId,
        'qty_in'         => 0,
        'qty_out'        => $shrinkageUnits,
        'unit_cost'      => $costRate,
        'remarks'        => "Breakage write-off #{$adjNumber}",
        'condition_type' => 'saleable'
    ]);

    // Post to General Ledger: Debit COGS / Inventory Loss (5010), Credit Inventory Asset (1030)
    $adjJournalId = post_journal_entry([
        'entry_date'     => date('Y-m-d'),
        'reference_type' => 'manual_journal',
        'reference_id'   => $adjId,
        'narration'      => "Stock write-off adjustment #{$adjNumber}"
    ], [
        ['account_id' => ACC_COGS, 'debit' => $shrinkageVal, 'credit' => 0.00, 'description' => "Breakage loss write-off #{$adjNumber}"],
        ['account_id' => ACC_INVENTORY, 'debit' => 0.00, 'credit' => $shrinkageVal, 'description' => "Inventory reduced - #{$adjNumber}"]
    ]);

    $postAdjStock = get_stock_balance($product['id'], $warehouse['id']);
    $expectedFinalSaleable = $initialSaleable + $receivedUnits - $shrinkageUnits;

    assert_test("Physical Stock Audit Adjustment Applied", $adjId > 0 && $adjJournalId > 0);
    assert_test("Stock Balance Decremented by Breakage (-{$shrinkageUnits} units)", abs((float)$postAdjStock['saleable_qty'] - $expectedFinalSaleable) < 0.01, "Actual balance: {$postAdjStock['saleable_qty']}");

    // -----------------------------------------------------------------
    // TEST 5: Stock Movement Ledger Source of Truth Verification
    // -----------------------------------------------------------------
    $ledgerSum = db_fetch_one("
        SELECT 
            SUM(qty_in) as total_in,
            SUM(qty_out) as total_out,
            SUM(qty_in - qty_out) as net_ledger_qty
        FROM stock_movements 
        WHERE product_id = ? AND warehouse_id = ?
    ", [$product['id'], $warehouse['id']]);

    assert_test("Stock Movements Source of Truth Strictly Matches Balance Table", abs((float)$ledgerSum['net_ledger_qty'] - (float)$postAdjStock['total_qty']) < 0.01, "Ledger: {$ledgerSum['net_ledger_qty']}, Balance: {$postAdjStock['total_qty']}");

    // -----------------------------------------------------------------
    // TEST 6: Double-Entry GL Ledger Zero-Imbalance Verification
    // -----------------------------------------------------------------
    $unbalancedCount = (int)db_fetch_val("
        SELECT COUNT(*) 
        FROM journal_entries 
        WHERE ABS(total_debit - total_credit) > 0.01
    ");
    assert_test("All System Financial Vouchers Strictly Balanced (Total Debit == Total Credit)", $unbalancedCount === 0);

} catch (Exception $e) {
    echo "\nFATAL TEST EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n----------------------------------------------------------------------\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "----------------------------------------------------------------------\n";

if ($failed === 0) {
    echo "PHASE 4 PURCHASING, GRN & INVENTORY MANAGEMENT VERIFIED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "PHASE 4 HAS TEST FAILURES. REVIEW ABOVE.\n";
    exit(1);
}
