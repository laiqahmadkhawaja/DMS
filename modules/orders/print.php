<?php
/**
 * DMS - Distribution Management System
 * Dedicated Order Print View
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('orders_view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Invalid order ID');
}

$order = db_fetch_one("
    SELECT 
        o.*,
        c.shop_name, c.owner_name, c.phone as customer_phone, c.address as customer_address,
        a.area_name, a.city,
        b.booker_name, b.phone as booker_phone,
        u.full_name as creator_name
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    JOIN areas a ON a.id = o.area_id
    JOIN bookers b ON b.id = o.booker_id
    LEFT JOIN users u ON u.id = o.created_by
    WHERE o.id = ?
", [$id]);

if (!$order) {
    exit('Order not found');
}

$items = db_fetch_all("
    SELECT 
        oi.*,
        p.product_name, p.product_code, p.carton_qty as packs_per_ctn, p.pack_qty as pcs_per_pack,
        u.symbol as unit_symbol
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN units u ON u.id = oi.unit_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
", [$id]);

$calcCartons = array_sum(array_column($items, 'carton_qty'));
$calcPacks   = array_sum(array_column($items, 'pack_qty'));
$calcUnits   = array_sum(array_column($items, 'quantity'));
$totalCartons = (float)($order['total_cartons'] ?? 0) > 0 ? (float)$order['total_cartons'] : $calcCartons;
$totalUnits   = (float)($order['total_units'] ?? 0) > 0 ? (float)$order['total_units'] : $calcUnits;
$padRows = max(8, count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Order #<?= htmlspecialchars($order['order_number']) ?> - <?= htmlspecialchars($order['shop_name']) ?></title>
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

        /* Top Interactive Toolbar */
        .print-toolbar {
            background-color: #0f172a;
            color: #fff;
            padding: 10px 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        /* Centered Paper Container */
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

        .order-title {
            letter-spacing: 0.5px;
            font-size: 18px;
            color: #000;
        }

        .date-badge {
            background-color: #c99c04 !important;
            color: #000 !important;
            font-weight: bold;
            padding: 1px 7px;
            border-radius: 2px;
            display: inline-block;
        }

        /* Print Media Styles */
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
                <i class="bi bi-receipt me-1 text-warning"></i>
                Order #<?= htmlspecialchars($order['order_number']) ?>
            </span>
            <span class="badge bg-secondary">
                <?= htmlspecialchars($order['shop_name']) ?>
            </span>
            <span class="badge bg-success">
                Rs. <?= number_format((float)$order['net_amount'], 0) ?>
            </span>
            <span class="badge bg-light text-dark">
                <?= format_qty($totalCartons) ?> Ctns
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Navigation Back Buttons -->
            <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Order
            </a>
            <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-list-ul me-1"></i> Order List
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

    <!-- Printable Sheet Preview Container -->
    <div class="print-container">
        <div class="a4-sheet">
            <!-- Header: Company & Voucher Title -->
            <div class="text-center pb-2 border-bottom border-dark position-relative">
                <div class="position-absolute end-0 top-0">
                    <span class="badge border border-dark text-dark bg-white text-uppercase" style="font-size: 10px; padding: 3px 6px;">
                        Status: <?= strtoupper($order['status']) ?>
                    </span>
                </div>
                <h4 class="fw-bold mb-0 text-uppercase order-title">
                    <?= CLIENT_NAME ?>
                </h4>
                <div class="fw-semibold text-dark" style="font-size: 11px; margin-top: 2px;">
                    03000673276 &nbsp;&bull;&nbsp; Jandiala Bhagwala G.T. Road Gujranwala
                </div>
                <div class="fw-bold text-uppercase mt-1" style="font-size: 13px; letter-spacing: 1px;">
                    Market Order Booking Voucher
                </div>
            </div>

            <!-- Metadata Section (Key-Value Grid) -->
            <div class="metadata-table my-2">
                <table class="table table-borderless table-sm mb-0 fw-bold" style="width: 100% !important; table-layout: fixed; border-collapse: collapse !important; font-size: 11.5px; color: #000; line-height: 1.35;">
                    <tbody>
                        <tr>
                            <td style="width: 16%; padding: 1px 3px;">ORDER NO.</td>
                            <td style="width: 34%; padding: 1px 3px; font-weight: bold;">
                                <?= htmlspecialchars($order['order_number']) ?>
                                <?php if (!empty($order['free_loose_pieces'])): ?>
                                    <span style="font-size: 8.5px; border: 1px solid #000; padding: 0 4px; border-radius: 2px; font-weight: bold; margin-left: 4px;">BONUS/سکیم</span>
                                <?php endif; ?>
                            </td>
                            <td style="width: 16%; padding: 1px 3px;" class="text-end">Order Booker:</td>
                            <td style="width: 34%; padding: 1px 3px;"><?= htmlspecialchars($order['booker_name']) ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 1px 3px;">Retailer / Shop:</td>
                            <td style="padding: 1px 3px; font-weight: bold;"><?= htmlspecialchars($order['shop_name']) ?></td>
                            <td style="padding: 1px 3px;" class="text-end">Booking Date:</td>
                            <td style="padding: 1px 3px;">
                                <span class="date-badge">
                                    <?= date('d-m-Y', strtotime($order['order_date'])) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 1px 3px;">Route / Area:</td>
                            <td style="padding: 1px 3px;"><?= htmlspecialchars($order['area_name']) ?> (<?= htmlspecialchars($order['city']) ?>)</td>
                            <td style="padding: 1px 3px;" class="text-end">Delivery Date:</td>
                            <td style="padding: 1px 3px;">
                                <span class="fw-bold"><?= !empty($order['delivery_date']) ? date('d-m-Y', strtotime($order['delivery_date'])) : 'Standard' ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 1px 3px;">Shop Address:</td>
                            <td colspan="3" style="padding: 1px 3px;"><?= htmlspecialchars($order['customer_address']) ?> &bull; Phone: <?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Order Items Grid Table -->
            <table class="table table-bordered table-sm mb-1" style="width: 100% !important; table-layout: fixed; border: 1.5px solid #000 !important; border-collapse: collapse !important; font-size: 11.5px; color: #000;">
                <thead>
                    <tr style="background-color: #f8f9fa; border-bottom: 1.5px solid #000 !important;" class="text-center fw-bold">
                        <th style="width: 5%; border: 1px solid #000 !important; padding: 3px 2px;">Sr.</th>
                        <th style="width: 35%; border: 1px solid #000 !important; padding: 3px 5px; text-align: left;" class="text-start ps-1">Product Description</th>
                        <th style="width: 12%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Pack Ratio</th>
                        <th style="width: 10%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Carton Qty</th>
                        <th style="width: 9%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Pack Qty</th>
                        <th style="width: 9%; border: 1px solid #000 !important; padding: 3px 2px; text-align: center;">Pcs Qty</th>
                        <th style="width: 10%; border: 1px solid #000 !important; padding: 3px 4px; text-align: right; padding-right: 5px;">Rate</th>
                        <th style="width: 10%; border: 1px solid #000 !important; padding: 3px 5px; text-align: right;" class="text-end pe-1">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sr = 1;
                    $printedRows = 0;
                    foreach ($items as $item): 
                        $printedRows++;
                        $ctnQty  = (float)($item['carton_qty'] ?? 0);
                        $packQty = (float)($item['pack_qty'] ?? 0);
                        $pcsQty  = (float)($item['pcs_qty'] ?? 0);
                        $packsPerCtn = (float)$item['packs_per_ctn'] > 0 ? (int)$item['packs_per_ctn'] : 1;
                        $pcsPerPack  = (float)$item['pcs_per_pack'] > 0 ? (int)$item['pcs_per_pack'] : 1;
                        $ratioText = $pcsPerPack > 1 ? "1*{$packsPerCtn}*{$pcsPerPack}" : "1*{$packsPerCtn}";
                    ?>
                        <tr style="height: 23px;">
                            <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px;"><?= $sr++ ?></td>
                            <td class="text-start ps-1 fw-semibold text-truncate" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: left;">
                                <?= htmlspecialchars($item['product_name']) ?>
                            </td>
                            <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                                <?= $ratioText ?>
                            </td>
                            <td class="text-center fw-bold" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                                <?= $ctnQty > 0 ? format_qty($ctnQty) : '' ?>
                            </td>
                            <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                                <?= $packQty > 0 ? format_qty($packQty) : '-' ?>
                            </td>
                            <td class="text-center" style="border: 1px solid #000 !important; padding: 1px 2px; text-align: center;">
                                <?= $pcsQty > 0 ? format_qty($pcsQty) : '-' ?>
                            </td>
                            <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                                <?= number_format((float)$item['rate'], 0) ?><?= ($item['unit_symbol'] === 'Pcs' || (int)$item['unit_id'] === 4) ? ' <span style="font-size:8.5px;">/Pc</span>' : (((int)$item['unit_id'] === 3 || (int)$item['unit_id'] === 2 || in_array(strtolower($item['unit_symbol'] ?? ''), ['pck', 'pack', 'box'])) ? ' <span style="font-size:8.5px;">/Pack</span>' : '') ?>
                            </td>
                            <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                                <?= number_format((float)$item['line_total'], 0) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Pad with blank rows for consistent paper height like in physical books -->
                    <?php for ($padIdx = $printedRows; $padIdx < $padRows; $padIdx++): ?>
                        <tr style="height: 23px;">
                            <td style="border: 1px solid #000 !important; padding: 1px 2px;">&nbsp;</td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
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
                    <tr style="background-color: #c99c04 !important; color: #000 !important; border-top: 1.5px solid #000 !important; border-bottom: 1.5px solid #000 !important;" class="fw-bold">
                        <td colspan="3" class="text-center text-uppercase" style="border: 1px solid #000 !important; padding: 2px 5px; font-size: 11.5px; letter-spacing: 1px;">
                            Total Order
                        </td>
                        <td class="text-center" style="border: 1px solid #000 !important; padding: 2px 2px;">
                            <?= format_qty($totalCartons) ?> Ctns
                        </td>
                        <td class="text-center" style="border: 1px solid #000 !important; padding: 2px 2px;">
                            <?= $calcPacks > 0 ? format_qty($calcPacks) . ' Pck' : '-' ?>
                        </td>
                        <td class="text-center" style="border: 1px solid #000 !important; padding: 2px 2px;">
                            <?= format_qty($totalUnits) ?> Pcs
                        </td>
                        <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 2px 5px;">
                            Gross: <?= number_format((float)$order['gross_amount'], 0) ?>
                        </td>
                        <td class="text-end pe-1" style="border: 1px solid #000 !important; padding: 2px 5px; font-size: 12px;">
                            <?= number_format((float)$order['net_amount'], 0) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <?php if (!empty($order['remarks'])): ?>
                <div class="p-1 px-2 border border-dark rounded mb-2 small fw-bold" style="font-size: 10.5px;">
                    Remarks: <?= htmlspecialchars($order['remarks']) ?>
                </div>
            <?php endif; ?>

            <!-- Footer Signatures -->
            <div class="d-flex justify-content-between pt-4 small fw-bold" style="font-size: 10px; color: #000; margin-top: 15px;">
                <div>Order Booker: __________________</div>
                <div>Warehouse Supervisor: __________________</div>
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
