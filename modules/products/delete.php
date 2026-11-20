<?php
/**
 * DMS - Soft Delete Product
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('products_delete');

$id = (int)($_GET['id'] ?? 0);
$product = db_fetch_one("SELECT * FROM products WHERE id = ? AND is_deleted = 0", [$id]);

if (!$product) {
    set_flash_message('danger', 'Product record not found.');
    redirect(APP_URL . '/modules/products/index.php');
}

// Check references in order_items, sales_invoice_items, stock_movements
$orderCount = (int)db_fetch_val("SELECT COUNT(*) FROM order_items WHERE product_id = ?", [$id]);
$invCount   = (int)db_fetch_val("SELECT COUNT(*) FROM sales_invoice_items WHERE product_id = ?", [$id]);
$stockCount = (int)db_fetch_val("SELECT COUNT(*) FROM stock_movements WHERE product_id = ?", [$id]);

if ($orderCount > 0 || $invCount > 0 || $stockCount > 0) {
    db_execute("UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_product', 'products', $id, "Deactivated product {$product['product_name']} due to existing transaction history");
    set_flash_message('warning', "Product '{$product['product_name']}' has transaction history ({$invCount} bills, {$orderCount} orders, {$stockCount} stock entries). It has been marked Inactive instead of deletion.");
} else {
    db_execute("UPDATE products SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_product', 'products', $id, "Soft deleted product: {$product['product_name']}");
    set_flash_message('success', "Product '{$product['product_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/products/index.php');
