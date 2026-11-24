<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

echo "=== TESTING POS-STYLE PHYSICAL STOCK & OPENING STOCK ADJUSTMENT ===\n\n";

// Pick a test product
$prod = db_fetch_one("SELECT * FROM products WHERE is_active = 1 AND is_deleted = 0 LIMIT 1");
assert($prod !== null, "Product should exist");
echo "Selected Product: #{$prod['id']} - {$prod['product_name']} ({$prod['product_code']})\n";

$ratios = get_product_packaging_ratios($prod);
$pcsPerCtn = max(1.0, (float)($ratios['pcs_per_ctn'] ?? 1));
$costCtn = (float)($prod['purchase_rate'] ?? 0);
$costPc = (float)($prod['purchase_piece_rate'] ?? 0) > 0 
    ? (float)$prod['purchase_piece_rate'] 
    : round($costCtn / $pcsPerCtn, 6);

echo "Packaging: 1 Ctn = {$pcsPerCtn} Pcs | Cost: Rs. {$costCtn}/Ctn (Rs. {$costPc}/Pc)\n\n";

db()->beginTransaction();

try {
    $adjNumber = generate_document_number(PREFIX_ADJUSTMENT, 'stock_adjustments', 'adjustment_number');
    $warehouseId = 1;
    $adjDate = date('Y-m-d');
    $reason = "Opening Stock Initialization Test";

    // 1. Create Adjustment Header
    $stmt = db()->prepare("
        INSERT INTO stock_adjustments (
            adjustment_number, warehouse_id, adjustment_date, reason, status, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?, 'approved', 1, NOW()
        )
    ");
    $stmt->execute([$adjNumber, $warehouseId, $adjDate, $reason]);
    $adjId = (int)db()->lastInsertId();
    echo "[1] Inserted stock_adjustment header: ID {$adjId}, Number: {$adjNumber}\n";

    // 2. Add 5 cartons
    $ctnQty = 5.0;
    $packQty = 0.0;
    $pcsQty = 0.0;
    $basePieces = calculate_base_pieces($ctnQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
    $lineTotalCost = round($basePieces * $costPc, 2);

    $stmtItem = db()->prepare("
        INSERT INTO stock_adjustment_items (
            stock_adjustment_id, product_id, carton_qty, pack_qty, pcs_qty, 
            adjustment_type, condition_type, quantity, unit_cost, total_amount, remarks, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 
            'add', 'saleable', ?, ?, ?, 'Test opening stock row', NOW()
        )
    ");
    $stmtItem->execute([$adjId, $prod['id'], $ctnQty, $packQty, $pcsQty, $basePieces, $costPc, $lineTotalCost]);
    echo "[2] Inserted stock_adjustment_item: {$ctnQty} Ctns ({$basePieces} units), Value: Rs. {$lineTotalCost}\n";

    // 3. Record Movement
    $movId = record_stock_movement([
        'product_id'     => $prod['id'],
        'warehouse_id'   => $warehouseId,
        'movement_type'  => 'opening_stock',
        'reference_type' => 'opening',
        'reference_id'   => $adjId,
        'qty_in'         => $basePieces,
        'qty_out'        => 0,
        'unit_cost'      => $costPc,
        'total_cost'     => $lineTotalCost,
        'remarks'        => "Initial Opening Stock Test",
        'condition_type' => 'saleable'
    ]);
    echo "[3] Recorded stock_movement ID: {$movId} (movement_type = opening_stock)\n";

    // 4. Verify Stock Balances Updated
    $bal = get_stock_balance($prod['id'], $warehouseId);
    echo "[4] Current Stock Balance: Saleable = {$bal['saleable_qty']}, Total = {$bal['total_qty']}, Value = Rs. {$bal['total_value']}\n";
    assert($bal['saleable_qty'] >= $basePieces, "Saleable quantity should have increased");

    // 5. Post GL Voucher
    $jv = post_journal_entry([
        'entry_date'     => $adjDate,
        'reference_type' => 'manual_journal',
        'reference_id'   => $adjId,
        'narration'      => "Initial Opening Stock Entry #{$adjNumber} - Valuation: " . format_currency($lineTotalCost)
    ], [
        ['account_id' => ACC_INVENTORY, 'debit' => $lineTotalCost, 'credit' => 0.00, 'description' => "Opening Inventory Asset - #{$adjNumber}"],
        ['account_id' => 3010, 'debit' => 0.00, 'credit' => $lineTotalCost, 'description' => "Owner Opening Equity / Capital - #{$adjNumber}"]
    ]);
    echo "[5] General Ledger Journal Entry Posted: JV #{$jv}\n";

    db()->rollBack();
    echo "\n=== ALL TESTS PASSED! Database rolled back cleanly. ===\n";

} catch (Exception $e) {
    db()->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
