<?php
/**
 * Test Suite: Mobile App End-to-End Workflow Verification
 * Tests Order Booking, Delivery Cash Collection, Field Returns, Warehouse Verification & Cashier Settlement
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

echo "=================================================================\n";
echo "DMS MOBILE APP MODULE AUTOMATED VERIFICATION SUITE\n";
echo "=================================================================\n\n";

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

function run_sub_api(string $action, array $data = [], string $method = 'GET') {
    $runner = __DIR__ . '/api_runner.php';
    $dataBase64 = base64_encode(json_encode($data));
    $cmd = "php " . escapeshellarg($runner) . " " . escapeshellarg($action) . " " . escapeshellarg($method) . " " . escapeshellarg($dataBase64);
    $output = shell_exec($cmd);
    return json_decode($output, true);
}

// 1. Session & Auth Verification
echo "1. Simulating Authenticated Session:\n";
$login = attempt_login('admin', 'admin123');
assert_test("Admin login succeeds", $login['success'] ?? false);
assert_test("User ID in session", !empty($_SESSION['user_id']));

// 2. Test Init Endpoint
echo "\n2. Testing 'init' Endpoint:\n";
$initRes = run_sub_api('init');
assert_test("Init API returns success", $initRes['success'] === true);
assert_test("Salesmen array returned", !empty($initRes['data']['salesmen']));
assert_test("Products array returned with live stock", !empty($initRes['data']['products']));
assert_test("Packaging ratios present on products", isset($initRes['data']['products'][0]['pcs_per_ctn']));

$testProduct = $initRes['data']['products'][0];
$testSalesman = $initRes['data']['salesmen'][0];
$testBooker = $initRes['data']['bookers'][0] ?? ['id' => 1];

// 3. Test Customers Endpoint
echo "\n3. Testing 'customers' Endpoint:\n";
$custRes = run_sub_api('customers');
assert_test("Customers API returns success", $custRes['success'] === true);
assert_test("Customers list is not empty", !empty($custRes['data']));
$testCustomer = $custRes['data'][0];
assert_test("Customer has current_balance and area_id", isset($testCustomer['current_balance']) && isset($testCustomer['area_id']));

// 4. Test Direct Order Booking via App
echo "\n4. Testing Direct Mobile Order Booking:\n";
$orderPayload = [
    'customer_id'   => $testCustomer['id'],
    'salesman_id'   => $testSalesman['id'],
    'booker_id'     => $testBooker['id'],
    'order_date'    => date('Y-m-d'),
    'delivery_date' => date('Y-m-d', strtotime('+1 day')),
    'remarks'       => 'Automated Mobile App Test Order',
    'confirm_now'   => 1,
    'items'         => [
        [
            'product_id'       => $testProduct['id'],
            'carton_qty'       => 2,
            'pack_qty'         => 0,
            'pcs_qty'          => 5,
            'rate'             => $testProduct['piece_rate'] > 0 ? $testProduct['piece_rate'] : 50,
            'discount_percent' => 0
        ]
    ]
];

$orderRes = run_sub_api('book_order', $orderPayload, 'POST');
assert_test("Order Booking API succeeds", $orderRes['success'] === true);
$bookedOrderId = $orderRes['data']['order_id'] ?? 0;
$bookedOrderNo = $orderRes['data']['order_number'] ?? '';
assert_test("Order ID generated (> 0)", $bookedOrderId > 0);
assert_test("Order Number generated ({$bookedOrderNo})", str_starts_with($bookedOrderNo, PREFIX_ORDER));

$dbOrder = db_fetch_one("SELECT * FROM orders WHERE id = ?", [$bookedOrderId]);
assert_test("Database contains the new order with status 'confirmed'", $dbOrder && $dbOrder['status'] === 'confirmed');
$dbOrderItems = db_fetch_all("SELECT * FROM order_items WHERE order_id = ?", [$bookedOrderId]);
assert_test("Order Items created in database", count($dbOrderItems) === 1);

// 5. Test Delivery Run & Field Collection
echo "\n5. Testing Delivery Run & Salesman Cash Collection:\n";
$testRunNumber = generate_document_number(PREFIX_RUN, 'delivery_runs', 'run_number');
db()->prepare("
    INSERT INTO delivery_runs (run_number, run_date, salesman_id, total_bills, total_amount, status, created_by, created_at)
    VALUES (?, CURDATE(), ?, 1, 5000.00, 'assigned', ?, NOW())
")->execute([$testRunNumber, $testSalesman['id'], current_user_id()]);
$testRunId = (int)db()->lastInsertId();

db()->prepare("
    INSERT INTO delivery_run_bills (delivery_run_id, customer_id, bill_number, bill_amount, stop_sequence, delivery_status, cash_collected, credit_given, return_value, created_at)
    VALUES (?, ?, 'BILL-TEST-001', 5000.00, 1, 'pending', 0, 0, 0, NOW())
")->execute([$testRunId, $testCustomer['id']]);
$testRunBillId = (int)db()->lastInsertId();

$entryPayload = [
    'run_bill_id'     => $testRunBillId,
    'cash_collected'  => 3500.00,
    'credit_given'    => 1500.00,
    'return_value'    => 0.00,
    'delivery_status' => 'delivered',
    'remarks'         => 'Paid partial cash, rest on credit'
];
$entryRes = run_sub_api('save_delivery_entry', $entryPayload, 'POST');
assert_test("Save Delivery Entry API succeeds", $entryRes['success'] === true);

$updatedBill = db_fetch_one("SELECT * FROM delivery_run_bills WHERE id = ?", [$testRunBillId]);
assert_test("delivery_run_bills updated with cash 3500 & credit 1500", (float)$updatedBill['cash_collected'] == 3500.00 && (float)$updatedBill['credit_given'] == 1500.00);
assert_test("Delivery status updated to 'delivered'", $updatedBill['delivery_status'] === 'delivered');

// 6. Test Field Return of Goods
echo "\n6. Testing Field Return of Goods:\n";
$returnPayload = [
    'run_bill_id' => $testRunBillId,
    'items'       => [
        [
            'product_id'     => $testProduct['id'],
            'carton_qty'     => 1,
            'pack_qty'       => 0,
            'pcs_qty'        => 0,
            'rate'           => 1000.00,
            'condition_type' => 'saleable',
            'reason'         => 'Customer rejected 1 carton'
        ]
    ]
];
$returnRes = run_sub_api('save_field_return', $returnPayload, 'POST');
assert_test("Save Field Return API succeeds", $returnRes['success'] === true);

$fieldRetItems = db_fetch_all("SELECT * FROM field_return_items WHERE delivery_run_bill_id = ?", [$testRunBillId]);
assert_test("field_return_items record created", count($fieldRetItems) === 1);
$friId = $fieldRetItems[0]['id'];
assert_test("Warehouse verified initial status is 0", (int)$fieldRetItems[0]['warehouse_verified'] === 0);

// 7. Test Warehouse Return Stock Verification
echo "\n7. Testing Warehouse Incharge Return Stock Verification:\n";
$whVerifyRes = run_sub_api('verify_warehouse_return', ['item_ids' => [$friId]], 'POST');
assert_test("Warehouse Verification API succeeds", $whVerifyRes['success'] === true);

$updatedFri = db_fetch_one("SELECT * FROM field_return_items WHERE id = ?", [$friId]);
assert_test("field_return_items marked as warehouse_verified = 1", (int)$updatedFri['warehouse_verified'] === 1);
assert_test("Sales return voucher ID linked", !empty($updatedFri['sales_return_id']));

$srVoucher = db_fetch_one("SELECT * FROM sales_returns WHERE id = ?", [$updatedFri['sales_return_id']]);
assert_test("sales_returns table record exists and is processed", $srVoucher && $srVoucher['status'] === 'processed');

// 8. Test Cashier Handover Verification
echo "\n8. Testing Cashier Cash Handover & Route Settlement:\n";
$cashierRes = run_sub_api('verify_cashier_handover', [
    'delivery_run_id' => $testRunId,
    'cash_received'   => 3500.00,
    'remarks'         => 'Cashier verified Rs. 3500 handover'
], 'POST');
if (!$cashierRes || empty($cashierRes['success'])) {
    echo "  [DEBUG] Cashier Error: " . json_encode($cashierRes) . "\n";
}
assert_test("Cashier Handover API succeeds", $cashierRes['success'] === true);

$settlement = db_fetch_one("SELECT * FROM settlements WHERE delivery_run_id = ?", [$testRunId]);
assert_test("Settlement created for delivery run", !empty($settlement));

$completedRun = db_fetch_one("SELECT status FROM delivery_runs WHERE id = ?", [$testRunId]);
assert_test("Delivery run status is marked 'completed'", $completedRun['status'] === 'completed');

// 9. Test Daily Summary Endpoint
echo "\n9. Testing Daily Summary Endpoint:\n";
$summaryRes = run_sub_api('daily_summary', ['salesman_id' => $testSalesman['id']]);
assert_test("Daily Summary API succeeds", $summaryRes['success'] === true);
assert_test("Summary contains orders count and cash collected", isset($summaryRes['data']['orders_count']) && isset($summaryRes['data']['cash_collected']));

echo "\n=================================================================\n";
echo "TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo "🎉 ALL MOBILE APP TESTS PASSED WITH 100% SUCCESS!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
