<?php
/**
 * DMS - Soft Delete Category
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$category = db_fetch_one("SELECT * FROM product_categories WHERE id = ? AND is_deleted = 0", [$id]);

if (!$category) {
    set_flash_message('danger', 'Category record not found.');
    redirect(APP_URL . '/modules/categories/index.php');
}

// Check if any product belongs to this category
$prodCount = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE category_id = ? AND is_deleted = 0", [$id]);

if ($prodCount > 0) {
    db_execute("UPDATE product_categories SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('deactivate_category', 'categories', $id, "Deactivated category {$category['category_name']} because {$prodCount} products reference it");
    set_flash_message('warning', "Category '{$category['category_name']}' is linked to {$prodCount} products in your catalog. Marked as Inactive instead of deletion.");
} else {
    db_execute("UPDATE product_categories SET is_deleted = 1, is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('delete_category', 'categories', $id, "Soft deleted category: {$category['category_name']}");
    set_flash_message('success', "Category '{$category['category_name']}' removed successfully.");
}

redirect(APP_URL . '/modules/categories/index.php');
