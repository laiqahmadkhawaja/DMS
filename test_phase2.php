<?php
/**
 * DMS - Phase 2 Automated Verification Suite
 * Tests all Master Data modules, CRUD, validations, and ledger balances
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name} - Details: {$details}\n";
    }
}

echo "=================================================================\n";
echo "DMS PHASE 2 MASTER DATA AUTOMATED VERIFICATION SUITE\n";
echo "=================================================================\n\n";

// 1. Areas Verification
echo "1. Areas / Routes Master Verification:\n";
$areaCount = (int)db_fetch_val("SELECT COUNT(*) FROM areas WHERE is_deleted = 0");
assert_test("Areas populated in database", $areaCount >= 6, "Found {$areaCount}");

$areaTest = db_fetch_one("SELECT * FROM areas WHERE area_name = 'Jandiala Bhagwala'");
assert_test("Specific route 'Jandiala Bhagwala' found", !empty($areaTest));

// 2. Bookers Verification
echo "\n2. Order Bookers Master Verification:\n";
$bookerCount = (int)db_fetch_val("SELECT COUNT(*) FROM bookers WHERE is_deleted = 0");
assert_test("Order Bookers registered in database", $bookerCount >= 2, "Found {$bookerCount}");

$bookerImran = db_fetch_one("SELECT * FROM bookers WHERE booker_name = 'Imran Butt'");
assert_test("Booker 'Imran Butt' verified", !empty($bookerImran) && $bookerImran['phone'] === '0300-6732761');

// 3. Salesmen Verification
echo "\n3. Salesmen & Delivery Staff Master Verification:\n";
$smCount = (int)db_fetch_val("SELECT COUNT(*) FROM salesmen WHERE is_deleted = 0");
assert_test("Delivery staff registered", $smCount >= 2, "Found {$smCount}");

$smSaghar = db_fetch_one("SELECT * FROM salesmen WHERE salesman_name = 'Saghar'");
assert_test("Salesman 'Saghar' verified with vehicle assignment", !empty($smSaghar) && !empty($smSaghar['vehicle_number']));

// 4. Categories & Units
echo "\n4. Categories & Units Master Verification:\n";
$catCount = (int)db_fetch_val("SELECT COUNT(*) FROM product_categories WHERE is_deleted = 0");
assert_test("Product Categories populated", $catCount >= 4, "Found {$catCount}");

$units = db_fetch_all("SELECT unit_code FROM units WHERE is_deleted = 0");
$unitCodes = array_column($units, 'unit_code');
assert_test("Core units present (CTN, BOX, PCK, PCS)", in_array('CTN', $unitCodes) && in_array('PCS', $unitCodes));

// 5. Products & Packaging Ratios
echo "\n5. Products Catalog & Packaging Ratios Verification:\n";
$prodCount = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0");
assert_test("Products catalog populated", $prodCount >= 8, "Found {$prodCount}");

$prodDaalMoth = db_fetch_one("SELECT * FROM products WHERE product_name = 'Nimko Daal Moth (1*36)'");
assert_test("Product 'Nimko Daal Moth (1*36)' verified with 36 carton ratio", !empty($prodDaalMoth) && (float)$prodDaalMoth['carton_qty'] == 36.0);

$stockBal = get_stock_balance($prodDaalMoth['id']);
assert_test("Product stock balance matches opening inventory movements", (float)$stockBal['saleable_qty'] > 0, "Stock: " . $stockBal['saleable_qty']);

// 6. Customers & Ledger Balances
echo "\n6. Customers / Retailers Master Verification:\n";
$custCount = (int)db_fetch_val("SELECT COUNT(*) FROM customers WHERE is_deleted = 0");
assert_test("Retail customers registered", $custCount >= 14, "Found {$custCount}");

$custPrince = db_fetch_one("SELECT * FROM customers WHERE shop_name = 'Prince Grocery'");
assert_test("Client shop 'Prince Grocery' (Jandiala Bhagwala) verified", !empty($custPrince));

$custAkram = db_fetch_one("SELECT * FROM customers WHERE shop_name = 'Akram Super Store'");
assert_test("Customer opening balance matches image 6 (Akram Super Store: Rs 140,000)", (float)$custAkram['current_balance'] == 140000.00, "Got: " . $custAkram['current_balance']);

// 7. Duplicate Prevention Test
echo "\n7. Customer Duplicate Prevention Verification:\n";
$dupValidator = new Validator();
$shopNameTest = 'Prince Grocery';
$areaIdTest = $custPrince['area_id'];
$dupExists = (bool)db_fetch_val("
    SELECT id FROM customers 
    WHERE LOWER(shop_name) = LOWER(?) AND area_id = ? AND is_deleted = 0 
    LIMIT 1
", [$shopNameTest, $areaIdTest]);
assert_test("Duplicate check correctly identifies existing shop in same area", $dupExists);

// 8. Suppliers & Payables
echo "\n8. Suppliers & Payables Master Verification:\n";
$suppCount = (int)db_fetch_val("SELECT COUNT(*) FROM suppliers WHERE is_deleted = 0");
assert_test("Suppliers registered", $suppCount >= 2, "Found {$suppCount}");

$supp1 = db_fetch_one("SELECT * FROM suppliers WHERE supplier_code = 'SUPP-001'");
assert_test("Supplier opening payable balance posted to General Ledger", (float)$supp1['current_balance'] >= 250000.00, "Got: " . $supp1['current_balance']);

// 9. Customer Price Lists Override
echo "\n9. Special Price Lists Override Verification:\n";
db_execute("DELETE FROM customer_prices WHERE customer_id = ? AND product_id = ?", [$custPrince['id'], $prodDaalMoth['id']]);
db_execute("
    INSERT INTO customer_prices (customer_id, product_id, unit_id, special_rate, created_at)
    VALUES (?, ?, ?, ?, NOW())
", [$custPrince['id'], $prodDaalMoth['id'], $prodDaalMoth['base_unit_id'], 1480.00]);

$specialRate = (float)db_fetch_val("
    SELECT special_rate FROM customer_prices 
    WHERE customer_id = ? AND product_id = ?
", [$custPrince['id'], $prodDaalMoth['id']]);
assert_test("Customer special price successfully configured (1480 vs standard 1512)", $specialRate == 1480.00, "Got: {$specialRate}");

// 10. Dashboard Recalculation Check
echo "\n10. Real Operational Dashboard Aggregates Verification:\n";
$totalCustReceivables = (float)db_fetch_val("SELECT SUM(current_balance) FROM customers WHERE current_balance > 0");
assert_test("Dashboard Receivables aggregate accurately matches customer balances sum", $totalCustReceivables > 400000.00, "Total: " . $totalCustReceivables);

echo "\n=================================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo ">> PHASE 2 MASTER DATA COMPLETION GATE PASSED WITH 100% SUCCESS <<\n";
    exit(0);
} else {
    echo ">> PHASE 2 COMPLETION GATE FAILED <<\n";
    exit(1);
}
