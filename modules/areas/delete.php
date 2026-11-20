<?php
/**
 * DMS - Soft Delete Area
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$area = db_fetch_one("SELECT * FROM areas WHERE id = ? AND is_deleted = 0", [$id]);

if (!$area) {
    set_flash_message('danger', 'Area not found.');
    redirect(APP_URL . '/modules/areas/index.php');
}

// Check if area is referenced by active customers or orders
$custCount = (int)db_fetch_val("SELECT COUNT(*) FROM customers WHERE area_id = ? AND is_deleted = 0", [$id]);
$orderCount = (int)db_fetch_val("SELECT COUNT(*) FROM orders WHERE area_id = ?", [$id]);

if ($custCount > 0 || $orderCount > 0) {
    // If referenced, deactivate instead of deleting to preserve historical integrity
    db_execute("UPDATE areas SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_area', 'areas', $id, "Deactivated area {$area['area_name']} due to existing references ({$custCount} customers, {$orderCount} orders)");
    set_flash_message('warning', "Area '{$area['area_name']}' cannot be permanently deleted because {$custCount} customers and {$orderCount} orders reference it. It has been marked Inactive instead.");
} else {
    // Safe soft delete
    db_execute("UPDATE areas SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_area', 'areas', $id, "Soft deleted area {$area['area_name']}");
    set_flash_message('success', "Area '{$area['area_name']}' deleted successfully.");
}

redirect(APP_URL . '/modules/areas/index.php');
