<?php
/**
 * DMS - Distribution Management System
 * AJAX: Fetch Customer Billing History & Latest Product Rates for Returns
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

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
if ($customerId <= 0) {
    json_error('Valid customer ID is required.', 400);
}

try {
    $customer = db_fetch_one("
        SELECT c.id, c.shop_name, c.customer_code, c.current_balance, c.credit_limit, c.phone, c.address, a.area_name
        FROM customers c
        LEFT JOIN areas a ON c.area_id = a.id
        WHERE c.id = ? AND c.is_deleted = 0
    ", [$customerId]);

    if (!$customer) {
        json_error('Customer not found.', 404);
    }

    // 1. Fetch recent invoices
    $recentInvoices = db_fetch_all("
        SELECT id, invoice_number, invoice_date, net_amount, status
        FROM sales_invoices
        WHERE customer_id = ? AND status != 'cancelled'
        ORDER BY invoice_date DESC, id DESC
        LIMIT 25
    ", [$customerId]);

    // 2. Fetch latest sold rate for each product this customer purchased
    $rawRates = db_fetch_all("
        SELECT 
            sii.product_id, 
            sii.unit_id,
            sii.carton_qty,
            sii.pack_qty,
            sii.pcs_qty,
            sii.rate, 
            sii.discount_percent, 
            u.unit_code,
            u.symbol as unit_symbol,
            si.id as invoice_id, 
            si.invoice_number, 
            si.invoice_date
        FROM sales_invoice_items sii
        JOIN sales_invoices si ON si.id = sii.invoice_id
        LEFT JOIN units u ON sii.unit_id = u.id
        WHERE si.customer_id = ? AND si.status != 'cancelled'
        ORDER BY si.invoice_date DESC, si.id DESC
    ", [$customerId]);

    $latestRates = [];
    foreach ($rawRates as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($latestRates[$pid])) {
            $unitCodeRaw = strtolower(trim((string)($r['unit_code'] ?? '')));
            $unitId = (int)$r['unit_id'];
            $cartonQty = (float)$r['carton_qty'];
            $packQty   = (float)$r['pack_qty'];
            $pcsQty    = (float)$r['pcs_qty'];

            $unit = 'ctn';
            if ($unitId === 4 || $unitCodeRaw === 'pcs') {
                $unit = 'pcs';
            } elseif ($unitId === 3 || $unitCodeRaw === 'pck' || $unitCodeRaw === 'pack') {
                $unit = 'pack';
            } elseif ($cartonQty > 0) {
                $unit = 'ctn';
            } elseif ($packQty > 0) {
                $unit = 'pack';
            } elseif ($pcsQty > 0) {
                $unit = 'pcs';
            }

            $latestRates[$pid] = [
                'rate'             => (float)$r['rate'],
                'unit'             => $unit,
                'unit_id'          => $unitId,
                'unit_symbol'      => $r['unit_symbol'] ?: ($unit === 'pack' ? 'Pck' : ($unit === 'pcs' ? 'Pcs' : 'Ctn')),
                'carton_qty'       => $cartonQty,
                'pack_qty'         => $packQty,
                'pcs_qty'          => $pcsQty,
                'discount_percent' => (float)$r['discount_percent'],
                'invoice_id'       => (int)$r['invoice_id'],
                'invoice_number'   => $r['invoice_number'],
                'invoice_date'     => date('d-M-Y', strtotime($r['invoice_date']))
            ];
        }
    }

    json_success('Customer rate history loaded', [
        'customer'        => $customer,
        'recent_invoices' => $recentInvoices,
        'latest_rates'    => $latestRates
    ]);

} catch (Exception $e) {
    error_log("Error in get_customer_rates AJAX: " . $e->getMessage());
    json_error("Database error occurred while fetching customer rate history.");
}
