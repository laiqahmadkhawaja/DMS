<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

// 1. Add columns to orders table
$ordersCols = array_column(db_fetch_all('DESCRIBE orders'), 'Field');

if (!in_array('discount_mode', $ordersCols)) {
    db_execute("ALTER TABLE orders ADD COLUMN discount_mode ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate' AFTER area_id");
    echo "Added orders.discount_mode\n";
}
if (!in_array('subtotal_discount_percent', $ordersCols)) {
    db_execute("ALTER TABLE orders ADD COLUMN subtotal_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_mode");
    echo "Added orders.subtotal_discount_percent\n";
}
if (!in_array('subtotal_discount_amount', $ordersCols)) {
    db_execute("ALTER TABLE orders ADD COLUMN subtotal_discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER subtotal_discount_percent");
    echo "Added orders.subtotal_discount_amount\n";
}

// 2. Add columns to sales_invoices table
$invCols = array_column(db_fetch_all('DESCRIBE sales_invoices'), 'Field');

if (!in_array('discount_mode', $invCols)) {
    db_execute("ALTER TABLE sales_invoices ADD COLUMN discount_mode ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate' AFTER area_id");
    echo "Added sales_invoices.discount_mode\n";
}
if (!in_array('subtotal_discount_percent', $invCols)) {
    db_execute("ALTER TABLE sales_invoices ADD COLUMN subtotal_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER extra_discount_amount");
    echo "Added sales_invoices.subtotal_discount_percent\n";
}

echo "Database migration completed successfully.\n";
