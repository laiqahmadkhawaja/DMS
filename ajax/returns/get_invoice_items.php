<?php
/**
 * DMS - Distribution Management System
 * AJAX: Fetch Invoice Line Items for Direct Return
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/response.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_error('Unauthenticated', 401);
}

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if ($invoiceId <= 0) {
    json_error('Valid invoice ID is required.', 400);
}

try {
    $invoice = db_fetch_one("
        SELECT id, invoice_number, invoice_date, customer_id, net_amount, status
        FROM sales_invoices
        WHERE id = ?
    ", [$invoiceId]);

    if (!$invoice) {
        json_error('Invoice not found.', 404);
    }

    $items = db_fetch_all("
        SELECT 
            sii.id as line_id,
            sii.product_id,
            p.product_code,
            p.product_name,
            p.carton_qty as packs_per_ctn,
            p.pack_qty as pcs_per_pack,
            sii.carton_qty,
            sii.pack_qty,
            sii.pcs_qty,
            sii.quantity as total_cartons,
            sii.rate,
            sii.discount_percent,
            sii.line_total
        FROM sales_invoice_items sii
        JOIN products p ON sii.product_id = p.id
        WHERE sii.invoice_id = ?
        ORDER BY sii.id ASC
    ", [$invoiceId]);

    json_success('Invoice items loaded', [
        'invoice' => $invoice,
        'items'   => $items
    ]);

} catch (Exception $e) {
    error_log("Error in get_invoice_items AJAX: " . $e->getMessage());
    json_error("Database error occurred while fetching invoice items.");
}
