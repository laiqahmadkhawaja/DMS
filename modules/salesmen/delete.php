<?php
/**
 * DMS - Soft Delete Salesman
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$salesman = db_fetch_one("SELECT * FROM salesmen WHERE id = ? AND is_deleted = 0", [$id]);

if (!$salesman) {
    set_flash_message('danger', 'Salesman record not found.');
    redirect(APP_URL . '/modules/salesmen/index.php');
}

// Check references in load sheets, settlements, delivery runs
$loadCount = (int)db_fetch_val("SELECT COUNT(*) FROM load_sheets WHERE salesman_id = ?", [$id]);
$setCount  = (int)db_fetch_val("SELECT COUNT(*) FROM settlements WHERE salesman_id = ?", [$id]);

if ($loadCount > 0 || $setCount > 0) {
    db_execute("UPDATE salesmen SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_salesman', 'salesmen', $id, "Deactivated salesman {$salesman['salesman_name']} due to existing references ({$loadCount} loads, {$setCount} settlements)");
    set_flash_message('warning', "Salesman '{$salesman['salesman_name']}' is linked to {$loadCount} load sheets and {$setCount} settlements. Marked as Inactive instead of deletion.");
} else {
    db_execute("UPDATE salesmen SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_salesman', 'salesmen', $id, "Soft deleted salesman: {$salesman['salesman_name']}");
    set_flash_message('success', "Salesman '{$salesman['salesman_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/salesmen/index.php');
