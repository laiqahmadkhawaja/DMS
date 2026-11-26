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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'test_connection':
            $status = whatsapp_check_connection();
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

            $res = whatsapp_send_text($phone, $message);
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
                $res = whatsapp_send_file($phone, $b64, $uploaded['name'], $caption);
            } elseif (!empty($_POST['file_base64']) && !empty($_POST['filename'])) {
                $res = whatsapp_send_file($phone, $_POST['file_base64'], $_POST['filename'], $caption);
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
