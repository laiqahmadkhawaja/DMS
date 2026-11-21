<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - Batch Printing for Entire Route / Load Sheet
 * Supports 1-up (Full Page), 2-up (Half A4), 3-up (Slip), and Duplicate modes
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('invoices_print');

$loadSheetId = isset($_GET['load_sheet_id']) ? (int)$_GET['load_sheet_id'] : 0;
$date = isset($_GET['date']) ? sanitize_input($_GET['date']) : '';
$layoutMode = isset($_GET['layout']) && in_array($_GET['layout'], ['1-up', '2-up', '3-up']) ? $_GET['layout'] : '2-up';
$duplicateCopies = isset($_GET['duplicate']) && $_GET['duplicate'] == '1';
$showCutLine = !isset($_GET['cutline']) || $_GET['cutline'] != '0';

$where = ["inv.status != 'cancelled'"];
$params = [];

if ($loadSheetId > 0) {
    $where[] = "inv.load_sheet_id = ?";
    $params[] = $loadSheetId;
} elseif (!empty($date)) {
    $where[] = "inv.invoice_date = ?";
    $params[] = $date;
} else {
    // Default to today if nothing provided
    $date = date('Y-m-d');
    $where[] = "inv.invoice_date = ?";
    $params[] = $date;
}

$whereClause = implode(" AND ", $where);

$invoices = db_fetch_all("
    SELECT 
        inv.*,
        c.shop_name as customer_name,
        c.phone as customer_phone,
        c.address as customer_address,
        a.area_name,
        b.booker_name,
        s.salesman_name,
        ls.load_number
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    LEFT JOIN salesmen s ON inv.salesman_id = s.id
    LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
    WHERE {$whereClause}
    ORDER BY a.area_name ASC, c.shop_name ASC, inv.id ASC
", $params);

if (empty($invoices)) {
    exit('<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;text-align:center;"><h3>No Invoices Found</h3><p>No active invoices match the selected criteria for printing.</p><a href="javascript:history.back()">Go Back</a></body></html>');
}

// Fetch all items for these invoices
$invIds = array_column($invoices, 'id');
$inPlaceholders = implode(',', array_fill(0, count($invIds), '?'));
$items = db_fetch_all("
    SELECT 
        sii.*,
        p.product_code,
        p.product_name,
        p.carton_qty as ctn_ratio,
        p.pack_qty as pack_ratio,
        u.unit_code,
        u.unit_name
    FROM sales_invoice_items sii
    JOIN products p ON sii.product_id = p.id
    JOIN units u ON sii.unit_id = u.id
    WHERE sii.invoice_id IN ({$inPlaceholders})
    ORDER BY sii.invoice_id ASC, sii.id ASC
", $invIds);

$itemsByInvoice = [];
foreach ($items as $item) {
    $itemsByInvoice[$item['invoice_id']][] = $item;
}

$totalAmount = array_sum(array_column($invoices, 'net_amount'));
$loadSheetNumber = $invoices[0]['load_number'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Print (<?= count($invoices) ?> Bills) - <?= CLIENT_NAME ?></title>
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

        /* Toolbar styles */
        .print-toolbar {
            background-color: #0f172a;
            color: #fff;
            padding: 10px 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        /* Container sheet preview */
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

        .copy-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 7px;
            border: 1.5px solid #000;
            background: #fff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cut-line {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 16px 0;
            color: #94a3b8;
            font-size: 11px;
            font-family: monospace;
            user-select: none;
        }

        .cut-scissors {
            font-size: 14px;
            margin: 0 6px;
        }

        .cut-text {
            overflow: hidden;
            white-space: nowrap;
        }

        /* Print media styles */
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
                page-break-after: always !important;
                page-break-inside: avoid !important;
                width: 100% !important;
                min-height: auto !important;
            }

            .a4-sheet:last-child {
                page-break-after: avoid !important;
            }

            .copy-badge, .date-badge, th, td, tr, .total-bar {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .cut-line {
                margin: 10px 0 !important;
                color: #555 !important;
            }
        }
    </style>
</head>
<body>
    <!-- Top Interactive Toolbar -->
    <div class="no-print print-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-6 fw-bold">
                <i class="bi bi-printer me-1 text-warning"></i>
                Batch Print: <?= count($invoices) ?> Bills
            </span>
            <?php if (!empty($loadSheetNumber)): ?>
                <span class="badge bg-primary">Load Sheet: <?= htmlspecialchars($loadSheetNumber) ?></span>
            <?php endif; ?>
            <span class="badge bg-light text-dark">
                Total: Rs. <?= number_format($totalAmount, 0) ?>
            </span>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Layout Selector -->
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn <?= $layoutMode === '1-up' ? 'btn-warning fw-bold' : 'btn-outline-light' ?>" onclick="setLayout('1-up')">
                    1-up (Full)
                </button>
                <button type="button" class="btn <?= $layoutMode === '2-up' ? 'btn-warning fw-bold' : 'btn-outline-light' ?>" onclick="setLayout('2-up')">
                    2-up (Half A4)
                </button>
                <button type="button" class="btn <?= $layoutMode === '3-up' ? 'btn-warning fw-bold' : 'btn-outline-light' ?>" onclick="setLayout('3-up')">
                    3-up (Slip)
                </button>
            </div>

            <!-- Duplicate Toggle -->
            <button type="button" class="btn btn-sm <?= $duplicateCopies ? 'btn-info text-white fw-bold' : 'btn-outline-light' ?>" onclick="toggleDuplicate()">
                <i class="bi bi-files me-1"></i> <?= $duplicateCopies ? 'Duplicate: ON' : 'Duplicate: OFF' ?>
            </button>

            <!-- Print Action -->
            <button onclick="window.print();" class="btn btn-sm btn-primary fw-bold px-3">
                <i class="bi bi-printer-fill me-1"></i> Print All Bills
            </button>

            <!-- Close -->
            <button onclick="window.close();" class="btn btn-sm btn-outline-light">
                <i class="bi bi-x me-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Printable Bill Sheets -->
    <?php require TEMPLATES_PATH . '/invoices/multi_bill_print.php'; ?>

    <script>
        function updateQueryParam(key, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(key, value);
            window.location.href = url.toString();
        }

        function setLayout(layout) {
            updateQueryParam('layout', layout);
        }

        function toggleDuplicate() {
            const current = <?= $duplicateCopies ? '1' : '0' ?>;
            updateQueryParam('duplicate', current === 1 ? '0' : '1');
        }
    </script>
</body>
</html>