<?php
/**
 * DMS - Soft Delete Brand
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$brand = db_fetch_one("SELECT * FROM brands WHERE id = ? AND is_deleted = 0", [$id]);

if (!$brand) {
    set_flash_message('danger', 'Brand record not found.');
    redirect(APP_URL . '/modules/brands/index.php');
}

// Check if any product belongs to this brand
$prodCount = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE brand_id = ? AND is_deleted = 0", [$id]);

if ($prodCount > 0) {
    db_execute("UPDATE brands SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_brand', 'brands', $id, "Deactivated brand {$brand['brand_name']} because {$prodCount} products reference it");
    set_flash_message('warning', "Brand '{$brand['brand_name']}' is linked to {$prodCount} products in your catalog. Marked as Inactive instead of deletion.");
} else {
    db_execute("UPDATE brands SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_brand', 'brands', $id, "Soft deleted brand: {$brand['brand_name']}");
    set_flash_message('success', "Brand '{$brand['brand_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/brands/index.php');
