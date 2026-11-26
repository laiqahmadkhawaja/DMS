<?php
/**
 * DMS - Distribution Management System
 * AJAX Handler: Open-WA WhatsApp Actions
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Master Client-Wise Feature Toggle: block all actions if feature is turned off
if (!function_exists('is_whatsapp_feature_enabled') || !is_whatsapp_feature_enabled()) {
    echo json_encode([
        'success' => false,
        'message' => 'WhatsApp integration is disabled in system features for this installation.'
    ]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Support live configuration overrides passed from frontend form (before settings are saved)
$rawApiUrl = $_POST['api_url'] ?? $_GET['api_url'] ?? null;
$rawApiKey = $_POST['api_key'] ?? $_GET['api_key'] ?? null;
$rawSessionId = $_POST['session_id'] ?? $_GET['session_id'] ?? null;

$apiUrl = ($rawApiUrl !== null && trim($rawApiUrl) !== '') ? trim($rawApiUrl) : null;
$apiKey = ($rawApiKey !== null && trim($rawApiKey) !== '') ? trim($rawApiKey) : null;
$sessionId = ($rawSessionId !== null && trim($rawSessionId) !== '') ? trim($rawSessionId) : null;

$liveConfigOverride = null;
if ($apiUrl !== null || $apiKey !== null || $sessionId !== null) {
    $liveConfigOverride = [
        'base_url'   => $apiUrl,
        'api_key'    => $apiKey,
        'session_id' => $sessionId
    ];
}

try {
    switch ($action) {
        case 'save_settings':
            $enabled = !empty($_POST['whatsapp_enabled']) ? '1' : '0';
            $url = trim($_POST['whatsapp_api_url'] ?? '');
            $session = trim($_POST['whatsapp_session_id'] ?? 'default');
            $key = trim($_POST['whatsapp_api_key'] ?? '');
            $autoSend = !empty($_POST['whatsapp_auto_send_invoice']) ? '1' : '0';
            $owners = trim($_POST['whatsapp_owner_phones'] ?? '');

            if ($session === '') {
                $session = 'default';
            }

            $stmt = db()->prepare("
                INSERT INTO settings (setting_group, setting_key, setting_value, updated_at)
                VALUES ('whatsapp', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");

            $items = [
                'whatsapp_enabled'           => $enabled,
                'whatsapp_api_url'           => $url,
                'whatsapp_session_id'        => $session,
                'whatsapp_api_key'           => $key,
                'whatsapp_auto_send_invoice' => $autoSend,
                'whatsapp_owner_phones'      => $owners
            ];

            foreach ($items as $k => $v) {
                $stmt->execute([$k, $v]);
            }

            if (function_exists('audit_log')) {
                audit_log('update', 'settings', 'whatsapp_gateway', "Saved Open-WA WhatsApp Gateway configuration via Settings page button");
            }

            echo json_encode([
                'success' => true,
                'message' => 'WhatsApp gateway settings saved successfully into database!'
            ]);
            break;

        case 'test_connection':
            $status = whatsapp_check_connection($apiUrl, $apiKey, $sessionId);
            if ($status['connected']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Open-WA server connected successfully!',
                    'data'    => $status
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $status['error'] ?: 'Could not reach Open-WA server.',
                    'data'    => $status
                ]);
            }
            break;

        case 'preview_invoice':
            $invoiceId = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid invoice ID.']);
                exit;
            }
            $data = whatsapp_build_invoice_summary($invoiceId);
            echo json_encode(['success' => true, 'text' => $data['text'], 'phone' => $data['phone']]);
            break;

        case 'preview_statement':
            $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
            if ($customerId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer ID.']);
                exit;
            }
            $data = whatsapp_build_customer_statement($customerId);
            echo json_encode(['success' => true, 'text' => $data['text'], 'phone' => $data['phone']]);
            break;

        case 'send_test_message':
            $phone = trim($_POST['phone'] ?? '');
            $message = trim($_POST['message'] ?? 'Hello from DMS! Open-WA WhatsApp integration is active and working properly.');

            if (empty($phone)) {
                echo json_encode(['success' => false, 'message' => 'Please provide a valid recipient phone number.']);
                exit;
            }

            $res = whatsapp_send_text($phone, $message, $liveConfigOverride);
            if ($res['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => "Test WhatsApp message sent successfully to {$phone}!",
                    'data'    => $res['response']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to send message: ' . ($res['error'] ?: 'Unknown error from Open-WA server.'),
                    'data'    => $res
                ]);
            }
            break;

        case 'send_file':
            $phone = trim($_POST['phone'] ?? '');
            $caption = trim($_POST['caption'] ?? '');

            if (empty($phone)) {
                echo json_encode(['success' => false, 'message' => 'Please provide recipient phone number.']);
                exit;
            }

            // Check if file was uploaded or provided via base64
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $uploaded = $_FILES['file'];
                $data = file_get_contents($uploaded['tmp_name']);
                $b64 = base64_encode($data);
                $res = whatsapp_send_file($phone, $b64, $uploaded['name'], $caption, $liveConfigOverride);
            } elseif (!empty($_POST['file_base64']) && !empty($_POST['filename'])) {
                $res = whatsapp_send_file($phone, $_POST['file_base64'], $_POST['filename'], $caption, $liveConfigOverride);
            } else {
                echo json_encode(['success' => false, 'message' => 'No file (PDF or picture) was uploaded.']);
                exit;
            }

            if ($res['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'File sent successfully via WhatsApp!',
                    'data'    => $res['response']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to send file: ' . ($res['error'] ?: 'Open-WA server error.'),
                    'data'    => $res
                ]);
            }
            break;

        case 'send_invoice':
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;

            if ($invoiceId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid invoice ID provided.']);
                exit;
            }

            $res = whatsapp_send_invoice($invoiceId, $phone);
            if ($res['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Invoice bill sent via WhatsApp successfully!',
                    'data'    => $res['response']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'WhatsApp sending failed: ' . ($res['error'] ?: 'Unknown server error.'),
                    'data'    => $res
                ]);
            }
            break;

        case 'send_customer_statement':
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;

            if ($customerId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer ID provided.']);
                exit;
            }

            $stmtData = whatsapp_build_customer_statement($customerId);
            $targetPhone = $phone ?: $stmtData['phone'];

            if (empty($targetPhone)) {
                echo json_encode(['success' => false, 'message' => 'No mobile phone number found for this customer.']);
                exit;
            }

            $res = whatsapp_send_text($targetPhone, $stmtData['text']);
            if ($res['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Customer statement sent via WhatsApp successfully!',
                    'data'    => $res['response']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'WhatsApp sending failed: ' . ($res['error'] ?: 'Unknown server error.'),
                    'data'    => $res
                ]);
            }
            break;

        case 'broadcast_closing':
            $date = !empty($_POST['date']) ? trim($_POST['date']) : null;
            $res = whatsapp_broadcast_daily_closing($date);
            echo json_encode($res);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
