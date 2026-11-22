<?php
/**
 * DMS - Distribution Management System
 * Delivery Runs - Standalone Print Recovery Sheet (DAILY SALE RECIVED + CREDIT)
 * Matches Client Photo 8
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('delivery_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    exit('Invalid delivery run ID');
}

$run = db_fetch_one("
    SELECT 
        dr.*,
        s.salesman_name,
        s.phone as salesman_phone,
        ls.load_number,
        a.area_name as run_area_name,
        u.full_name as creator_name
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    LEFT JOIN load_sheets ls ON dr.load_sheet_id = ls.id
    LEFT JOIN areas a ON dr.area_id = a.id
    LEFT JOIN users u ON dr.created_by = u.id
    WHERE dr.id = ?
", [$id]);

if (!$run) {
    exit('Delivery run not found');
}

$settlement = db_fetch_one("
    SELECT * FROM settlements 
    WHERE delivery_run_id = ? 
    ORDER BY id DESC LIMIT 1
", [$id]);

$isSettled = !empty($settlement) || $run['status'] === 'completed';
$fillFigures = isset($_GET['filled']) ? ($_GET['filled'] == '1') : $isSettled;

$bills = db_fetch_all("
    SELECT 
        drb.*,
        COALESCE(inv.invoice_number, drb.bill_number, CONCAT('REC-', COALESCE(c_direct.id, c_inv.id))) as invoice_number,
        inv.invoice_date,
        COALESCE(drb.bill_amount, inv.net_amount, 0) as net_amount,
        COALESCE(si.cash_amount, drb.cash_collected, inv.paid_amount, 0) as cash_amount,
        COALESCE(si.credit_amount, drb.credit_given, inv.credit_amount, 0) as credit_amount,
        COALESCE(si.return_amount, drb.return_value, inv.return_amount, 0) as return_amount,
        COALESCE(c_inv.shop_name, c_direct.shop_name) as shop_name,
        COALESCE(c_inv.current_balance, c_direct.current_balance, 0) as customer_current_balance,
        COALESCE(c_inv.phone, c_direct.phone) as customer_phone,
        COALESCE(c_inv.address, c_direct.address) as customer_address,
        COALESCE(a_inv.area_name, a_direct.area_name, a_run.area_name, '-') as area_name,
        COALESCE(b_inv.booker_name, b_direct.booker_name, '-') as booker_name
    FROM delivery_run_bills drb
    LEFT JOIN sales_invoices inv ON drb.invoice_id = inv.id
    LEFT JOIN settlement_items si ON (si.invoice_id = inv.id AND si.settlement_id = ?)
    LEFT JOIN customers c_inv ON inv.customer_id = c_inv.id
    LEFT JOIN customers c_direct ON drb.customer_id = c_direct.id
    LEFT JOIN areas a_inv ON inv.area_id = a_inv.id
    LEFT JOIN areas a_direct ON c_direct.area_id = a_direct.id
    LEFT JOIN delivery_runs dr ON drb.delivery_run_id = dr.id
    LEFT JOIN areas a_run ON dr.area_id = a_run.id
    LEFT JOIN bookers b_inv ON inv.booker_id = b_inv.id
    LEFT JOIN bookers b_direct ON c_direct.booker_id = b_direct.id
    WHERE drb.delivery_run_id = ?
    ORDER BY drb.stop_sequence ASC, drb.id ASC
", [$settlement ? $settlement['id'] : 0, $id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Run Sheet - <?= htmlspecialchars($run['run_number']) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/icons/bootstrap-icons.css">
    <style>
        body {
            background-color: #f1f3f5;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            margin: 0;
            padding: 0;
        }

        .print-toolbar {
            background-color: #1a2a3a;
            color: #fff;
            padding: 10px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .sheet-paper {
            max-width: 900px;
            margin: 25px auto;
            background: #fff;
            padding: 24px 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 6mm;
            }

            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .no-print, .print-toolbar {
                display: none !important;
            }

            .sheet-paper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            th, td, tr {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
    <!-- Interactive Toolbar -->
    <div class="no-print print-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-6 fw-bold">
                <i class="bi bi-clipboard-check text-warning me-1"></i>
                Field Sheet: <?= htmlspecialchars($run['run_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars($run['salesman_name']) ?></span>
            <span class="badge bg-light text-dark"><?= count($bills) ?> Customer Bills</span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Format Toggle: Blank for field vs Filled figures -->
            <a href="?id=<?= $id ?>&filled=<?= $fillFigures ? '0' : '1' ?>" class="btn btn-sm <?= $fillFigures ? 'btn-info text-white fw-bold' : 'btn-outline-light' ?>">
                <i class="bi bi-pencil me-1"></i> <?= $fillFigures ? 'Figures: Filled' : 'Mode: Field Blank Form' ?>
            </a>

            <button onclick="window.print();" class="btn btn-sm btn-primary fw-bold px-3">
                <i class="bi bi-printer-fill me-1"></i> Print Sheet (Ctrl+P)
            </button>
            <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Run
            </a>
            <button onclick="window.close();" class="btn btn-sm btn-outline-light">
                <i class="bi bi-x me-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Paper Container -->
    <div class="sheet-paper">
        <?php require TEMPLATES_PATH . '/delivery_sheets/delivery_print.php'; ?>
    </div>

    <script>
        if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>