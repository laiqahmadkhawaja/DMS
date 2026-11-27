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
        a.area_name as run_area_name,
        u.full_name as creator_name
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    LEFT JOIN load_sheets ls ON dr.load_sheet_id = ls.id
    LEFT JOIN areas a ON dr.area_id = a.id
    LEFT JOIN users u ON dr.created_by = u.id
    WHERE dr.id = ?
", [$id]);

if (!$run) {
    set_flash('danger', 'Delivery run not found.');
    redirect(APP_URL . '/modules/delivery/index.php');
}

$settlement = db_fetch_one("
    SELECT * FROM settlements 
    WHERE delivery_run_id = ? 
    ORDER BY id DESC LIMIT 1
", [$id]);

$bills = db_fetch_all("
    SELECT 
        drb.*,
        COALESCE(inv.invoice_number, drb.bill_number, CONCAT('REC-', COALESCE(c_direct.id, c_inv.id))) as invoice_number,
        inv.invoice_date,
        COALESCE(drb.bill_amount, inv.net_amount, 0) as net_amount,
        COALESCE(inv.paid_amount, 0) as paid_amount,
        COALESCE(inv.credit_amount, 0) as credit_amount,
        COALESCE(inv.return_amount, 0) as return_amount,
        si.cash_amount as settled_cash,
        si.credit_amount as settled_credit,
        si.return_amount as settled_return,
        COALESCE(c_inv.shop_name, c_direct.shop_name) as shop_name,
        COALESCE(c_inv.current_balance, c_direct.current_balance, 0) as customer_current_balance,
        COALESCE(c_inv.phone, c_direct.phone) as customer_phone,
        COALESCE(c_inv.address, c_direct.address) as customer_address,
        COALESCE(a_inv.area_name, a_direct.area_name, a_run.area_name, '-') as area_name,
        COALESCE(b_inv.booker_name, b_direct.booker_name, '-') as booker_name
    FROM delivery_run_bills drb
    LEFT JOIN sales_invoices inv ON drb.invoice_id = inv.id
    LEFT JOIN settlement_items si ON (si.invoice_id = inv.id AND si.settlement_id = ?)
    LEFT JOIN customers c_inv ON inv.customer_id = c_inv.id
    LEFT JOIN customers c_direct ON drb.customer_id = c_direct.id
    LEFT JOIN areas a_inv ON inv.area_id = a_inv.id
    LEFT JOIN areas a_direct ON c_direct.area_id = a_direct.id
    LEFT JOIN delivery_runs dr ON drb.delivery_run_id = dr.id
    LEFT JOIN areas a_run ON dr.area_id = a_run.id
    LEFT JOIN bookers b_inv ON inv.booker_id = b_inv.id
    LEFT JOIN bookers b_direct ON c_direct.booker_id = b_direct.id
    WHERE drb.delivery_run_id = ?
    ORDER BY drb.stop_sequence ASC, drb.id ASC
", [$settlement ? $settlement['id'] : 0, $id]);

$isSettled = !empty($settlement) || $run['status'] === 'completed';
$viewMode = $_GET['mode'] ?? ($isSettled ? 'filled' : 'blank');
$showFilled = ($viewMode === 'filled');

$pageTitle = 'Delivery Run ' . $run['run_number'];
$activeMenu = 'delivery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Credit Sheets (کریڈٹ شیٹ)', 'url' => 'index.php'],
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
                    <i class="bi bi-journal-check me-2"></i>Credit Sheet (کریڈٹ شیٹ): <?= htmlspecialchars($run['run_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($run['status'] === 'completed'): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Completed</span>
                    <?php else: ?>
                        <span class="badge bg-primary"><?= htmlspecialchars($run['status']) ?></span>
                    <?php endif; ?>

                    <?php if ($settlement): ?>
                        <a href="../settlements/view.php?id=<?= $settlement['id'] ?>" class="badge bg-info text-dark text-decoration-none border shadow-sm">
                            <i class="bi bi-receipt me-1"></i>Settlement: <?= htmlspecialchars($settlement['settlement_number']) ?>
                        </a>
                    <?php endif; ?>
                    <span class="text-muted small">Salesman: <?= htmlspecialchars($run['salesman_name']) ?> | Date: <?= date('d-M-Y', strtotime($run['run_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <a href="?id=<?= $id ?>&mode=<?= $showFilled ? 'blank' : 'filled' ?>" class="btn btn-sm <?= $showFilled ? 'btn-outline-secondary' : 'btn-outline-success' ?> shadow-sm">
                    <i class="bi <?= $showFilled ? 'bi-file-earmark' : 'bi-check2-circle' ?> me-1"></i> <?= $showFilled ? 'Blank Field Form' : 'Show Settled Figures' ?>
                </a>
                <a href="print.php?id=<?= $run['id'] ?>&filled=<?= $showFilled ? '1' : '0' ?>" target="_blank" class="btn btn-outline-primary btn-sm shadow-sm">
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
                            <?php if ($run['run_type'] === 'manual'): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1">Manual Recovery Sheet</span>
                                <?php if (!empty($run['run_area_name'])): ?>
                                    | Route: <strong><?= htmlspecialchars($run['run_area_name']) ?></strong>
                                <?php endif; ?>
                            <?php elseif ($run['load_number']): ?>
                                | Load Sheet: <strong><?= htmlspecialchars($run['load_number']) ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($run['route_notes'])): ?>
                                | Notes: <em><?= htmlspecialchars($run['route_notes']) ?></em>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="fs-5 fw-bold text-dark pe-2">
                        <?= date('d.m.y', strtotime($run['run_date'])) ?>
                    </div>
                </div>

                <!-- Table (Columns matching Photo 8 Enhanced: Sr. | Shop Name | Bill No. | Prev Bal | Bill Amt | Total Due | Cash | Return | Net Bal) -->
                <div class="table-responsive">
                    <table class="table table-bordered recovery-table align-middle mb-0" style="border: 2px solid #000; font-size: 13px; color: #000;">
                        <thead>
                            <tr style="background-color: #d1b80d; color: #000;" class="yellow-header text-center small fw-bold">
                                <th style="width: 4%; border: 1px solid #000;">Sr.</th>
                                <th style="width: 25%; border: 1px solid #000;" class="text-start ps-2">Shop Name & Route</th>
                                <th style="width: 10%; border: 1px solid #000;">Bill No.</th>
                                <th style="width: 11%; border: 1px solid #000;">Prev Bal (سابقہ)</th>
                                <th style="width: 11%; border: 1px solid #000;">Bill Amt (بل)</th>
                                <th style="width: 12%; border: 1px solid #000; background-color: #c4ab0c;">Credit (کریڈٹ)</th>
                                <th style="width: 10%; border: 1px solid #000;">Cash (کیش)</th>
                                <th style="width: 8%; border: 1px solid #000;">Return (ریٹرن)</th>
                                <th style="width: 9%; border: 1px solid #000;">Balance (بقایا)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            $totalPrevBal    = 0;
                            $totalBillAmount = 0;
                            $totalDueAmount  = 0;
                            $totalCredit     = 0;
                            $totalReturn     = 0;
                            $totalCash       = 0;
                            $totalNetBal     = 0;

                            foreach ($bills as $b): 
                                $billAmount = (float)$b['net_amount'];
                                $cashVal   = $b['settled_cash'] !== null ? (float)$b['settled_cash'] : ((float)$b['paid_amount'] > 0 ? (float)$b['paid_amount'] : (float)($b['cash_collected'] ?? 0));
                                $creditVal = $b['settled_credit'] !== null ? (float)$b['settled_credit'] : ((float)$b['credit_amount'] > 0 ? (float)$b['credit_amount'] : (float)($b['credit_given'] ?? 0));
                                $returnVal = $b['settled_return'] !== null ? (float)$b['settled_return'] : ((float)$b['return_amount'] > 0 ? (float)$b['return_amount'] : (float)($b['return_value'] ?? 0));

                                $custBal = (float)($b['customer_current_balance'] ?? 0);
                                if ($run['run_type'] === 'manual') {
                                    $prevBal = ((float)$b['prev_balance'] > 0 || (float)$b['net_amount'] > 0) ? (float)$b['prev_balance'] : $custBal;
                                    $totalDue = $prevBal + $billAmount;
                                    $remainingBal = max(0, $totalDue - ($cashVal + $returnVal));
                                } elseif ($isSettled) {
                                    $prevBal = max(0, $custBal - $creditVal);
                                    $remainingBal = max(0, $prevBal + $billAmount - ($cashVal + $returnVal));
                                    $totalDue = $prevBal + $billAmount;
                                } else {
                                    $prevBal = $custBal;
                                    $remainingBal = $prevBal + $billAmount;
                                    $totalDue = $prevBal + $billAmount;
                                }

                                $totalPrevBal    += $prevBal;
                                $totalBillAmount += $billAmount;
                                $totalDueAmount  += $totalDue;
                                $totalCredit     += $creditVal;
                                $totalReturn     += $returnVal;
                                $totalCash       += $cashVal;
                                $totalNetBal     += $remainingBal;
                            ?>
                                <tr style="height: 32px;">
                                    <td class="text-center" style="border: 1px solid #000;"><?= $sr++ ?></td>
                                    <td class="ps-2 fw-bold" style="border: 1px solid #000;">
                                        <?= htmlspecialchars($b['shop_name']) ?>
                                        <?php if (!empty($b['area_name'])): ?>
                                            <small class="text-muted fw-normal">(<?= htmlspecialchars($b['area_name']) ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-monospace fw-semibold" style="border: 1px solid #000;"><?= htmlspecialchars($b['invoice_number']) ?></td>
                                    <td class="text-end pe-2" style="border: 1px solid #000;"><?= $prevBal > 0 ? number_format($prevBal, 0) : '0' ?></td>
                                    <td class="text-end pe-2 fw-semibold" style="border: 1px solid #000;"><?= number_format($billAmount, 0) ?></td>
                                    <td class="text-end pe-2 fw-bold text-dark" style="border: 1px solid #000; background-color: #fcf9ea;"><?= number_format($totalDue, 0) ?></td>
                                    
                                    <?php if ($showFilled): ?>
                                        <td class="text-end pe-2 fw-bold text-success" style="border: 1px solid #000; background-color: #fff;">
                                            <?= $cashVal > 0 ? number_format($cashVal, 0) : '0' ?>
                                        </td>
                                        <td class="text-end pe-2 fw-bold text-danger" style="border: 1px solid #000; background-color: #fff;">
                                            <?= $returnVal > 0 ? number_format($returnVal, 0) : '0' ?>
                                        </td>
                                        <td class="text-end pe-2 fw-bold text-navy" style="border: 1px solid #000; background-color: #fff;">
                                            <?= number_format($remainingBal, 0) ?>
                                        </td>
                                    <?php else: ?>
                                        <!-- Blank columns for salesman to write with pen on delivery -->
                                        <td style="border: 1px solid #000; background-color: #fff;"></td>
                                        <td style="border: 1px solid #000; background-color: #fff;"></td>
                                        <td style="border: 1px solid #000; background-color: #fff;"></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Blank padding rows if few bills -->
                            <?php for ($i = count($bills); $i < 10; $i++): ?>
                                <tr style="height: 32px;">
                                    <td class="text-center" style="border: 1px solid #000;"><?= $sr++ ?></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000; background-color: #fcf9ea;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                    <td style="border: 1px solid #000;"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #c99c04; color: #000;" class="yellow-total fw-bold">
                                <td colspan="3" class="text-center text-uppercase">Total Summary</td>
                                <td class="text-end pe-2"><?= number_format($totalPrevBal, 0) ?></td>
                                <td class="text-end pe-2"><?= number_format($totalBillAmount, 0) ?></td>
                                <td class="text-end pe-2 fw-bold"><?= number_format($totalDueAmount, 0) ?></td>
                                <?php if ($showFilled): ?>
                                    <td class="text-end pe-2 fw-bold text-success"><?= number_format($totalCash, 0) ?></td>
                                    <td class="text-end pe-2 fw-bold text-danger"><?= number_format($totalReturn, 0) ?></td>
                                    <td class="text-end pe-2 fw-bold text-navy"><?= number_format($totalNetBal, 0) ?></td>
                                <?php else: ?>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                <?php endif; ?>
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
