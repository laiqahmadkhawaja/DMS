<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

$hasCol = db_fetch_one("SHOW COLUMNS FROM products LIKE 'packing_description'");
if (!$hasCol) {
    db()->exec("ALTER TABLE products ADD COLUMN packing_description VARCHAR(100) NULL AFTER pack_qty");
    echo "Added packing_description column to products." . PHP_EOL;
} else {
    echo "packing_description already exists." . PHP_EOL;
}
