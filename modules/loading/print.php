<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - Standalone Print Load Sheet (TOTAL STOCK ISSUE SUMMARY)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('loading_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    exit('Invalid load sheet ID');
}

$sheet = db_fetch_one("
    SELECT 
        ls.*,
        s.salesman_name,
        s.phone as salesman_phone,
        w.warehouse_name,
        u.full_name as creator_name,
        iu.full_name as issuer_name
    FROM load_sheets ls
    JOIN salesmen s ON ls.salesman_id = s.id
    JOIN warehouses w ON ls.warehouse_id = w.id
    LEFT JOIN users u ON ls.created_by = u.id
    LEFT JOIN users iu ON ls.issued_by = iu.id
    WHERE ls.id = ?
", [$id]);

if (!$sheet) {
    exit('Load sheet not found');
}

// Fetch items
$items = db_fetch_all("
    SELECT 
        lsi.*,
        p.product_code,
        p.product_name,
        p.carton_qty as ctn_ratio,
        c.category_name,
        u.unit_code,
        u.unit_name
    FROM load_sheet_items lsi
    JOIN products p ON lsi.product_id = p.id
    JOIN units u ON lsi.unit_id = u.id
    LEFT JOIN product_categories c ON p.category_id = c.id
    WHERE lsi.load_sheet_id = ?
    ORDER BY c.category_name ASC, p.product_name ASC
", [$id]);

// Fetch included orders to derive booker & route area info
$orders = db_fetch_all("
    SELECT 
        o.id,
        a.area_name,
        b.booker_name
    FROM load_sheet_orders lso
    JOIN orders o ON lso.order_id = o.id
    JOIN areas a ON o.area_id = a.id
    JOIN bookers b ON o.booker_id = b.id
    WHERE lso.load_sheet_id = ?
", [$id]);

$bookerNames = array_unique(array_filter(array_column($orders, 'booker_name')));
$bookersList = !empty($bookerNames) ? implode(', ', $bookerNames) : 'All Routes';

$areaNames = array_unique(array_filter(array_column($orders, 'area_name')));
$areasList = !empty($areaNames) ? implode(', ', $areaNames) : 'All Routes';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Issue Summary - <?= htmlspecialchars($sheet['load_number']) ?></title>
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
            max-width: 960px;
            margin: 25px auto;
            background: #fff;
            padding: 24px 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        @media print {
            @page {
                size: A4 landscape;
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

            th, td, tr, .date-badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
    <!-- Interactive Toolbar -->
    <div class="no-print print-toolbar d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-6 fw-bold">
                <i class="bi bi-truck text-warning me-1"></i>
                Stock Issue: <?= htmlspecialchars($sheet['load_number']) ?>
            </span>
            <span class="badge bg-secondary"><?= htmlspecialchars($sheet['salesman_name']) ?></span>
            <span class="badge bg-light text-dark"><?= count($items) ?> Products Dispatched</span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print();" class="btn btn-sm btn-primary fw-bold px-3">
                <i class="bi bi-printer-fill me-1"></i> Print Sheet (Ctrl+P)
            </button>
            <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Load Sheet
            </a>
            <button onclick="window.close();" class="btn btn-sm btn-outline-light">
                <i class="bi bi-x me-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Paper Container -->
    <div class="sheet-paper">
        <?php require TEMPLATES_PATH . '/loading_sheets/load_sheet_print.php'; ?>
    </div>

    <script>
        if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>