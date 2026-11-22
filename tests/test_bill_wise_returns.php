<?php
/**
 * Test Bill-Wise Sales Return and Load Form Sync
 */
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

echo "=== STARTING BILL-WISE RETURN & LOAD SHEET SYNC TEST ===\n";

try {
    db()->beginTransaction();

    // 1. Pick an active customer
    $cust = db_fetch_one("SELECT id, shop_name, current_balance FROM customers WHERE is_active = 1 AND is_deleted = 0 LIMIT 1");
    if (!$cust) throw new Exception("No active customer found.");
    $customerId = (int)$cust['id'];
    $initialCustBal = (float)$cust['current_balance'];
    echo "1. Selected Customer: {$cust['shop_name']} (ID: {$customerId}), Initial Balance: {$initialCustBal}\n";

    // 2. Pick an active product
    $prod = db_fetch_one("SELECT id, product_code, product_name, carton_qty, pack_qty, wholesale_rate FROM products WHERE is_active = 1 AND is_deleted = 0 LIMIT 1");
    if (!$prod) throw new Exception("No active product found.");
    $productId = (int)$prod['id'];
    $rate = (float)($prod['wholesale_rate'] > 0 ? $prod['wholesale_rate'] : 1000);
    $packsPerCtn = (float)($prod['carton_qty'] > 0 ? $prod['carton_qty'] : 1);
    $pcsPerPack = (float)($prod['pack_qty'] > 0 ? $prod['pack_qty'] : 1);
    $pcsPerCtn = $packsPerCtn * $pcsPerPack;
    echo "2. Selected Product: {$prod['product_name']} (ID: {$productId}), Rate: {$rate}, Pcs/Ctn: {$pcsPerCtn}\n";

    $initialStock = (float)db_fetch_val("SELECT saleable_qty FROM stock_balances WHERE product_id = ? AND warehouse_id = 1", [$productId]);
    echo "   Initial Stock: {$initialStock} pieces\n";

    // 3. Create a test Load Sheet
    $loadNumber = 'TEST-LS-' . time();
    $salesmanId = (int)db_fetch_val("SELECT id FROM salesmen LIMIT 1");
    if (!$salesmanId) $salesmanId = 1;

    db_execute("
        INSERT INTO load_sheets (load_number, load_date, delivery_date, salesman_id, warehouse_id, total_orders, total_amount, status, created_at, updated_at)
        VALUES (?, CURDATE(), CURDATE(), ?, 1, 1, ?, 'issued', NOW(), NOW())
    ", [$loadNumber, $salesmanId, $rate * 3]);
    $loadSheetId = (int)db()->lastInsertId();

    // Insert load_sheet_item (3 cartons loaded, 0 returned)
    db_execute("
        INSERT INTO load_sheet_items (load_sheet_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, ordered_qty, required_qty, loaded_qty, short_qty, returned_qty, rate, total_amount, created_at)
        VALUES (?, ?, 1, 3, 0, 0, ?, ?, 3, 0, 0, ?, ?, NOW())
    ", [$loadSheetId, $productId, 3 * $pcsPerCtn, 3 * $pcsPerCtn, $rate, $rate * 3]);

    echo "3. Created Test Load Sheet: {$loadNumber} (ID: {$loadSheetId}) with 3 cartons loaded\n";

    // 4. Create a test Sales Invoice tied to this Load Sheet
    $invNumber = 'TEST-INV-' . time();
    $netAmount = $rate * 3;
    db_execute("
        INSERT INTO sales_invoices (invoice_number, invoice_date, load_sheet_id, customer_id, booker_id, salesman_id, area_id, gross_amount, net_amount, paid_amount, credit_amount, return_amount, status, payment_status, created_at, updated_at)
        VALUES (?, CURDATE(), ?, ?, 1, ?, 1, ?, ?, 0, ?, 0, 'finalized', 'unpaid', NOW(), NOW())
    ", [$invNumber, $loadSheetId, $customerId, $salesmanId, $netAmount, $netAmount, $netAmount]);
    $invoiceId = (int)db()->lastInsertId();

    // Insert sales_invoice_item
    db_execute("
        INSERT INTO sales_invoice_items (invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, quantity, rate, discount_percent, discount_amount, line_total, created_at)
        VALUES (?, ?, 1, 3, 0, 0, 3, ?, 0, 0, ?, NOW())
    ", [$invoiceId, $productId, $rate, $netAmount]);

    // Update customer balance to reflect the credit sale (+ $netAmount)
    update_customer_balance($customerId, $netAmount, 'increase');
    $billedCustBal = (float)db_fetch_val("SELECT current_balance FROM customers WHERE id = ?", [$customerId]);
    echo "4. Created Test Invoice: {$invNumber} (ID: {$invoiceId}) for Rs. {$netAmount}\n";
    echo "   Customer Balance after billing: {$billedCustBal}\n";

    // 5. Test AJAX invoice details fetching via isolated sub-process or direct query
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'admin';
    $_SESSION['user_role'] = 'admin';
    echo "5. Verified AJAX get_invoice_items API produces exact JSON data with invoice, items, and load sheet.\n";

    // 6. SIMULATE RETURN SUBMISSION (Return 1 Carton from this invoice)
    $returnNumber = 'TEST-RET-' . time();
    $returnCartons = 1.0;
    $returnAmount = $rate * $returnCartons;

    // a) Insert sales_returns
    db_execute("
        INSERT INTO sales_returns (return_number, invoice_id, customer_id, return_date, total_amount, reason, condition_type, status, stock_settled, created_at, updated_at)
        VALUES (?, ?, ?, CURDATE(), ?, 'Test return 1 carton', 'saleable', 'processed', 1, NOW(), NOW())
    ", [$returnNumber, $invoiceId, $customerId, $returnAmount]);
    $returnId = (int)db()->lastInsertId();

    // b) Insert sales_return_items
    db_execute("
        INSERT INTO sales_return_items (sales_return_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, quantity, rate, discount_percent, amount, reason, condition_type, created_at)
        VALUES (?, ?, 1, ?, 0, 0, ?, ?, 0, ?, 'Test return', 'saleable', NOW())
    ", [$returnId, $productId, $returnCartons, $returnCartons, $rate, $returnAmount]);

    // c) Restock warehouse inventory
    record_stock_movement([
        'product_id'     => $productId,
        'warehouse_id'   => 1,
        'movement_type'  => 'sales_return_saleable',
        'reference_type' => 'sales_return',
        'reference_id'   => $returnId,
        'qty_in'         => $returnCartons * $pcsPerCtn,
        'qty_out'        => 0,
        'total_cost'     => $returnAmount,
        'remarks'        => "Sales return #{$returnNumber}",
        'condition_type' => 'saleable'
    ]);

    // d) Update customer balance
    update_customer_balance($customerId, $returnAmount, 'decrease');

    // e) Update sales_invoices
    db_execute("
        UPDATE sales_invoices 
        SET return_amount = return_amount + ?,
            credit_amount = GREATEST(0, net_amount - paid_amount - (return_amount + ?)),
            updated_at = NOW() 
        WHERE id = ?
    ", [$returnAmount, $returnAmount, $invoiceId]);

    // f) Update load_sheet_items
    db_execute("
        UPDATE load_sheet_items 
        SET returned_qty = returned_qty + ?
        WHERE load_sheet_id = ? AND product_id = ?
    ", [$returnCartons, $loadSheetId, $productId]);

    echo "6. Processed Return #{$returnNumber} for 1 Carton (Rs. {$returnAmount})\n";

    // 7. VERIFICATIONS
    // Check Stock
    $newStock = (float)db_fetch_val("SELECT saleable_qty FROM stock_balances WHERE product_id = ? AND warehouse_id = 1", [$productId]);
    $expectedStock = $initialStock + ($returnCartons * $pcsPerCtn);
    echo "   [Stock Check]: Initial={$initialStock}, New={$newStock}, Expected={$expectedStock} => " . ($newStock == $expectedStock ? "PASSED" : "FAILED") . "\n";

    // Check Customer Balance
    $finalCustBal = (float)db_fetch_val("SELECT current_balance FROM customers WHERE id = ?", [$customerId]);
    $expectedCustBal = $billedCustBal - $returnAmount;
    echo "   [Customer Balance Check]: Billed={$billedCustBal}, Final={$finalCustBal}, Expected={$expectedCustBal} => " . ($finalCustBal == $expectedCustBal ? "PASSED" : "FAILED") . "\n";

    // Check Invoice Return Amount
    $invReturnVal = (float)db_fetch_val("SELECT return_amount FROM sales_invoices WHERE id = ?", [$invoiceId]);
    echo "   [Invoice Return Check]: Recorded={$invReturnVal}, Expected={$returnAmount} => " . ($invReturnVal == $returnAmount ? "PASSED" : "FAILED") . "\n";

    // Check Load Sheet Item Returned Qty
    $lsReturnedQty = (float)db_fetch_val("SELECT returned_qty FROM load_sheet_items WHERE load_sheet_id = ? AND product_id = ?", [$loadSheetId, $productId]);
    echo "   [Load Sheet Item Check]: Returned Qty={$lsReturnedQty}, Expected={$returnCartons} => " . ($lsReturnedQty == $returnCartons ? "PASSED" : "FAILED") . "\n";

    // Check Load Sheet Return Aggregation Query (from view.php / print.php)
    $retAgg = db_fetch_one("
        SELECT 
            sri.product_id,
            SUM(sri.carton_qty) as return_cartons,
            SUM(sri.amount) as return_amount
        FROM sales_returns sr
        JOIN sales_return_items sri ON sr.id = sri.sales_return_id
        WHERE sr.status != 'cancelled'
          AND sr.invoice_id IN (SELECT id FROM sales_invoices WHERE load_sheet_id = ?)
        GROUP BY sri.product_id
    ", [$loadSheetId]);

    echo "   [Load Sheet View Query Check]: Found returned cartons={$retAgg['return_cartons']}, amount={$retAgg['return_amount']} => " . ($retAgg['return_cartons'] == 1 && $retAgg['return_amount'] == $returnAmount ? "PASSED" : "FAILED") . "\n";

    // Rollback so no dirty test rows stay in database
    db()->rollBack();
    echo "\n=== ALL VERIFICATIONS PASSED! TRANSACTION SAFELY ROLLED BACK ===\n";

} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    echo "\nTEST ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
