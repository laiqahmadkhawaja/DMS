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
        p.carton_qty as ctn_ratio,
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

// Fetch included orders
$orders = db_fetch_all("
    SELECT 
        o.id,
        o.order_number,
        o.order_date,
        c.shop_name,
        c.phone as customer_phone,
        a.area_name,
        b.booker_name,
        o.net_amount,
        o.status as order_status,
        inv.id as invoice_id,
        inv.invoice_number
    FROM load_sheet_orders lso
    JOIN orders o ON lso.order_id = o.id
    JOIN customers c ON o.customer_id = c.id
    JOIN areas a ON o.area_id = a.id
    JOIN bookers b ON o.booker_id = b.id
    LEFT JOIN sales_invoices inv ON inv.order_id = o.id AND inv.load_sheet_id = lso.load_sheet_id
    WHERE lso.load_sheet_id = ?
    ORDER BY a.area_name ASC, c.shop_name ASC
", [$id]);

// Booker names for header
$bookerNames = array_unique(array_column($orders, 'booker_name'));
$bookersList = !empty($bookerNames) ? implode(', ', $bookerNames) : 'N/A';

// Areas for header
$areaNames = array_unique(array_column($orders, 'area_name'));
$areasList = !empty($areaNames) ? implode(', ', $areaNames) : 'All Routes';

$pageTitle = 'Load Sheet ' . $sheet['load_number'];
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
                    <?php if ($sheet['status'] === 'draft'): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass me-1"></i>Draft</span>
                    <?php elseif ($sheet['status'] === 'issued'): ?>
                        <span class="badge bg-success"><i class="bi bi-truck me-1"></i>Issued / Dispatched</span>
                    <?php elseif ($sheet['status'] === 'returned_closed'): ?>
                        <span class="badge bg-info text-dark"><i class="bi bi-check2-all me-1"></i>Settled & Closed</span>
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
                <?php if ($sheet['status'] !== 'cancelled' && $sheet['status'] !== 'returned_closed'): ?>
                    <a href="edit.php?id=<?= $sheet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Edit Sheet
                    </a>
                <?php endif; ?>
                <a href="print.php?id=<?= $sheet['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print Sheet (Photo 5/9)
                </a>
                <?php if ($sheet['status'] === 'draft' && has_permission('loading_issue')): ?>
                    <a href="issue.php?id=<?= $sheet['id'] ?>" class="btn btn-success btn-sm shadow-sm" onclick="return confirm('Confirm stock dispatch from warehouse?');">
                        <i class="bi bi-box-arrow-right me-1"></i> Issue Stock Now
                    </a>
                <?php endif; ?>
                <?php if ($sheet['status'] === 'issued'): ?>
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
                                <th style="width: 14%; border: 1px solid #000;">Company / Area</th>
                                <th style="width: 32%; border: 1px solid #000;" class="text-start">Product</th>
                                <th style="width: 9%; border: 1px solid #000;">Ctn Qty</th>
                                <th style="width: 9%; border: 1px solid #000;">Pcs Qty</th>
                                <th style="width: 10%; border: 1px solid #000;">Rate</th>
                                <th style="width: 12%; border: 1px solid #000;">Total Amount</th>
                                <th style="width: 14%; border: 1px solid #000;">
                                    <div>Return</div>
                                    <div class="d-flex justify-content-around small fw-normal border-top border-dark mt-1 pt-1">
                                        <span>Return</span>
                                        <span>Amount</span>
                                    </div>
                                </th>
                                <th style="width: 10%; border: 1px solid #000;">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalCtn = 0;
                            $totalPcs = 0;
                            $grandTotalAmount = 0;
                            foreach ($items as $item): 
                                $ctnRatio = (float)$item['ctn_ratio'] > 0 ? (float)$item['ctn_ratio'] : 1;
                                $baseQty = (float)$item['ordered_qty'];
                                
                                // Calculate Ctn and Pcs display
                                $ctnPart = floor($baseQty / $ctnRatio);
                                $pcsPart = fmod($baseQty, $ctnRatio);
                                
                                $totalCtn += $ctnPart;
                                $totalPcs += $pcsPart;
                                $grandTotalAmount += (float)$item['total_amount'];
                            ?>
                                <tr>
                                    <td class="small fw-semibold"><?= htmlspecialchars($item['category_name'] ?: 'General') ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td class="text-center fw-bold"><?= $ctnPart > 0 ? $ctnPart : '' ?></td>
                                    <td class="text-center"><?= $pcsPart > 0 ? $pcsPart : '' ?></td>
                                    <td class="text-center"><?= number_format((float)$item['rate'], 2) ?></td>
                                    <td class="text-end pe-2 fw-bold"><?= number_format((float)$item['total_amount'], 2) ?></td>
                                    <!-- Blank columns for salesman recovery reconciliation as in real photos -->
                                    <td style="background-color: #fafafa;"></td>
                                    <td style="background-color: #fafafa;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #c99c04; color: #000;" class="print-yellow-total fw-bold">
                                <td colspan="2" class="text-end text-uppercase pe-3">Total:</td>
                                <td class="text-center"><?= $totalCtn ?></td>
                                <td class="text-center"><?= $totalPcs ?></td>
                                <td></td>
                                <td class="text-end pe-2"><?= number_format($grandTotalAmount, 2) ?></td>
                                <td></td>
                                <td></td>
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
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
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
                                    <td class="text-end fw-bold text-navy"><?= format_currency((float)$o['net_amount']) ?></td>
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
