<?php
/**
 * DMS - Create Booker Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/bookers/index.php');
}

$bookerCode     = clean_input($_POST['booker_code'] ?? '');
$bookerName     = clean_input($_POST['booker_name'] ?? '');
$phone          = clean_input($_POST['phone'] ?? '');
$cnic           = clean_input($_POST['cnic'] ?? '');
$commissionRate = (float)($_POST['commission_rate'] ?? 0);
$notes          = clean_input($_POST['notes'] ?? '');
$isActive       = isset($_POST['is_active']) ? 1 : 0;
$areaIds        = $_POST['area_ids'] ?? [];

$validator = new Validator();
$validator->required('booker_code', $bookerCode)
          ->unique('booker_code', $bookerCode, 'bookers', 'booker_code')
          ->required('booker_name', $bookerName)
          ->required('phone', $phone);

if ($validator->fails()) {
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/bookers/index.php');
}

try {
    db_transaction(function ($pdo) use ($bookerCode, $bookerName, $phone, $cnic, $commissionRate, $notes, $isActive, $areaIds) {
        $stmt = $pdo->prepare("
            INSERT INTO bookers (booker_code, booker_name, phone, cnic, commission_rate, notes, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$bookerCode, $bookerName, $phone, $cnic, $commissionRate, $notes, $isActive]);
        $bookerId = (int)$pdo->lastInsertId();

        // Assign Areas
        if (!empty($areaIds) && is_array($areaIds)) {
            $stmtArea = $pdo->prepare("INSERT INTO booker_areas (booker_id, area_id, created_at) VALUES (?, ?, NOW())");
            foreach ($areaIds as $areaId) {
                $stmtArea->execute([$bookerId, (int)$areaId]);
            }
        }

        log_audit('create_booker', 'bookers', $bookerId, "Created booker: {$bookerName} ({$bookerCode})");
    });

    set_flash_message('success', "Order booker '{$bookerName}' registered successfully.");
} catch (Exception $e) {
    set_flash_message('danger', "Failed to register booker: " . $e->getMessage());
}

redirect(APP_URL . '/modules/bookers/index.php');
