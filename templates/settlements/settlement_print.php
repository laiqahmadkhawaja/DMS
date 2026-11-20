<?php
/**
 * DMS - Distribution Management System
 * Route Settlement & Daily Reconciliation Printable Template (Matches Client Photo 1 & Photo 3)
 * Ultra-Compact Excel-like Congested Print Layout (Zero Paper Wastage)
 * 
 * Required variables:
 * $settlement - Array of settlement header data
 * $items - Array of settled bills
 * $cashSub - Array or null of cash submission
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$totInv = (float)$settlement['total_invoices_amount'];
$totCredit = (float)$settlement['total_credit_given'];
$totReturn = (float)$settlement['total_returns_amount'];
$totCash = (float)$settlement['total_cash_received'];
$discrepancy = (float)($settlement['difference_amount'] ?? 0);
?>

<div class="settlement-print-sheet">
    <!-- Header Banner: Dense, Excel-style Title & Date -->
    <div class="d-flex justify-content-between align-items-center pb-1 mb-1 border-bottom border-dark">
        <div>
            <h4 class="fw-bold text-uppercase mb-0 tracking-wider" style="font-family: Arial, sans-serif; letter-spacing: 0.5px; font-size: 16px; line-height: 1.1;">
                DAILY SALE RECIVED + CREDIT
            </h4>
            <div class="fw-semibold text-dark" style="font-size: 10px;">
                <?= CLIENT_NAME ?> &bull; Route Evening Settlement &bull; Salesman: <strong><?= htmlspecialchars($settlement['salesman_name']) ?></strong>
            </div>
        </div>
        <div class="text-end">
            <span class="fw-bold px-2 py-0" style="background-color: #d1b80d; color: #000; border-radius: 2px; font-size: 12px;">
                <?= date('d.m.y', strtotime($settlement['settlement_date'])) ?>
            </span>
            <div class="fw-bold" style="font-size: 11px;">Voucher #: <?= htmlspecialchars($settlement['settlement_number']) ?></div>
        </div>
    </div>

    <!-- Settlement Table (Photo 1 Columns - Congested Excel Grid) -->
    <table class="table table-bordered table-sm settle-print-table mb-1" style="border: 1px solid #000; font-size: 10.5px; color: #000; line-height: 1.2;">
        <thead>
            <tr style="background-color: #d1b80d; color: #000; border-bottom: 1.5px solid #000;" class="text-center fw-bold">
                <th style="width: 5%; border: 1px solid #000; padding: 1px 3px;">Sr.</th>
                <th style="width: 35%; border: 1px solid #000; padding: 1px 3px;" class="text-start ps-1">Shop Name</th>
                <th style="width: 14%; border: 1px solid #000; padding: 1px 3px;">Bill No.</th>
                <th style="width: 14%; border: 1px solid #000; padding: 1px 3px;" class="text-end pe-1">Total Amount</th>
                <th style="width: 11%; border: 1px solid #000; padding: 1px 3px;">Credit</th>
                <th style="width: 11%; border: 1px solid #000; padding: 1px 3px;">Return</th>
                <th style="width: 12%; border: 1px solid #000; padding: 1px 3px;">Cash</th>
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
                <tr style="height: 18px;">
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 3px;"><?= $sr++ ?></td>
                    <td class="ps-1 fw-semibold text-truncate" style="border: 1px solid #000; padding: 0px 3px;"><?= htmlspecialchars($item['shop_name']) ?></td>
                    <td class="text-center fw-bold" style="border: 1px solid #000; padding: 0px 3px;"><?= htmlspecialchars($item['invoice_number']) ?></td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 3px;"><?= number_format($billVal, 0) ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 3px;"><?= $creditVal > 0 ? number_format($creditVal, 0) : '' ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 3px;"><?= $returnVal > 0 ? number_format($returnVal, 0) : '' ?></td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 3px;"><?= $cashVal > 0 ? number_format($cashVal, 0) : '' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #c99c04; color: #000; border-top: 1.5px solid #000;" class="fw-bold">
                <td colspan="3" class="text-center text-uppercase" style="border: 1px solid #000; padding: 1px 3px;">Total</td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($totInv, 0) ?></td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($totCredit, 0) ?></td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($totReturn, 0) ?></td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($totCash, 0) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Reconciliation & Cash Handover Summary Block (Compact) -->
    <div class="row g-2 my-1" style="font-size: 10px; line-height: 1.2;">
        <div class="col-6">
            <div class="border border-dark p-1 rounded">
                <div class="fw-bold border-bottom border-dark pb-0 mb-1 text-uppercase">Zero-Sum Reconciliation Audit</div>
                <div class="d-flex justify-content-between py-0">
                    <span>Total Invoices Dispatched:</span>
                    <span class="fw-bold">Rs. <?= number_format($totInv, 0) ?></span>
                </div>
                <div class="d-flex justify-content-between py-0 text-danger">
                    <span>Less Customer Returns:</span>
                    <span class="fw-bold">- Rs. <?= number_format($totReturn, 0) ?></span>
                </div>
                <div class="d-flex justify-content-between py-0 text-primary">
                    <span>Less Market Credit Allowed:</span>
                    <span class="fw-bold">- Rs. <?= number_format($totCredit, 0) ?></span>
                </div>
                <div class="d-flex justify-content-between py-0 border-top border-dark fw-bold text-success">
                    <span>Net Expected Physical Cash:</span>
                    <span>Rs. <?= number_format($totCash, 0) ?></span>
                </div>
                <div class="d-flex justify-content-between py-0 fw-bold <?= abs($discrepancy) < 0.01 ? 'text-success' : 'text-danger' ?>">
                    <span>Route Variance (Over / Short):</span>
                    <span>Rs. <?= number_format($discrepancy, 0) ?> (<?= abs($discrepancy) < 0.01 ? 'BALANCED' : 'IMBALANCE' ?>)</span>
                </div>
            </div>
        </div>

        <div class="col-6">
            <div class="border border-dark p-1 rounded">
                <div class="fw-bold border-bottom border-dark pb-0 mb-1 text-uppercase">Cashier Handover Receipt</div>
                <?php if ($cashSub): ?>
                    <div class="d-flex justify-content-between py-0">
                        <span>Cash Submission Ref:</span>
                        <span class="fw-bold"><?= htmlspecialchars($cashSub['submission_number']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-0">
                        <span>Actual Cash Count Deposited:</span>
                        <span class="fw-bold text-success fs-6">Rs. <?= number_format((float)$cashSub['amount'], 0) ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-0">
                        <span>Handover Date & Time:</span>
                        <span><?= date('d-M-Y H:i', strtotime($cashSub['submission_date'])) ?></span>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between py-0">
                        <span>Physical Cash Received:</span>
                        <span class="fw-bold text-success fs-6">Rs. <?= number_format($totCash, 0) ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-0">
                        <span>Status:</span>
                        <span class="fw-bold text-success">Settled & Handed Over</span>
                    </div>
                <?php endif; ?>
                <div class="small text-muted border-top border-dark pt-0" style="font-size: 8.5px;">
                    GL Voucher: Linked automatically to Cash in Hand (1010) & Accounts Receivable (1020).
                </div>
            </div>
        </div>
    </div>

    <!-- Official Signatures Row -->
    <div class="row mt-2 pt-1 text-center small fw-bold" style="font-size: 9.5px; color: #222;">
        <div class="col-4">
            <div class="border-top border-dark pt-1">Salesman: <?= htmlspecialchars($settlement['salesman_name']) ?></div>
        </div>
        <div class="col-4">
            <div class="border-top border-dark pt-1">Head Cashier / Receiver</div>
        </div>
        <div class="col-4">
            <div class="border-top border-dark pt-1">Reconciliation Supervisor / Auditor</div>
        </div>
    </div>
</div>
