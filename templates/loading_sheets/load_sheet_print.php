<?php
/**
 * DMS - Distribution Management System
 * Load Sheet Printable Template (Matches Client Photo 5 & Photo 9: TOTAL STOCK ISSUE SUMMARY)
 * Ultra-Compact Excel-like Congested Print Layout (Zero Paper Wastage)
 * 
 * Required variables:
 * $sheet - Array of load sheet header
 * $items - Array of dispatched items
 * $bookersList - String of bookers
 * $areasList - String of areas
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}
?>

<div class="load-sheet-container">
    <!-- Header Banner: Dense, Excel-style -->
    <div class="d-flex justify-content-between align-items-center pb-1 mb-1 border-bottom border-dark">
        <div>
            <h4 class="fw-bold text-uppercase mb-0 tracking-wider" style="font-family: Arial, sans-serif; letter-spacing: 0.5px; font-size: 16px; line-height: 1.1;">
                TOTAL STOCK ISSUE SUMMARY
            </h4>
            <div class="fw-semibold text-dark" style="font-size: 10px;">
                <?= CLIENT_NAME ?> &bull; 03000673276 &bull; Jandiala Bhagwala G.T. Road Gujranwala
            </div>
        </div>
        <div class="text-end">
            <span class="px-2 py-0 fw-bold" style="background-color: #d1b80d; color: #000; border-radius: 2px; font-size: 12px;">
                <?= date('d.m.y', strtotime($sheet['delivery_date'] ?? $sheet['load_date'])) ?>
            </span>
            <div class="fw-bold" style="font-size: 11px;">Sheet #: <?= htmlspecialchars($sheet['load_number']) ?></div>
        </div>
    </div>

    <!-- Metadata Row (Matches Form #, Booker(s), Date, Area, Salesman) -->
    <div class="row g-1 mb-1 fw-bold" style="font-size: 10.5px; color: #000; line-height: 1.2;">
        <div class="col-4">
            <span class="text-secondary">Booker(s):</span> 
            <span><?= htmlspecialchars($bookersList) ?></span>
        </div>
        <div class="col-4 text-center">
            <span class="text-secondary">Salesman / Driver:</span> 
            <span><?= htmlspecialchars($sheet['salesman_name']) ?> (<?= htmlspecialchars($sheet['salesman_phone'] ?: 'N/A') ?>)</span>
        </div>
        <div class="col-4 text-end">
            <span class="text-secondary">Route / Area:</span> 
            <span><?= htmlspecialchars($areasList) ?></span>
        </div>
    </div>

    <!-- Products Dispatch Table (Photo 5 & 9 Columns - Congested Excel Grid) -->
    <table class="table table-bordered table-sm load-items-table mb-1" style="border: 1px solid #000; font-size: 10px; color: #000; line-height: 1.2;">
        <thead>
            <tr style="background-color: #d1b80d; color: #000; border-bottom: 1.5px solid #000;" class="text-center fw-bold text-uppercase">
                <th style="width: 14%; border: 1px solid #000; padding: 1px 3px;">Company / Area</th>
                <th style="width: 32%; border: 1px solid #000; padding: 1px 3px;" class="text-start ps-1">Product</th>
                <th style="width: 8%; border: 1px solid #000; padding: 1px 3px;">Ctn Qty</th>
                <th style="width: 8%; border: 1px solid #000; padding: 1px 3px;">Pcs Qty</th>
                <th style="width: 10%; border: 1px solid #000; padding: 1px 3px;">Rate</th>
                <th style="width: 12%; border: 1px solid #000; padding: 1px 3px;" class="text-end pe-1">Total Amount</th>
                <th style="width: 16%; border: 1px solid #000; padding: 1px 2px;">
                    <div>Return</div>
                    <div class="d-flex justify-content-around fw-bold border-top border-dark pt-0" style="font-size: 9px;">
                        <span>Return</span>
                        <span>Amount</span>
                    </div>
                </th>
                <th style="width: 10%; border: 1px solid #000; padding: 1px 3px;">Credit</th>
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
                
                $ctnPart = floor($baseQty / $ctnRatio);
                $pcsPart = fmod($baseQty, $ctnRatio);
                
                $totalCtn += $ctnPart;
                $totalPcs += $pcsPart;
                $grandTotalAmount += (float)$item['total_amount'];
            ?>
                <tr style="height: 17px;">
                    <td class="fw-semibold ps-1 text-truncate" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= htmlspecialchars($item['category_name'] ?: 'General') ?>
                    </td>
                    <td class="fw-bold ps-1" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= htmlspecialchars($item['product_name']) ?>
                    </td>
                    <td class="text-center fw-bold" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= $ctnPart > 0 ? $ctnPart : '' ?>
                    </td>
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= $pcsPart > 0 ? $pcsPart : '' ?>
                    </td>
                    <td class="text-center" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= number_format((float)$item['rate'], 0) ?>
                    </td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 3px;">
                        <?= number_format((float)$item['total_amount'], 0) ?>
                    </td>
                    <!-- Salesman field handwriting columns -->
                    <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                    <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #c99c04; color: #000; border-top: 1.5px solid #000;" class="fw-bold">
                <td colspan="2" class="text-end text-uppercase pe-2" style="padding: 1px 3px;">Total</td>
                <td class="text-center" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalCtn ?></td>
                <td class="text-center" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalPcs ?></td>
                <td style="border: 1px solid #000;"></td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($grandTotalAmount, 0) ?></td>
                <td style="border: 1px solid #000;"></td>
                <td style="border: 1px solid #000;"></td>
            </tr>
        </tfoot>
    </table>

    <!-- Gate Pass & Audit Signatures -->
    <div class="row mt-2 pt-1 text-center small fw-bold" style="font-size: 9.5px; color: #222;">
        <div class="col-4">
            <div class="border-top border-dark pt-1">Prepared By: <?= htmlspecialchars($sheet['creator_name'] ?? 'Admin') ?></div>
        </div>
        <div class="col-4">
            <div class="border-top border-dark pt-1">Warehouse Gate Incharge / Dispatched</div>
        </div>
        <div class="col-4">
            <div class="border-top border-dark pt-1">Salesman Receiver / Van Driver</div>
        </div>
    </div>
</div>
