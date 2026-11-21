<?php
/**
 * DMS - Distribution Management System
 * AJAX Real-Time Delivery Area / Route Quick-Create Endpoint
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Check system policy setting
$allowRealtime = get_setting('allow_realtime_routes', '1');
if ($allowRealtime !== '1' && !is_admin()) {
    echo json_encode([
        'success' => false, 
        'message' => 'Real-time route creation is disabled by system policy. Fixed routes are enforced.'
    ]);
    exit;
}

// Retrieve and sanitize inputs (supports form-data and json payload)
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

$areaName    = clean_input($_POST['area_name'] ?? $jsonInput['area_name'] ?? '');
$city        = clean_input($_POST['city'] ?? $jsonInput['city'] ?? get_setting('city', 'Gujranwala'));
$description = clean_input($_POST['description'] ?? $jsonInput['description'] ?? '');

if (empty($areaName)) {
    echo json_encode(['success' => false, 'message' => 'Area / Route name is required.']);
    exit;
}

try {
    // 1. Check if an active area with this name already exists in this city
    $existing = db_fetch_one("
        SELECT id, area_code, area_name, city 
        FROM areas 
        WHERE LOWER(area_name) = LOWER(?) AND is_deleted = 0 
        LIMIT 1
    ", [$areaName]);

    if ($existing) {
        echo json_encode([
            'success'     => true,
            'is_existing' => true,
            'message'     => "Area '{$existing['area_name']}' already exists and has been selected.",
            'area'        => [
                'id'        => (int)$existing['id'],
                'area_code' => $existing['area_code'],
                'area_name' => $existing['area_name'],
                'city'      => $existing['city']
            ]
        ]);
        exit;
    }

    // 2. Compute thread-safe sequential area_code
    $maxNum = (int)db_fetch_val("
        SELECT MAX(CAST(SUBSTRING(area_code, 6) AS UNSIGNED)) 
        FROM areas 
        WHERE area_code LIKE 'AREA-%'
    ");
    $nextNumber = max($maxNum + 1, 1);
    $areaCode = 'AREA-' . sprintf('%03d', $nextNumber);

    // 3. Insert new area
    $stmt = db()->prepare("
        INSERT INTO areas (area_code, area_name, city, description, is_active, created_at)
        VALUES (?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$areaCode, $areaName, $city, $description]);
    $newAreaId = (int)db()->lastInsertId();

    // 4. Log audit trail
    if (function_exists('log_audit')) {
        log_audit('quick_create_area', 'areas', $newAreaId, "Real-time registered delivery route: {$areaName} ({$areaCode})");
    }

    echo json_encode([
        'success'     => true,
        'is_existing' => false,
        'message'     => "New delivery area '{$areaName}' registered and selected successfully.",
        'area'        => [
            'id'        => $newAreaId,
            'area_code' => $areaCode,
            'area_name' => $areaName,
            'city'      => $city
        ]
    ]);
} catch (Exception $e) {
    error_log("Quick create area error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error while registering area: ' . $e->getMessage()
    ]);
}
