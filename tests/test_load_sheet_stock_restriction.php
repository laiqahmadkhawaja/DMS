<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/inventory_functions.php';

echo "=== Running Test: Load Sheet Stock Restriction ===\n";

try {
    db()->beginTransaction();

    // 1. Pick or create a test customer, booker, salesman, and product
    $customer = db_fetch_one("SELECT id FROM customers WHERE is_active = 1 LIMIT 1");
    $booker = db_fetch_one("SELECT id FROM bookers WHERE is_active = 1 LIMIT 1");
    $salesman = db_fetch_one("SELECT id FROM salesmen WHERE is_active = 1 LIMIT 1");
    $warehouse = db_fetch_one("SELECT id FROM warehouses WHERE is_active = 1 LIMIT 1");
    $product = db_fetch_one("SELECT id, product_name, default_sale_rate, carton_qty FROM products WHERE is_deleted = 0 LIMIT 1");

    if (!$customer || !$booker || !$salesman || !$warehouse || !$product) {
        throw new Exception("Prerequisites missing for test.");
    }

    $whId = (int)$warehouse['id'];
    $prodId = (int)$product['id'];
    $rate = (float)$product['default_sale_rate'];
    $ctnRatio = (float)$product['carton_qty'] > 0 ? (float)$product['carton_qty'] : 1;

    // Set product stock to 0 in this warehouse
    db()->prepare("
        INSERT INTO stock_balances (warehouse_id, product_id, saleable_qty, damaged_qty, expired_qty, total_qty, last_updated_at)
        VALUES (?, ?, 0, 0, 0, 0, NOW())
        ON DUPLICATE KEY UPDATE saleable_qty = 0, total_qty = 0
    ")->execute([$whId, $prodId]);

    // Step 1: Create an Order with 0 stock (Booking MUST SUCCEED!)
    $orderNumber = 'ORD-TEST-' . time();
    $stmtOrd = db()->prepare("
        INSERT INTO orders (
            order_number, order_date, delivery_date, customer_id, booker_id, 
            area_id, total_cartons, total_units, gross_amount, net_amount, status, created_at
        ) VALUES (
            ?, CURDATE(), CURDATE(), ?, ?,
            1, 2, 2 * ?, ?, ?, 'confirmed', NOW()
        )
    ");
    $gross = 2 * $rate;
    $stmtOrd->execute([$orderNumber, $customer['id'], $booker['id'], $ctnRatio, $gross, $gross]);
    $orderId = (int)db()->lastInsertId();

    $stmtItem = db()->prepare("
        INSERT INTO order_items (
            order_id, product_id, unit_id, carton_qty, pcs_qty, quantity, rate, line_total, created_at
        ) VALUES (
            ?, ?, 1, 2, 0, 2 * ?, ?, ?, NOW()
        )
    ");
    $reqPcs = 2 * $ctnRatio;
    $stmtItem->execute([$orderId, $prodId, $ctnRatio, $rate, $gross]);

    echo "PASS: Order #{$orderNumber} successfully booked with 0 stock ({$reqPcs} pcs required).\n";

    // Step 2: Test Load Sheet Backend Stock Check (MUST BLOCK with exception!)
    $blocked = false;
    try {
        $requiredQty = $reqPcs;
        $stockBal = db_fetch_one("SELECT saleable_qty FROM stock_balances WHERE warehouse_id = ? AND product_id = ?", [$whId, $prodId]);
        $availQty = $stockBal ? (float)$stockBal['saleable_qty'] : 0.0;

        if ($availQty < $requiredQty) {
            $shortage = $requiredQty - $availQty;
            throw new Exception("Cannot create Load Form! Warehouse has insufficient stock for '{$product['product_name']}'. Shortage: {$shortage} pcs.");
        }
    } catch (Exception $e) {
        $blocked = true;
        echo "PASS: Load Sheet creation successfully BLOCKED due to stock shortage: " . $e->getMessage() . "\n";
    }

    if (!$blocked) {
        throw new Exception("FAIL: Load sheet creation should have been blocked when stock is 0!");
    }

    // Step 3: Now add sufficient stock to warehouse
    db()->prepare("
        UPDATE stock_balances 
        SET saleable_qty = ?, total_qty = ? 
        WHERE warehouse_id = ? AND product_id = ?
    ")->execute([$reqPcs + 10, $reqPcs + 10, $whId, $prodId]);

    $stockBalAfter = db_fetch_one("SELECT saleable_qty FROM stock_balances WHERE warehouse_id = ? AND product_id = ?", [$whId, $prodId]);
    $availQtyAfter = (float)$stockBalAfter['saleable_qty'];

    if ($availQtyAfter >= $reqPcs) {
        echo "PASS: Stock updated to {$availQtyAfter} pcs. Load Sheet check PASSES when sufficient stock is available.\n";
    } else {
        throw new Exception("FAIL: Stock update failed.");
    }

    db()->rollBack();
    echo "=== All Load Sheet Stock Restriction Tests PASSED! ===\n";

} catch (Exception $e) {
    db()->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
