<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['roles'] = ['admin'];
$_SESSION['permissions'] = ['*'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';

require_once __DIR__ . '/../config/config.php';

echo "=== DATABASE VERIFICATION ===\n";
$suppliers = db_fetch_all("SELECT id, supplier_code, supplier_name, company_name FROM suppliers");
foreach ($suppliers as $s) {
    $cnt = db_fetch_val("SELECT COUNT(*) FROM products WHERE supplier_id = ?", [$s['id']]);
    echo "Supplier ID: {$s['id']} | Code: {$s['supplier_code']} | Name: {$s['supplier_name']} | Count: {$cnt}\n";
}

$totalProds = db_fetch_val("SELECT COUNT(*) FROM products");
$totalZeroStock = db_fetch_val("SELECT COUNT(*) FROM stock_balances WHERE saleable_qty = 0");
echo "Total Products in DB: {$totalProds}\n";
echo "Total Zero Stock Balances: {$totalZeroStock}\n";

$sampleSMS = db_fetch_all("SELECT product_code, product_name, packing_description, carton_qty, purchase_rate, wholesale_rate FROM products WHERE supplier_id = 2 ORDER BY id DESC LIMIT 5");
echo "\n=== SAMPLE SMS PRODUCTS (Excel imported items) ===\n";
foreach ($sampleSMS as $p) {
    echo "{$p['product_code']} | {$p['product_name']} | Packing: {$p['packing_description']} | CtnQty: {$p['carton_qty']} | Cost: {$p['purchase_rate']} | Wholesale: {$p['wholesale_rate']}\n";
}

$sampleUjab = db_fetch_all("SELECT product_code, product_name, packing_description, carton_qty, purchase_rate, wholesale_rate FROM products WHERE supplier_id = 1 LIMIT 5");
echo "\n=== SAMPLE UJAB PRODUCTS ===\n";
foreach ($sampleUjab as $p) {
    echo "{$p['product_code']} | {$p['product_name']} | Packing: {$p['packing_description']} | CtnQty: {$p['carton_qty']} | Cost: {$p['purchase_rate']} | Wholesale: {$p['wholesale_rate']}\n";
}

echo "\n=== TESTING CORE MODULE PAGES ===\n";
$pages = [
    '/Distribution/modules/products/index.php' => __DIR__ . '/../modules/products/index.php',
    '/Distribution/modules/products/import.php' => __DIR__ . '/../modules/products/import.php',
    '/Distribution/modules/purchases/create.php' => __DIR__ . '/../modules/purchases/create.php',
    '/Distribution/modules/invoices/create.php' => __DIR__ . '/../modules/invoices/create.php',
    '/Distribution/modules/returns/create.php' => __DIR__ . '/../modules/returns/create.php',
    '/Distribution/modules/inventory/index.php' => __DIR__ . '/../modules/inventory/index.php',
    '/Distribution/modules/reports/daily_closing.php' => __DIR__ . '/../modules/reports/daily_closing.php',
];

foreach ($pages as $uri => $file) {
    $_SERVER['SCRIPT_NAME'] = $uri;
    $_GET = [];
    ob_start();
    try {
        require $file;
        $len = strlen(ob_get_clean());
        echo "[PASS] " . basename($file) . " (rendered {$len} bytes)\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "[FAIL] " . basename($file) . " - " . $e->getMessage() . " on line " . $e->getLine() . "\n";
    }
}
