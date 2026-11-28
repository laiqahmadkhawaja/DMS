<?php
/**
 * Database Migration: Add carton_rate and piece_rate columns to products
 * and safely initialize rates without multiplying or corrupting existing prices.
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

    echo "2. Ensuring missing rates are safely initialized (zero multiplication)..." . PHP_EOL;
    
    // Safely initialize carton_rate from wholesale_rate if carton_rate is 0 or null
    $db->exec("
        UPDATE products 
        SET carton_rate = wholesale_rate 
        WHERE (carton_rate IS NULL OR carton_rate = 0) AND wholesale_rate > 0 AND wholesale_rate < 100000;
    ");

    // Safely calculate piece_rate by DIVIDING carton_rate by total pieces per carton
    $db->exec("
        UPDATE products 
        SET piece_rate = ROUND(carton_rate / GREATEST(1, carton_qty * pack_qty), 2) 
        WHERE (piece_rate IS NULL OR piece_rate = 0) AND carton_rate > 0 AND carton_rate < 100000;
    ");

    // Safely initialize purchase_carton_rate from purchase_rate
    $db->exec("
        UPDATE products 
        SET purchase_carton_rate = purchase_rate 
        WHERE (purchase_carton_rate IS NULL OR purchase_carton_rate = 0) AND purchase_rate > 0 AND purchase_rate < 100000;
    ");

    // Safely calculate purchase_piece_rate by DIVIDING purchase_carton_rate
    $db->exec("
        UPDATE products 
        SET purchase_piece_rate = ROUND(purchase_carton_rate / GREATEST(1, carton_qty * pack_qty), 2) 
        WHERE (purchase_piece_rate IS NULL OR purchase_piece_rate = 0) AND purchase_carton_rate > 0 AND purchase_carton_rate < 100000;
    ");

    echo " - Product rate columns verified and initialized safely." . PHP_EOL;

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}
