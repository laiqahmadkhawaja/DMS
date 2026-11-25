<?php
/**
 * DMS - Distribution Management System
 * Core Configuration & Bootstrap File
 */

// Error logging setup (Production error standards)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/constants.php';

// Ensure logs directory exists
if (!is_dir(LOGS_PATH)) {
    @mkdir(LOGS_PATH, 0755, true);
}
ini_set('error_log', LOGS_PATH . DIRECTORY_SEPARATOR . 'php_errors.log');

// Set Business Timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// Secure Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 86400; // 24 hours
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

// Global Exception & Error Handler to prevent raw leaks
set_exception_handler(function (Throwable $e) {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[{$timestamp}] Uncaught Exception: {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}" . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    @file_put_contents(LOGS_PATH . DIRECTORY_SEPARATOR . 'app_exceptions.log', $log, FILE_APPEND);

    // If request is AJAX, return clean JSON
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($isAjax) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'An internal server error occurred. Please contact the administrator.'
        ]);
        exit;
    }

    // Friendly HTML error message for regular navigation
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><title>System Error</title><link rel="stylesheet" href="' . APP_URL . '/assets/bootstrap/bootstrap.min.css"></head><body class="bg-light d-flex align-items-center justify-content-center vh-100">';
    echo '<div class="card shadow-sm p-4 text-center" style="max-width: 480px;"><h4 class="text-danger mb-3">System Notice</h4><p class="text-secondary">An unexpected operation error occurred. The incident has been recorded in the system audit log.</p><a href="' . APP_URL . '/dashboard.php" class="btn btn-primary mt-2">Return to Dashboard</a></div>';
    echo '</body></html>';
    exit;
});

// Load core helpers
require_once CONFIG_PATH . '/database.php';
require_once INCLUDES_PATH . '/response.php';
require_once INCLUDES_PATH . '/functions.php';

// Dynamic Business Identity Constants (Driven by System Settings)
if (!defined('CLIENT_NAME')) {
    define('CLIENT_NAME', get_setting('distributor_name', 'Muzzam Enterprises'));
}
if (!defined('CLIENT_LOCATION')) {
    $city = get_setting('city', 'Gujranwala');
    define('CLIENT_LOCATION', $city . ', Pakistan');
}
if (!defined('CLIENT_PHONE')) {
    define('CLIENT_PHONE', get_setting('distributor_phone', ''));
}
if (!defined('CLIENT_ADDRESS')) {
    define('CLIENT_ADDRESS', get_setting('distributor_address', ''));
}
require_once INCLUDES_PATH . '/validation.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/database_helpers.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';
require_once INCLUDES_PATH . '/partner_functions.php';
