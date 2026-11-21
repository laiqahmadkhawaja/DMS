<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

// 1. Add columns to purchase_orders table
$poCols = array_column(db_fetch_all('DESCRIBE purchase_orders'), 'Field');

if (!in_array('discount_mode', $poCols)) {
    db_execute("ALTER TABLE purchase_orders ADD COLUMN discount_mode ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate' AFTER supplier_invoice_no");
    echo "Added purchase_orders.discount_mode\n";
}
if (!in_array('subtotal_discount_percent', $poCols)) {
    db_execute("ALTER TABLE purchase_orders ADD COLUMN subtotal_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_amount");
    echo "Added purchase_orders.subtotal_discount_percent\n";
}
if (!in_array('subtotal_discount_amount', $poCols)) {
    db_execute("ALTER TABLE purchase_orders ADD COLUMN subtotal_discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER subtotal_discount_percent");
    echo "Added purchase_orders.subtotal_discount_amount\n";
}
if (!in_array('total_cartons', $poCols)) {
    db_execute("ALTER TABLE purchase_orders ADD COLUMN total_cartons DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER subtotal_discount_amount");
    echo "Added purchase_orders.total_cartons\n";
}
if (!in_array('total_units', $poCols)) {
    db_execute("ALTER TABLE purchase_orders ADD COLUMN total_units DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_cartons");
    echo "Added purchase_orders.total_units\n";
}

// 2. Add columns to purchase_order_items table
$poiCols = array_column(db_fetch_all('DESCRIBE purchase_order_items'), 'Field');

if (!in_array('carton_qty', $poiCols)) {
    db_execute("ALTER TABLE purchase_order_items ADD COLUMN carton_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_id");
    echo "Added purchase_order_items.carton_qty\n";
}
if (!in_array('pack_qty', $poiCols)) {
    db_execute("ALTER TABLE purchase_order_items ADD COLUMN pack_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER carton_qty");
    echo "Added purchase_order_items.pack_qty\n";
}
if (!in_array('pcs_qty', $poiCols)) {
    db_execute("ALTER TABLE purchase_order_items ADD COLUMN pcs_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER pack_qty");
    echo "Added purchase_order_items.pcs_qty\n";
}
if (!in_array('discount_percent', $poiCols)) {
    db_execute("ALTER TABLE purchase_order_items ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER rate");
    echo "Added purchase_order_items.discount_percent\n";
}

echo "Purchase order migration completed successfully.\n";
