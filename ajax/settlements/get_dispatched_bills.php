<?php
/**
 * DMS - Distribution Management System
 * AJAX: Fetch Dispatched Bills for Route Settlement
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

if (!has_permission('settlements_create') && !has_permission('settlements_view')) {
    json_error('Permission denied', 403);
}

$salesmanId    = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
$deliveryRunId = isset($_GET['delivery_run_id']) ? (int)$_GET['delivery_run_id'] : 0;
$loadSheetId   = isset($_GET['load_sheet_id']) ? (int)$_GET['load_sheet_id'] : 0;
$settleDate    = isset($_GET['date']) ? sanitize_input($_GET['date']) : '';

$where = ["inv.status = 'finalized'"];
$params = [];

if ($deliveryRunId > 0) {
    $where[] = "inv.id IN (SELECT invoice_id FROM delivery_run_bills WHERE delivery_run_id = ?)";
    $params[] = $deliveryRunId;
} elseif ($loadSheetId > 0) {
    $where[] = "inv.load_sheet_id = ?";
    $params[] = $loadSheetId;
} else {
    if ($salesmanId > 0) {
        $where[] = "inv.salesman_id = ?";
        $params[] = $salesmanId;
    }
    if (!empty($settleDate)) {
        $where[] = "inv.invoice_date = ?";
        $params[] = $settleDate;
    }
}

$whereClause = implode(" AND ", $where);

try {
    $runJoin = "";
    $runSelect = ", 0.00 as field_cash, 0.00 as field_credit, 0.00 as field_return, NULL as field_return_items_json";
    if ($deliveryRunId > 0) {
        $runJoin = "LEFT JOIN delivery_run_bills drb ON (drb.invoice_id = inv.id AND drb.delivery_run_id = {$deliveryRunId})";
        $runSelect = ", COALESCE(drb.cash_collected, 0.00) as field_cash, COALESCE(drb.credit_given, 0.00) as field_credit, COALESCE(drb.return_value, 0.00) as field_return, drb.return_items_json as field_return_items_json";
    }

    $bills = db_fetch_all("
        SELECT 
            inv.id as invoice_id,
            inv.invoice_number,
            inv.invoice_date,
            inv.customer_id,
            c.shop_name as customer_name,
            c.customer_code,
            c.address as customer_address,
            inv.net_amount,
            inv.paid_amount,
            inv.credit_amount,
            inv.return_amount,
            inv.load_sheet_id,
            a.area_name,
            b.booker_name
            {$runSelect}
        FROM sales_invoices inv
        JOIN customers c ON inv.customer_id = c.id
        JOIN areas a ON inv.area_id = a.id
        JOIN bookers b ON inv.booker_id = b.id
        {$runJoin}
        WHERE {$whereClause}
        ORDER BY a.area_name ASC, c.shop_name ASC
    ", $params);

    // Also fetch items for each invoice so return quantities can be captured if needed
    $invoiceIds = array_column($bills, 'invoice_id');
    $itemsByInvoice = [];

    if (!empty($invoiceIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $items = db_fetch_all("
            SELECT 
                sii.invoice_id,
                sii.product_id,
                p.product_name,
                p.carton_qty as ctn_ratio,
                sii.quantity,
                sii.rate,
                sii.line_total
            FROM sales_invoice_items sii
            JOIN products p ON sii.product_id = p.id
            WHERE sii.invoice_id IN ({$inPlaceholders})
            ORDER BY sii.id ASC
        ", $invoiceIds);

        foreach ($items as $item) {
            $itemsByInvoice[$item['invoice_id']][] = $item;
        }
    }

    foreach ($bills as &$bill) {
        $bill['items'] = $itemsByInvoice[$bill['invoice_id']] ?? [];
    }
    unset($bill);

    json_success('Bills fetched successfully', [
        'count' => count($bills),
        'bills' => $bills
    ]);

} catch (Exception $e) {
    error_log("Error in get_dispatched_bills AJAX: " . $e->getMessage());
    json_error("Database error occurred while fetching bills.");
}
