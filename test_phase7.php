<?php
/**
 * DMS - Distribution Management System
 * Phase 7 Verification Suite - Printing & Paper Layouts
 * Matches Client Photos 1, 2, 3, 5, 7, 8, 9
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/database_helpers.php';
require_once __DIR__ . '/includes/functions.php';

$passed = 0;
$failed = 0;

function assert_test($description, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description} - {$details}\n";
    }
}

echo "====================================================================\n";
echo "DMS PHASE 7 VERIFICATION: PRINTING & PAPER LAYOUTS (PHOTOS 1-9)\n";
echo "====================================================================\n\n";

// 1. Check all required template and endpoint files exist
echo "--- 1. Checking Template & Endpoint Files ---\n";
$requiredFiles = [
    'templates/invoices/bill_print.php',
    'templates/invoices/multi_bill_print.php',
    'modules/invoices/print.php',
    'modules/invoices/batch_print.php',
    'templates/loading_sheets/load_sheet_print.php',
    'modules/loading/print.php',
    'templates/delivery_sheets/delivery_print.php',
    'modules/delivery/print.php',
    'templates/settlements/settlement_print.php',
    'modules/settlements/print.php',
    'modules/orders/print.php',
    'modules/purchases/print.php',
    'modules/goods_receiving/print.php',
    'modules/returns/print.php',
    'modules/accounts/print_receipt.php',
    'modules/accounts/print_supplier_payment.php',
    'modules/accounts/print_journal.php',
    'modules/expenses/print.php'
];

foreach ($requiredFiles as $file) {
    $exists = file_exists(__DIR__ . '/' . $file);
    assert_test("File exists: {$file}", $exists);
}

// 2. Syntax check all print files via php -l
echo "\n--- 2. Syntax Verification for Print Files ---\n";
foreach ($requiredFiles as $file) {
    $filePath = escapeshellarg(__DIR__ . '/' . $file);
    $output = [];
    $returnVar = 0;
    exec("php -l {$filePath} 2>&1", $output, $returnVar);
    $isClean = ($returnVar === 0);
    assert_test("Syntax check: {$file}", $isClean, implode(' ', $output));
}

// 3. Test Single Bill Print Template (Photo 2 & 7)
echo "\n--- 3. Testing Single Bill Printable Template (Photo 2 & 7) ---\n";
$mockInvoice = [
    'id' => 75,
    'invoice_number' => 'BILL NO. 75',
    'invoice_date' => '2026-11-19',
    'customer_name' => 'Ramzan Sweets',
    'customer_address' => 'Daal Bazar',
    'area_name' => 'Daal Bazar',
    'booker_name' => 'Imran Butt',
    'salesman_name' => 'Route Delivery Saghar',
    'net_amount' => 35000.00
];

$mockInvoiceItems = [
    [
        'product_name' => 'Booster Candy',
        'carton_qty' => 5,
        'pcs_qty' => 0,
        'ctn_ratio' => 24,
        'pack_code' => 'M',
        'quantity' => 120,
        'rate' => 7000.00,
        'line_total' => 35000.00
    ]
];

ob_start();
$invoice = $mockInvoice;
$invoiceItems = $mockInvoiceItems;
$copyLabel = 'Customer Copy';
$padRows = 8;
$compactMode = false;
require __DIR__ . '/templates/invoices/bill_print.php';
$billHtml = ob_get_clean();

assert_test("Bill contains Client Name (Muzzam Enterprises)", strpos($billHtml, CLIENT_NAME) !== false);
assert_test("Bill contains Phone & Gujranwala Address", strpos($billHtml, 'Jandiala Bhagwala G.T. Road Gujranwala') !== false);
assert_test("Bill contains Product 'Booster Candy'", strpos($billHtml, 'Booster Candy') !== false);
assert_test("Bill contains Booker 'Imran Butt'", strpos($billHtml, 'Imran Butt') !== false);
assert_test("Bill contains Customer 'Ramzan Sweets'", strpos($billHtml, 'Ramzan Sweets') !== false);
assert_test("Bill contains Total '35,000'", strpos($billHtml, '35,000') !== false);
assert_test("Bill contains Yellow Total Styling (#c99c04)", strpos($billHtml, '#c99c04') !== false);
assert_test("Bill contains Copy Badge 'Customer Copy'", strpos($billHtml, 'Customer Copy') !== false);

// 4. Test Multi-Bill Print Template (1-up, 2-up, 3-up, Duplicate Mode)
echo "\n--- 4. Testing Multi-Bill Layout Orchestrator ---\n";

// Test 2-up Split Half A4 with Cutting Line
$mockInvoices = [
    $mockInvoice,
    [
        'id' => 76,
        'invoice_number' => 'BILL NO. 76',
        'invoice_date' => '2026-11-19',
        'customer_name' => 'Prince Grocery',
        'customer_address' => 'Jandiala Bhagwala',
        'area_name' => 'Jandiala',
        'booker_name' => 'Imran Butt',
        'salesman_name' => 'Saghar',
        'net_amount' => 9600.00
    ]
];
$mockItemsByInvoice = [
    75 => $mockInvoiceItems,
    76 => [
        [
            'product_name' => 'Nimko Daal Moth (1*36)',
            'carton_qty' => 1,
            'pcs_qty' => 0,
            'ctn_ratio' => 36,
            'pack_code' => '',
            'quantity' => 36,
            'rate' => 1512.00,
            'line_total' => 1512.00
        ]
    ]
];

ob_start();
$invoices = $mockInvoices;
$itemsByInvoice = $mockItemsByInvoice;
$layoutMode = '2-up';
$duplicateCopies = false;
$showCutLine = true;
require __DIR__ . '/templates/invoices/multi_bill_print.php';
$multiHtml2Up = ob_get_clean();

assert_test("2-up layout generates cut-line indicator (Cut Here)", strpos($multiHtml2Up, 'Cut Here') !== false);
assert_test("2-up layout generates scissor icon (✁)", strpos($multiHtml2Up, '✁') !== false);
assert_test("2-up layout contains bill-slot-2up class", strpos($multiHtml2Up, 'bill-slot-2up') !== false);

// Test Duplicate Copy Mode (Customer + Office Copy on same sheet)
ob_start();
$invoices = [$mockInvoice];
$itemsByInvoice = [75 => $mockInvoiceItems];
$layoutMode = '2-up';
$duplicateCopies = true;
$showCutLine = true;
require __DIR__ . '/templates/invoices/multi_bill_print.php';
$duplicateHtml = ob_get_clean();

assert_test("Duplicate mode generates 'Customer Copy'", strpos($duplicateHtml, 'Customer Copy') !== false);
assert_test("Duplicate mode generates 'Office Copy'", strpos($duplicateHtml, 'Office Copy') !== false);
assert_test("Duplicate mode formats both copies on single sheet with cut line", substr_count($duplicateHtml, 'bill-slot-2up') === 2);

// Test 3-up Slip Mode
ob_start();
$invoices = [$mockInvoice, $mockInvoice, $mockInvoice];
$itemsByInvoice = [75 => $mockInvoiceItems];
$layoutMode = '3-up';
$duplicateCopies = false;
$showCutLine = true;
require __DIR__ . '/templates/invoices/multi_bill_print.php';
$slipHtml3Up = ob_get_clean();

assert_test("3-up layout contains bill-slot-3up class", strpos($slipHtml3Up, 'bill-slot-3up') !== false);
assert_test("3-up layout formats 3 slots", substr_count($slipHtml3Up, 'bill-slot-3up') === 3);

// 5. Test Warehouse Loading Sheet Summary (Photos 5 & 9)
echo "\n--- 5. Testing Warehouse Loading Sheet Summary (Photos 5 & 9) ---\n";
$mockSheet = [
    'id' => 10,
    'load_number' => 'LOAD-20261117-001',
    'load_date' => '2026-11-17',
    'delivery_date' => '2026-11-17',
    'salesman_name' => 'Saghar Delivery',
    'salesman_phone' => '0300-1234567',
    'creator_name' => 'Muzamal Shah'
];
$mockLoadItems = [
    [
        'category_name' => 'Nimko',
        'product_name' => 'Color Vermicelli',
        'ordered_qty' => 1,
        'ctn_ratio' => 1,
        'rate' => 120.00,
        'total_amount' => 120.00
    ],
    [
        'category_name' => 'Pickles',
        'product_name' => 'Mix Pickle in Oil Small',
        'ordered_qty' => 1,
        'ctn_ratio' => 1,
        'rate' => 250.00,
        'total_amount' => 250.00
    ]
];

ob_start();
$sheet = $mockSheet;
$items = $mockLoadItems;
$bookersList = 'Abdur Rehman';
$areasList = 'Rahwali & Daal Bazar';
require __DIR__ . '/templates/loading_sheets/load_sheet_print.php';
$loadHtml = ob_get_clean();

assert_test("Load sheet contains 'TOTAL STOCK ISSUE SUMMARY'", strpos($loadHtml, 'TOTAL STOCK ISSUE SUMMARY') !== false);
assert_test("Load sheet contains Yellow Header styling (#d1b80d)", strpos($loadHtml, '#d1b80d') !== false);
assert_test("Load sheet contains Booker 'Abdur Rehman'", strpos($loadHtml, 'Abdur Rehman') !== false);
assert_test("Load sheet contains Product 'Color Vermicelli'", strpos($loadHtml, 'Color Vermicelli') !== false);
assert_test("Load sheet contains Signatures (Warehouse Gate Incharge)", strpos($loadHtml, 'Warehouse Gate Incharge') !== false);
assert_test("Load sheet contains Signatures (Salesman Receiver)", strpos($loadHtml, 'Salesman Receiver') !== false);

// 6. Test Delivery Run Field Recovery Sheet (Photo 8)
echo "\n--- 6. Testing Delivery Run Field Recovery Sheet (Photo 8) ---\n";
$mockRun = [
    'id' => 15,
    'run_number' => 'RUN-20261117-001',
    'run_date' => '2026-11-17',
    'salesman_name' => 'Saghar Delivery',
    'salesman_phone' => '0300-1234567'
];
$mockBills = [
    [
        'shop_name' => 'Usman J.S',
        'invoice_number' => 'BILL-1001',
        'net_amount' => 3672.00,
        'cash_amount' => 1510.00,
        'credit_amount' => 2160.00,
        'return_amount' => 0.00
    ],
    [
        'shop_name' => 'Hafiz Super Store',
        'invoice_number' => 'BILL-1003',
        'net_amount' => 15390.00,
        'cash_amount' => 0.00,
        'credit_amount' => 0.00,
        'return_amount' => 15390.00
    ]
];

// Test Field Form (blank figures for handwriting)
ob_start();
$run = $mockRun;
$bills = $mockBills;
$fillFigures = false;
require __DIR__ . '/templates/delivery_sheets/delivery_print.php';
$fieldFormHtml = ob_get_clean();

assert_test("Field Recovery contains 'DAILY SALE RECIVED + CREDIT'", strpos($fieldFormHtml, 'DAILY SALE RECIVED + CREDIT') !== false);
assert_test("Field Recovery contains Shop 'Usman J.S'", strpos($fieldFormHtml, 'Usman J.S') !== false);
assert_test("Field Recovery contains Bill No 'BILL-1001'", strpos($fieldFormHtml, 'BILL-1001') !== false);
assert_test("Field Recovery contains Total Amount '3,672'", strpos($fieldFormHtml, '3,672') !== false);
assert_test("Field Recovery includes extra blank rows for handwriting", substr_count($fieldFormHtml, '<tr style="height:') >= 5);

// Test Filled Mode
ob_start();
$fillFigures = true;
require __DIR__ . '/templates/delivery_sheets/delivery_print.php';
$filledFormHtml = ob_get_clean();
assert_test("Filled mode displays Cash '1,510'", strpos($filledFormHtml, '1,510') !== false);
assert_test("Filled mode displays Credit '2,160'", strpos($filledFormHtml, '2,160') !== false);
assert_test("Filled mode displays Return '15,390'", strpos($filledFormHtml, '15,390') !== false);

// 7. Test Route Settlement Sheet (Photos 1 & 3)
echo "\n--- 7. Testing Route Settlement Sheet (Photos 1 & 3) ---\n";
$mockSettlement = [
    'id' => 20,
    'settlement_number' => 'SETTLE-20261117-001',
    'settlement_date' => '2026-11-17',
    'salesman_name' => 'Saghar Delivery',
    'total_invoices_amount' => 77322.00,
    'total_credit_given' => 27420.00,
    'total_returns_amount' => 37760.00,
    'total_cash_received' => 12140.00,
    'difference_amount' => 0.00
];
$mockSettlementItems = [
    [
        'shop_name' => 'Usman J.S',
        'invoice_number' => 'BILL-1001',
        'bill_amount' => 3672.00,
        'credit_amount' => 2160.00,
        'return_amount' => 0.00,
        'cash_amount' => 1510.00
    ],
    [
        'shop_name' => 'Hafiz Super Store',
        'invoice_number' => 'BILL-1003',
        'bill_amount' => 15390.00,
        'credit_amount' => 0.00,
        'return_amount' => 15390.00,
        'cash_amount' => 0.00
    ]
];
$mockCashSub = [
    'submission_number' => 'CSUB-20261117-001',
    'amount' => 12140.00,
    'submission_date' => '2026-11-17 18:30:00'
];

ob_start();
$settlement = $mockSettlement;
$items = $mockSettlementItems;
$cashSub = $mockCashSub;
require __DIR__ . '/templates/settlements/settlement_print.php';
$settleHtml = ob_get_clean();

assert_test("Settlement sheet contains 'DAILY SALE RECIVED + CREDIT'", strpos($settleHtml, 'DAILY SALE RECIVED + CREDIT') !== false);
assert_test("Settlement sheet contains Total Invoiced '77,322'", strpos($settleHtml, '77,322') !== false);
assert_test("Settlement sheet contains Total Credit '27,420'", strpos($settleHtml, '27,420') !== false);
assert_test("Settlement sheet contains Total Return '37,760'", strpos($settleHtml, '37,760') !== false);
assert_test("Settlement sheet contains Total Cash '12,140'", strpos($settleHtml, '12,140') !== false);
assert_test("Settlement sheet contains Zero-Sum Audit (BALANCED)", strpos($settleHtml, 'BALANCED') !== false);
assert_test("Settlement sheet contains Cash Submission reference", strpos($settleHtml, 'CSUB-20261117-001') !== false);
assert_test("Settlement sheet contains Signatures (Head Cashier)", strpos($settleHtml, 'Head Cashier / Receiver') !== false);

// 8. Live Database Integration: Query real records and verify templates render cleanly
echo "\n--- 8. Live Database Integration Test with Real Records ---\n";
try {
    // Check if we have an invoice
    $realInvoice = db_fetch_one("SELECT id FROM sales_invoices LIMIT 1");
    if ($realInvoice) {
        $invId = $realInvoice['id'];
        $dbInv = db_fetch_one("
            SELECT inv.*, c.shop_name as customer_name, c.phone as customer_phone, c.address as customer_address,
                   a.area_name, b.booker_name, s.salesman_name, ls.load_number
            FROM sales_invoices inv
            JOIN customers c ON inv.customer_id = c.id
            JOIN areas a ON inv.area_id = a.id
            JOIN bookers b ON inv.booker_id = b.id
            LEFT JOIN salesmen s ON inv.salesman_id = s.id
            LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
            WHERE inv.id = ?
        ", [$invId]);

        $dbItems = db_fetch_all("
            SELECT sii.*, p.product_code, p.product_name, p.carton_qty as ctn_ratio, u.unit_code, u.unit_name
            FROM sales_invoice_items sii
            JOIN products p ON sii.product_id = p.id
            JOIN units u ON sii.unit_id = u.id
            WHERE sii.invoice_id = ?
        ", [$invId]);

        ob_start();
        $invoice = $dbInv;
        $invoiceItems = $dbItems;
        $copyLabel = 'Original Copy';
        $padRows = 6;
        $compactMode = false;
        require __DIR__ . '/templates/invoices/bill_print.php';
        $renderedDbBill = ob_get_clean();

        assert_test("Live DB Invoice renders without errors (#{$dbInv['invoice_number']})", strlen($renderedDbBill) > 500);
    } else {
        assert_test("Live DB Invoice test skipped (no records yet)", true);
    }

    // Check if we have a load sheet
    $realSheet = db_fetch_one("SELECT id FROM load_sheets LIMIT 1");
    if ($realSheet) {
        $sheetId = $realSheet['id'];
        $dbSheet = db_fetch_one("
            SELECT ls.*, s.salesman_name, s.phone as salesman_phone, w.warehouse_name,
                   u.full_name as creator_name, iu.full_name as issuer_name
            FROM load_sheets ls
            JOIN salesmen s ON ls.salesman_id = s.id
            JOIN warehouses w ON ls.warehouse_id = w.id
            LEFT JOIN users u ON ls.created_by = u.id
            LEFT JOIN users iu ON ls.issued_by = iu.id
            WHERE ls.id = ?
        ", [$sheetId]);

        $dbLoadItems = db_fetch_all("
            SELECT lsi.*, p.product_code, p.product_name, p.carton_qty as ctn_ratio, c.category_name, u.unit_code, u.unit_name
            FROM load_sheet_items lsi
            JOIN products p ON lsi.product_id = p.id
            JOIN units u ON lsi.unit_id = u.id
            LEFT JOIN product_categories c ON p.category_id = c.id
            WHERE lsi.load_sheet_id = ?
        ", [$sheetId]);

        ob_start();
        $sheet = $dbSheet;
        $items = $dbLoadItems;
        $bookersList = 'Test Booker';
        $areasList = 'Test Route';
        require __DIR__ . '/templates/loading_sheets/load_sheet_print.php';
        $renderedDbLoad = ob_get_clean();

        assert_test("Live DB Load Sheet renders without errors (#{$dbSheet['load_number']})", strlen($renderedDbLoad) > 500);
    } else {
        assert_test("Live DB Load Sheet test skipped (no records yet)", true);
    }
} catch (Exception $e) {
    assert_test("Live DB Integration Test", false, $e->getMessage());
}

echo "\n====================================================================\n";
echo "PHASE 7 VERIFICATION SUMMARY:\n";
echo "Total Passed: {$passed}\n";
echo "Total Failed: {$failed}\n";
if ($failed === 0) {
    echo "STATUS: ALL PHASE 7 TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "STATUS: SOME TESTS FAILED. PLEASE REVIEW ABOVE.\n";
}
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
