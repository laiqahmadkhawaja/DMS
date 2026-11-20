<?php
/**
 * DMS - Distribution Management System
 * Delivery Runs - View & Print Delivery Run Sheet
 * Matches Client Photo 8 (DAILY SALE RECIVED + CREDIT - Blank Field Recovery Form)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('delivery_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid delivery run ID.');
    redirect(APP_URL . '/modules/delivery/index.php');
}

$run = db_fetch_one("
    SELECT 
        dr.*,
        s.salesman_name,
        s.phone as salesman_phone,
        ls.load_number,
        u.full_name as creator_name
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    LEFT JOIN load_sheets ls ON dr.load_sheet_id = ls.id
    LEFT JOIN users u ON dr.created_by = u.id
    WHERE dr.id = ?
", [$id]);

if (!$run) {
    set_flash('danger', 'Delivery run not found.');
    redirect(APP_URL . '/modules/delivery/index.php');
}

$bills = db_fetch_all("
    SELECT 
        drb.*,
        inv.invoice_number,
        inv.invoice_date,
        inv.net_amount,
        c.shop_name,
        c.phone as customer_phone,
        c.address as customer_address,
        a.area_name,
        b.booker_name
    FROM delivery_run_bills drb
    JOIN sales_invoices inv ON drb.invoice_id = inv.id
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    WHERE drb.delivery_run_id = ?
    ORDER BY drb.stop_sequence ASC, a.area_name ASC, c.shop_name ASC
", [$id]);

$pageTitle = 'Delivery Run ' . $run['run_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Delivery Runs', 'url' => 'index.php'],
    ['label' => $run['run_number'], 'url' => '']
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
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .recovery-table th, .recovery-table td {
        border: 1px solid #000 !important;
        padding: 4px 6px !important;
    }
    .yellow-header {
        background-color: #d1b80d !important;
        color: #000 !important;
        font-weight: bold !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .yellow-total {
        background-color: #c99c04 !important;
        color: #000 !important;
        font-weight: bold !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Action Buttons (No Print) -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-geo-alt me-2"></i>Delivery Run: <?= htmlspecialchars($run['run_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-primary"><?= htmlspecialchars($run['status']) ?></span>
                    <span class="text-muted small">Salesman: <?= htmlspecialchars($run['salesman_name']) ?> | Date: <?= date('d-M-Y', strtotime($run['run_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <a href="print.php?id=<?= $run['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print Sheet (Photo 8)
                </a>
                <?php if ($run['status'] !== 'completed'): ?>
                    <a href="../settlements/create.php?delivery_run_id=<?= $run['id'] ?>" class="btn btn-success btn-sm shadow-sm fw-bold">
                        <i class="bi bi-cash-stack me-1"></i> Settle Route (F8)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- PRINTABLE FIELD RECOVERY SHEET (EXACT REPLICA OF PHOTO 8) -->
        <div class="card border-0 shadow-sm print-sheet mb-5">
            <div class="card-body p-4">
                <!-- Header (Exact title & date as Photo 8: DAILY SALE RECIVED + CREDIT   17.11.26) -->
                <div class="d-flex justify-content-between align-items-end pb-2 mb-2">
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="font-family: Arial, sans-serif; letter-spacing: 0.5px;">
                            DAILY SALE RECIVED + CREDIT
                        </h4>
                        <div class="small text-muted">
                            Salesman: <strong><?= htmlspecialchars($run['salesman_name']) ?></strong> 
                            <?php if ($run['load_number']): ?>
                                | Load Sheet: <strong><?= htmlspecialchars($run['load_number']) ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="fs-5 fw-bold text-dark pe-2">
                        <?= date('d.m.y', strtotime($run['run_date'])) ?>
                    </div>
                </div>

                <!-- Table (Columns matching Photo 8: Sr. | Shop Name | Bill No. | Total Amount | Credit | Return | Cash) -->
                <div class="table-responsive">
                    <table class="table table-bordered recovery-table align-middle mb-0" style="border: 2px solid #000; font-size: 13px; color: #000;">
                        <thead>
                            <tr style="background-color: #d1b80d; color: #000;" class="yellow-header text-center small">
                                <th style="width: 5%; border: 1px solid #000;">Sr.</th>
                                <th style="width: 35%; border: 1px solid #000;" class="text-start ps-2">Shop Name</th>
                                <th style="width: 12%; border: 1px solid #000;">Bill No.</th>
                                <th style="width: 14%; border: 1px solid #000;">Total Amount</th>
                                <th style="width: 11%; border: 1px solid #000;">Credit</th>
                                <th style="width: 11%; border: 1px solid #000;">Return</th>
                                <th style="width: 12%; border: 1px solid #000;">Cash</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            $totalDispatched = 0;
                            foreach ($bills as $b): 
                                $totalDispatched += (float)$b['net_amount'];
                            ?>
                                <tr style="height: 32px;">
                                    <td class="text-center" style="border: 1px solid #000;"><?= $sr++ ?></td>
                                    <td class="ps-2 fw-bold" style="border: 1px solid #000;"><?= htmlspecialchars($b['shop_name']) ?></td>
                                    <td class="text-center" style="border: 1px solid #000;"><?= htmlspecialchars($b['invoice_number']) ?></td>
                                    <td class="text-center fw-bold" style="border: 1px solid #000;"><?= number_format((float)$b['net_amount'], 0) ?></td>
                                    <!-- Blank columns for salesman to write with pen on delivery -->
                                    <td style="border: 1px solid #000; background-color: #fff;"></td>
                                    <td style="border: 1px solid #000; background-color: #fff;"></td>
                                    <td style="border: 1px solid #000; background-color: #fff;"></td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Blank padding rows if few bills -->
                            <?php for ($i = count($bills); $i < 12; $i++): ?>
                                <tr style="height: 32px;">
                                    <td class="text-center" style="border: 1px solid #000;"><?= $sr++ ?></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #c99c04; color: #000;" class="yellow-total fw-bold">
                                <td colspan="3" class="text-center text-uppercase">Total</td>
                                <td class="text-center"><?= number_format($totalDispatched, 0) ?></td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures -->
                <div class="row mt-5 pt-4 text-center small fw-bold">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Salesman Signature</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Cashier Handover Check</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Manager Reconciliation Approval</div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
