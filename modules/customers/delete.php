<?php
/**
 * DMS - Soft Delete Customer
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('customers_delete');

$id = (int)($_GET['id'] ?? 0);
$customer = db_fetch_one("SELECT * FROM customers WHERE id = ? AND is_deleted = 0", [$id]);

if (!$customer) {
    set_flash_message('danger', 'Customer record not found.');
    redirect(APP_URL . '/modules/customers/index.php');
}

// Check transactions: orders, invoices, settlements, balance
$orderCount = (int)db_fetch_val("SELECT COUNT(*) FROM orders WHERE customer_id = ?", [$id]);
$invCount   = (int)db_fetch_val("SELECT COUNT(*) FROM sales_invoices WHERE customer_id = ?", [$id]);
$hasBalance = (float)$customer['current_balance'] != 0;

if ($orderCount > 0 || $invCount > 0 || $hasBalance) {
    db_execute("UPDATE customers SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_customer', 'customers', $id, "Deactivated customer {$customer['shop_name']} due to existing history ({$invCount} bills, {$orderCount} orders, balance: {$customer['current_balance']})");
    set_flash_message('warning', "Customer '{$customer['shop_name']}' has active transactions or outstanding balance (" . format_currency($customer['current_balance']) . "). Marked as Inactive instead of deletion.");
} else {
    db_execute("UPDATE customers SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_customer', 'customers', $id, "Soft deleted customer: {$customer['shop_name']}");
    set_flash_message('success', "Customer '{$customer['shop_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/customers/index.php');
