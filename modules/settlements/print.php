<?php
/**
 * DMS - Distribution Management System
 * Route Settlements - Standalone Print Settlement Sheet (DAILY SALE RECIVED + CREDIT)
 * Matches Client Photo 1 & Photo 3
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('settlements_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    exit('Invalid settlement ID');
}

$settlement = db_fetch_one("
    SELECT 
        st.*,
        s.salesman_name,
        s.phone as salesman_phone,
        dr.run_number,
        ls.load_number,
        u.full_name as creator_name,
        ru.full_name as reconciler_name
    FROM settlements st
    JOIN salesmen s ON st.salesman_id = s.id
    LEFT JOIN delivery_runs dr ON st.delivery_run_id = dr.id
    LEFT JOIN load_sheets ls ON st.load_sheet_id = ls.id
    LEFT JOIN users u ON st.created_by = u.id
    LEFT JOIN users ru ON st.reconciled_by = ru.id
    WHERE st.id = ?
", [$id]);

if (!$settlement) {
    exit('Settlement not found');
}

// Fetch settlement items
$items = db_fetch_all("
    SELECT 
        si.*,
        c.shop_name,
        c.customer_code,
        a.area_name,
        inv.invoice_number
    FROM settlement_items si
    JOIN customers c ON si.customer_id = c.id
    LEFT JOIN areas a ON c.area_id = a.id
    JOIN sales_invoices inv ON si.invoice_id = inv.id
    WHERE si.settlement_id = ?
    ORDER BY si.id ASC
", [$id]);

// Cash submission
$cashSub = db_fetch_one("SELECT * FROM cash_submissions WHERE settlement_id = ?", [$id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Settlement - <?= htmlspecialchars($settlement['settlement_number']) ?></title>
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
                <i class="bi bi-file-earmark-check text-warning me-1"></i>
                Settlement: <?= htmlspecialchars($settlement['settlement_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars($settlement['salesman_name']) ?></span>
            <span class="badge bg-light text-dark">Cash Handover: Rs. <?= number_format((float)$settlement['total_cash_received'], 0) ?></span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print();" class="btn btn-sm btn-primary fw-bold px-3">
                <i class="bi bi-printer-fill me-1"></i> Print Voucher (Ctrl+P)
            </button>
            <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Settlement
            </a>
            <button onclick="window.close();" class="btn btn-sm btn-outline-light">
                <i class="bi bi-x me-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Paper Container -->
    <div class="sheet-paper">
        <?php require TEMPLATES_PATH . '/settlements/settlement_print.php'; ?>
    </div>

    <script>
        if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>