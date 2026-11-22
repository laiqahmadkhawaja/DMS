<?php
/**
 * DMS - Distribution Management System
 * AJAX Real-Time Retailer / Customer Quick-Create Endpoint
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!has_permission('customers_create')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied: customers_create.']);
    exit;
}

// Retrieve and sanitize inputs (supports form-data and json payload)
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

$shopName       = clean_input($_POST['shop_name'] ?? $jsonInput['shop_name'] ?? '');
$ownerName      = clean_input($_POST['owner_name'] ?? $jsonInput['owner_name'] ?? '');
$phone          = clean_input($_POST['phone'] ?? $jsonInput['phone'] ?? '');
$areaId         = (int)($_POST['area_id'] ?? $jsonInput['area_id'] ?? 0);
$bookerId       = !empty($_POST['booker_id'] ?? $jsonInput['booker_id'] ?? 0) ? (int)($_POST['booker_id'] ?? $jsonInput['booker_id']) : null;
$address        = clean_input($_POST['address'] ?? $jsonInput['address'] ?? '');
$creditLimit    = (float)($_POST['credit_limit'] ?? $jsonInput['credit_limit'] ?? 0);
$openingBalance = (float)($_POST['opening_balance'] ?? $jsonInput['opening_balance'] ?? 0);

if (empty($shopName)) {
    echo json_encode(['success' => false, 'message' => 'Retailer / Shop name is required.']);
    exit;
}

if ($areaId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a delivery route / area.']);
    exit;
}

// Verify that the chosen area exists
$area = db_fetch_one("SELECT id, area_name, city FROM areas WHERE id = ? AND is_deleted = 0", [$areaId]);
if (!$area) {
    echo json_encode(['success' => false, 'message' => 'Selected delivery area does not exist.']);
    exit;
}

if (empty($address)) {
    $address = $area['area_name'] . ', ' . $area['city'];
}

try {
    // 1. Check if an active retailer with this shop name already exists in this area
    $existing = db_fetch_one("
        SELECT c.id, c.customer_code, c.shop_name, c.owner_name, c.phone, c.area_id, c.booker_id,
               c.credit_limit, c.current_balance, c.default_discount_percent,
               a.area_name, b.booker_name
        FROM customers c
        LEFT JOIN areas a ON a.id = c.area_id
        LEFT JOIN bookers b ON b.id = c.booker_id
        WHERE LOWER(c.shop_name) = LOWER(?) AND c.area_id = ? AND c.is_deleted = 0
        LIMIT 1
    ", [$shopName, $areaId]);

    if ($existing) {
        echo json_encode([
            'success'     => true,
            'is_existing' => true,
            'message'     => "Retailer '{$existing['shop_name']}' already exists in {$existing['area_name']} and has been selected.",
            'customer'    => [
                'id'                       => (int)$existing['id'],
                'customer_code'            => $existing['customer_code'],
                'shop_name'                => $existing['shop_name'],
                'owner_name'               => $existing['owner_name'],
                'phone'                    => $existing['phone'],
                'area_id'                  => (int)$existing['area_id'],
                'area_name'                => $existing['area_name'],
                'booker_id'                => $existing['booker_id'] ? (int)$existing['booker_id'] : null,
                'booker_name'              => $existing['booker_name'] ?? '',
                'credit_limit'             => (float)$existing['credit_limit'],
                'current_balance'          => (float)$existing['current_balance'],
                'default_discount_percent' => (float)$existing['default_discount_percent']
            ]
        ]);
        exit;
    }

    // 2. Generate unique sequential customer_code
    $maxNum = (int)db_fetch_val("
        SELECT MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)) 
        FROM customers 
        WHERE customer_code REGEXP '^CUST-[0-9]+$'
    ");
    $nextNumber = max($maxNum + 1, (int)db_fetch_val("SELECT COUNT(*) FROM customers") + 1);
    $customerCode = 'CUST-' . sprintf('%05d', $nextNumber);

    while ((int)db_fetch_val("SELECT COUNT(*) FROM customers WHERE customer_code = ?", [$customerCode]) > 0) {
        $nextNumber++;
        $customerCode = 'CUST-' . sprintf('%05d', $nextNumber);
    }

    // 3. Insert customer inside transaction
    $newCustomerId = null;
    db_transaction(function ($pdo) use (
        $customerCode, $shopName, $ownerName, $phone, $address,
        $areaId, $bookerId, $creditLimit, $openingBalance, &$newCustomerId
    ) {
        $stmt = $pdo->prepare("
            INSERT INTO customers (
                customer_code, shop_name, owner_name, phone, address,
                area_id, booker_id, credit_limit, opening_balance,
                current_balance, default_discount_percent, is_active, is_deleted, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, 0.00, 1, 0, NOW()
            )
        ");
        $stmt->execute([
            $customerCode, $shopName, $ownerName, $phone, $address,
            $areaId, $bookerId, $creditLimit, $openingBalance,
            $openingBalance
        ]);
        $newCustomerId = (int)$pdo->lastInsertId();

        // If opening balance > 0, post double-entry opening journal voucher
        if ($openingBalance > 0 && function_exists('post_journal_entry')) {
            post_journal_entry([
                'entry_date'     => date('Y-m-d'),
                'reference_type' => 'opening',
                'reference_id'   => $newCustomerId,
                'narration'      => "Opening Receivable Balance for Customer: {$shopName}"
            ], [
                [
                    'account_id'  => ACC_RECEIVABLES,
                    'debit'       => $openingBalance,
                    'credit'      => 0,
                    'description' => "Opening debit balance for {$shopName}",
                    'party_type'  => 'customer',
                    'party_id'    => $newCustomerId
                ],
                [
                    'account_id'  => ACC_CAPITAL,
                    'debit'       => 0,
                    'credit'      => $openingBalance,
                    'description' => "Opening capital reserve for {$shopName}",
                    'party_type'  => null,
                    'party_id'    => null
                ]
            ]);
        }

        if (function_exists('log_audit')) {
            log_audit('quick_create_customer', 'customers', $newCustomerId, "Quick registered retailer: {$shopName} ({$customerCode}), Route: #{$areaId}");
        }
    });

    $bookerName = '';
    if ($bookerId) {
        $bookerName = db_fetch_val("SELECT booker_name FROM bookers WHERE id = ?", [$bookerId]) ?: '';
    }

    echo json_encode([
        'success'     => true,
        'is_existing' => false,
        'message'     => "Retailer '{$shopName}' registered and selected successfully.",
        'customer'    => [
            'id'                       => $newCustomerId,
            'customer_code'            => $customerCode,
            'shop_name'                => $shopName,
            'owner_name'               => $ownerName,
            'phone'                    => $phone,
            'address'                  => $address,
            'area_id'                  => $areaId,
            'area_name'                => $area['area_name'],
            'booker_id'                => $bookerId,
            'booker_name'              => $bookerName,
            'credit_limit'             => $creditLimit,
            'current_balance'          => $openingBalance,
            'default_discount_percent' => 0.00
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error registering retailer: ' . $e->getMessage()
    ]);
}
