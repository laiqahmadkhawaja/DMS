<?php
/**
 * DMS - Distribution Management System
 * Application Constants
 */

if (!defined('DMS_RUNNING')) {
    define('DMS_RUNNING', true);
}

// Application Information
define('APP_NAME', 'Distribution Management System');
define('APP_SHORT_NAME', 'DMS');
define('APP_VERSION', '1.0.0');
define('CLIENT_NAME', 'Muzzam Enterprises');
define('CLIENT_LOCATION', 'Gujranwala, Pakistan');

// Paths
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'config');
define('INCLUDES_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'includes');
define('MODULES_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'modules');
define('AJAX_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'ajax');
define('TEMPLATES_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'templates');
define('STORAGE_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'storage');
define('LOGS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'logs');

// URL Configuration (Autodetect base URL for local XAMPP / WAMP)
if (php_sapi_name() === 'cli') {
    define('APP_URL', 'http://localhost/Distribution');
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseDir = rtrim(preg_replace('#/(modules|ajax|templates|assets|includes|config|database).*$#', '', '/' . ltrim($scriptDir, '/')), '/');
    define('APP_URL', $protocol . $host . $baseDir);
}

// Database Credentials
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'distribution_dms');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Business & Numbering Defaults
define('DEFAULT_CURRENCY', 'Rs.');
define('DEFAULT_TIMEZONE', 'Asia/Karachi');

// Ensure timezone and session are initialized across all modules
date_default_timezone_set(DEFAULT_TIMEZONE);

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

// Document Numbering Prefixes
define('PREFIX_ORDER', 'ORD');
define('PREFIX_INVOICE', 'INV');
define('PREFIX_LOAD', 'LOAD');
define('PREFIX_RUN', 'RUN');
define('PREFIX_SETTLEMENT', 'SET');
define('PREFIX_PURCHASE', 'PUR');
define('PREFIX_GRN', 'GRN');
define('PREFIX_RETURN', 'RET');
define('PREFIX_PAYMENT', 'PAY');
define('PREFIX_EXPENSE', 'EXP');
define('PREFIX_JOURNAL', 'JV');
define('PREFIX_ADJUSTMENT', 'ADJ');

// System Accounts (from accounts table)
define('ACC_CASH_MAIN', 1001);
define('ACC_PETTY_CASH', 1002);
define('ACC_BANK_MAIN', 1010);
define('ACC_RECEIVABLES', 1020);
define('ACC_INVENTORY', 1030);
define('ACC_PAYABLES', 2010);
define('ACC_CAPITAL', 3010);
define('ACC_SALES', 4010);
define('ACC_DISCOUNTS', 4020);
define('ACC_RETURNS', 4030);
define('ACC_COGS', 5010);
