<?php
/**
 * DMS - Distribution Management System
 * Invoice Printable Template (Matches Client Photo 2 & Photo 7)
 * 
 * Required variables when included:
 * $invoice - Array of invoice header data
 * $invoiceItems - Array of invoice line items
 * 
 * Optional variables:
 * $copyLabel - Optional string like 'Customer Copy', 'Office Copy', 'Delivery Copy'
 * $padRows - Integer for blank row padding (default 8 for 1-up, 4 for 2-up, 2 for 3-up)
 * $compactMode - Boolean for tighter margins and smaller fonts (default false)
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$copyLabel = $copyLabel ?? '';
$compactMode = !empty($compactMode);
$defaultPad = $compactMode ? 4 : 8;
$padRows = (isset($padRows) && (int)$padRows > 0) ? (int)$padRows : $defaultPad;
$fontSize = $compactMode ? '11px' : '12px';
$headerFontSize = $compactMode ? '16px' : '19px';
$lineHeight = $compactMode ? '21px' : '24px';
?>

<div class="invoice-box <?= $compactMode ? 'invoice-box-compact' : '' ?>" style="width: 100%; box-sizing: border-box; margin-bottom: 2px;">
    <!-- Header: Business Title & Phone & Address -->
    <div class="invoice-header text-center pt-0 pb-1 position-relative">
        <?php if (!empty($copyLabel)): ?>
            <div class="position-absolute end-0 top-0">
                <span class="copy-badge" style="display: inline-block; font-size: 10px; font-weight: bold; padding: 2px 7px; border: 1.5px solid #000; background: #fff; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= htmlspecialchars($copyLabel) ?>
                </span>
            </div>
        <?php endif; ?>
        
        <h4 class="fw-bold mb-0 text-uppercase tracking-wider client-name" style="font-family: Arial, Helvetica, sans-serif; font-size: <?= $headerFontSize ?>; letter-spacing: 0.8px; line-height: 1.15; color: #000;">
            <?= CLIENT_NAME ?>
        </h4>
        <div class="fw-bold text-dark client-contact" style="font-size: <?= $compactMode ? '10px' : '11px' ?>; margin-top: 2px;">
            03000673276 &nbsp; Jandiala Bhagwala G.T. Road Gujranwala
        </div>
    </div>

    <!-- Metadata Section (Matches Photo 2 & 7 layout) -->
    <div class="metadata-table my-1">
        <table class="table table-borderless table-sm mb-0 fw-bold" style="width: 100% !important; table-layout: fixed; border-collapse: collapse !important; font-size: <?= $fontSize ?>; color: #000; line-height: 1.3;">
            <tbody>
                <tr>
                    <td style="width: 14%; padding: 1px 3px;">BILL NO.</td>
                    <td style="width: 42%; padding: 1px 3px; font-weight: bold;"><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                    <td colspan="2" class="text-end pe-0" style="width: 44%; padding: 1px 0px; text-align: right;">
                        booker: <span class="fw-bold"><?= htmlspecialchars($invoice['booker_name'] ?? '') ?></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 1px 3px;">Name :</td>
                    <td style="padding: 1px 3px; font-weight: bold;"><?= htmlspecialchars($invoice['customer_name'] ?? ($invoice['shop_name'] ?? '')) ?></td>
                    <td colspan="2" class="text-end pe-0" style="padding: 1px 0px; text-align: right;">
                        Date: <span class="date-badge" style="background-color: #c99c04; color: #000; font-weight: bold; padding: 1px 7px; border-radius: 2px; font-size: <?= $fontSize ?>; display: inline-block;">
                            <?= !empty($invoice['invoice_date']) ? date('d-m-y', strtotime($invoice['invoice_date'])) : date('d-m-y') ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 1px 3px;">Address :</td>
                    <td colspan="3" style="padding: 1px 3px;"><?= htmlspecialchars($invoice['customer_address'] ?? ($invoice['address'] ?? ($invoice['area_name'] ?? ''))) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Line Items Table (Matches Photo 2 & 7: Product | Carton | Con. Pkng | Box/Packets | Qty | Unit Prize | Total) -->
    <table class="table table-bordered table-sm invoice-items-table mb-1" style="width: 100% !important; table-layout: fixed; border: 1.5px solid #000 !important; border-collapse: collapse !important; font-size: <?= $fontSize ?>; color: #000;">
        <thead>
            <tr style="background-color: #f8f9fa; border-bottom: 1.5px solid #000 !important;" class="text-center fw-bold">
                <th style="width: 36%; border: 1px solid #000 !important; padding: 3px 5px; text-align: left;" class="text-start ps-1">Product</th>
                <th style="width: 10%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Carton</th>
                <th style="width: 11%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Con. Pkng</th>
                <th style="width: 13%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Box / Packets</th>
                <th style="width: 8%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Qty</th>
                <th style="width: 11%; border: 1px solid #000 !important; padding: 3px 4px; text-align: right; padding-right: 5px;">Unit Prize</th>
                <th style="width: 11%; border: 1px solid #000 !important; padding: 3px 5px; text-align: right;" class="text-end pe-1">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $printedRows = 0;
            foreach ($invoiceItems as $item): 
                $printedRows++;
                $ctnQty  = (float)($item['carton_qty'] ?? 0);
                $packQty = (float)($item['pack_qty'] ?? 0);
                $pcsQty  = (float)($item['pcs_qty'] ?? 0);
                $packsPerCtn = (float)($item['ctn_ratio'] ?? 1) > 0 ? (int)$item['ctn_ratio'] : 1;
                $pcsPerPack  = (float)($item['pack_ratio'] ?? 1) > 0 ? (int)$item['pack_ratio'] : 1;
                $conPkg = $pcsPerPack > 1 ? "1*{$packsPerCtn}*{$pcsPerPack}" : "1*{$packsPerCtn}";
            ?>
                <tr style="height: <?= $lineHeight ?>;">
                    <td class="text-start ps-1 fw-semibold text-truncate" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: left;">
                        <?= htmlspecialchars($item['product_name']) ?>
                    </td>
                    <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                        <?= $ctnQty > 0 ? (float)$ctnQty : '' ?>
                    </td>
                    <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                        <?= htmlspecialchars($conPkg) ?>
                    </td>
                    <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                        <?= $packQty > 0 ? (float)$packQty : (!empty($item['pack_code']) ? htmlspecialchars($item['pack_code']) : '') ?>
                    </td>
                    <td class="text-center fw-semibold" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                        <?= $pcsQty > 0 ? (float)$pcsQty : ((float)$item['quantity'] > 0 ? (float)$item['quantity'] : '') ?>
                    </td>
                    <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                        <?= number_format((float)$item['rate'], 0) ?>
                    </td>
                    <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                        <?= number_format((float)$item['line_total'], 0) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <!-- Pad with blank rows for consistent paper height like in physical books -->
            <?php for ($padIdx = $printedRows; $padIdx < $padRows; $padIdx++): ?>
                <tr style="height: <?= $lineHeight ?>;">
                    <td style="border: 1px solid #000 !important; padding: 1px 5px;">&nbsp;</td>
                    <td style="border: 1px solid #000 !important; padding: 1px 2px;"></td>
                    <td style="border: 1px solid #000 !important; padding: 1px 2px;"></td>
                    <td style="border: 1px solid #000 !important; padding: 1px 2px;"></td>
                    <td style="border: 1px solid #000 !important; padding: 1px 2px;"></td>
                    <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                    <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <?php 
            $totDiscount = (float)($invoice['extra_discount_amount'] ?? 0) + (float)($invoice['item_discount_amount'] ?? 0);
            if ($totDiscount > 0): 
                $discLabel = (!empty($invoice['discount_mode']) && $invoice['discount_mode'] === 'subtotal') ? 'Subtotal Disc' : 'Discount';
                if (!empty($invoice['subtotal_discount_percent']) && (float)$invoice['subtotal_discount_percent'] > 0) {
                    $discLabel .= ' (' . (float)$invoice['subtotal_discount_percent'] . '%)';
                }
            ?>
                <tr style="background-color: #fff; color: #000; border-top: 1px solid #000 !important;" class="fw-bold">
                    <td colspan="6" class="text-end text-uppercase" style="border: 1px solid #000 !important; padding: 1px 5px; font-size: <?= $fontSize ?>;">Gross Total:</td>
                    <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 1px 5px; font-size: <?= $fontSize ?>;">
                        <?= number_format((float)$invoice['gross_amount'], 0) ?>
                    </td>
                </tr>
                <tr style="background-color: #fff; color: #a00; border-top: 1px solid #000 !important;" class="fw-bold">
                    <td colspan="6" class="text-end text-uppercase" style="border: 1px solid #000 !important; padding: 1px 5px; font-size: <?= $fontSize ?>;"><?= $discLabel ?>:</td>
                    <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 1px 5px; font-size: <?= $fontSize ?>; color: #a00;">
                        -<?= number_format($totDiscount, 0) ?>
                    </td>
                </tr>
            <?php endif; ?>
            <tr style="background-color: #c99c04 !important; color: #000 !important; border-top: 1.5px solid #000 !important; border-bottom: 1.5px solid #000 !important;" class="fw-bold">
                <td colspan="6" class="text-center text-uppercase" style="border: 1px solid #000 !important; padding: 2px 5px; font-size: <?= $fontSize ?>; letter-spacing: 1px;">Total</td>
                <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 2px 5px; font-size: <?= $fontSize ?>;">
                    <?= number_format((float)$invoice['net_amount'], 0) ?>
                </td>
            </tr>
        </tfoot>
    </table>

    <!-- Footer Note & Signatures -->
    <div class="d-flex justify-content-between pt-1 small fw-bold" style="font-size: <?= $compactMode ? '9.5px' : '10.5px' ?>; color: #000; margin-top: <?= $compactMode ? '22px' : '32px' ?>; padding-top: 4px;">
        <div>Customer Signature: __________________</div>
        <div>Salesman: <?= htmlspecialchars($invoice['salesman_name'] ?? 'Route Delivery') ?></div>
        <div>Authorized: __________________</div>
    </div>
</div>
