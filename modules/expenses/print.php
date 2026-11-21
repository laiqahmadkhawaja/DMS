<?php
/**
 * DMS - Distribution Management System
 * Dedicated Expense Voucher Print View
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('expenses_manage');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Invalid Expense Voucher ID');
}

$expense = db_fetch_one("
    SELECT 
        e.*,
        ec.category_name,
        ec.category_code,
        a.account_name as paid_from_name,
        a.account_code as paid_from_code,
        u.full_name as created_by_name
    FROM expenses e
    JOIN expense_categories ec ON e.expense_category_id = ec.id
    JOIN accounts a ON e.paid_from_account_id = a.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.id = ?
", [$id]);

if (!$expense) {
    exit('Expense voucher not found');
}

$jv = db_fetch_one("
    SELECT id, entry_number, entry_date 
    FROM journal_entries 
    WHERE reference_type = 'expense' AND reference_id = ?
", [$id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Expense #<?= htmlspecialchars($expense['expense_number']) ?></title>
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
            max-width: 760px;
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
                Expense #<?= htmlspecialchars($expense['expense_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars($expense['category_name']) ?></span>
            <span class="badge bg-danger">Paid: Rs. <?= number_format((float)$expense['amount'], 0) ?></span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Navigation Back Buttons -->
            <a href="<?= APP_URL ?>/modules/expenses/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Expense
            </a>
            <a href="<?= APP_URL ?>/modules/expenses/index.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-list-ul me-1"></i> Expenses List
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
                        POSTED
                    </span>
                </div>
                <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px;">
                    <?= CLIENT_NAME ?>
                </h4>
                <div class="fw-semibold text-dark" style="font-size: 11px; margin-top: 2px;">
                    03000673276 &nbsp;&bull;&nbsp; Jandiala Bhagwala G.T. Road Gujranwala
                </div>
                <div class="fw-bold text-uppercase mt-1" style="font-size: 13px; letter-spacing: 1px;">
                    PETTY CASH & EXPENSE VOUCHER
                </div>
            </div>

            <!-- Metadata Table -->
            <div class="metadata-table my-3">
                <table style="width: 100% !important; border-collapse: collapse !important; font-size: 11.5px; color: #000; line-height: 1.45;">
                    <tr>
                        <td style="width: 50%; vertical-align: top; padding: 0;">
                            <table class="table table-borderless table-sm mb-0 fw-bold" style="font-size: 11.5px; color: #000; width: auto; border-collapse: collapse !important;">
                                <tr>
                                    <td style="padding: 2px 8px 2px 0; white-space: nowrap; width: 105px;">VOUCHER NO:</td>
                                    <td style="padding: 2px 0; font-weight: bold;"><?= htmlspecialchars($expense['expense_number']) ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 2px 8px 2px 0; white-space: nowrap; width: 105px;">Expense Head:</td>
                                    <td style="padding: 2px 0; font-weight: bold;"><?= htmlspecialchars($expense['category_name']) ?> (<?= htmlspecialchars($expense['category_code']) ?>)</td>
                                </tr>
                                <?php if ($jv): ?>
                                <tr>
                                    <td style="padding: 2px 8px 2px 0; white-space: nowrap; width: 105px;">GL Journal JV:</td>
                                    <td style="padding: 2px 0; font-weight: bold; color: #1e40af;"><?= htmlspecialchars($jv['entry_number']) ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                        <td style="width: 50%; vertical-align: top; padding: 0;">
                            <table class="table table-borderless table-sm mb-0 fw-bold" style="font-size: 11.5px; color: #000; width: auto; margin-left: auto; border-collapse: collapse !important;">
                                <tr>
                                    <td style="padding: 2px 8px 2px 0; text-align: right; white-space: nowrap;">Voucher Date:</td>
                                    <td style="padding: 2px 0; text-align: right; white-space: nowrap;">
                                        <span class="date-badge"><?= date('d-m-Y', strtotime($expense['expense_date'])) ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 2px 8px 2px 0; text-align: right; white-space: nowrap;">Paid From:</td>
                                    <td style="padding: 2px 0; text-align: right; white-space: nowrap; font-weight: bold;">
                                        <?= htmlspecialchars($expense['paid_from_name']) ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Expense Detail Box -->
            <table class="table table-bordered table-sm my-3" style="width: 100% !important; border: 1.5px solid #000 !important; font-size: 12px; color: #000;">
                <tr style="background-color: #f8f9fa;">
                    <td style="width: 60%; padding: 10px 12px; border: 1px solid #000 !important;">
                        <div class="small text-muted text-uppercase fw-bold">Particulars / Description:</div>
                        <div class="fs-6 fw-bold text-dark mt-1"><?= htmlspecialchars($expense['description']) ?></div>
                    </td>
                    <td style="width: 40%; padding: 10px 12px; border: 1px solid #000 !important; text-align: right;" class="text-end">
                        <div class="small text-muted text-uppercase fw-bold">Disbursed Amount:</div>
                        <div class="fs-4 fw-bold text-danger mt-1">Rs. <?= number_format((float)$expense['amount'], 2) ?></div>
                    </td>
                </tr>
            </table>

            <!-- Amount in Words -->
            <div class="border border-dark p-2 mb-3" style="font-size: 11.5px;">
                <span class="text-muted fw-bold">Amount in Words:</span>
                <span class="fw-bold text-dark ms-1">Rupees <?= number_format((float)$expense['amount'], 2) ?> Only</span>
            </div>

            <!-- Signatures -->
            <div class="row pt-5 mt-4 text-center" style="font-size: 11px;">
                <div class="col-4">
                    <div class="border-top border-dark pt-1 fw-bold">
                        Prepared By: <?= htmlspecialchars($expense['created_by_name'] ?? 'Accountant') ?>
                    </div>
                </div>
                <div class="col-4">
                    <div class="border-top border-dark pt-1 fw-bold">
                        Approved By Manager
                    </div>
                </div>
                <div class="col-4">
                    <div class="border-top border-dark pt-1 fw-bold">
                        Receiver / Payee Signature
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>
</html>
