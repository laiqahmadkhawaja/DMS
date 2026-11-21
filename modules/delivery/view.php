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

$settlement = db_fetch_one("
    SELECT * FROM settlements 
    WHERE delivery_run_id = ? 
    ORDER BY id DESC LIMIT 1
", [$id]);

$bills = db_fetch_all("
    SELECT 
        drb.*,
        inv.invoice_number,
        inv.invoice_date,
        inv.net_amount,
        inv.paid_amount,
        inv.credit_amount,
        inv.return_amount,
        si.cash_amount as settled_cash,
        si.credit_amount as settled_credit,
        si.return_amount as settled_return,
        c.shop_name,
        c.current_balance as customer_current_balance,
        c.phone as customer_phone,
        c.address as customer_address,
        a.area_name,
        b.booker_name
    FROM delivery_run_bills drb
    JOIN sales_invoices inv ON drb.invoice_id = inv.id
    LEFT JOIN settlement_items si ON (si.invoice_id = inv.id AND si.settlement_id = ?)
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    WHERE drb.delivery_run_id = ?
    ORDER BY drb.stop_sequence ASC, a.area_name ASC, c.shop_name ASC
", [$settlement ? $settlement['id'] : 0, $id]);

$isSettled = !empty($settlement) || $run['status'] === 'completed';
$viewMode = $_GET['mode'] ?? ($isSettled ? 'filled' : 'blank');
$showFilled = ($viewMode === 'filled');

$pageTitle = 'Delivery Run ' . $run['run_number'];
$activeMenu = 'delivery';
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
                            <?php if ($run['load_number']): ?>
                                | Load Sheet: <strong><?= htmlspecialchars($run['load_number']) ?></strong>
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
                                <th style="width: 12%; border: 1px solid #000; background-color: #c4ab0c;">Total Due (کل واجب)</th>
                                <th style="width: 10%; border: 1px solid #000;">Cash Recv (نقد)</th>
                                <th style="width: 8%; border: 1px solid #000;">Return (واپسی)</th>
                                <th style="width: 9%; border: 1px solid #000;">Net Bal (بقایا)</th>
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
                                $cashVal   = $b['settled_cash'] !== null ? (float)$b['settled_cash'] : (float)$b['paid_amount'];
                                $creditVal = $b['settled_credit'] !== null ? (float)$b['settled_credit'] : (float)$b['credit_amount'];
                                $returnVal = $b['settled_return'] !== null ? (float)$b['settled_return'] : (float)$b['return_amount'];

                                $custBal = (float)($b['customer_current_balance'] ?? 0);
                                if ($isSettled) {
                                    $prevBal = max(0, $custBal - $creditVal);
                                    $remainingBal = max(0, $prevBal + $billAmount - ($cashVal + $returnVal));
                                } else {
                                    $prevBal = $custBal;
                                    $remainingBal = $prevBal + $billAmount;
                                }
                                $totalDue = $prevBal + $billAmount;

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
