<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

// 1. Add columns to sales_returns
$retCols = array_column(db_fetch_all('DESCRIBE sales_returns'), 'Field');

if (!in_array('stock_settled', $retCols)) {
    db_execute("ALTER TABLE sales_returns ADD COLUMN stock_settled TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
    echo "Added sales_returns.stock_settled\n";
}

// 2. Add columns to sales_return_items
$itemCols = array_column(db_fetch_all('DESCRIBE sales_return_items'), 'Field');

if (!in_array('carton_qty', $itemCols)) {
    db_execute("ALTER TABLE sales_return_items ADD COLUMN carton_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_id");
    echo "Added sales_return_items.carton_qty\n";
}
if (!in_array('pack_qty', $itemCols)) {
    db_execute("ALTER TABLE sales_return_items ADD COLUMN pack_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER carton_qty");
    echo "Added sales_return_items.pack_qty\n";
}
if (!in_array('pcs_qty', $itemCols)) {
    db_execute("ALTER TABLE sales_return_items ADD COLUMN pcs_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER pack_qty");
    echo "Added sales_return_items.pcs_qty\n";
}
if (!in_array('discount_percent', $itemCols)) {
    db_execute("ALTER TABLE sales_return_items ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER rate");
    echo "Added sales_return_items.discount_percent\n";
}

echo "Sales return migration completed successfully.\n";
