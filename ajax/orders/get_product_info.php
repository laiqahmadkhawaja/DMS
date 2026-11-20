<?php
/**
 * DMS - AJAX Endpoint: Get Product Pricing & Conversion Info
 */

require_once __DIR__ . '/../../config/config.php';
require_login();

$productId  = (int)($_GET['product_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);

if ($productId <= 0) {
    json_error('Product ID is required.');
}

$product = db_fetch_one("
    SELECT 
        p.id, p.product_code, p.barcode, p.product_name,
        p.base_unit_id, u.unit_name, u.symbol as unit_symbol,
        p.wholesale_rate, p.default_sale_rate, p.retail_rate,
        p.carton_qty, p.pack_qty, p.min_stock_level,
        COALESCE(sb.saleable_qty, 0) as available_stock
    FROM products p
    JOIN units u ON u.id = p.base_unit_id
    LEFT JOIN stock_balances sb ON sb.product_id = p.id
    WHERE p.id = ? AND p.is_deleted = 0
", [$productId]);

if (!$product) {
    json_error('Product not found or inactive.');
}

// Check special agreed price for this customer
$effectiveRate = (float)$product['wholesale_rate'];
$hasSpecialRate = false;

if ($customerId > 0) {
    $specialRate = db_fetch_val("
        SELECT special_rate FROM customer_prices 
        WHERE customer_id = ? AND product_id = ?
        LIMIT 1
    ", [$customerId, $productId]);

    if ($specialRate !== null && (float)$specialRate > 0) {
        $effectiveRate = (float)$specialRate;
        $hasSpecialRate = true;
    }
}

$product['effective_rate'] = $effectiveRate;
$product['has_special_rate'] = $hasSpecialRate;

json_success('Product pricing retrieved.', $product);
