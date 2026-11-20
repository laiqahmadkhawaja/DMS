<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - Single Bill Printable Page (with 1-up and 2-up Duplicate options)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('invoices_print');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    exit('Invalid invoice ID');
}

$mode = isset($_GET['mode']) ? sanitize_input($_GET['mode']) : 'single'; // 'single' or 'duplicate'

$invoice = db_fetch_one("
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
    WHERE inv.id = ?
", [$id]);

if (!$invoice) {
    exit('Invoice not found');
}

$invoiceItems = db_fetch_all("
    SELECT 
        sii.*,
        p.product_code,
        p.product_name,
        p.carton_qty as ctn_ratio,
        u.unit_code,
        u.unit_name
    FROM sales_invoice_items sii
    JOIN products p ON sii.product_id = p.id
    JOIN units u ON sii.unit_id = u.id
    WHERE sii.invoice_id = ?
    ORDER BY sii.id ASC
", [$id]);

$invoices = [$invoice];
$itemsByInvoice = [$invoice['id'] => $invoiceItems];
$layoutMode = ($mode === 'duplicate') ? '2-up' : '1-up';
$duplicateCopies = ($mode === 'duplicate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Bill #<?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($invoice['customer_name']) ?></title>
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
                <i class="bi bi-receipt me-1 text-warning"></i>
                Bill #<?= htmlspecialchars($invoice['invoice_number']) ?>
            </span>
            <span class="badge bg-secondary">
                <?= htmlspecialchars($invoice['customer_name']) ?>
            </span>
            <span class="badge bg-success">
                Rs. <?= number_format((float)$invoice['net_amount'], 0) ?>
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Mode Switcher -->
            <div class="btn-group btn-group-sm" role="group">
                <a href="?id=<?= $id ?>&mode=single" class="btn <?= $mode === 'single' ? 'btn-warning text-dark fw-bold' : 'btn-outline-light' ?>">
                    <i class="bi bi-file-earmark me-1"></i> Single Page
                </a>
                <a href="?id=<?= $id ?>&mode=duplicate" class="btn <?= $mode === 'duplicate' ? 'btn-warning text-dark fw-bold' : 'btn-outline-light' ?>">
                    <i class="bi bi-files me-1"></i> 2-up Duplicate (Customer + Office)
                </a>
            </div>

            <!-- Navigation Back -->
            <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i> Back to Invoice
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

    <!-- Printable Bill Content -->
    <?php require TEMPLATES_PATH . '/invoices/multi_bill_print.php'; ?>

    <script>
        // Auto trigger print if requested via query param ?autoprint=1
        if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>
