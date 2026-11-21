<?php
/**
 * DMS - Create Warehouse Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || isset($_POST['ajax']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        json_error('Invalid request method.');
    }
    redirect(APP_URL . '/modules/warehouses/index.php');
}

$warehouseCode = strtoupper(clean_input($_POST['warehouse_code'] ?? ''));
$warehouseName = clean_input($_POST['warehouse_name'] ?? '');
$location      = clean_input($_POST['location'] ?? '');
$isPrimary     = isset($_POST['is_primary']) ? 1 : 0;
$isActive      = isset($_POST['is_active']) ? 1 : 0;

if (empty($warehouseCode)) {
    $count = (int)db_fetch_val("SELECT COUNT(*) FROM warehouses");
    $warehouseCode = 'WH-' . sprintf('%02d', $count + 1);
}

$validator = new Validator();
$validator->required('warehouse_code', $warehouseCode)
          ->unique('warehouse_code', $warehouseCode, 'warehouses', 'warehouse_code')
          ->required('warehouse_name', $warehouseName);

if ($validator->fails()) {
    if ($isAjax) {
        json_validation_error($validator->getErrors(), $validator->getFirstError());
    }
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/warehouses/index.php');
}

try {
    db()->beginTransaction();

    // If marked as primary, clear primary status from other warehouses
    if ($isPrimary) {
        db_execute("UPDATE warehouses SET is_primary = 0 WHERE is_deleted = 0");
    } else {
        // If no primary warehouse exists, automatically make this one primary
        $existingPrimary = db_fetch_val("SELECT id FROM warehouses WHERE is_primary = 1 AND is_deleted = 0");
        if (!$existingPrimary) {
            $isPrimary = 1;
        }
    }

    $stmt = db()->prepare("
        INSERT INTO warehouses (warehouse_code, warehouse_name, location, is_primary, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$warehouseCode, $warehouseName, $location, $isPrimary, $isActive]);
    $warehouseId = (int)db()->lastInsertId();

    log_audit('create_warehouse', 'warehouses', $warehouseId, "Created warehouse: {$warehouseName} ({$warehouseCode})");
    
    db()->commit();

    if ($isAjax) {
        json_success("Warehouse '{$warehouseName}' added successfully.", [
            'id'             => $warehouseId,
            'warehouse_name' => $warehouseName,
            'warehouse_code' => $warehouseCode,
            'location'       => $location,
            'is_primary'     => $isPrimary
        ]);
    }
    
    set_flash_message('success', "Warehouse '{$warehouseName}' added successfully.");
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log("Error creating warehouse: " . $e->getMessage());
    if ($isAjax) {
        json_error("Failed to add warehouse: " . $e->getMessage());
    }
    set_flash_message('danger', "Database error: " . $e->getMessage());
}

redirect(APP_URL . '/modules/warehouses/index.php');
