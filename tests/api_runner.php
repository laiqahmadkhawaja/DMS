<?php
/**
 * Helper to execute an API action in an isolated process
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/auth.php';

attempt_login('admin', 'admin123');

$action = $argv[1] ?? 'init';
$method = $argv[2] ?? 'GET';
$payloadBase64 = $argv[3] ?? '';

$payload = !empty($payloadBase64) ? json_decode(base64_decode($payloadBase64), true) : [];

if ($method === 'POST') {
    $_POST = array_merge(['action' => $action], $payload);
    $_GET = ['action' => $action];
} else {
    $_GET = array_merge(['action' => $action], $payload);
    $_POST = [];
}

include __DIR__ . '/../modules/app/api.php';
