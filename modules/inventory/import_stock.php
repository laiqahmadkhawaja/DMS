<?php
/**
 * DMS - Distribution Management System
 * Inventory - First-Time Opening Stock Excel & CSV Importer
 * 
 * Supports:
 * 1. Downloading Blank Excel/CSV Template
 * 2. Downloading Catalog Pre-filled Excel Template (with all active products & packaging formulas)
 * 3. Bulk Upload & Validation of Opening Stock in Cartons, Packs, and Pieces
 * 4. Atomic stock_movements recording & stock_balances synchronization
 * 5. Automated General Ledger Opening Balance posting (Inventory Asset 1030 vs Owner Capital 3010)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
if (!has_permission('inventory_adjust') && !has_permission('excel_import')) {
    require_permission('inventory_adjust');
}

$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    die("Composer vendor autoload is missing. Please run composer install.");
}
require_once $vendorAutoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$pageTitle = 'Import Opening Stock from Excel / CSV — ' . APP_NAME;
$activeMenu = 'import_stock';

// -------------------------------------------------------------------------
// 1. TEMPLATE DOWNLOAD ACTIONS
// -------------------------------------------------------------------------

// A. Download Blank Template
if (isset($_GET['action']) && $_GET['action'] === 'download_blank_template') {
    $format = strtolower(clean_input($_GET['format'] ?? 'xlsx'));
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Opening Stock');

    $headers = [
        'A1' => 'Product Code*',
        'B1' => 'Product Name',
        'C1' => 'Cartons (Ctn)',
        'D1' => 'Packs (Pck)',
        'E1' => 'Loose Pieces (Pcs)',
        'F1' => 'Cost Rate / Carton (Optional)'
    ];

    foreach ($headers as $cell => $text) {
        $sheet->setCellValue($cell, $text);
    }

    // Example sample rows
    $sheet->setCellValue('A2', 'PRD-0001');
    $sheet->setCellValue('B2', 'Booster Candy (Sample)');
    $sheet->setCellValue('C2', 5);
    $sheet->setCellValue('D2', 4);
    $sheet->setCellValue('E2', 20);
    $sheet->setCellValue('F2', 6500.00);

    $sheet->setCellValue('A3', 'HASH-001');
    $sheet->setCellValue('B3', 'Hashim Chocolate Wafer (Sample)');
    $sheet->setCellValue('C3', 12);
    $sheet->setCellValue('D3', 0);
    $sheet->setCellValue('E3', 0);
    $sheet->setCellValue('F3', 4200.00);

    // Styling
    $headerRange = 'A1:F1';
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1B3B6F'); // DMS Navy
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    if (ob_get_length()) ob_end_clean();

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="opening_stock_template.csv"');
        $writer = new Csv($spreadsheet);
        $writer->save('php://output');
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="opening_stock_template.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }
    exit;
}

// B. Download Pre-filled Template with All Catalog Products
if (isset($_GET['action']) && $_GET['action'] === 'export_catalog_template') {
    $products = db_fetch_all("
        SELECT 
            p.id, p.product_code, p.barcode, p.product_name,
            c.category_name, b.brand_name,
            p.carton_qty, p.pack_qty, p.packing_description,
            p.purchase_rate, p.purchase_carton_rate, p.purchase_piece_rate,
            COALESCE(sb.saleable_qty, 0) as current_stock_pieces
        FROM products p
        LEFT JOIN product_categories c ON c.id = p.category_id
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = 1
        WHERE p.is_deleted = 0 AND p.is_active = 1
        ORDER BY c.category_name ASC, p.product_name ASC
    ");

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Physical Stock Count');

    $headers = [
        'A1' => 'Product Code*',
        'B1' => 'Product Name',
        'C1' => 'Category',
        'D1' => 'Brand',
        'E1' => 'Packaging Formula',
        'F1' => 'Cartons (Ctn)',
        'G1' => 'Packs (Pck)',
        'H1' => 'Loose Pieces (Pcs)',
        'I1' => 'Carton Cost Rate (PKR)',
        'J1' => 'Current Stock (Reference)'
    ];

    foreach ($headers as $cell => $text) {
        $sheet->setCellValue($cell, $text);
    }

    $rowIdx = 2;
    foreach ($products as $p) {
        $ratios = get_product_packaging_ratios($p);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $packFormula = "1 Ctn = {$packsPerCtn} Pck × {$pcsPerPack} Pcs ({$pcsPerCtn} Pcs/Ctn)";
        $purCartonCost = (float)($p['purchase_carton_rate'] > 0 ? $p['purchase_carton_rate'] : $p['purchase_rate']);

        $currentBk = breakdown_base_pieces((float)$p['current_stock_pieces'], $packsPerCtn, $pcsPerPack);

        $sheet->setCellValue('A' . $rowIdx, $p['product_code']);
        $sheet->setCellValue('B' . $rowIdx, $p['product_name']);
        $sheet->setCellValue('C' . $rowIdx, $p['category_name'] ?? 'General');
        $sheet->setCellValue('D' . $rowIdx, $p['brand_name'] ?? '-');
        $sheet->setCellValue('E' . $rowIdx, $packFormula);
        $sheet->setCellValue('F' . $rowIdx, 0); // Cartons to fill
        $sheet->setCellValue('G' . $rowIdx, 0); // Packs to fill
        $sheet->setCellValue('H' . $rowIdx, 0); // Loose pieces to fill
        $sheet->setCellValue('I' . $rowIdx, $purCartonCost);
        $sheet->setCellValue('J' . $rowIdx, $currentBk['formatted']);

        $rowIdx++;
    }

    // Styling
    $headerRange = 'A1:J1';
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0D6EFD'); // Primary Blue
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    // Light green highlight on editable count columns F, G, H
    $editColsRange = 'F1:H1';
    $sheet->getStyle($editColsRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('198754'); // Green for input columns

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="opening_stock_catalog_' . date('Ymd_His') . '.xlsx"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// -------------------------------------------------------------------------
// 2. PROCESS UPLOADED EXCEL / CSV FILE
// -------------------------------------------------------------------------

$errors = [];
$warnings = [];
$successMessage = '';
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_stock_import') {
    $warehouseId  = (int)($_POST['warehouse_id'] ?? 1);
    $movementDate = clean_input($_POST['movement_date'] ?? date('Y-m-d'));
    $importMode   = clean_input($_POST['import_mode'] ?? 'replace'); // 'replace' (set opening) or 'add' (increase stock)
    $postGL       = isset($_POST['post_gl']) && $_POST['post_gl'] === '1';

    // Verify warehouse
    $wh = db_fetch_one("SELECT id, warehouse_name FROM warehouses WHERE id = ? AND is_active = 1", [$warehouseId]);
    if (!$wh) {
        $errors[] = "Selected warehouse does not exist or is inactive.";
    }

    if (!isset($_FILES['stock_file']) || $_FILES['stock_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a valid Excel (.xlsx / .xls) or CSV (.csv) file to upload.";
    }

    if (empty($errors)) {
        $uploadedFile = $_FILES['stock_file']['tmp_name'];
        $origName = $_FILES['stock_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $errors[] = "Unsupported file type. Please upload a Microsoft Excel (.xlsx/.xls) or CSV (.csv) file.";
        } else {
            try {
                $spreadsheet = IOFactory::load($uploadedFile);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();

                if (count($rows) < 2) {
                    throw new Exception("The uploaded file does not contain any data rows below the header.");
                }

                $headerRow = array_map(function($h) {
                    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$h)));
                }, $rows[0]);

                // Auto-detect column indexes
                $colMap = [
                    'code'    => null,
                    'name'    => null,
                    'cartons' => null,
                    'packs'   => null,
                    'pieces'  => null,
                    'cost'    => null
                ];

                foreach ($headerRow as $idx => $headerName) {
                    if (strpos($headerName, 'code') !== false || strpos($headerName, 'sku') !== false || strpos($headerName, 'barcode') !== false || strpos($headerName, 'itemcode') !== false) {
                        if ($colMap['code'] === null) $colMap['code'] = $idx;
                    } elseif (strpos($headerName, 'name') !== false || strpos($headerName, 'product') !== false || strpos($headerName, 'item') !== false) {
                        if ($colMap['name'] === null) $colMap['name'] = $idx;
                    } elseif (strpos($headerName, 'carton') !== false || strpos($headerName, 'ctn') !== false) {
                        if ($colMap['cartons'] === null) $colMap['cartons'] = $idx;
                    } elseif (strpos($headerName, 'pack') !== false || strpos($headerName, 'pck') !== false) {
                        if ($colMap['packs'] === null) $colMap['packs'] = $idx;
                    } elseif (strpos($headerName, 'piece') !== false || strpos($headerName, 'pcs') !== false || strpos($headerName, 'unit') !== false || strpos($headerName, 'loose') !== false) {
                        if ($colMap['pieces'] === null) $colMap['pieces'] = $idx;
                    } elseif (strpos($headerName, 'cost') !== false || strpos($headerName, 'rate') !== false || strpos($headerName, 'purchase') !== false) {
                        if ($colMap['cost'] === null) $colMap['cost'] = $idx;
                    }
                }

                // Positional fallbacks if headers weren't named standard
                if ($colMap['code'] === null) $colMap['code'] = 0;
                if ($colMap['cartons'] === null) $colMap['cartons'] = 2;
                if ($colMap['packs'] === null) $colMap['packs'] = 3;
                if ($colMap['pieces'] === null) $colMap['pieces'] = 4;
                if ($colMap['cost'] === null && isset($rows[0][5])) $colMap['cost'] = 5;

                // Load all existing products into memory for lightning-fast matching
                $prodList = db_fetch_all("
                    SELECT 
                        id, product_code, barcode, product_name, 
                        carton_qty, pack_qty, base_unit_id,
                        purchase_rate, purchase_carton_rate, purchase_piece_rate
                    FROM products 
                    WHERE is_deleted = 0
                ");
                $prodByCode = [];
                foreach ($prodList as $p) {
                    $prodByCode[strtoupper(trim($p['product_code']))] = $p;
                    if (!empty($p['barcode'])) {
                        $prodByCode[strtoupper(trim($p['barcode']))] = $p;
                    }
                }

                // Begin Database Transaction
                db()->beginTransaction();

                $processedItems = [];
                $skippedRows = 0;
                $totalBaseUnits = 0.0;
                $totalCartons = 0.0;
                $totalPacks = 0.0;
                $totalLoosePieces = 0.0;
                $totalInventoryValuation = 0.0;

                for ($i = 1; $i < count($rows); $i++) {
                    $rowNum = $i + 1;
                    $r = $rows[$i];

                    $rawCode = strtoupper(trim((string)($r[$colMap['code']] ?? '')));
                    if (empty($rawCode)) {
                        $skippedRows++;
                        continue;
                    }

                    if (!isset($prodByCode[$rawCode])) {
                        $warnings[] = "Row #{$rowNum}: Product code '{$rawCode}' not found in catalog. Skipped.";
                        $skippedRows++;
                        continue;
                    }

                    $prod = $prodByCode[$rawCode];
                    $productId = (int)$prod['id'];

                    $ctnQty  = max(0, (float)($r[$colMap['cartons']] ?? 0));
                    $packQty = max(0, (float)($r[$colMap['packs']] ?? 0));
                    $pcsQty  = max(0, (float)($r[$colMap['pieces']] ?? 0));
                    $rawCost = isset($colMap['cost']) && isset($r[$colMap['cost']]) ? (float)$r[$colMap['cost']] : 0.0;

                    $ratios = get_product_packaging_ratios($prod);
                    $packsPerCtn = $ratios['packs_per_ctn'];
                    $pcsPerPack  = $ratios['pcs_per_pack'];
                    $pcsPerCtn   = $ratios['pcs_per_ctn'];

                    $basePieces = calculate_base_pieces($ctnQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);

                    // Skip row if zero quantity and mode is add
                    if ($basePieces <= 0 && $importMode === 'add') {
                        $skippedRows++;
                        continue;
                    }

                    // Determine unit piece cost
                    if ($rawCost > 0) {
                        // If user entered carton rate or piece rate
                        if ($rawCost > ($prod['purchase_piece_rate'] * 2) && $pcsPerCtn > 1) {
                            $pieceCost = round($rawCost / $pcsPerCtn, 6);
                        } else {
                            $pieceCost = round($rawCost, 6);
                        }
                    } else {
                        if ((float)$prod['purchase_piece_rate'] > 0) {
                            $pieceCost = (float)$prod['purchase_piece_rate'];
                        } elseif ((float)$prod['purchase_rate'] > 0) {
                            $pieceCost = round((float)$prod['purchase_rate'] / $pcsPerCtn, 6);
                        } else {
                            $pieceCost = 0.0;
                        }
                    }

                    $lineTotalCost = round($basePieces * $pieceCost, 2);
                    $breakdown = breakdown_base_pieces($basePieces, $packsPerCtn, $pcsPerPack);

                    // Process Stock Movement & Balance
                    if ($importMode === 'replace') {
                        // Check existing stock balance for this product & warehouse
                        $existingBal = db_fetch_one("
                            SELECT id, saleable_qty, total_qty 
                            FROM stock_balances 
                            WHERE warehouse_id = ? AND product_id = ?
                        ", [$warehouseId, $productId]);

                        $currentSaleable = $existingBal ? (float)$existingBal['saleable_qty'] : 0.0;
                        $diffPieces = $basePieces - $currentSaleable;

                        // Delete any prior opening stock movements if resetting fresh opening
                        db()->prepare("
                            DELETE FROM stock_movements 
                            WHERE warehouse_id = ? AND product_id = ? AND movement_type = 'opening_stock'
                        ")->execute([$warehouseId, $productId]);

                        // Record opening movement
                        record_stock_movement([
                            'product_id'     => $productId,
                            'warehouse_id'   => $warehouseId,
                            'movement_type'  => 'opening_stock',
                            'reference_type' => 'opening',
                            'reference_id'   => null,
                            'qty_in'         => $basePieces,
                            'qty_out'        => 0,
                            'unit_cost'      => $pieceCost,
                            'total_cost'     => $lineTotalCost,
                            'remarks'        => "Initial Opening Stock Import [{$breakdown['formatted']}]",
                            'condition_type' => 'saleable'
                        ]);

                        // Ensure stock balance matches exact opening quantity
                        db()->prepare("
                            UPDATE stock_balances 
                            SET saleable_qty = ?, total_qty = ?, avg_unit_cost = ?, total_value = ?, last_updated_at = NOW()
                            WHERE warehouse_id = ? AND product_id = ?
                        ")->execute([$basePieces, $basePieces, $pieceCost, $lineTotalCost, $warehouseId, $productId]);

                    } else {
                        // Mode is 'add' (incremental addition)
                        record_stock_movement([
                            'product_id'     => $productId,
                            'warehouse_id'   => $warehouseId,
                            'movement_type'  => 'opening_stock',
                            'reference_type' => 'opening',
                            'reference_id'   => null,
                            'qty_in'         => $basePieces,
                            'qty_out'        => 0,
                            'unit_cost'      => $pieceCost,
                            'total_cost'     => $lineTotalCost,
                            'remarks'        => "Opening Stock Additional Import [{$breakdown['formatted']}]",
                            'condition_type' => 'saleable'
                        ]);
                    }

                    $totalBaseUnits          += $basePieces;
                    $totalCartons            += $ctnQty;
                    $totalPacks              += $packQty;
                    $totalLoosePieces        += $pcsQty;
                    $totalInventoryValuation += $lineTotalCost;

                    $processedItems[] = [
                        'code'           => $prod['product_code'],
                        'name'           => $prod['product_name'],
                        'cartons'        => $ctnQty,
                        'packs'          => $packQty,
                        'pieces'         => $pcsQty,
                        'total_units'    => $basePieces,
                        'piece_cost'     => $pieceCost,
                        'total_cost'     => $lineTotalCost,
                        'breakdown_text' => $breakdown['formatted']
                    ];
                }

                if (empty($processedItems)) {
                    throw new Exception("No valid stock entries could be imported. Please verify product codes in the spreadsheet.");
                }

                // Post General Ledger Opening Voucher
                $glEntryId = null;
                if ($postGL && $totalInventoryValuation > 0) {
                    $invAccount = db_fetch_one("SELECT id FROM accounts WHERE account_code = '1030' LIMIT 1");
                    $equityAccount = db_fetch_one("SELECT id FROM accounts WHERE account_code = '3010' LIMIT 1");

                    if ($invAccount && $equityAccount) {
                        $glEntryId = post_journal_entry([
                            'entry_date'     => $movementDate,
                            'reference_type' => 'opening',
                            'reference_id'   => null,
                            'narration'      => "Opening Inventory Import - {$wh['warehouse_name']} (" . count($processedItems) . " SKUs)"
                        ], [
                            [
                                'account_id'  => (int)$invAccount['id'],
                                'debit'       => $totalInventoryValuation,
                                'credit'      => 0,
                                'description' => "Opening Stock Asset - Warehouse: {$wh['warehouse_name']}"
                            ],
                            [
                                'account_id'  => (int)$equityAccount['id'],
                                'debit'       => 0,
                                'credit'      => $totalInventoryValuation,
                                'description' => "Owner Capital / Opening Stock Equity Balance"
                            ]
                        ]);
                    }
                }

                db()->commit();

                log_audit('import_opening_stock', 'inventory', $warehouseId, "Imported opening stock for " . count($processedItems) . " items. Valuation: Rs. " . number_format($totalInventoryValuation, 2));

                $importResults = [
                    'warehouse_name'   => $wh['warehouse_name'],
                    'date'             => $movementDate,
                    'total_skus'       => count($processedItems),
                    'total_cartons'    => $totalCartons,
                    'total_packs'      => $totalPacks,
                    'total_loose'      => $totalLoosePieces,
                    'total_units'      => $totalBaseUnits,
                    'total_valuation'  => $totalInventoryValuation,
                    'skipped_rows'     => $skippedRows,
                    'gl_entry_id'      => $glEntryId,
                    'items'            => $processedItems
                ];

                $successMessage = "Opening stock successfully imported for " . count($processedItems) . " products in {$wh['warehouse_name']}! Total stock valuation: " . format_currency($totalInventoryValuation);

            } catch (Exception $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $errors[] = "Import failed: " . $e->getMessage();
            }
        }
    }
}

// Warehouses list for dropdown
$warehouses = db_fetch_all("SELECT id, warehouse_code, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC");

// Products count and current total valuation for overview
$totalActiveProducts = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0 AND is_active = 1");
$currentStockCount   = (int)db_fetch_val("SELECT COUNT(DISTINCT product_id) FROM stock_balances WHERE saleable_qty > 0");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom gap-2">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-file-earmark-arrow-up text-success me-2"></i>First-Time Opening Stock Excel Import
                </h4>
                <p class="text-muted small mb-0">Bulk initialize warehouse physical stock using Microsoft Excel (.xlsx/.xls) or CSV with automatic 3-tier packaging conversion (Carton &rarr; Pack &rarr; Pieces).</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Stock Overview
                </a>
                <a href="<?= APP_URL ?>/modules/inventory/adjust.php" class="btn btn-sm btn-outline-warning text-dark">
                    <i class="bi bi-sliders me-1"></i> Manual Stock Audit
                </a>
                <a href="<?= APP_URL ?>/modules/products/bulk_prices.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-currency-dollar me-1"></i> Bulk Price Setting
                </a>
            </div>
        </div>

        <!-- Notification Alerts -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <h6 class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Errors Encountered:</h6>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                <h6 class="fw-bold mb-1"><i class="bi bi-info-circle-fill me-2"></i>Warnings / Skipped Lines:</h6>
                <ul class="mb-0 ps-3 small" style="max-height: 150px; overflow-y: auto;">
                    <?php foreach ($warnings as $warn): ?>
                        <li><?= e($warn) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <h5 class="fw-bold mb-1"><i class="bi bi-check-circle-fill me-2"></i>Success!</h5>
                <div><?= e($successMessage) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Import Summary Banner (When completed) -->
        <?php if ($importResults): ?>
            <div class="card border-0 shadow-sm mb-4 bg-light border-start border-success border-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-success mb-0">
                            <i class="bi bi-clipboard2-check-fill me-2"></i>Stock Import Completed Successfully
                        </h5>
                        <span class="badge bg-success fs-6"><?= e($importResults['warehouse_name']) ?> | <?= e($importResults['date']) ?></span>
                    </div>

                    <div class="row g-3 text-center">
                        <div class="col-md-3">
                            <div class="p-3 bg-white rounded shadow-sm">
                                <div class="text-muted small text-uppercase fw-bold">Total SKUs Updated</div>
                                <div class="fs-3 fw-bold text-navy"><?= number_format($importResults['total_skus']) ?></div>
                                <div class="small text-muted"><?= $importResults['skipped_rows'] ?> skipped / empty rows</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 bg-white rounded shadow-sm">
                                <div class="text-muted small text-uppercase fw-bold">Cartons + Packs Count</div>
                                <div class="fs-3 fw-bold text-primary">
                                    <?= number_format($importResults['total_cartons']) ?> <small class="fs-6 text-muted">Ctns</small>
                                    + <?= number_format($importResults['total_packs']) ?> <small class="fs-6 text-muted">Pcks</small>
                                </div>
                                <div class="small text-muted">+ <?= number_format($importResults['total_loose']) ?> loose pieces</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 bg-white rounded shadow-sm">
                                <div class="text-muted small text-uppercase fw-bold">Total Base Units (Pieces)</div>
                                <div class="fs-3 fw-bold text-dark"><?= number_format($importResults['total_units']) ?></div>
                                <div class="small text-success">Accurately mapped to packaging</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 bg-white rounded shadow-sm">
                                <div class="text-muted small text-uppercase fw-bold">Total Stock Valuation</div>
                                <div class="fs-3 fw-bold text-success"><?= format_currency($importResults['total_valuation']) ?></div>
                                <div class="small text-muted">
                                    <?= $importResults['gl_entry_id'] ? '<span class="text-success"><i class="bi bi-check2"></i> Posted to GL #' . $importResults['gl_entry_id'] . '</span>' : 'Unposted GL' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <a href="<?= APP_URL ?>/modules/inventory/index.php" class="btn btn-primary btn-sm">
                            <i class="bi bi-box-seam me-1"></i> View Live Inventory Overview
                        </a>
                        <a href="<?= APP_URL ?>/modules/inventory/ledger.php" class="btn btn-outline-primary btn-sm ms-2">
                            <i class="bi bi-journal-text me-1"></i> View Stock Movement Ledger
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- Left Column: Upload Form -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold text-navy">
                            <i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Upload Opening Stock Spreadsheet
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="process_stock_import">

                            <!-- Target Warehouse -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">
                                    1. Target Warehouse <span class="text-danger">*</span>
                                </label>
                                <select name="warehouse_id" class="form-select form-select-lg" required>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <option value="<?= $wh['id'] ?>">
                                            <?= e($wh['warehouse_name']) ?> (<?= e($wh['warehouse_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Choose the physical warehouse receiving this opening stock balance.</div>
                            </div>

                            <!-- Inventory Date -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-dark">
                                        2. Stock Inventory Date <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" name="movement_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    <div class="form-text">Accounting & ledger balance effective date.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-dark">
                                        3. Import Mode <span class="text-danger">*</span>
                                    </label>
                                    <select name="import_mode" class="form-select">
                                        <option value="replace" selected>Set as Initial Opening Stock (Replace)</option>
                                        <option value="add">Add to Existing Warehouse Stock</option>
                                    </select>
                                    <div class="form-text">Use <strong>Replace</strong> for first-time onboarding.</div>
                                </div>
                            </div>

                            <!-- Excel File Upload -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold text-dark">
                                    4. Choose Excel / CSV File <span class="text-danger">*</span>
                                </label>
                                <div class="input-group input-group-lg">
                                    <input type="file" name="stock_file" class="form-control" accept=".xlsx, .xls, .csv" required>
                                </div>
                                <div class="form-text">
                                    Supported formats: Microsoft Excel (<strong>.xlsx</strong>, <strong>.xls</strong>) or Comma-Separated Values (<strong>.csv</strong>).
                                </div>
                            </div>

                            <!-- Accounting Post Checkbox -->
                            <div class="mb-4 p-3 bg-light rounded border">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="post_gl" id="postGLSwitch" value="1" checked>
                                    <label class="form-check-label fw-bold text-navy" for="postGLSwitch">
                                        Automatically post Opening Balance to General Ledger
                                    </label>
                                    <div class="small text-muted mt-1">
                                        Debits <strong>1030 - Inventory Asset</strong> &amp; Credits <strong>3010 - Owner Capital / Equity</strong> for total stock valuation.
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-success btn-lg fw-bold shadow-sm" onclick="return confirm('Confirm importing opening stock for this warehouse?');">
                                    <i class="bi bi-file-earmark-check-fill me-2"></i>Upload &amp; Import Opening Stock Now
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Step-by-Step Guide & Template Downloads -->
            <div class="col-lg-5">
                
                <!-- Card 1: Fast Template Download -->
                <div class="card border-0 shadow-sm mb-4 border-top border-primary border-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold text-navy">
                            <i class="bi bi-download text-primary me-2"></i>Download Ready-to-Use Templates
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">
                            The easiest way is to download our pre-filled template containing all your registered products, fill in the quantities from your physical stock count, and upload it back.
                        </p>

                        <!-- Pre-filled Catalog Export -->
                        <div class="p-3 bg-light rounded border mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet-fill text-success me-2 fs-5"></i>Pre-filled Catalog Template</span>
                                <span class="badge bg-primary">Recommended</span>
                            </div>
                            <div class="small text-muted mb-3">
                                Includes all <strong><?= $totalActiveProducts ?> registered products</strong> with their exact packaging formula and carton purchase rates ready for physical counting.
                            </div>
                            <a href="?action=export_catalog_template" class="btn btn-primary btn-sm w-100 fw-bold">
                                <i class="bi bi-download me-1"></i> Download Catalog Excel (.xlsx)
                            </a>
                        </div>

                        <!-- Blank Templates -->
                        <div class="row g-2">
                            <div class="col-6">
                                <a href="?action=download_blank_template&format=xlsx" class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="bi bi-file-earmark-excel text-success me-1"></i> Blank .XLSX
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="?action=download_blank_template&format=csv" class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="bi bi-filetype-csv text-primary me-1"></i> Blank .CSV
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Packaging & Hierarchy Guide -->
                <div class="card border-0 shadow-sm border-top border-info border-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold text-navy">
                            <i class="bi bi-diagram-3-fill text-info me-2"></i>Packaging Hierarchy Conversion
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-light border small text-dark mb-3">
                            <strong>Standard DMS Packaging Rule:</strong><br>
                            <code>1 Carton (Ctn) = 10 Packs (Pck)</code><br>
                            <code>1 Pack (Pck) = 200 Pieces (Pcs)</code><br>
                            <code>1 Carton (Ctn) = 2,000 Pieces Total</code>
                        </div>
                        <ul class="text-muted small ps-3 mb-0">
                            <li class="mb-2"><strong>Product Code*</strong>: Matches SKU or Barcode (e.g., <code>PRD-0001</code>).</li>
                            <li class="mb-2"><strong>Cartons</strong>: Total sealed master cartons in warehouse.</li>
                            <li class="mb-2"><strong>Packs</strong>: Number of intermediate loose packs/boxes.</li>
                            <li class="mb-2"><strong>Loose Pieces</strong>: Additional single loose pieces.</li>
                            <li><strong>Cost Rate (Optional)</strong>: Purchase cost per carton. If left blank, the catalog's saved purchase rate is automatically used.</li>
                        </ul>
                    </div>
                </div>

            </div>

        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
