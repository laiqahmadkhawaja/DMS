<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - View & Print Load Sheet Summary
 * Matches Client Photo 5 & Photo 9 (TOTAL STOCK ISSUE SUMMARY)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('loading_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid load sheet ID specified.');
    redirect(APP_URL . '/modules/loading/index.php');
}

$sheet = db_fetch_one("
    SELECT 
        ls.*,
        s.salesman_name,
        s.phone as salesman_phone,
        w.warehouse_name,
        u.full_name as creator_name,
        iu.full_name as issuer_name
    FROM load_sheets ls
    JOIN salesmen s ON ls.salesman_id = s.id
    JOIN warehouses w ON ls.warehouse_id = w.id
    LEFT JOIN users u ON ls.created_by = u.id
    LEFT JOIN users iu ON ls.issued_by = iu.id
    WHERE ls.id = ?
", [$id]);

if (!$sheet) {
    set_flash('danger', 'Load sheet not found.');
    redirect(APP_URL . '/modules/loading/index.php');
}

// Fetch items
$items = db_fetch_all("
    SELECT 
        lsi.*,
        p.product_code,
        p.product_name,
        p.carton_qty as packs_per_ctn,
        p.pack_qty as pcs_per_pack,
        c.category_name,
        u.unit_code,
        u.unit_name
    FROM load_sheet_items lsi
    JOIN products p ON lsi.product_id = p.id
    JOIN units u ON lsi.unit_id = u.id
    LEFT JOIN product_categories c ON p.category_id = c.id
    WHERE lsi.load_sheet_id = ?
    ORDER BY c.category_name ASC, p.product_name ASC
", [$id]);

// Check for linked settlement & determine filled view mode
$settlement = db_fetch_one("
    SELECT s.*, dr.run_number 
    FROM settlements s 
    LEFT JOIN delivery_runs dr ON s.delivery_run_id = dr.id
    WHERE s.load_sheet_id = ? 
       OR s.delivery_run_id IN (SELECT id FROM delivery_runs WHERE load_sheet_id = ?)
    ORDER BY s.id DESC LIMIT 1
", [$id, $id]);

$isSettled = !empty($settlement) || $sheet['status'] === 'returned_closed';
$viewMode = $_GET['mode'] ?? ($isSettled ? 'filled' : 'blank');
$showFilled = ($viewMode === 'filled');

// Fetch included orders with recovery data
$orders = db_fetch_all("
    SELECT 
        o.id,
        o.order_number,
        o.order_date,
        c.shop_name,
        c.phone as customer_phone,
        a.area_name,
        b.booker_name,
        o.gross_amount,
        o.discount_amount,
        o.discount_mode,
        o.net_amount,
        o.status as order_status,
        inv.id as invoice_id,
        inv.invoice_number,
        inv.paid_amount,
        inv.credit_amount,
        inv.return_amount,
        si.cash_amount as settled_cash,
        si.credit_amount as settled_credit,
        si.return_amount as settled_return
    FROM load_sheet_orders lso
    JOIN orders o ON lso.order_id = o.id
    JOIN customers c ON o.customer_id = c.id
    JOIN areas a ON o.area_id = a.id
    JOIN bookers b ON o.booker_id = b.id
    LEFT JOIN sales_invoices inv ON (inv.order_id = o.id AND (inv.load_sheet_id = lso.load_sheet_id OR inv.load_sheet_id IS NULL))
    LEFT JOIN settlement_items si ON (si.invoice_id = inv.id AND si.settlement_id = ?)
    WHERE lso.load_sheet_id = ?
    ORDER BY a.area_name ASC, c.shop_name ASC
", [$settlement ? $settlement['id'] : 0, $id]);

// Calculate per-product settled returns & credits
$productCredits = [];
$productReturns = [];
$productReturnQtys = [];

if ($settlement) {
    // 1. Direct sales return items if itemized
    $retItems = db_fetch_all("
        SELECT 
            sri.product_id,
            SUM(sri.quantity) as return_base_units,
            SUM(sri.amount) as return_amount
        FROM sales_returns sr
        JOIN sales_return_items sri ON sr.id = sri.sales_return_id
        WHERE sr.settlement_id = ?
        GROUP BY sri.product_id
    ", [$settlement['id']]);

    foreach ($retItems as $ri) {
        $productReturns[(int)$ri['product_id']] = (float)$ri['return_amount'];
        $productReturnQtys[(int)$ri['product_id']] = (float)$ri['return_base_units'];
    }

    // 2. Invoice line distributions for credits and returns
    $invoiceLines = db_fetch_all("
        SELECT 
            sii.product_id,
            sii.invoice_id,
            sii.line_total,
            sii.carton_qty,
            sii.pcs_qty,
            p.wholesale_rate,
            inv.net_amount,
            COALESCE(si.credit_amount, inv.credit_amount) as invoice_credit,
            COALESCE(si.return_amount, inv.return_amount) as invoice_return
        FROM sales_invoice_items sii
        JOIN sales_invoices inv ON sii.invoice_id = inv.id
        JOIN products p ON sii.product_id = p.id
        LEFT JOIN settlement_items si ON (si.invoice_id = inv.id AND si.settlement_id = ?)
        WHERE inv.load_sheet_id = ? 
           OR inv.order_id IN (SELECT order_id FROM load_sheet_orders WHERE load_sheet_id = ?)
    ", [$settlement['id'], $id, $id]);

    foreach ($invoiceLines as $il) {
        $pid = (int)$il['product_id'];
        $lineTot = (float)$il['line_total'];
        $invNet = (float)$il['net_amount'] > 0 ? (float)$il['net_amount'] : 1;
        $ratio = min(1.0, $lineTot / $invNet);

        $lineCredit = (float)$il['invoice_credit'] * $ratio;
        $productCredits[$pid] = ($productCredits[$pid] ?? 0.0) + $lineCredit;

        if (!isset($productReturns[$pid])) {
            $lineRet = (float)$il['invoice_return'] * $ratio;
            $productReturns[$pid] = ($productReturns[$pid] ?? 0.0) + $lineRet;
            $rate = (float)$il['wholesale_rate'] > 0 ? (float)$il['wholesale_rate'] : 1;
            $productReturnQtys[$pid] = ($productReturnQtys[$pid] ?? 0.0) + ($lineRet / $rate);
        }
    }
}

// Booker names for header
$bookerNames = array_unique(array_column($orders, 'booker_name'));
$bookersList = !empty($bookerNames) ? implode(', ', $bookerNames) : 'N/A';

// Areas for header
$areaNames = array_unique(array_column($orders, 'area_name'));
$areasList = !empty($areaNames) ? implode(', ', $areaNames) : 'All Routes';

$pageTitle = 'Load Sheet ' . $sheet['load_number'];
$activeMenu = 'loading';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Warehouse Loading', 'url' => 'index.php'],
    ['label' => $sheet['load_number'], 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<style>
@media print {
    .no-print, .sidebar-wrapper, .topbar, .footer, .content-header, .btn, .breadcrumb {
        display: none !important;
    }
    .content-wrapper {
        margin: 0 !important;
        padding: 0 !important;
    }
    .print-sheet {
        border: 1px solid #000 !important;
        padding: 10px !important;
        font-size: 13px !important;
        color: #000 !important;
    }
    .print-table th, .print-table td {
        border: 1px solid #333 !important;
        padding: 4px 6px !important;
    }
    .print-table th {
        background-color: #d1e7dd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .print-yellow-header {
        background-color: #c9b003 !important;
        color: #000 !important;
        font-weight: bold !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .print-yellow-total {
        background-color: #d4a000 !important;
        font-weight: bold !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Top Action Bar (No Print) -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Load Sheet: <?= htmlspecialchars($sheet['load_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($sheet['status'] === 'returned_closed' || $settlement): ?>
                        <span class="badge bg-info text-dark"><i class="bi bi-check2-all me-1"></i>Settled & Closed</span>
                        <?php if ($settlement): ?>
                            <a href="../settlements/view.php?id=<?= $settlement['id'] ?>" class="badge bg-light text-primary text-decoration-none border shadow-sm">
                                <i class="bi bi-receipt me-1"></i>Settlement: <?= htmlspecialchars($settlement['settlement_number']) ?>
                            </a>
                        <?php endif; ?>
                    <?php elseif ($sheet['status'] === 'draft'): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass me-1"></i>Draft</span>
                    <?php elseif ($sheet['status'] === 'issued'): ?>
                        <span class="badge bg-success"><i class="bi bi-truck me-1"></i>Issued / Dispatched</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($sheet['status']) ?></span>
                    <?php endif; ?>
                    <span class="text-muted small">Load Date: <?= date('d-M-Y', strtotime($sheet['load_date'])) ?> | Delivery: <?= date('d-M-Y', strtotime($sheet['delivery_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <a href="?id=<?= $id ?>&mode=<?= $showFilled ? 'blank' : 'filled' ?>" class="btn btn-sm <?= $showFilled ? 'btn-outline-secondary' : 'btn-outline-success' ?> shadow-sm">
                    <i class="bi <?= $showFilled ? 'bi-file-earmark' : 'bi-check2-circle' ?> me-1"></i> <?= $showFilled ? 'Blank Field Form' : 'Show Settled Figures' ?>
                </a>
                <?php if ($sheet['status'] !== 'cancelled' && $sheet['status'] !== 'returned_closed' && !$settlement): ?>
                    <a href="edit.php?id=<?= $sheet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Edit Sheet
                    </a>
                <?php endif; ?>
                <a href="print.php?id=<?= $sheet['id'] ?>&filled=<?= $showFilled ? '1' : '0' ?>" target="_blank" class="btn btn-outline-primary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print Sheet (Photo 5/9)
                </a>
                <?php if ($settlement): ?>
                    <a href="../settlements/view.php?id=<?= $settlement['id'] ?>" class="btn btn-sm btn-outline-success shadow-sm">
                        <i class="bi bi-file-earmark-check me-1"></i> View Settlement
                    </a>
                <?php endif; ?>
                <?php if ($sheet['status'] === 'draft' && has_permission('loading_issue')): ?>
                    <a href="issue.php?id=<?= $sheet['id'] ?>" class="btn btn-success btn-sm shadow-sm" onclick="return confirm('Confirm stock dispatch from warehouse?');">
                        <i class="bi bi-box-arrow-right me-1"></i> Issue Stock Now
                    </a>
                <?php endif; ?>
                <?php if ($sheet['status'] === 'issued' && !$settlement): ?>
                    <a href="../delivery/create.php?load_sheet_id=<?= $sheet['id'] ?>" class="btn btn-info btn-sm shadow-sm text-white">
                        <i class="bi bi-pin-map me-1"></i> Create Delivery Run
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- PRINTABLE LOAD SHEET SUMMARY (EXACT REPLICA OF PHOTOS 5 & 9) -->
        <div class="card border-0 shadow-sm print-sheet mb-4">
            <div class="card-body p-4">
                <!-- Header Banner -->
                <div class="text-center pb-2 mb-3 border-bottom border-dark">
                    <h3 class="fw-bold text-uppercase tracking-wide mb-1" style="font-family: Arial, sans-serif; letter-spacing: 1px;">
                        TOTAL STOCK ISSUE SUMMARY
                    </h3>
                    <div class="small fw-semibold text-muted">
                        <?= CLIENT_NAME ?> - <?= CLIENT_LOCATION ?> (Ph: 0300-0673276)
                    </div>
                </div>

                <!-- Metadata Row (Matches Form #, Booker(s), Date, Area) -->
                <div class="row g-2 mb-3 fw-bold small">
                    <div class="col-4">
                        <span class="text-muted">Form / Sheet #:</span> 
                        <span class="text-dark"><?= htmlspecialchars($sheet['load_number']) ?></span>
                    </div>
                    <div class="col-4 text-center">
                        <span class="text-muted">Booker(s):</span> 
                        <span class="text-dark"><?= htmlspecialchars($bookersList) ?></span>
                    </div>
                    <div class="col-4 text-end">
                        <span class="text-muted">Date:</span> 
                        <span class="text-dark"><?= date('d.m.y', strtotime($sheet['delivery_date'])) ?></span>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Salesman:</span> 
                        <span class="text-navy"><?= htmlspecialchars($sheet['salesman_name']) ?></span> (<?= htmlspecialchars($sheet['salesman_phone'] ?: 'N/A') ?>)
                    </div>
                    <div class="col-6 text-end">
                        <span class="text-muted">Route / Area:</span> 
                        <span class="text-dark"><?= htmlspecialchars($areasList) ?></span>
                    </div>
                </div>

                <!-- Products Dispatch Table (Photo 5 & 9 Columns) -->
                <div class="table-responsive">
                    <table class="table table-bordered print-table align-middle mb-0" style="border: 2px solid #000;">
                        <thead>
                            <tr style="background-color: #d1b80d; color: #000;" class="print-yellow-header text-center small text-uppercase">
                                <th rowspan="2" style="width: 13%; border: 1px solid #000; vertical-align: middle;">Company / Area</th>
                                <th rowspan="2" style="width: 27%; border: 1px solid #000; vertical-align: middle;" class="text-start">Product</th>
                                <th rowspan="2" style="width: 7%; border: 1px solid #000; vertical-align: middle;">Ctn Qty</th>
                                <th rowspan="2" style="width: 7%; border: 1px solid #000; vertical-align: middle;">Pack Qty</th>
                                <th rowspan="2" style="width: 7%; border: 1px solid #000; vertical-align: middle;">Pcs Qty</th>
                                <th rowspan="2" style="width: 9%; border: 1px solid #000; vertical-align: middle;">Rate</th>
                                <th rowspan="2" style="width: 11%; border: 1px solid #000; vertical-align: middle;">Total Amount</th>
                                <th colspan="2" style="width: 11%; border: 1px solid #000; padding: 2px;">Return</th>
                                <th rowspan="2" style="width: 8%; border: 1px solid #000; vertical-align: middle;">Credit</th>
                            </tr>
                            <tr style="background-color: #d1b80d; color: #000;" class="print-yellow-header text-center small text-uppercase">
                                <th style="width: 5%; border: 1px solid #000; font-size: 0.72rem; padding: 2px;">Return</th>
                                <th style="width: 6%; border: 1px solid #000; font-size: 0.72rem; padding: 2px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalCtn = 0;
                            $totalPack = 0;
                            $totalPcs = 0;
                            $grandTotalAmount = 0;
                            $grandTotalReturnAmt = 0;
                            $grandTotalReturnQty = 0;
                            $grandTotalCreditAmt = 0;

                            foreach ($items as $item): 
                                $packsPerCtn = (float)$item['packs_per_ctn'] > 0 ? (float)$item['packs_per_ctn'] : 1;
                                $pcsPerPack  = (float)$item['pcs_per_pack'] > 0 ? (float)$item['pcs_per_pack'] : 1;
                                $baseQty     = (float)$item['ordered_qty'];
                                
                                if ((float)($item['carton_qty'] ?? 0) > 0 || (float)($item['pack_qty'] ?? 0) > 0 || (float)($item['pcs_qty'] ?? 0) > 0) {
                                    $ctnPart  = (float)($item['carton_qty'] ?? 0);
                                    $packPart = (float)($item['pack_qty'] ?? 0);
                                    $pcsPart  = (float)($item['pcs_qty'] ?? 0);
                                } else {
                                    $bk = breakdown_base_pieces($baseQty, $packsPerCtn, $pcsPerPack);
                                    $ctnPart  = $bk['cartons'];
                                    $packPart = $bk['packs'];
                                    $pcsPart  = $bk['pieces'];
                                }
                                
                                $totalCtn  += $ctnPart;
                                $totalPack += $packPart;
                                $totalPcs  += $pcsPart;
                                $grandTotalAmount += (float)$item['total_amount'];

                                $pid = (int)$item['product_id'];
                                $itemCredit = (float)($productCredits[$pid] ?? 0);
                                $itemReturnAmt = (float)($productReturns[$pid] ?? 0);
                                $itemReturnQty = (float)($productReturnQtys[$pid] ?? 0);

                                $grandTotalReturnAmt += $itemReturnAmt;
                                $grandTotalReturnQty += $itemReturnQty;
                                $grandTotalCreditAmt += $itemCredit;
                            ?>
                                <tr>
                                    <td class="small fw-semibold"><?= htmlspecialchars($item['category_name'] ?: 'General') ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td class="text-center fw-bold"><?= $ctnPart > 0 ? $ctnPart : '' ?></td>
                                    <td class="text-center"><?= $packPart > 0 ? $packPart : '' ?></td>
                                    <td class="text-center"><?= $pcsPart > 0 ? $pcsPart : '' ?></td>
                                    <td class="text-center"><?= number_format((float)$item['rate'], 2) ?></td>
                                    <td class="text-end pe-2 fw-bold"><?= number_format((float)$item['total_amount'], 2) ?></td>
                                    
                                    <?php if ($showFilled): ?>
                                        <td class="text-center small fw-bold" style="border: 1px solid #000; background-color: #fff;">
                                            <?= $itemReturnQty > 0 ? number_format($itemReturnQty, 0) : '0' ?>
                                        </td>
                                        <td class="text-end pe-2 small fw-bold <?= $itemReturnAmt > 0 ? 'text-warning' : 'text-dark' ?>" style="border: 1px solid #000; background-color: #fff;">
                                            <?= number_format($itemReturnAmt, 2) ?>
                                        </td>
                                        <td class="text-end pe-2 fw-bold text-danger" style="border: 1px solid #000; background-color: #fff;">
                                            <?= number_format($itemCredit, 2) ?>
                                        </td>
                                    <?php else: ?>
                                        <!-- Blank columns for salesman recovery reconciliation as in real photos -->
                                        <td style="background-color: #fafafa; border: 1px solid #000;"></td>
                                        <td style="background-color: #fafafa; border: 1px solid #000;"></td>
                                        <td style="background-color: #fafafa; border: 1px solid #000;"></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #c99c04; color: #000;" class="print-yellow-total fw-bold">
                                <td colspan="2" class="text-end text-uppercase pe-3">Total:</td>
                                <td class="text-center"><?= $totalCtn ?></td>
                                <td class="text-center"><?= $totalPack > 0 ? $totalPack : '' ?></td>
                                <td class="text-center"><?= $totalPcs ?></td>
                                <td></td>
                                <td class="text-end pe-2"><?= number_format($grandTotalAmount, 2) ?></td>
                                <?php if ($showFilled): ?>
                                    <td class="text-center small fw-bold" style="border: 1px solid #000;">
                                        <?= $grandTotalReturnQty > 0 ? number_format($grandTotalReturnQty, 0) : '0' ?>
                                    </td>
                                    <td class="text-end pe-2 small fw-bold" style="border: 1px solid #000;">
                                        <?= number_format($grandTotalReturnAmt, 2) ?>
                                    </td>
                                    <td class="text-end pe-2 fw-bold text-danger" style="border: 1px solid #000;"><?= number_format($grandTotalCreditAmt, 2) ?></td>
                                <?php else: ?>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures Row for Dispatch Audit -->
                <div class="row mt-5 pt-4 text-center small fw-bold">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Prepared By: <?= htmlspecialchars($sheet['creator_name'] ?: 'Admin') ?></div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Warehouse Incharge / Stock Out</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Salesman Receiver Signature</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Included Orders & Customer Bills List (No Print) -->
        <div class="card border-0 shadow-sm no-print mb-5">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-receipt me-1"></i> Included Customer Orders & Sales Bills (<?= count($orders) ?>)
                </span>
                <?php if ($sheet['status'] === 'issued'): ?>
                    <a href="../invoices/batch_print.php?load_sheet_id=<?= $sheet['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print All Invoices
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-muted text-uppercase">
                            <tr>
                                <th class="ps-3">Order #</th>
                                <th>Generated Bill #</th>
                                <th>Customer / Shop</th>
                                <th>Route / Area</th>
                                <th>Booker</th>
                                <th class="text-end">Bill Amount</th>
                                <?php if ($showFilled): ?>
                                    <th class="text-end text-success">Cash Paid</th>
                                    <th class="text-end text-danger">Credit</th>
                                    <th class="text-end text-warning">Return</th>
                                    <th class="text-center">Recovery</th>
                                <?php endif; ?>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): 
                                $rowCash = $o['settled_cash'] !== null ? (float)$o['settled_cash'] : (float)$o['paid_amount'];
                                $rowCredit = $o['settled_credit'] !== null ? (float)$o['settled_credit'] : (float)$o['credit_amount'];
                                $rowReturn = $o['settled_return'] !== null ? (float)$o['settled_return'] : (float)$o['return_amount'];
                            ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-navy">
                                        <a href="../orders/view.php?id=<?= $o['id'] ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($o['order_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($o['invoice_number'])): ?>
                                            <a href="../invoices/view.php?id=<?= $o['invoice_id'] ?>" class="badge bg-success-subtle text-success border border-success text-decoration-none px-2 py-1">
                                                <i class="bi bi-receipt me-1"></i><?= htmlspecialchars($o['invoice_number']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Will generate on issue</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($o['shop_name']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($o['area_name']) ?></span></td>
                                    <td><?= htmlspecialchars($o['booker_name']) ?></td>
                                    <td class="text-end fw-bold text-navy">
                                        <?= format_currency((float)$o['net_amount']) ?>
                                        <?php if ((float)($o['discount_amount'] ?? 0) > 0): ?>
                                            <small class="text-danger d-block fw-normal" style="font-size: 11px;">
                                                -<?= format_currency((float)$o['discount_amount']) ?> (<?= ($o['discount_mode'] ?? '') === 'subtotal' ? 'Subtotal' : 'Item' ?>)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($showFilled): ?>
                                        <td class="text-end fw-bold text-success"><?= number_format($rowCash, 2) ?></td>
                                        <td class="text-end fw-bold text-danger"><?= number_format($rowCredit, 2) ?></td>
                                        <td class="text-end fw-bold text-warning"><?= number_format($rowReturn, 2) ?></td>
                                        <td class="text-center">
                                            <?php if ($rowCash > 0 || $rowCredit > 0 || $rowReturn > 0): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Settled</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-end pe-3">
                                        <?php if (!empty($o['invoice_id'])): ?>
                                            <a href="../invoices/print.php?id=<?= $o['invoice_id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print Customer Memo">
                                                <i class="bi bi-printer"></i> Bill
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
