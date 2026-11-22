<?php
/**
 * Database Migration: Add carton_rate and piece_rate columns to products
 * and populate accurate rates for all products across the catalog.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    $db = Database::getConnection();

    echo "1. Checking products table columns..." . PHP_EOL;

    // Check if carton_rate column exists
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'carton_rate'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN carton_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER wholesale_rate");
        echo " - Added column: carton_rate" . PHP_EOL;
    }
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'piece_rate'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN piece_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER carton_rate");
        echo " - Added column: piece_rate" . PHP_EOL;
    }
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'purchase_carton_rate'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN purchase_carton_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER purchase_rate");
        echo " - Added column: purchase_carton_rate" . PHP_EOL;
    }
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'purchase_piece_rate'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN purchase_piece_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER purchase_carton_rate");
        echo " - Added column: purchase_piece_rate" . PHP_EOL;
    }
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'retail_carton_rate'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN retail_carton_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER retail_rate");
        echo " - Added column: retail_carton_rate" . PHP_EOL;
    }
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'retail_piece_rate'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN retail_piece_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER retail_carton_rate");
        echo " - Added column: retail_piece_rate" . PHP_EOL;
    }

    echo "2. Populating rates for all products..." . PHP_EOL;
    $products = $db->query("
        SELECT p.id, p.product_code, p.product_name, p.packing_description,
               p.carton_qty, p.pack_qty, p.purchase_rate, p.wholesale_rate, p.retail_rate
        FROM products p
        ORDER BY p.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stmtUpdate = $db->prepare("
        UPDATE products SET
            carton_rate = ?,
            piece_rate = ?,
            purchase_carton_rate = ?,
            purchase_piece_rate = ?,
            retail_carton_rate = ?,
            retail_piece_rate = ?,
            wholesale_rate = ?,
            purchase_rate = ?,
            retail_rate = ?
        WHERE id = ?
    ");

    $updated = 0;
    foreach ($products as $p) {
        $code = $p['product_code'];
        $ctnQty = (float)$p['carton_qty'] > 0 ? (float)$p['carton_qty'] : 1.0;
        $packQty = (float)$p['pack_qty'] > 0 ? (float)$p['pack_qty'] : 1.0;
        $pcsPerCtn = $ctnQty * $packQty;
        
        $wsRate = (float)$p['wholesale_rate'];
        $purRate = (float)$p['purchase_rate'];
        $retRate = (float)$p['retail_rate'];

        // 1. Kunafa: Already carton rate
        if (strpos($code, 'KUN-') === 0) {
            $cartonRate = $wsRate;
            $pieceRate = round($wsRate / $pcsPerCtn, 2);
            $purCartonRate = $purRate;
            $purPieceRate = round($purRate / $pcsPerCtn, 2);
            $retCartonRate = $retRate;
            $retPieceRate = round($retRate / $pcsPerCtn, 2);
        }
        // 2. Hashim Wafers (1-7): Carton rate
        elseif (in_array($code, ['HASH-001', 'HASH-002', 'HASH-003', 'HASH-004', 'HASH-005', 'HASH-006', 'HASH-007'])) {
            $cartonRate = $wsRate;
            $pieceRate = round($wsRate / $pcsPerCtn, 2);
            $purCartonRate = $purRate;
            $purPieceRate = round($purRate / $pcsPerCtn, 2);
            $retCartonRate = $retRate;
            $retPieceRate = round($retRate / $pcsPerCtn, 2);
        }
        // 3. Hashim Pack/Box rates (where rate was per pack/box, and carton has multiple packs/boxes)
        elseif (
            (intval(substr($code, 5)) >= 10 && intval(substr($code, 5)) <= 19) ||
            in_array($code, ['HASH-008', 'HASH-009', 'HASH-020', 'HASH-021', 'HASH-022', 'HASH-023', 'HASH-024', 'HASH-045', 'HASH-046', 'HASH-073', 'HASH-074'])
        ) {
            $cartonRate = round($wsRate * $ctnQty, 2);
            $pieceRate = round($wsRate / $packQty, 2);
            $purCartonRate = round($purRate * $ctnQty, 2);
            $purPieceRate = round($purRate / $packQty, 2);
            $retCartonRate = round($retRate * $ctnQty, 2);
            $retPieceRate = round($retRate / $packQty, 2);
        }
        // 4. Mamas 11kg bucket
        elseif ($code === 'HASH-042') {
            $cartonRate = $wsRate;
            $pieceRate = $wsRate;
            $purCartonRate = $purRate;
            $purPieceRate = $purRate;
            $retCartonRate = $retRate;
            $retPieceRate = $retRate;
        }
        // 5. All other items (4ME Air Fresheners, 4ME Soaps, Mamas Sauces, Mayo, Ketchup, Cocotea, ALL UJAB items):
        // wsRate was entered as PIECE rate
        else {
            $pieceRate = $wsRate;
            $cartonRate = round($wsRate * $pcsPerCtn, 2);
            $purPieceRate = $purRate;
            $purCartonRate = round($purRate * $pcsPerCtn, 2);
            $retPieceRate = $retRate;
            $retCartonRate = round($retRate * $pcsPerCtn, 2);
        }

        $stmtUpdate->execute([
            $cartonRate,
            $pieceRate,
            $purCartonRate,
            $purPieceRate,
            $retCartonRate,
            $retPieceRate,
            $cartonRate,     // wholesale_rate matches carton_rate as standard default
            $purCartonRate,  // purchase_rate matches pur_carton_rate
            $retCartonRate,  // retail_rate matches ret_carton_rate
            $p['id']
        ]);
        $updated++;
    }

    echo " - Successfully updated {$updated} products with exact carton and piece rates!" . PHP_EOL;

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}
