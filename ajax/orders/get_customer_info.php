<?php
/**
 * DMS - AJAX Endpoint: Get Customer Information
 */

require_once __DIR__ . '/../../config/config.php';
require_login();

$customerId = (int)($_GET['customer_id'] ?? 0);

if ($customerId <= 0) {
    json_error('Customer ID is required.');
}

$customer = db_fetch_one("
    SELECT 
        c.id, c.customer_code, c.shop_name, c.owner_name, c.address, c.phone,
        c.credit_limit, c.current_balance, c.default_discount_percent,
        c.area_id, a.area_name,
        c.booker_id, b.booker_name
    FROM customers c
    JOIN areas a ON a.id = c.area_id
    LEFT JOIN bookers b ON b.id = c.booker_id
    WHERE c.id = ? AND c.is_deleted = 0
", [$customerId]);

if (!$customer) {
    json_error('Customer not found or inactive.');
}

json_success('Customer details retrieved.', $customer);
