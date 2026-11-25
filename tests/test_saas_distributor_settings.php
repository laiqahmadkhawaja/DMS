<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

echo "=== TESTING DYNAMIC DISTRIBUTOR SETTINGS & BILL INSTRUCTIONS ===\n\n";

// 1. Test Dynamic Distributor Name
echo "1. Testing Distributor Name Dynamism...\n";
$originalName = get_setting('distributor_name', 'Muzzam Enterprises');
$testName = "Al-Madina Traders & Wholesale";

update_setting('distributor_name', $testName);
$loadedName = get_setting('distributor_name', '', true);
assert($loadedName === $testName, "Failed to update distributor name. Expected '{$testName}', got '{$loadedName}'");
echo "   [PASS] Distributor name updated in settings: {$loadedName}\n";

// 2. Test Phone Number Optionality
echo "\n2. Testing Optional Distributor Phone...\n";
update_setting('distributor_phone', '');
$emptyPhone = get_setting('distributor_phone', '', true);
assert($emptyPhone === '', "Phone should be allowable as empty");
echo "   [PASS] Distributor phone can be empty (optional).\n";

update_setting('distributor_phone', '0300-1234567');
$savedPhone = get_setting('distributor_phone', '', true);
assert($savedPhone === '0300-1234567', "Phone should store provided value");
echo "   [PASS] Distributor phone successfully stores value: {$savedPhone}\n";

// 3. Test Bill Instructions on Printout
echo "\n3. Testing Bill Instructions on Invoices...\n";
$testInstruction = "نوٹ: مال چیک کر کے وصول کریں۔ بعد میں واپسی نہیں ہوگی۔";
update_setting('bill_instructions', $testInstruction);
$savedInstruction = get_setting('bill_instructions', '', true);
assert($savedInstruction === $testInstruction, "Failed to save bill instructions");

// Render a mock bill using bill_print.php
$invoice = [
    'id' => 9999,
    'invoice_number' => 'INV-2026-99999',
    'invoice_date' => date('Y-m-d'),
    'customer_name' => 'Test Retailer Shop',
    'customer_phone' => '03009876543',
    'customer_address' => 'Main Bazaar',
    'area_name' => 'Model Town',
    'booker_name' => 'Ali Raza',
    'salesman_name' => 'Kashif Delivery',
    'gross_amount' => 1500.00,
    'net_amount' => 1500.00,
    'extra_discount_amount' => 0,
    'item_discount_amount' => 0,
    'paid_amount' => 0,
    'credit_amount' => 1500.00,
    'free_loose_pieces' => 0,
    'remarks' => ''
];
$invoiceItems = [
    [
        'id' => 1,
        'product_name' => 'Test FMCG Product 50g',
        'unit_name' => 'Ctn',
        'carton_qty' => 1,
        'pack_qty' => 0,
        'loose_qty' => 0,
        'rate' => 1500.00,
        'line_total' => 1500.00
    ]
];
$compactMode = false;
$fontSize = '11px';
$headerFontSize = '18px';
$lineHeight = '20px';

ob_start();
include __DIR__ . '/../templates/invoices/bill_print.php';
$billHtml = ob_get_clean();

assert(strpos($billHtml, $testInstruction) !== false, "Bill printout MUST include configured instructions!");
echo "   [PASS] Bill printout rendered with custom instructions successfully.\n";

// Test clearing bill instructions
update_setting('bill_instructions', '');
ob_start();
include __DIR__ . '/../templates/invoices/bill_print.php';
$billHtmlEmpty = ob_get_clean();
assert(strpos($billHtmlEmpty, 'bill-instructions') === false, "When bill_instructions is empty, container should not be rendered");
echo "   [PASS] Clean bill layout when instructions are blank.\n";

// 4. Test Order Voucher Print View (modules/orders/print.php)
echo "\n4. Testing Order Booking Print View (modules/orders/print.php)...\n";
update_setting('bill_instructions', $testInstruction);
$_GET['id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['roles'] = ['admin'];
$_SESSION['permissions'] = ['orders_view'];

ob_start();
include __DIR__ . '/../modules/orders/print.php';
$orderPrintHtml = ob_get_clean();

assert(strpos($orderPrintHtml, $testInstruction) !== false, "Order printout MUST include configured bill instructions!");
echo "   [PASS] Order print view includes bill instructions.\n";

// Restore original distributor name and settings
update_setting('distributor_name', $originalName);
update_setting('distributor_phone', '03000673276');
echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
