<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role_id'] = 1;

ob_start();
include __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

$maskCount = substr_count($html, 'kpi-masked');
$btnCount  = substr_count($html, 'btnToggleKpiPrivacy');

echo "Dashboard rendered successfully!\n";
echo "Masked KPI elements count: {$maskCount}\n";
echo "Toggle button count: {$btnCount}\n";

assert($maskCount >= 6, "Expected at least 6 masked KPI elements");
assert($btnCount >= 1, "Expected toggle button");

echo "TEST PASSED!\n";
