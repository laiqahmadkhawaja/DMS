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
            <tr style="background-color: #d1b80d; color: #000; border-bottom: 1px solid #000;" class="text-center fw-bold text-uppercase">
                <th rowspan="2" style="width: 13%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;">Company / Area</th>
                <th rowspan="2" style="width: 27%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;" class="text-start ps-1">Product</th>
                <th rowspan="2" style="width: 7%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;">Ctn Qty</th>
                <th rowspan="2" style="width: 7%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;">Pack Qty</th>
                <th rowspan="2" style="width: 7%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;">Pcs Qty</th>
                <th rowspan="2" style="width: 9%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;">Rate</th>
                <th rowspan="2" style="width: 11%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;" class="text-end pe-1">Total Amount</th>
                <th colspan="2" style="width: 11%; border: 1px solid #000; padding: 1px 2px;">Return</th>
                <th rowspan="2" style="width: 8%; border: 1px solid #000; padding: 1px 3px; vertical-align: middle;">Credit</th>
            </tr>
            <tr style="background-color: #d1b80d; color: #000; border-bottom: 1.5px solid #000;" class="text-center fw-bold text-uppercase">
                <th style="width: 5%; border: 1px solid #000; padding: 1px 2px; font-size: 9px;">Return</th>
                <th style="width: 6%; border: 1px solid #000; padding: 1px 2px; font-size: 9px; border-right: 1.5px solid #000 !important;">Amount</th>
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
                $packsPerCtn = (float)($item['packs_per_ctn'] ?? $item['ctn_ratio'] ?? 1);
                $pcsPerPack  = (float)($item['pcs_per_pack'] ?? 1);
                if ($packsPerCtn <= 0) $packsPerCtn = 1;
                if ($pcsPerPack <= 0) $pcsPerPack = 1;

                $baseQty = (float)$item['ordered_qty'];
                
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
                        <?= $packPart > 0 ? $packPart : '' ?>
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
                    
                    <?php if (!empty($fillFigures)): ?>
                        <td class="text-center fw-bold" style="border: 1px solid #000; padding: 0px 2px; font-size: 9px;">
                            <?= $itemReturnQty > 0 ? number_format($itemReturnQty, 0) : '0' ?>
                        </td>
                        <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 2px; font-size: 9px; border-right: 1.5px solid #000 !important;">
                            <?= number_format($itemReturnAmt, 0) ?>
                        </td>
                        <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 0px 3px;">
                            <?= number_format($itemCredit, 0) ?>
                        </td>
                    <?php else: ?>
                        <!-- Salesman field handwriting columns -->
                        <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                        <td style="border: 1px solid #000; background-color: #fff; padding: 0px; border-right: 1.5px solid #000 !important;"></td>
                        <td style="border: 1px solid #000; background-color: #fff; padding: 0px;"></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #c99c04; color: #000; border-top: 1.5px solid #000;" class="fw-bold">
                <td colspan="2" class="text-end text-uppercase pe-2" style="padding: 1px 3px;">Total</td>
                <td class="text-center" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalCtn ?></td>
                <td class="text-center" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalPack ?></td>
                <td class="text-center" style="border: 1px solid #000; padding: 1px 3px;"><?= $totalPcs ?></td>
                <td style="border: 1px solid #000;"></td>
                <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($grandTotalAmount, 0) ?></td>
                <?php if (!empty($fillFigures)): ?>
                    <td class="text-center fw-bold" style="border: 1px solid #000; padding: 1px 2px; font-size: 9px;">
                        <?= $grandTotalReturnQty > 0 ? number_format($grandTotalReturnQty, 0) : '0' ?>
                    </td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000; padding: 1px 2px; font-size: 9px; border-right: 1.5px solid #000 !important;">
                        <?= number_format($grandTotalReturnAmt, 0) ?>
                    </td>
                    <td class="text-end pe-1" style="border: 1px solid #000; padding: 1px 3px;"><?= number_format($grandTotalCreditAmt, 0) ?></td>
                <?php else: ?>
                    <td style="border: 1px solid #000;"></td>
                    <td style="border: 1px solid #000; border-right: 1.5px solid #000 !important;"></td>
                    <td style="border: 1px solid #000;"></td>
                <?php endif; ?>
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
