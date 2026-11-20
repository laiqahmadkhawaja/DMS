<?php
/**
 * DMS - Distribution Management System
 * Route Settlements & Daily Reconciliation - View & Print Settlement Sheet
 * Matches Client Photo 1 & Photo 3 (DAILY SALE RECIVED + CREDIT)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('settlements_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid settlement ID.');
    redirect(APP_URL . '/modules/settlements/index.php');
}

$settlement = db_fetch_one("
    SELECT 
        st.*,
        s.salesman_name,
        s.phone as salesman_phone,
        dr.run_number,
        ls.load_number,
        u.full_name as creator_name,
        ru.full_name as reconciler_name
    FROM settlements st
    JOIN salesmen s ON st.salesman_id = s.id
    LEFT JOIN delivery_runs dr ON st.delivery_run_id = dr.id
    LEFT JOIN load_sheets ls ON st.load_sheet_id = ls.id
    LEFT JOIN users u ON st.created_by = u.id
    LEFT JOIN users ru ON st.reconciled_by = ru.id
    WHERE st.id = ?
", [$id]);

if (!$settlement) {
    set_flash('danger', 'Settlement not found.');
    redirect(APP_URL . '/modules/settlements/index.php');
}

// Fetch settlement items
$items = db_fetch_all("
    SELECT 
        si.*,
        c.shop_name,
        c.customer_code,
        a.area_name,
        inv.invoice_number
    FROM settlement_items si
    JOIN customers c ON si.customer_id = c.id
    LEFT JOIN areas a ON c.area_id = a.id
    JOIN sales_invoices inv ON si.invoice_id = inv.id
    WHERE si.settlement_id = ?
    ORDER BY si.id ASC
", [$id]);

// Cash submission
$cashSub = db_fetch_one("SELECT * FROM cash_submissions WHERE settlement_id = ?", [$id]);

// Linked Journal Entry
$journalEntry = db_fetch_one("
    SELECT * FROM journal_entries 
    WHERE reference_type = 'settlement' AND reference_id = ?
", [$id]);

$pageTitle = 'Settlement ' . $settlement['settlement_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settlements', 'url' => 'index.php'],
    ['label' => $settlement['settlement_number'], 'url' => '']
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
    .settle-table th, .settle-table td {
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
                    <i class="bi bi-file-earmark-check me-2"></i>Route Settlement: <?= htmlspecialchars($settlement['settlement_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i><?= htmlspecialchars($settlement['status']) ?></span>
                    <span class="text-muted small">Salesman: <?= htmlspecialchars($settlement['salesman_name']) ?> | Date: <?= date('d-M-Y', strtotime($settlement['settlement_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Settlements
                </a>
                <a href="print.php?id=<?= $settlement['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print Sheet (Photo 1/3)
                </a>
            </div>
        </div>

        <!-- PRINTABLE SETTLEMENT SHEET (EXACT REPLICA OF PHOTO 1) -->
        <div class="card border-0 shadow-sm print-sheet mb-4">
            <div class="card-body p-4">
                <!-- Title Header matching Photo 1: DAILY SALE RECIVED + CREDIT   17.11.26 -->
                <div class="d-flex justify-content-between align-items-end pb-2 mb-2">
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="font-family: Arial, sans-serif; letter-spacing: 0.5px;">
                            DAILY SALE RECIVED + CREDIT
                        </h4>
                        <div class="small text-muted">
                            Salesman: <strong><?= htmlspecialchars($settlement['salesman_name']) ?></strong>
                            <?php if ($settlement['run_number']): ?>
                                | Delivery Run: <strong><?= htmlspecialchars($settlement['run_number']) ?></strong>
                            <?php endif; ?>
                            | Voucher #: <strong><?= htmlspecialchars($settlement['settlement_number']) ?></strong>
                        </div>
                    </div>
                    <div class="fs-5 fw-bold text-dark pe-2">
                        <?= date('d.m.y', strtotime($settlement['settlement_date'])) ?>
                    </div>
                </div>

                <!-- Table (Columns matching Photo 1: Sr. | Shop Name | Bill No. | Total Amount | Credit | Return | Cash) -->
                <div class="table-responsive">
                    <table class="table table-bordered settle-table align-middle mb-0" style="border: 2px solid #000; font-size: 13px; color: #000;">
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
                            foreach ($items as $item): 
                                $billVal = (float)$item['bill_amount'];
                                $creditVal = (float)$item['credit_amount'];
                                $returnVal = (float)$item['return_amount'];
                                $cashVal = (float)$item['cash_amount'];
                            ?>
                                <tr style="height: 30px;">
                                    <td class="text-center" style="border: 1px solid #000;"><?= $sr++ ?></td>
                                    <td class="ps-2 fw-bold" style="border: 1px solid #000;"><?= htmlspecialchars($item['shop_name']) ?></td>
                                    <td class="text-center" style="border: 1px solid #000;"><?= htmlspecialchars($item['invoice_number']) ?></td>
                                    <td class="text-center fw-bold" style="border: 1px solid #000;"><?= number_format($billVal, 0) ?></td>
                                    <td class="text-center" style="border: 1px solid #000;"><?= $creditVal > 0 ? number_format($creditVal, 0) : '' ?></td>
                                    <td class="text-center" style="border: 1px solid #000;"><?= $returnVal > 0 ? number_format($returnVal, 0) : '' ?></td>
                                    <td class="text-center fw-bold" style="border: 1px solid #000;"><?= $cashVal > 0 ? number_format($cashVal, 0) : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #c99c04; color: #000;" class="yellow-total fw-bold">
                                <td colspan="3" class="text-center text-uppercase">Total</td>
                                <td class="text-center"><?= number_format((float)$settlement['total_invoices_amount'], 0) ?></td>
                                <td class="text-center"><?= number_format((float)$settlement['total_credit_given'], 0) ?></td>
                                <td class="text-center"><?= number_format((float)$settlement['total_returns_amount'], 0) ?></td>
                                <td class="text-center"><?= number_format((float)$settlement['total_cash_received'], 0) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures Row -->
                <div class="row mt-5 pt-3 text-center small fw-bold">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Salesman: <?= htmlspecialchars($settlement['salesman_name']) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Cashier: <?= htmlspecialchars($cashSub ? 'Rs. ' . number_format($cashSub['amount'], 2) . ' Received' : 'Verified') ?></div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Reconciled By: <?= htmlspecialchars($settlement['reconciler_name'] ?: 'Admin') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System & Accounting Posting Details (No Print) -->
        <div class="row g-4 no-print mb-5">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-cash-coin me-1"></i> Cash Handover Submission</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($cashSub): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Submission #:</span>
                                <span class="fw-bold"><?= htmlspecialchars($cashSub['submission_number']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Amount Handed to Office:</span>
                                <span class="fw-bold text-success fs-5"><?= format_currency((float)$cashSub['amount']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Handover Date:</span>
                                <span><?= date('d-M-Y', strtotime($cashSub['submission_date'])) ?></span>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">No separate physical cash submission record created.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-journal-text me-1"></i> General Ledger Posting</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($journalEntry): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">JV Number:</span>
                                <span class="fw-bold text-navy"><?= htmlspecialchars($journalEntry['entry_number']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Posted Date:</span>
                                <span><?= date('d-M-Y', strtotime($journalEntry['entry_date'])) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Total Balanced Value:</span>
                                <span class="fw-bold text-dark"><?= format_currency((float)$journalEntry['total_debit']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">GL Status:</span>
                                <span class="badge bg-success">Posted & Balanced</span>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">No direct journal entry record found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
