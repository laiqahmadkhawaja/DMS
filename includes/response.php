<?php
/**
 * DMS - Distribution Management System
 * Centralized JSON & HTTP Response Helper
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Return standardized JSON success response and exit
 *
 * @param string $message
 * @param array $data
 * @param int $statusCode
 */
function json_success(string $message = 'Operation completed successfully.', array $data = [], int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Return standardized JSON error response and exit
 *
 * @param string $message
 * @param array $errors Field-specific errors if applicable
 * @param int $statusCode
 */
function json_error(string $message = 'An error occurred.', array $errors = [], int $statusCode = 400): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = [
        'success' => false,
        'message' => $message
    ];
    if (!empty($errors)) {
        $payload['errors'] = $errors;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Return validation failure response and exit
 *
 * @param array $errors
 * @param string $message
 */
function json_validation_error(array $errors, string $message = 'Please correct the validation errors.'): void {
    json_error($message, $errors, 422);
}

/**
 * Return JSON authentication / authorization failure
 *
 * @param string $message
 */
function json_unauthorized(string $message = 'Unauthorized action or session expired.'): void {
    json_error($message, [], 403);
}

/**
 * Safe redirect ensuring headers sent check and immediate exit
 *
 * @param string $url
 * @param int $statusCode
 */
function redirect(string $url, int $statusCode = 302): void {
    if (headers_sent()) {
        echo "<script>window.location.href = " . json_encode($url) . ";</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "'></noscript>";
    } else {
        header("Location: " . $url, true, $statusCode);
    }
    exit;
}

/**
 * Safe flash message helper using session
 *
 * @param string $type success|danger|warning|info
 * @param string $message
 */
if (!function_exists('set_flash_message')) {
    function set_flash_message(string $type, string $message): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash_messages'][] = [
                'type'    => $type,
                'message' => $message
            ];
        }
    }
}

/**
 * Short alias for set_flash_message
 */
if (!function_exists('set_flash')) {
    function set_flash(string $type, string $message): void {
        set_flash_message($type, $message);
    }
}

/**
 * Retrieve and clear flash messages
 *
 * @return array
 */
if (!function_exists('get_flash_messages')) {
    function get_flash_messages(): array {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['flash_messages'])) {
            $messages = $_SESSION['flash_messages'];
            unset($_SESSION['flash_messages']);
            return $messages;
        }
        return [];
    }
}
