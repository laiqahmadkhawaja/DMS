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

$fillFigures = isset($_GET['filled']) && $_GET['filled'] == '1';

$run = db_fetch_one("
    SELECT 
        dr.*,
        s.salesman_name,
        s.phone as salesman_phone,
        ls.load_number,
        u.full_name as creator_name
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    LEFT JOIN load_sheets ls ON dr.load_sheet_id = ls.id
    LEFT JOIN users u ON dr.created_by = u.id
    WHERE dr.id = ?
", [$id]);

if (!$run) {
    exit('Delivery run not found');
}

$bills = db_fetch_all("
    SELECT 
        drb.*,
        inv.invoice_number,
        inv.invoice_date,
        inv.net_amount,
        c.shop_name,
        c.phone as customer_phone,
        c.address as customer_address,
        a.area_name,
        b.booker_name
    FROM delivery_run_bills drb
    JOIN sales_invoices inv ON drb.invoice_id = inv.id
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    WHERE drb.delivery_run_id = ?
    ORDER BY drb.stop_sequence ASC, a.area_name ASC, c.shop_name ASC
", [$id]);
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