<?php
/**
 * DMS - Distribution Management System
 * Migration: 2026-11-28 Add pricing_mode to orders and sales_invoices
 * 
 * Supports dynamic switching between Wholesale Price and MRP Price in real-time,
 * saving the selected price tier with orders and invoices for accurate sales and profit calculations.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    $db = Database::getConnection();

    echo "1. Checking orders table for pricing_mode column..." . PHP_EOL;
    $hasPricingModeOrders = (int)$db->query("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'orders' 
          AND COLUMN_NAME = 'pricing_mode'
    ")->fetchColumn();

    if ($hasPricingModeOrders === 0) {
        $db->exec("
            ALTER TABLE `orders` 
            ADD COLUMN `pricing_mode` ENUM('wholesale', 'mrp') NOT NULL DEFAULT 'wholesale' 
            AFTER `discount_mode`;
        ");
        echo " - Added column `pricing_mode` to `orders` table successfully." . PHP_EOL;
    } else {
        echo " - Column `pricing_mode` already exists in `orders`." . PHP_EOL;
    }

    echo "2. Checking sales_invoices table for pricing_mode column..." . PHP_EOL;
    $hasPricingModeInvoices = (int)$db->query("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'sales_invoices' 
          AND COLUMN_NAME = 'pricing_mode'
    ")->fetchColumn();

    if ($hasPricingModeInvoices === 0) {
        $db->exec("
            ALTER TABLE `sales_invoices` 
            ADD COLUMN `pricing_mode` ENUM('wholesale', 'mrp') NOT NULL DEFAULT 'wholesale' 
            AFTER `discount_mode`;
        ");
        echo " - Added column `pricing_mode` to `sales_invoices` table successfully." . PHP_EOL;
    } else {
        echo " - Column `pricing_mode` already exists in `sales_invoices`." . PHP_EOL;
    }

    echo "3. Ensuring product MRP / Retail rates are safely synchronized..." . PHP_EOL;
    // If retail_carton_rate is empty, copy from retail_rate
    $db->exec("
        UPDATE products 
        SET retail_carton_rate = retail_rate 
        WHERE (retail_carton_rate IS NULL OR retail_carton_rate = 0) 
          AND retail_rate > 0 AND retail_rate < 100000;
    ");

    // If retail_piece_rate is empty, divide retail_carton_rate by pcs_per_ctn
    $db->exec("
        UPDATE products 
        SET retail_piece_rate = ROUND(retail_carton_rate / GREATEST(1, carton_qty * pack_qty), 2) 
        WHERE (retail_piece_rate IS NULL OR retail_piece_rate = 0) 
          AND retail_carton_rate > 0 AND retail_carton_rate < 100000;
    ");

    // Fallback: If retail_carton_rate is still 0, default to carton_rate so MRP is never 0
    $db->exec("
        UPDATE products 
        SET retail_carton_rate = carton_rate,
            retail_piece_rate = piece_rate,
            retail_rate = wholesale_rate 
        WHERE (retail_carton_rate IS NULL OR retail_carton_rate = 0) 
          AND carton_rate > 0 AND carton_rate < 100000;
    ");

    echo " - Product retail/MRP rates synchronized successfully." . PHP_EOL;
    echo "Migration completed successfully!" . PHP_EOL;

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    throw $e;
}
