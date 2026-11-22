<?php
/**
 * DMS - Distribution Management System
 * AJAX: Fetch Detailed Invoice Line Items for Direct Bill-Wise Return
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

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$invoiceNumber = isset($_GET['invoice_number']) ? trim((string)$_GET['invoice_number']) : '';

if ($invoiceId <= 0 && empty($invoiceNumber)) {
    json_error('Valid invoice ID or Invoice Number is required.', [], 400);
}

try {
    $where = [];
    $params = [];
    if ($invoiceId > 0) {
        $where[] = "inv.id = ?";
        $params[] = $invoiceId;
    } else {
        $where[] = "inv.invoice_number = ?";
        $params[] = $invoiceNumber;
    }

    $whereSql = implode(' AND ', $where);

    $invoice = db_fetch_one("
        SELECT 
            inv.id, 
            inv.invoice_number, 
            inv.invoice_date, 
            inv.customer_id, 
            inv.load_sheet_id,
            inv.order_id,
            inv.booker_id,
            inv.salesman_id,
            inv.gross_amount,
            inv.discount_mode,
            inv.net_amount, 
            inv.paid_amount,
            inv.credit_amount,
            inv.return_amount,
            inv.status,
            c.shop_name,
            c.customer_code,
            c.current_balance,
            c.phone as customer_phone,
            c.address as customer_address,
            a.area_name,
            b.booker_name,
            sm.salesman_name,
            ls.load_number,
            ls.load_date,
            o.order_number
        FROM sales_invoices inv
        JOIN customers c ON inv.customer_id = c.id
        LEFT JOIN areas a ON inv.area_id = a.id
        LEFT JOIN bookers b ON inv.booker_id = b.id
        LEFT JOIN salesmen sm ON inv.salesman_id = sm.id
        LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
        LEFT JOIN orders o ON inv.order_id = o.id
        WHERE {$whereSql}
    ", $params);

    if (!$invoice) {
        json_error('Invoice not found.', [], 404);
    }

    $invId = (int)$invoice['id'];

    // Fetch existing returns for this invoice to calculate previous returned quantities
    $prevReturns = db_fetch_all("
        SELECT 
            sri.product_id,
            COALESCE(SUM(sri.carton_qty), 0) as returned_cartons,
            COALESCE(SUM(sri.pack_qty), 0) as returned_packs,
            COALESCE(SUM(sri.pcs_qty), 0) as returned_pcs,
            COALESCE(SUM(sri.quantity), 0) as returned_base_qty,
            COALESCE(SUM(sri.amount), 0) as returned_amount
        FROM sales_returns sr
        JOIN sales_return_items sri ON sr.id = sri.sales_return_id
        WHERE sr.invoice_id = ? AND sr.status != 'cancelled'
        GROUP BY sri.product_id
    ", [$invId]);

    $prevReturnsMap = [];
    foreach ($prevReturns as $pr) {
        $prevReturnsMap[(int)$pr['product_id']] = [
            'returned_cartons'  => (float)$pr['returned_cartons'],
            'returned_packs'    => (float)$pr['returned_packs'],
            'returned_pcs'      => (float)$pr['returned_pcs'],
            'returned_base_qty' => (float)$pr['returned_base_qty'],
            'returned_amount'   => (float)$pr['returned_amount'],
        ];
    }

    // Fetch invoice items
    $items = db_fetch_all("
        SELECT 
            sii.id as line_id,
            sii.product_id,
            sii.unit_id,
            p.product_code,
            p.product_name,
            p.carton_qty as packs_per_ctn,
            p.pack_qty as pcs_per_pack,
            p.packing_description,
            u.symbol as unit_symbol,
            sii.carton_qty,
            sii.pack_qty,
            sii.pcs_qty,
            sii.quantity as total_cartons,
            sii.rate,
            sii.discount_percent,
            sii.discount_amount,
            sii.line_total
        FROM sales_invoice_items sii
        JOIN products p ON sii.product_id = p.id
        LEFT JOIN units u ON sii.unit_id = u.id
        WHERE sii.invoice_id = ?
        ORDER BY sii.id ASC
    ", [$invId]);

    // Enhance items with packaging ratios and previous returns
    $enrichedItems = [];
    foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $packsPerCtn = (float)$it['packs_per_ctn'] > 0 ? (float)$it['packs_per_ctn'] : 1.0;
        $pcsPerPack  = (float)$it['pcs_per_pack'] > 0 ? (float)$it['pcs_per_pack'] : 1.0;
        $pcsPerCtn   = $packsPerCtn * $pcsPerPack;

        $billedCartons = (float)$it['carton_qty'];
        $billedPacks   = (float)$it['pack_qty'];
        $billedPcs     = (float)$it['pcs_qty'];
        $billedRate    = (float)$it['rate'];
        $discPct       = (float)$it['discount_percent'];
        $lineTotal     = (float)$it['line_total'];

        $prev = $prevReturnsMap[$pid] ?? [
            'returned_cartons'  => 0.0,
            'returned_packs'    => 0.0,
            'returned_pcs'      => 0.0,
            'returned_base_qty' => 0.0,
            'returned_amount'   => 0.0,
        ];

        $remCartons = max(0, $billedCartons - $prev['returned_cartons']);
        $remPcs     = max(0, $billedPcs - $prev['returned_pcs']);

        $enrichedItems[] = [
            'line_id'           => (int)$it['line_id'],
            'product_id'        => $pid,
            'product_code'      => $it['product_code'],
            'product_name'      => $it['product_name'],
            'unit_id'           => (int)$it['unit_id'],
            'unit_symbol'       => $it['unit_symbol'] ?: 'CTN',
            'packs_per_ctn'     => $packsPerCtn,
            'pcs_per_pack'      => $pcsPerPack,
            'pcs_per_ctn'       => $pcsPerCtn,
            'packing_desc'      => $it['packing_description'] ?: "{$packsPerCtn}x{$pcsPerPack}",
            'billed_carton_qty' => $billedCartons,
            'billed_pack_qty'   => $billedPacks,
            'billed_pcs_qty'    => $billedPcs,
            'rate'              => $billedRate,
            'discount_percent'  => $discPct,
            'line_total'        => $lineTotal,
            'returned_cartons'  => $prev['returned_cartons'],
            'returned_pcs'      => $prev['returned_pcs'],
            'returned_amount'   => $prev['returned_amount'],
            'remaining_cartons' => $remCartons,
            'remaining_pcs'     => $remPcs,
            'is_fully_returned' => ($remCartons <= 0 && $remPcs <= 0 && ($billedCartons > 0 || $billedPcs > 0))
        ];
    }

    json_success('Invoice items loaded successfully', [
        'invoice' => $invoice,
        'items'   => $enrichedItems
    ]);

} catch (Exception $e) {
    error_log("Error in get_invoice_items AJAX: " . $e->getMessage());
    json_error("Database error occurred while fetching invoice items: " . $e->getMessage());
}
