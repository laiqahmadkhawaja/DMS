<?php
/**
 * DMS - Create Category Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/categories/index.php');
}

$categoryCode = clean_input($_POST['category_code'] ?? '');
$categoryName = clean_input($_POST['category_name'] ?? '');
$description  = clean_input($_POST['description'] ?? '');
$isActive     = isset($_POST['is_active']) ? 1 : 0;

$validator = new Validator();
$validator->required('category_code', $categoryCode)
          ->unique('category_code', $categoryCode, 'product_categories', 'category_code')
          ->required('category_name', $categoryName);

if ($validator->fails()) {
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/categories/index.php');
}

try {
    $stmt = db()->prepare("
        INSERT INTO product_categories (category_code, category_name, description, is_active, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$categoryCode, $categoryName, $description, $isActive]);
    $catId = (int)db()->lastInsertId();

    log_audit('create_category', 'categories', $catId, "Created category: {$categoryName} ({$categoryCode})");
    set_flash_message('success', "Category '{$categoryName}' added successfully.");
} catch (Exception $e) {
    set_flash_message('danger', "Failed to create category: " . $e->getMessage());
}

redirect(APP_URL . '/modules/categories/index.php');
