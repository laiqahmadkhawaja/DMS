<?php
/**
 * DMS - Distribution Management System
 * Dedicated Purchase Order Print View
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Invalid Purchase Order ID');
}

$po = db_fetch_one("
    SELECT po.*, s.supplier_name, s.supplier_code, s.phone as supplier_phone, s.address as supplier_address,
           u.full_name as creator_name
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN users u ON u.id = po.created_by
    WHERE po.id = ?
", [$id]);

if (!$po) {
    exit('Purchase Order not found');
}

$items = db_fetch_all("
    SELECT poi.*, p.product_name, p.product_code, p.carton_qty as ctn_ratio, u.unit_name, u.symbol as unit_symbol
    FROM purchase_order_items poi
    JOIN products p ON p.id = poi.product_id
    JOIN units u ON u.id = poi.unit_id
    WHERE poi.purchase_order_id = ?
    ORDER BY poi.id ASC
", [$id]);

$padRows = max(8, count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print PO #<?= htmlspecialchars($po['purchase_number']) ?> - <?= htmlspecialchars($po['supplier_name']) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/icons/bootstrap-icons.css">
    <style>
        body {
            background-color: #475569;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        .print-toolbar {
            background-color: #0f172a;
            color: #fff;
            padding: 10px 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .print-container {
            max-width: 820px;
            margin: 24px auto 40px auto;
            padding: 0 15px;
        }

        .a4-sheet {
            background: #ffffff;
            padding: 24px 28px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.35);
            border-radius: 4px;
            margin-bottom: 25px;
            box-sizing: border-box;
            width: 100%;
        }

        .date-badge {
            background-color: #c99c04 !important;
            color: #000 !important;
            font-weight: bold;
            padding: 1px 7px;
            border-radius: 2px;
            display: inline-block;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 6mm 8mm;
            }

            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                color: #000 !important;
            }

            .no-print, .print-toolbar {
                display: none !important;
            }

            .print-container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .a4-sheet {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 3mm 4mm !important;
                width: 100% !important;
            }

            .date-badge, th, td, tr, .total-bar {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
    <!-- Top Interactive Toolbar -->
    <div class="no-print print-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-6 fw-bold">
                <i class="bi bi-cart-check me-1 text-warning"></i>
                PO #<?= htmlspecialchars($po['purchase_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars($po['supplier_name']) ?></span>
            <span class="badge bg-success">Rs. <?= number_format((float)$po['total_amount'], 0) ?></span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Navigation Back Buttons -->
            <a href="<?= APP_URL ?>/modules/purchases/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Purchase Order
            </a>
            <a href="<?= APP_URL ?>/modules/purchases/index.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-list-ul me-1"></i> PO List
            </a>

            <!-- Print Trigger -->
            <button onclick="window.print();" class="btn btn-sm btn-primary fw-bold px-3">
                <i class="bi bi-printer me-1"></i> Print (Ctrl+P)
            </button>

            <!-- Close -->
            <button onclick="window.close();" class="btn btn-sm btn-outline-light">
                <i class="bi bi-x me-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Paper Container -->
    <div class="print-container">
        <div class="a4-sheet">
            <!-- Header -->
            <div class="text-center pb-2 border-bottom border-dark position-relative">
                <div class="position-absolute end-0 top-0">
                    <span class="badge border border-dark text-dark bg-white text-uppercase" style="font-size: 10px; padding: 3px 6px;">
                        Status: <?= strtoupper($po['status']) ?>
                    </span>
                </div>
                <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px;">
                    <?= CLIENT_NAME ?>
                </h4>
                <div class="fw-semibold text-dark" style="font-size: 11px; margin-top: 2px;">
                    03000673276 &nbsp;&bull;&nbsp; Jandiala Bhagwala G.T. Road Gujranwala
                </div>
                <div class="fw-bold text-uppercase mt-1" style="font-size: 13px; letter-spacing: 1px;">
                    Official Purchase Order Voucher
                </div>
            </div>

            <!-- Metadata Table -->
            <div class="metadata-table my-2">
                <table class="table table-borderless table-sm mb-0 fw-bold" style="width: 100% !important; table-layout: fixed; border-collapse: collapse !important; font-size: 11.5px; color: #000; line-height: 1.35;">
                    <tbody>
                        <tr>
                            <td style="width: 16%; padding: 1px 3px;">PO NUMBER:</td>
                            <td style="width: 34%; padding: 1px 3px; font-weight: bold;"><?= htmlspecialchars($po['purchase_number']) ?></td>
                            <td style="width: 16%; padding: 1px 3px;" class="text-end">Order Date:</td>
                            <td style="width: 34%; padding: 1px 3px;">
                                <span class="date-badge"><?= date('d-m-Y', strtotime($po['order_date'])) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 1px 3px;">Supplier / Vendor:</td>
                            <td style="padding: 1px 3px; font-weight: bold;"><?= htmlspecialchars($po['supplier_name']) ?> (<?= htmlspecialchars($po['supplier_code']) ?>)</td>
                            <td style="padding: 1px 3px;" class="text-end">Expected Delivery:</td>
                            <td style="padding: 1px 3px;">
                                <span class="fw-bold"><?= !empty($po['expected_delivery_date']) ? date('d-m-Y', strtotime($po['expected_delivery_date'])) : 'As Arranged' ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 1px 3px;">Supplier Phone:</td>
                            <td colspan="3" style="padding: 1px 3px;"><?= htmlspecialchars($po['supplier_phone'] ?: 'N/A') ?> &bull; <?= htmlspecialchars($po['supplier_address'] ?: '') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Items Table -->
            <table class="table table-bordered table-sm mb-1" style="width: 100% !important; table-layout: fixed; border: 1.5px solid #000 !important; border-collapse: collapse !important; font-size: 11.5px; color: #000;">
                <thead>
                    <tr style="background-color: #f8f9fa; border-bottom: 1.5px solid #000 !important;" class="text-center fw-bold">
                        <th style="width: 6%; border: 1px solid #000 !important; padding: 3px 2px;">Sr.</th>
                        <th style="width: 44%; border: 1px solid #000 !important; padding: 3px 5px; text-align: left;" class="text-start ps-1">Product Description</th>
                        <th style="width: 14%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Packaging</th>
                        <th style="width: 12%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Order Qty</th>
                        <th style="width: 12%; border: 1px solid #000 !important; padding: 3px 4px; text-align: right; padding-right: 5px;">Unit Cost</th>
                        <th style="width: 12%; border: 1px solid #000 !important; padding: 3px 5px; text-align: right;" class="text-end pe-1">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sr = 1;
                    $printedRows = 0;
                    $totalQty = 0;
                    foreach ($items as $item): 
                        $printedRows++;
                        $qty = (float)$item['quantity'];
                        $totalQty += $qty;
                    ?>
                        <tr style="height: 23px;">
                            <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px;"><?= $sr++ ?></td>
                            <td class="text-start ps-1 fw-semibold text-truncate" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: left;">
                                <?= htmlspecialchars($item['product_name']) ?>
                            </td>
                            <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                                1*<?= (int)($item['ctn_ratio'] ?? 1) ?>
                            </td>
                            <td class="text-center fw-bold" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                                <?= format_qty($qty) ?> <?= htmlspecialchars($item['unit_symbol'] ?? '') ?>
                            </td>
                            <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                                <?= number_format((float)$item['rate'], 0) ?><?= (($item['unit_symbol'] ?? '') === 'Pcs' || (int)($item['unit_id'] ?? 0) === 4) ? ' <span style="font-size:8.5px;">/Pc</span>' : '' ?>
                            </td>
                            <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                                <?= number_format((float)$item['line_total'], 0) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php for ($padIdx = $printedRows; $padIdx < $padRows; $padIdx++): ?>
                        <tr style="height: 23px;">
                            <td style="border: 1px solid #000 !important; padding: 1px 2px;">&nbsp;</td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 2px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 2px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #c99c04 !important; color: #000 !important; border-top: 1.5px solid #000 !important; border-bottom: 1.5px solid #000 !important;" class="fw-bold">
                        <td colspan="3" class="text-center text-uppercase" style="border: 1px solid #000 !important; padding: 2px 5px; font-size: 11.5px; letter-spacing: 1px;">
                            Total Order Amount
                        </td>
                        <td class="text-center" style="border: 1px solid #000 !important; padding: 2px 2px;">
                            <?= format_qty($totalQty) ?>
                        </td>
                        <td style="border: 1px solid #000 !important;"></td>
                        <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 2px 5px; font-size: 12px;">
                            <?= number_format((float)$po['total_amount'], 0) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <?php if (!empty($po['remarks'])): ?>
                <div class="p-1 px-2 border border-dark rounded mb-2 small fw-bold" style="font-size: 10.5px;">
                    Remarks: <?= htmlspecialchars($po['remarks']) ?>
                </div>
            <?php endif; ?>

            <!-- Signatures -->
            <div class="d-flex justify-content-between pt-4 small fw-bold" style="font-size: 10px; color: #000; margin-top: 20px;">
                <div>Prepared By: <?= htmlspecialchars($po['creator_name'] ?? 'Warehouse Staff') ?></div>
                <div>Supplier Confirmation: __________________</div>
                <div>Authorized Signatory: __________________</div>
            </div>
        </div>
    </div>

    <script>
        if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>
