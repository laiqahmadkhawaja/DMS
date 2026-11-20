<?php
/**
 * DMS - Create Area Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/areas/index.php');
}

$areaCode    = clean_input($_POST['area_code'] ?? '');
$areaName    = clean_input($_POST['area_name'] ?? '');
$city        = clean_input($_POST['city'] ?? 'Gujranwala');
$description = clean_input($_POST['description'] ?? '');
$isActive    = isset($_POST['is_active']) ? 1 : 0;

$validator = new Validator();
$validator->required('area_code', $areaCode)
          ->unique('area_code', $areaCode, 'areas', 'area_code')
          ->required('area_name', $areaName);

if ($validator->fails()) {
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/areas/index.php');
}

try {
    $stmt = db()->prepare("
        INSERT INTO areas (area_code, area_name, city, description, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$areaCode, $areaName, $city, $description, $isActive]);
    $areaId = (int)db()->lastInsertId();

    log_audit('create_area', 'areas', $areaId, "Created area: {$areaName} ({$areaCode})");
    set_flash_message('success', "Area '{$areaName}' registered successfully.");
} catch (Exception $e) {
    set_flash_message('danger', "Failed to register area: " . $e->getMessage());
}

redirect(APP_URL . '/modules/areas/index.php');
