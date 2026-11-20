<?php
/**
 * DMS - Soft Delete Supplier
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('suppliers_delete');

$id = (int)($_GET['id'] ?? 0);
$supplier = db_fetch_one("SELECT * FROM suppliers WHERE id = ? AND is_deleted = 0", [$id]);

if (!$supplier) {
    set_flash_message('danger', 'Supplier not found.');
    redirect(APP_URL . '/modules/suppliers/index.php');
}

$poCount  = (int)db_fetch_val("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?", [$id]);
$grnCount = (int)db_fetch_val("SELECT COUNT(*) FROM goods_receipts WHERE supplier_id = ?", [$id]);
$hasBalance = (float)$supplier['current_balance'] != 0;

if ($poCount > 0 || $grnCount > 0 || $hasBalance) {
    db_execute("UPDATE suppliers SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_supplier', 'suppliers', $id, "Deactivated supplier {$supplier['supplier_name']} due to existing transaction history");
    set_flash_message('warning', "Supplier '{$supplier['supplier_name']}' has active transactions or payable balance (" . format_currency($supplier['current_balance']) . "). Marked as Inactive instead of deletion.");
} else {
    db_execute("UPDATE suppliers SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_supplier', 'suppliers', $id, "Soft deleted supplier: {$supplier['supplier_name']}");
    set_flash_message('success', "Supplier '{$supplier['supplier_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/suppliers/index.php');
