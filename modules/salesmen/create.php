<?php
/**
 * DMS - Create Salesman Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/salesmen/index.php');
}

$employeeCode   = clean_input($_POST['employee_code'] ?? '');
$salesmanName   = clean_input($_POST['salesman_name'] ?? '');
$phone          = clean_input($_POST['phone'] ?? '');
$vehicleNumber  = clean_input($_POST['vehicle_number'] ?? '');
$role           = clean_input($_POST['role'] ?? 'delivery_man');
$assignedAreaId = !empty($_POST['assigned_area_id']) ? (int)$_POST['assigned_area_id'] : null;
$notes          = clean_input($_POST['notes'] ?? '');
$isActive       = isset($_POST['is_active']) ? 1 : 0;

$validator = new Validator();
$validator->required('employee_code', $employeeCode)
          ->unique('employee_code', $employeeCode, 'salesmen', 'employee_code')
          ->required('salesman_name', $salesmanName)
          ->required('phone', $phone);

if ($validator->fails()) {
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/salesmen/index.php');
}

try {
    $stmt = db()->prepare("
        INSERT INTO salesmen (employee_code, salesman_name, phone, vehicle_number, role, assigned_area_id, notes, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$employeeCode, $salesmanName, $phone, $vehicleNumber, $role, $assignedAreaId, $notes, $isActive]);
    $salesmanId = (int)db()->lastInsertId();

    log_audit('create_salesman', 'salesmen', $salesmanId, "Created salesman: {$salesmanName} ({$employeeCode})");
    set_flash_message('success', "Delivery salesman '{$salesmanName}' registered successfully.");
} catch (Exception $e) {
    set_flash_message('danger', "Failed to register salesman: " . $e->getMessage());
}

redirect(APP_URL . '/modules/salesmen/index.php');
