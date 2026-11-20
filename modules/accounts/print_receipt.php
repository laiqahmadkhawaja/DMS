<?php
/**
 * DMS - Distribution Management System
 * Dedicated Customer Receipt Slip Print View
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('accounts_view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Invalid Payment ID');
}

$payment = db_fetch_one("
    SELECT cp.*, c.shop_name, c.customer_code, c.phone as customer_phone, c.address as customer_address,
           a.area_name, b.booker_name, u.full_name as creator_name
    FROM customer_payments cp
    JOIN customers c ON c.id = cp.customer_id
    LEFT JOIN areas a ON a.id = c.area_id
    LEFT JOIN bookers b ON b.id = c.booker_id
    LEFT JOIN users u ON u.id = cp.created_by
    WHERE cp.id = ?
", [$id]);

if (!$payment) {
    exit('Receipt record not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Receipt #<?= htmlspecialchars($payment['payment_number']) ?> - <?= htmlspecialchars($payment['shop_name']) ?></title>
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
            max-width: 720px;
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
                margin: 8mm 10mm;
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
                border: 1px solid #000 !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 4mm 6mm !important;
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
                <i class="bi bi-wallet2 text-warning me-1"></i>
                Receipt #<?= htmlspecialchars($payment['payment_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars($payment['shop_name']) ?></span>
            <span class="badge bg-success">Received: Rs. <?= number_format((float)$payment['amount'], 0) ?></span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Navigation Back Buttons -->
            <a href="<?= APP_URL ?>/modules/accounts/view_customer_payment.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Receipt
            </a>
            <a href="<?= APP_URL ?>/modules/accounts/customer_payments.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-list-ul me-1"></i> Receipts List
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
                        CLEARED
                    </span>
                </div>
                <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px;">
                    <?= CLIENT_NAME ?>
                </h4>
                <div class="fw-semibold text-dark" style="font-size: 11px; margin-top: 2px;">
                    03000673276 &nbsp;&bull;&nbsp; Jandiala Bhagwala G.T. Road Gujranwala
                </div>
                <div class="fw-bold text-uppercase mt-1" style="font-size: 13px; letter-spacing: 1px;">
                    Official Cash / Payment Receipt Voucher
                </div>
            </div>

            <!-- Metadata Table -->
            <div class="metadata-table my-3">
                <table class="table table-bordered table-sm mb-0" style="width: 100% !important; border: 1.5px solid #000 !important; border-collapse: collapse !important; font-size: 12px; color: #000;">
                    <tbody>
                        <tr>
                            <td style="width: 20%; background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">RECEIPT NO.</td>
                            <td style="width: 30%; border: 1px solid #000; font-weight: bold;"><?= htmlspecialchars($payment['payment_number']) ?></td>
                            <td style="width: 20%; background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">RECEIPT DATE</td>
                            <td style="width: 30%; border: 1px solid #000;">
                                <span class="date-badge"><?= date('d-m-Y', strtotime($payment['payment_date'])) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">CUSTOMER SHOP</td>
                            <td colspan="3" style="border: 1px solid #000; font-weight: bold;">
                                <?= htmlspecialchars($payment['shop_name']) ?> (<?= htmlspecialchars($payment['customer_code']) ?>)
                                &bull; <?= htmlspecialchars($payment['customer_address']) ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">PAYMENT MODE</td>
                            <td style="border: 1px solid #000; text-transform: uppercase; font-weight: bold;">
                                <?= htmlspecialchars($payment['payment_method']) ?>
                                <?= !empty($payment['reference_number']) ? ' (Ref: ' . htmlspecialchars($payment['reference_number']) . ')' : '' ?>
                            </td>
                            <td style="background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">BOOKER / ROUTE</td>
                            <td style="border: 1px solid #000;">
                                <?= htmlspecialchars($payment['booker_name'] ?: 'Office Direct') ?> (<?= htmlspecialchars($payment['area_name'] ?: 'General') ?>)
                            </td>
                        </tr>
                        <tr>
                            <td style="background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">AMOUNT RECEIVED</td>
                            <td colspan="3" style="border: 1px solid #000; font-size: 16px; font-weight: bold; color: #000; background-color: #f8fafc;">
                                Rs. <?= number_format((float)$payment['amount'], 2) ?>
                            </td>
                        </tr>
                        <?php if (!empty($payment['remarks'])): ?>
                            <tr>
                                <td style="background-color: #f8f9fa; font-weight: bold; border: 1px solid #000;">REMARKS / NOTES</td>
                                <td colspan="3" style="border: 1px solid #000;"><?= htmlspecialchars($payment['remarks']) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Signatures -->
            <div class="d-flex justify-content-between pt-5 small fw-bold" style="font-size: 10px; color: #000; margin-top: 35px;">
                <div>Received By: <?= htmlspecialchars($payment['creator_name'] ?? 'Cashier') ?></div>
                <div>Customer Signature: __________________</div>
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
