<?php
/**
 * DMS - Soft Delete Booker
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$booker = db_fetch_one("SELECT * FROM bookers WHERE id = ? AND is_deleted = 0", [$id]);

if (!$booker) {
    set_flash_message('danger', 'Booker record not found.');
    redirect(APP_URL . '/modules/bookers/index.php');
}

// Check references in orders or customers
$custCount  = (int)db_fetch_val("SELECT COUNT(*) FROM customers WHERE booker_id = ? AND is_deleted = 0", [$id]);
$orderCount = (int)db_fetch_val("SELECT COUNT(*) FROM orders WHERE booker_id = ?", [$id]);

if ($custCount > 0 || $orderCount > 0) {
    db_execute("UPDATE bookers SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_booker', 'bookers', $id, "Deactivated booker {$booker['booker_name']} due to existing references ({$custCount} customers, {$orderCount} orders)");
    set_flash_message('warning', "Booker '{$booker['booker_name']}' is linked to {$custCount} customers and {$orderCount} orders. Marked as Inactive instead of deletion.");
} else {
    db_execute("UPDATE bookers SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_booker', 'bookers', $id, "Soft deleted booker: {$booker['booker_name']}");
    set_flash_message('success', "Booker '{$booker['booker_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/bookers/index.php');
