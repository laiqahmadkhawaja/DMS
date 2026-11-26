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
$isSettled = !empty($run['status']) && $run['status'] === 'completed';
?>

<div class="delivery-recovery-sheet">
    <!-- Header Banner: Dense, Excel-style Title & Date -->
    <div class="d-flex justify-content-between align-items-center pb-1 mb-1 border-bottom border-dark">
        <div>
            <h4 class="fw-bold text-uppercase mb-0 tracking-wider" style="font-family: Arial, sans-serif; letter-spacing: 0.5px; font-size: 16px; line-height: 1.1;">
                DAILY SALE RECIVED + CREDIT (کریڈٹ شیٹ / سیلز مین شیٹ)
            </h4>
            <div class="fw-semibold text-dark" style="font-size: 10px;">
                <?= CLIENT_NAME ?> &bull; Salesman: <strong><?= htmlspecialchars($run['salesman_name']) ?></strong> (<?= htmlspecialchars($run['salesman_phone'] ?: 'N/A') ?>)
                <?php if ($run['run_type'] === 'manual'): ?>
                    &bull; <span style="background-color: #000; color: #fff; padding: 1px 4px; border-radius: 2px;">Manual Recovery Sheet</span>
                    <?php if (!empty($run['run_area_name'])): ?>
                        &bull; Route: <strong><?= htmlspecialchars($run['run_area_name']) ?></strong>
                    <?php endif; ?>
                <?php elseif (!empty($run['load_number'])): ?>
                    &bull; Load Sheet: <strong><?= htmlspecialchars($run['load_number']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($run['route_notes'])): ?>
                    &bull; Notes: <em><?= htmlspecialchars($run['route_notes']) ?></em>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <span class="fw-bold px-2 py-0" style="background-color: #d1b80d; color: #000; border-radius: 2px; font-size: 12px;">
                <?= date('d.m.y', strtotime($run['run_date'])) ?>
            </span>
            <div class="fw-bold" style="font-size: 11px;">Run #: <?= htmlspecialchars($run['run_number']) ?></div>
        </div>
    </div>

    <!-- Recovery Table (Photo 8 Enhanced: Sr | Shop Name | Bill # | Prev Bal | Bill Amt | Total Due | Cash Recv | Return | Net Bal) -->
    <table class="table table-bordered table-sm recovery-print-table mb-1" style="border: 1px solid #000; font-size: 10px; color: #000; line-height: 1.2;">
        <thead>
            <tr style="background-color: #d1b80d; color: #000; border-bottom: 1.5px solid #000;" class="text-center fw-bold">
                <th style="width: 4%; border: 1px solid #000; padding: 1px 2px;">Sr.</th>
                <th style="width: 25%; border: 1px solid #000; padding: 1px 3px;" class="text-start ps-1">Shop Name & Area</th>
                <th style="width: 10%; border: 1px solid #000; padding: 1px 2px;">Bill No.</th>
                <th style="width: 11%; border: 1px solid #000; padding: 1px 2px;" class="text-end pe-1">Prev Bal (سابقہ)</th>
                <th style="width: 11%; border: 1px solid #000; padding: 1px 2px;" class="text-end pe-1">Bill Amt (بل)</th>
                <th style="width: 12%; border: 1px solid #000; padding: 1px 2px; background-color: #c4ab0c;" class="text-end pe-1">Credit (کریڈٹ)</th>
                <th style="width: 10%; border: 1px solid #000; padding: 1px 2px;">Cash (کیش)</th>
                <th style="width: 8%; border: 1px solid #000; padding: 1px 2px;">Return (ریٹرن)</th>
                <th style="width: 9%; border: 1px solid #000; padding: 1px 2px;" class="text-end pe-1">Balance (بقایا)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sr = 0;
            $totalPrevBal    = 0;
            $totalBillAmount = 0;
            $totalDueAmount  = 0;
            $totalCash       = 0;
            $totalReturn     = 0;
            $totalNetBal     = 0;

            foreach ($bills as $b): 
                $sr++;
                $billAmount = (float)$b['net_amount'];
                $cashVal    = (float)($b['cash_amount'] ?? 0);
                $creditVal  = (float)($b['credit_amount'] ?? 0);
                $returnVal  = (float)($b['return_amount'] ?? 0);

                // Calculate Previous Balance & Total Due
                $custBal = (float)($b['customer_current_balance'] ?? 0);
                if ($run['run_type'] === 'manual') {
                    $prevBal = ((float)$b['prev_balance'] > 0 || (float)$b['net_amount'] > 0) ? (float)$b['prev_balance'] : $custBal;
                    $totalDue = $prevBal + $billAmount;
                    $remainingBal = ($creditVal > 0) ? $creditVal : max(0, $totalDue - ($cashVal + $returnVal));
                } elseif ($isSettled) {
                    $prevBal = max(0, $custBal - $creditVal);
                    $remainingBal = ($creditVal > 0) ? $creditVal : max(0, $prevBal + $billAmount - ($cashVal + $returnVal));
                    $totalDue = $prevBal + $billAmount;
                } else {
                    $prevBal = $custBal;
                    $remainingBal = ($creditVal > 0) ? $creditVal : ($prevBal + $billAmount);
                    $totalDue = $prevBal + $billAmount;
                }

                $totalPrevBal    += $prevBal;
                $totalBillAmount += $billAmount;
                $totalDueAmount  += $totalDue;
                $totalCash       += $cashVal;
                $totalReturn     += $returnVal;
                $totalNetBal     += $remainingBal;
            ?>
                <tr style="height: 18px;">
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 2px;"><?= $sr ?></td>
                    <td class="ps-1 fw-semibold text-truncate" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= htmlspecialchars($b['shop_name']) ?>
                        <?php if (!empty($b['area_name'])): ?>
                            <span style="font-size: 8.5px; color: #444;">(<?= htmlspecialchars($b['area_name']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center fw-bold" style="border: 1px solid #000; padding: 0px 2px; font-size: 9.5px;"><?= htmlspecialchars($b['invoice_number']) ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 2px;"><?= $prevBal > 0 ? number_format($prevBal, 0) : '0' ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 0px 2px;"><?= number_format($billAmount, 0) ?></td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 2px; background-color: #fcf9ea;"><?= number_format($totalDue, 0) ?></td>
                    
                    <?php if ($fillFigures): ?>
                        <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 2px;"><?= $cashVal > 0 ? number_format($cashVal, 0) : '0' ?></td>
                        <td class="text-end pe-1 text-danger" style="border: 1px solid #000; padding: 0px 2px;"><?= $returnVal > 0 ? number_format($returnVal, 0) : '0' ?></td>
                        <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 2px;"><?= number_format($remainingBal, 0) ?></td>
                    <?php else: ?>
                        <!-- Blank boxed cells for delivery salesman handwriting in the field -->
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
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 2px; color: #888;"><?= $i ?></td>
                    <td style="border: 1px solid #000; padding: 0px 3px;">&nbsp;</td>
                    <td style="border: 1px solid #000; padding: 0px 2px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 2px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 2px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 2px; background-color: #fcf9ea;"></td>
                    <td style="border: 1px solid #000; padding: 0px 2px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 2px;"></td>
                    <td style="border: 1px solid #000; padding: 0px 2px;"></td>
                </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #c99c04; color: #000; border-top: 1.5px solid #000;" class="fw-bold">
                <td colspan="3" class="text-center text-uppercase" style="border: 1px solid #000; padding: 1px 3px;">Total Summary</td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 2px;"><?= number_format($totalPrevBal, 0) ?></td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 2px;"><?= number_format($totalBillAmount, 0) ?></td>
                <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 1px 2px;"><?= number_format($totalDueAmount, 0) ?></td>
                <?php if ($fillFigures): ?>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 1px 2px;"><?= number_format($totalCash, 0) ?></td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 2px;"><?= number_format($totalReturn, 0) ?></td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 1px 2px;"><?= number_format($totalNetBal, 0) ?></td>
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
