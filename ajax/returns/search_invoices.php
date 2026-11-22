<?php
/**
 * DMS - Distribution Management System
 * AJAX: Search Invoices for Sales Returns
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/response.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_error('Unauthenticated', [], 401);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

try {
    $where = ["inv.status != 'cancelled'"];
    $params = [];

    if (!empty($q)) {
        $where[] = "(inv.invoice_number LIKE ? OR c.shop_name LIKE ? OR c.customer_code LIKE ? OR ls.load_number LIKE ?)";
        $term = "%{$q}%";
        $params = array_fill(0, 4, $term);
    }

    $whereSql = implode(' AND ', $where);

    $invoices = db_fetch_all("
        SELECT 
            inv.id,
            inv.invoice_number,
            inv.invoice_date,
            inv.net_amount,
            inv.paid_amount,
            inv.credit_amount,
            inv.return_amount,
            inv.status,
            inv.load_sheet_id,
            c.id as customer_id,
            c.shop_name,
            c.customer_code,
            c.current_balance,
            a.area_name,
            b.booker_name,
            sm.salesman_name,
            ls.load_number
        FROM sales_invoices inv
        JOIN customers c ON inv.customer_id = c.id
        LEFT JOIN areas a ON inv.area_id = a.id
        LEFT JOIN bookers b ON inv.booker_id = b.id
        LEFT JOIN salesmen sm ON inv.salesman_id = sm.id
        LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
        WHERE {$whereSql}
        ORDER BY inv.id DESC
        LIMIT 25
    ", $params);

    json_success('Invoices found', $invoices);

} catch (Exception $e) {
    error_log("Error in search_invoices AJAX: " . $e->getMessage());
    json_error("Database error while searching invoices.");
}
