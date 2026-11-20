<?php
/**
 * DMS - Create Measurement Unit Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/units/index.php');
}

$unitCode = strtoupper(clean_input($_POST['unit_code'] ?? ''));
$unitName = clean_input($_POST['unit_name'] ?? '');
$symbol   = clean_input($_POST['symbol'] ?? '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

$validator = new Validator();
$validator->required('unit_code', $unitCode)
          ->unique('unit_code', $unitCode, 'units', 'unit_code')
          ->required('unit_name', $unitName)
          ->required('symbol', $symbol);

if ($validator->fails()) {
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/units/index.php');
}

try {
    $stmt = db()->prepare("
        INSERT INTO units (unit_code, unit_name, symbol, is_active, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$unitCode, $unitName, $symbol, $isActive]);
    $unitId = (int)db()->lastInsertId();

    log_audit('create_unit', 'units', $unitId, "Created unit: {$unitName} ({$unitCode})");
    set_flash_message('success', "Packaging unit '{$unitName}' added successfully.");
} catch (Exception $e) {
    set_flash_message('danger', "Failed to create unit: " . $e->getMessage());
}

redirect(APP_URL . '/modules/units/index.php');
