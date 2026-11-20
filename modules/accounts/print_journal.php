<?php
/**
 * DMS - Distribution Management System
 * Dedicated Journal Voucher (JV) Print View
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('journal_entries');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Invalid Journal Voucher ID');
}

$entry = db_fetch_one("
    SELECT je.*, u.full_name as created_by_name
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.id
    WHERE je.id = ?
", [$id]);

if (!$entry) {
    exit('Journal voucher not found');
}

$lines = db_fetch_all("
    SELECT 
        jel.*,
        a.account_code,
        a.account_name,
        a.account_type
    FROM journal_entry_lines jel
    JOIN accounts a ON jel.account_id = a.id
    WHERE jel.journal_entry_id = ?
    ORDER BY jel.debit DESC, jel.id ASC
", [$id]);

foreach ($lines as &$l) {
    $l['party_name'] = null;
    if (!empty($l['party_type']) && !empty($l['party_id'])) {
        if ($l['party_type'] === 'customer') {
            $l['party_name'] = db_fetch_val("SELECT shop_name FROM customers WHERE id = ?", [$l['party_id']]);
        } elseif ($l['party_type'] === 'supplier') {
            $l['party_name'] = db_fetch_val("SELECT supplier_name FROM suppliers WHERE id = ?", [$l['party_id']]);
        } elseif ($l['party_type'] === 'salesman') {
            $l['party_name'] = db_fetch_val("SELECT salesman_name FROM salesmen WHERE id = ?", [$l['party_id']]);
        }
    }
}
unset($l);

$padRows = max(6, count($lines));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print JV #<?= htmlspecialchars($entry['entry_number']) ?></title>
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
                <i class="bi bi-journal-text text-warning me-1"></i>
                JV #<?= htmlspecialchars($entry['entry_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars(str_replace('_', ' ', $entry['reference_type'])) ?></span>
            <span class="badge bg-success">Total: Rs. <?= number_format((float)$entry['total_amount'], 0) ?></span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Navigation Back Buttons -->
            <a href="<?= APP_URL ?>/modules/accounts/journal_view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Voucher
            </a>
            <a href="<?= APP_URL ?>/modules/accounts/journal_list.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-list-ul me-1"></i> Vouchers List
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
                        POSTED & BALANCED
                    </span>
                </div>
                <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 0.5px;">
                    <?= CLIENT_NAME ?>
                </h4>
                <div class="fw-semibold text-dark" style="font-size: 11px; margin-top: 2px;">
                    03000673276 &nbsp;&bull;&nbsp; Jandiala Bhagwala G.T. Road Gujranwala
                </div>
                <div class="fw-bold text-uppercase mt-1" style="font-size: 13px; letter-spacing: 1px;">
                    JOURNAL VOUCHER (JV)
                </div>
            </div>

            <!-- Metadata Table -->
            <div class="metadata-table my-3">
                <table class="table table-borderless table-sm mb-0 fw-bold" style="width: 100% !important; table-layout: fixed; border-collapse: collapse !important; font-size: 11.5px; color: #000; line-height: 1.45;">
                    <tbody>
                        <tr>
                            <td style="width: 18%; padding: 2px 3px;">JV NUMBER:</td>
                            <td style="width: 32%; padding: 2px 3px; font-weight: bold;"><?= htmlspecialchars($entry['entry_number']) ?></td>
                            <td style="width: 18%; padding: 2px 3px;" class="text-end">Posting Date:</td>
                            <td style="width: 32%; padding: 2px 3px;">
                                <span class="date-badge"><?= date('d-m-Y', strtotime($entry['entry_date'])) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 3px;">Transaction Source:</td>
                            <td style="padding: 2px 3px; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars(str_replace('_', ' ', $entry['reference_type'])) ?></td>
                            <td style="padding: 2px 3px;" class="text-end">Source Ref ID:</td>
                            <td style="padding: 2px 3px;">
                                <span class="fw-bold"><?= $entry['reference_id'] ? '#' . $entry['reference_id'] : 'Manual Journal' ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Narration / Memo -->
            <div class="border border-dark p-2 mb-3 bg-light" style="font-size: 11.5px;">
                <span class="text-muted fw-bold">Narration / Memo:</span>
                <span class="fw-bold text-dark ms-1"><?= htmlspecialchars($entry['narration']) ?></span>
            </div>

            <!-- Line Items Table -->
            <table class="table table-bordered table-sm mb-1" style="width: 100% !important; table-layout: fixed; border: 1.5px solid #000 !important; border-collapse: collapse !important; font-size: 11.5px; color: #000;">
                <thead>
                    <tr style="background-color: #f8f9fa; border-bottom: 1.5px solid #000 !important;" class="text-center fw-bold">
                        <th style="width: 15%; border: 1px solid #000 !important; padding: 3px 2px;">Account Code</th>
                        <th style="width: 35%; border: 1px solid #000 !important; padding: 3px 5px; text-align: left;" class="text-start ps-1">Account Title</th>
                        <th style="width: 20%; border: 1px solid #000 !important; padding: 3px 5px; text-align: left;" class="text-start ps-1">Party / Particulars</th>
                        <th style="width: 15%; border: 1px solid #000 !important; padding: 3px 5px; text-align: right;" class="text-end pe-1">Debit (Rs.)</th>
                        <th style="width: 15%; border: 1px solid #000 !important; padding: 3px 5px; text-align: right;" class="text-end pe-1">Credit (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $printedRows = 0;
                    $totalDebit = 0;
                    $totalCredit = 0;
                    foreach ($lines as $row): 
                        $printedRows++;
                        $totalDebit += (float)$row['debit'];
                        $totalCredit += (float)$row['credit'];
                    ?>
                        <tr style="height: 23px;">
                            <td class="text-center fw-bold" style="border: 1px solid #000 !important; padding: 1px 2px;"><?= htmlspecialchars($row['account_code']) ?></td>
                            <td class="text-start ps-1 fw-semibold text-truncate" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: left;">
                                <?= htmlspecialchars($row['account_name']) ?>
                            </td>
                            <td class="text-start ps-1 text-truncate" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: left;">
                                <?= htmlspecialchars($row['party_name'] ?: ($row['description'] ?: '—')) ?>
                            </td>
                            <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                                <?= $row['debit'] > 0 ? number_format((float)$row['debit'], 2) : '—' ?>
                            </td>
                            <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 1px 5px; text-align: right;">
                                <?= $row['credit'] > 0 ? number_format((float)$row['credit'], 2) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php for ($padIdx = $printedRows; $padIdx < $padRows; $padIdx++): ?>
                        <tr style="height: 23px;">
                            <td style="border: 1px solid #000 !important; padding: 1px 2px;">&nbsp;</td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                            <td style="border: 1px solid #000 !important; padding: 1px 5px;"></td>
                        </tr>
                    <?php endfor; ?>

                    <!-- Total Bar -->
                    <tr class="total-bar fw-bold" style="background-color: #fce18b !important; height: 26px; border: 1.5px solid #000 !important;">
                        <td colspan="3" class="text-center fw-bold" style="border: 1px solid #000 !important; padding: 2px 5px;">
                            TOTAL BALANCED AMOUNT
                        </td>
                        <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 2px 5px; text-align: right;">
                            <?= number_format($totalDebit, 2) ?>
                        </td>
                        <td class="text-end pe-1 fw-bold" style="border: 1px solid #000 !important; padding: 2px 5px; text-align: right;">
                            <?= number_format($totalCredit, 2) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Signatures -->
            <div class="row pt-5 mt-4 text-center" style="font-size: 11px;">
                <div class="col-4">
                    <div class="border-top border-dark pt-1 fw-bold">
                        Prepared By: <?= htmlspecialchars($entry['created_by_name'] ?? 'Accountant') ?>
                    </div>
                </div>
                <div class="col-4">
                    <div class="border-top border-dark pt-1 fw-bold">
                        Verified By Chief Accountant
                    </div>
                </div>
                <div class="col-4">
                    <div class="border-top border-dark pt-1 fw-bold">
                        Authorized Director
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>
</html>
