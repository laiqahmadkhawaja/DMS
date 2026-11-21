<?php
/**
 * DMS - Create Brand Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || isset($_POST['ajax']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        json_error('Invalid request method.');
    }
    redirect(APP_URL . '/modules/brands/index.php');
}

$brandCode   = clean_input($_POST['brand_code'] ?? '');
$brandName   = clean_input($_POST['brand_name'] ?? '');
$description = clean_input($_POST['description'] ?? '');
$isActive    = isset($_POST['is_active']) ? 1 : 0;

if (empty($brandCode)) {
    $brandCode = 'BRD-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM brands") + 1);
}

$validator = new Validator();
$validator->required('brand_code', $brandCode)
          ->unique('brand_code', $brandCode, 'brands', 'brand_code')
          ->required('brand_name', $brandName);

if ($validator->fails()) {
    if ($isAjax) {
        json_validation_error($validator->getErrors(), $validator->getFirstError());
    }
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/brands/index.php');
}

try {
    $stmt = db()->prepare("
        INSERT INTO brands (brand_code, brand_name, description, is_active, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$brandCode, $brandName, $description, $isActive]);
    $brandId = (int)db()->lastInsertId();

    log_audit('create_brand', 'brands', $brandId, "Created brand: {$brandName} ({$brandCode})");
    
    if ($isAjax) {
        json_success("Brand '{$brandName}' added successfully.", [
            'id'         => $brandId,
            'brand_name' => $brandName,
            'brand_code' => $brandCode
        ]);
    }
    
    set_flash_message('success', "Brand '{$brandName}' added successfully.");
} catch (Exception $e) {
    if ($isAjax) {
        json_error("Failed to create brand: " . $e->getMessage());
    }
    set_flash_message('danger', "Failed to create brand: " . $e->getMessage());
}

redirect(APP_URL . '/modules/brands/index.php');
