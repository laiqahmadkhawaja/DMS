<?php
/**
 * DMS - Distribution Management System
 * Delivery Run Field Recovery Sheet Printable Template (Matches Client Photo 8: DAILY SALE RECIVED + CREDIT)
 * Ultra-Compact Excel-like Congested Print Layout (Zero Paper Wastage)
 * 
 * Required variables:
 * $run - Array of delivery run header
 * $bills - Array of included bills/stops
 * 
 * Optional:
 * $fillFigures - Boolean (default false; if false, Credit, Return, Cash are blank for pen writing; if true, shows settled figures)
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$fillFigures = !empty($fillFigures);
?>

<div class="delivery-recovery-sheet">
    <!-- Header Banner: Dense, Excel-style Title & Date -->
    <div class="d-flex justify-content-between align-items-center pb-1 mb-1 border-bottom border-dark">
        <div>
            <h4 class="fw-bold text-uppercase mb-0 tracking-wider" style="font-family: Arial, sans-serif; letter-spacing: 0.5px; font-size: 16px; line-height: 1.1;">
                DAILY SALE RECIVED + CREDIT
            </h4>
            <div class="fw-semibold text-dark" style="font-size: 10px;">
                <?= CLIENT_NAME ?> &bull; Salesman: <strong><?= htmlspecialchars($run['salesman_name']) ?></strong> (<?= htmlspecialchars($run['salesman_phone'] ?: 'N/A') ?>)
            </div>
        </div>
        <div class="text-end">
            <span class="fw-bold px-2 py-0" style="background-color: #d1b80d; color: #000; border-radius: 2px; font-size: 12px;">
                <?= date('d.m.y', strtotime($run['run_date'])) ?>
            </span>
            <div class="fw-bold" style="font-size: 11px;">Run #: <?= htmlspecialchars($run['run_number']) ?></div>
        </div>
    </div>

    <!-- Recovery Table (Photo 8: Sr. | Shop Name | Bill No. | Total Amount | Credit | Return | Cash) -->
    <table class="table table-bordered table-sm recovery-print-table mb-1" style="border: 1px solid #000; font-size: 10.5px; color: #000; line-height: 1.2;">
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
            $sr = 0;
            $totalAmount = 0;
            $totalCash = 0;
            $totalCredit = 0;
            $totalReturn = 0;

            foreach ($bills as $b): 
                $sr++;
                $billAmount = (float)$b['net_amount'];
                $cashVal = (float)($b['cash_amount'] ?? 0);
                $creditVal = (float)($b['credit_amount'] ?? 0);
                $returnVal = (float)($b['return_amount'] ?? 0);

                $totalAmount += $billAmount;
                $totalCash += $cashVal;
                $totalCredit += $creditVal;
                $totalReturn += $returnVal;
            ?>
                <tr style="height: 18px;">
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 3px;"><?= $sr ?></td>
                    <td class="ps-1 fw-semibold text-truncate" style="border: 1px solid #000; padding: 0px 3px;"><?= htmlspecialchars($b['shop_name']) ?></td>
                    <td class="text-center fw-bold" style="border: 1px solid #000; padding: 0px 3px;"><?= htmlspecialchars($b['invoice_number']) ?></td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 3px;"><?= number_format($billAmount, 0) ?></td>
                    
                    <?php if ($fillFigures): ?>
                        <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 3px;"><?= $creditVal > 0 ? number_format($creditVal, 0) : '' ?></td>
                        <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 3px;"><?= $returnVal > 0 ? number_format($returnVal, 0) : '' ?></td>
                        <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 3px;"><?= $cashVal > 0 ? number_format($cashVal, 0) : '' ?></td>
                    <?php else: ?>
                        <!-- Blank boxed cells for delivery salesman handwriting -->
                        <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                        <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                        <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>

            <!-- Additional blank rows for spot cash recoveries / extra field sales (Max 4 to prevent paper wastage) -->
            <?php 
            $padCount = max($sr + 3, count($bills) + 2);
            for ($i = $sr + 1; $i <= $padCount; $i++): 
            ?>
                <tr style="height: 18px;">
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 3px; color: #888;"><?= $i ?></td>
                    <td style="border: 1px solid #000; padding: 0px 3px;">&nbsp;</td>
                    <td style="border: 1px solid #000; padding: 0px 3px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 3px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 3px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 3px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 3px;"></td>
                </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #c99c04; color: #000; border-top: 1.5px solid #000;" class="fw-bold">
                <td colspan="3" class="text-center text-uppercase" style="border: 1px solid #000; padding: 1px 3px;">Total</td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($totalAmount, 0) ?></td>
                <?php if ($fillFigures): ?>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalCredit > 0 ? number_format($totalCredit, 0) : '' ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalReturn > 0 ? number_format($totalReturn, 0) : '' ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalCash > 0 ? number_format($totalCash, 0) : '' ?></td>
                <?php else: ?>
                    <td style="border: 1px solid #000;"></td>
                    <td style="border: 1px solid #000;"></td>
                    <td style="border: 1px solid #000;"></td>
                <?php endif; ?>
            </tr>
        </tfoot>
    </table>

    <!-- Signature Bar -->
    <div class="row mt-2 pt-1 text-center small fw-bold" style="font-size: 9.5px; color: #222;">
        <div class="col-4">
            <div class="border-top border-dark pt-1">Salesman Handover Signature</div>
        </div>
        <div class="col-4">
            <div class="border-top border-dark pt-1">Cashier Verification Signature</div>
        </div>
        <div class="col-4">
            <div class="border-top border-dark pt-1">Reconciliation Supervisor</div>
        </div>
    </div>
</div>
