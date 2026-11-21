<?php
/**
 * DMS - Make Warehouse Primary Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$warehouse = db_fetch_one("SELECT * FROM warehouses WHERE id = ? AND is_deleted = 0", [$id]);

if (!$warehouse) {
    set_flash_message('danger', 'Warehouse not found.');
    redirect(APP_URL . '/modules/warehouses/index.php');
}

if (!$warehouse['is_active']) {
    set_flash_message('danger', 'Cannot set an inactive warehouse as the primary hub.');
    redirect(APP_URL . '/modules/warehouses/index.php');
}

try {
    db()->beginTransaction();
    
    // Clear existing primary
    db_execute("UPDATE warehouses SET is_primary = 0 WHERE is_deleted = 0");
    
    // Set this warehouse as primary
    db_execute("UPDATE warehouses SET is_primary = 1, updated_at = NOW() WHERE id = ?", [$id]);
    
    log_audit('set_primary_warehouse', 'warehouses', $id, "Designated '{$warehouse['warehouse_name']}' as primary central hub");
    
    db()->commit();
    set_flash_message('success', "Warehouse '{$warehouse['warehouse_name']}' is now the default Primary Central Hub.");
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    set_flash_message('danger', 'Error updating primary warehouse: ' . $e->getMessage());
}

redirect(APP_URL . '/modules/warehouses/index.php');
