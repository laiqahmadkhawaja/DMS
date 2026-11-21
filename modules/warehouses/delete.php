<?php
/**
 * DMS - Soft Delete / Deactivate Warehouse
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$warehouse = db_fetch_one("SELECT * FROM warehouses WHERE id = ? AND is_deleted = 0", [$id]);

if (!$warehouse) {
    set_flash_message('danger', 'Warehouse record not found.');
    redirect(APP_URL . '/modules/warehouses/index.php');
}

if ($warehouse['is_primary']) {
    set_flash_message('danger', "Cannot delete the Primary Warehouse ('{$warehouse['warehouse_name']}'). Please designate another warehouse as Primary before removing this one.");
    redirect(APP_URL . '/modules/warehouses/index.php');
}

// Check for dependencies across DMS
$stockMovementsCount = (int)db_fetch_val("SELECT COUNT(*) FROM stock_movements WHERE warehouse_id = ?", [$id]);
$stockBalancesCount  = (int)db_fetch_val("SELECT COUNT(*) FROM stock_balances WHERE warehouse_id = ? AND total_qty > 0", [$id]);
$loadSheetsCount     = (int)db_fetch_val("SELECT COUNT(*) FROM load_sheets WHERE warehouse_id = ?", [$id]);
$grnCount            = (int)db_fetch_val("SELECT COUNT(*) FROM goods_receiving_notes WHERE warehouse_id = ?", [$id]);

$totalReferences = $stockMovementsCount + $stockBalancesCount + $loadSheetsCount + $grnCount;

if ($totalReferences > 0) {
    db_execute("UPDATE warehouses SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_warehouse', 'warehouses', $id, "Deactivated warehouse {$warehouse['warehouse_name']} because {$totalReferences} transactions reference it");
    set_flash_message('warning', "Warehouse '{$warehouse['warehouse_name']}' is linked to {$totalReferences} historical inventory transactions/load sheets. It has been marked as Inactive instead of deletion to protect ledger history.");
} else {
    db_execute("UPDATE warehouses SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_warehouse', 'warehouses', $id, "Soft deleted warehouse: {$warehouse['warehouse_name']}");
    set_flash_message('success', "Warehouse '{$warehouse['warehouse_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/warehouses/index.php');
