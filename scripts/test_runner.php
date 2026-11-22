<?php
$filePath = $argv[1] ?? '';
if (!$filePath) {
    exit("No file specified\n");
}

define('DMS_RUNNING', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/Distribution/' . str_replace('\\', '/', $filePath);
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config/config.php';

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['roles'] = ['admin'];

ob_start();
try {
    include __DIR__ . '/../' . $filePath;
    $output = ob_get_clean();
    if (strpos($output, 'System Notice') !== false) {
        echo "RESULT: SYSTEM_NOTICE_ERROR\n";
    } else {
        echo "RESULT: OK\n";
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "RESULT: EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
