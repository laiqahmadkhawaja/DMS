<?php
/**
 * DMS - Distribution Management System
 * Products Excel / CSV Importer & Exporter
 * Supports automated bulk import of supplier invoices & product catalogs.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('products_create');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$pageTitle = "Import / Export Products Catalog";
$activeMenu = "products";

$message = '';
$messageType = '';
$stats = null;

// Handle Pre-Loaded 1-Click Restore Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_load_default') {
    try {
        $jsonPath = __DIR__ . '/../../storage/imports/products_master_import.json';
        if (!file_exists($jsonPath)) {
            throw new Exception("Catalog file not found in storage/imports/.");
        }

        $items = json_decode(file_get_contents($jsonPath), true);
        if (!$items) {
            throw new Exception("Failed to decode JSON catalog.");
        }

        db()->exec("SET FOREIGN_KEY_CHECKS = 0;");

        // Ensure 3 Suppliers
        $supplierMap = [];
        $sups = db_fetch_all("SELECT id, supplier_name FROM suppliers");
        foreach ($sups as $s) {
            $supplierMap[$s['supplier_name']] = (int)$s['id'];
        }

        // Ensure Categories
        $categoryMap = [];
        $cats = db_fetch_all("SELECT id, category_name FROM product_categories");
        foreach ($cats as $c) {
            $categoryMap[$c['category_name']] = (int)$c['id'];
        }

        $units = db_fetch_all("SELECT id, unit_code FROM units");
        $unitMap = [];
        foreach ($units as $u) {
            $unitMap[$u['unit_code']] = (int)$u['id'];
        }

        $inserted = 0;
        $updated = 0;

        $stmtInsert = db()->prepare("
            INSERT INTO products (
                product_code, barcode, product_name, category_id, supplier_id,
                base_unit_id, purchase_rate, default_sale_rate, wholesale_rate,
                retail_rate, carton_qty, pack_qty, packing_description,
                min_stock_level, is_active, is_deleted, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                0.00, 1, 0, NOW(), NOW()
            )
        ");

        $stmtUpdate = db()->prepare("
            UPDATE products SET
                product_name = ?, category_id = ?, supplier_id = ?,
                purchase_rate = ?, default_sale_rate = ?, wholesale_rate = ?,
                retail_rate = ?, carton_qty = ?, pack_qty = ?, packing_description = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmtStock = db()->prepare("
            INSERT IGNORE INTO stock_balances (warehouse_id, product_id, saleable_qty, damaged_qty, expired_qty, total_qty, avg_unit_cost, total_value, last_updated_at)
            VALUES (1, ?, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NOW())
        ");

        foreach ($items as $item) {
            $supName = $item['supplier_name'];
            if (!isset($supplierMap[$supName])) {
                $code = 'SUPP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $supName), 0, 4));
                db()->prepare("INSERT INTO suppliers (supplier_code, supplier_name, company_name, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())")
                    ->execute([$code, $supName, $supName]);
                $supplierMap[$supName] = (int)db()->lastInsertId();
            }
            $supId = $supplierMap[$supName];

            $catName = $item['category_name'];
            if (!isset($categoryMap[$catName])) {
                $cCode = 'CAT-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $catName), 0, 3));
                db()->prepare("INSERT INTO product_categories (category_code, category_name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())")
                    ->execute([$cCode, $catName]);
                $categoryMap[$catName] = (int)db()->lastInsertId();
            }
            $catId = $categoryMap[$catName];

            $unitId = $unitMap[$item['unit']] ?? 1;

            $existingId = db_fetch_val("SELECT id FROM products WHERE product_code = ?", [$item['product_code']]);
            if ($existingId) {
                $stmtUpdate->execute([
                    $item['product_name'], $catId, $supId,
                    $item['purchase_rate'], $item['wholesale_rate'], $item['wholesale_rate'],
                    $item['retail_rate'], $item['carton_qty'], $item['pack_qty'], $item['packing_description'],
                    $existingId
                ]);
                $stmtStock->execute([$existingId]);
                $updated++;
            } else {
                $stmtInsert->execute([
                    $item['product_code'],
                    $item['product_code'],
                    $item['product_name'],
                    $catId,
                    $supId,
                    $unitId,
                    $item['purchase_rate'],
                    $item['wholesale_rate'],
                    $item['wholesale_rate'],
                    $item['retail_rate'],
                    $item['carton_qty'],
                    $item['pack_qty'],
                    $item['packing_description']
                ]);
                $newId = (int)db()->lastInsertId();
                $stmtStock->execute([$newId]);
                $inserted++;
            }
        }

        db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
        $message = "Successfully loaded 3-Company Catalog! Inserted: {$inserted}, Updated: {$updated}. All stock balances set to 0.00.";
        $messageType = "success";

    } catch (Exception $e) {
        db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
        $message = "Error restoring catalog: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Handle Custom Excel / CSV File Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['catalog_file']) && $_FILES['catalog_file']['error'] === UPLOAD_ERR_OK) {
    try {
        $file = $_FILES['catalog_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new Exception("Invalid file type. Please upload a valid .xlsx or .csv file.");
        }

        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            throw new Exception("The uploaded spreadsheet contains no data rows.");
        }

        // Header mapping
        $headerRow = array_map(function($h) {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$h)));
        }, $rows[0]);

        $colMap = [
            'code' => null,
            'name' => null,
            'supplier' => null,
            'category' => null,
            'packing' => null,
            'carton_qty' => null,
            'pack_qty' => null,
            'unit' => null,
            'purchase_rate' => null,
            'wholesale_rate' => null,
            'retail_rate' => null,
            'opening_stock' => null
        ];

        foreach ($headerRow as $idx => $colName) {
            if (strpos($colName, 'code') !== false || strpos($colName, 'sku') !== false) $colMap['code'] = $idx;
            elseif (strpos($colName, 'name') !== false || strpos($colName, 'product') !== false) $colMap['name'] = $idx;
            elseif (strpos($colName, 'supplier') !== false || strpos($colName, 'company') !== false) $colMap['supplier'] = $idx;
            elseif (strpos($colName, 'category') !== false) $colMap['category'] = $idx;
            elseif (strpos($colName, 'packing') !== false) $colMap['packing'] = $idx;
            elseif (strpos($colName, 'carton') !== false || strpos($colName, 'ctn') !== false) $colMap['carton_qty'] = $idx;
            elseif (strpos($colName, 'pack') !== false) $colMap['pack_qty'] = $idx;
            elseif (strpos($colName, 'unit') !== false) $colMap['unit'] = $idx;
            elseif (strpos($colName, 'purchase') !== false || strpos($colName, 'cost') !== false) $colMap['purchase_rate'] = $idx;
            elseif (strpos($colName, 'wholesale') !== false || strpos($colName, 'trade') !== false || strpos($colName, 'rate') !== false) $colMap['wholesale_rate'] = $idx;
            elseif (strpos($colName, 'retail') !== false || strpos($colName, 'mrp') !== false) $colMap['retail_rate'] = $idx;
            elseif (strpos($colName, 'stock') !== false || strpos($colName, 'qty') !== false) $colMap['opening_stock'] = $idx;
        }

        // Fallbacks for positional columns
        if ($colMap['code'] === null) $colMap['code'] = 0;
        if ($colMap['name'] === null) $colMap['name'] = 1;
        if ($colMap['supplier'] === null) $colMap['supplier'] = 2;
        if ($colMap['category'] === null) $colMap['category'] = 3;
        if ($colMap['packing'] === null) $colMap['packing'] = 4;
        if ($colMap['carton_qty'] === null) $colMap['carton_qty'] = 5;
        if ($colMap['pack_qty'] === null) $colMap['pack_qty'] = 6;
        if ($colMap['unit'] === null) $colMap['unit'] = 7;
        if ($colMap['purchase_rate'] === null) $colMap['purchase_rate'] = 8;
        if ($colMap['wholesale_rate'] === null) $colMap['wholesale_rate'] = 9;
        if ($colMap['retail_rate'] === null) $colMap['retail_rate'] = 10;

        db()->exec("SET FOREIGN_KEY_CHECKS = 0;");

        // Cache suppliers, categories, units
        $supplierMap = [];
        foreach (db_fetch_all("SELECT id, supplier_name FROM suppliers") as $s) {
            $supplierMap[strtolower(trim($s['supplier_name']))] = (int)$s['id'];
        }

        $categoryMap = [];
        foreach (db_fetch_all("SELECT id, category_name FROM product_categories") as $c) {
            $categoryMap[strtolower(trim($c['category_name']))] = (int)$c['id'];
        }

        $unitMap = [];
        foreach (db_fetch_all("SELECT id, unit_code FROM units") as $u) {
            $unitMap[strtoupper(trim($u['unit_code']))] = (int)$u['id'];
        }

        $stmtInsert = db()->prepare("
            INSERT INTO products (
                product_code, barcode, product_name, category_id, supplier_id,
                base_unit_id, purchase_rate, default_sale_rate, wholesale_rate,
                retail_rate, carton_qty, pack_qty, packing_description,
                min_stock_level, is_active, is_deleted, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                0.00, 1, 0, NOW(), NOW()
            )
        ");

        $stmtUpdate = db()->prepare("
            UPDATE products SET
                product_name = ?, category_id = ?, supplier_id = ?,
                purchase_rate = ?, default_sale_rate = ?, wholesale_rate = ?,
                retail_rate = ?, carton_qty = ?, pack_qty = ?, packing_description = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmtStock = db()->prepare("
            INSERT IGNORE INTO stock_balances (warehouse_id, product_id, saleable_qty, damaged_qty, expired_qty, total_qty, avg_unit_cost, total_value, last_updated_at)
            VALUES (1, ?, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NOW())
        ");

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $pName = trim((string)($r[$colMap['name']] ?? ''));
            if ($pName === '') {
                $skipped++;
                continue;
            }

            $pCode = strtoupper(trim((string)($r[$colMap['code']] ?? '')));
            if ($pCode === '') {
                $pCode = 'SKU-' . strtoupper(substr(md5($pName), 0, 6));
            }

            // Supplier
            $supNameRaw = trim((string)($r[$colMap['supplier']] ?? 'General Supplier'));
            $supKey = strtolower($supNameRaw);
            if (!isset($supplierMap[$supKey])) {
                $sCode = 'SUPP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $supNameRaw), 0, 4));
                db()->prepare("INSERT INTO suppliers (supplier_code, supplier_name, company_name, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())")
                    ->execute([$sCode, $supNameRaw, $supNameRaw]);
                $newSupId = (int)db()->lastInsertId();
                $supplierMap[$supKey] = $newSupId;
            }
            $supId = $supplierMap[$supKey];

            // Category
            $catNameRaw = trim((string)($r[$colMap['category']] ?? 'Confectionery'));
            $catKey = strtolower($catNameRaw);
            if (!isset($categoryMap[$catKey])) {
                $cCode = 'CAT-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $catNameRaw), 0, 3));
                db()->prepare("INSERT INTO product_categories (category_code, category_name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())")
                    ->execute([$cCode, $catNameRaw]);
                $newCatId = (int)db()->lastInsertId();
                $categoryMap[$catKey] = $newCatId;
            }
            $catId = $categoryMap[$catKey];

            $unitRaw = strtoupper(trim((string)($r[$colMap['unit']] ?? 'CTN')));
            $unitId = $unitMap[$unitRaw] ?? 1;

            $packingDesc = trim((string)($r[$colMap['packing']] ?? ''));
            $cartonQty = (float)($r[$colMap['carton_qty']] ?? 1);
            if ($cartonQty <= 0) $cartonQty = 1.0;
            $packQty = (float)($r[$colMap['pack_qty']] ?? 1);
            if ($packQty <= 0) $packQty = 1.0;

            $pRate = (float)($r[$colMap['purchase_rate']] ?? 0);
            $wRate = (float)($r[$colMap['wholesale_rate']] ?? $pRate);
            $rRate = (float)($r[$colMap['retail_rate']] ?? ($wRate * 1.15));

            $existingId = db_fetch_val("SELECT id FROM products WHERE product_code = ?", [$pCode]);
            if ($existingId) {
                $stmtUpdate->execute([
                    $pName, $catId, $supId,
                    $pRate, $wRate, $wRate,
                    $rRate, $cartonQty, $packQty, $packingDesc,
                    $existingId
                ]);
                $stmtStock->execute([$existingId]);
                $updated++;
            } else {
                $stmtInsert->execute([
                    $pCode, $pCode, $pName, $catId, $supId,
                    $unitId, $pRate, $wRate, $wRate,
                    $rRate, $cartonQty, $packQty, $packingDesc
                ]);
                $newId = (int)db()->lastInsertId();
                $stmtStock->execute([$newId]);
                $inserted++;
            }
        }

        db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
        $message = "Excel / CSV Import Completed! Successfully inserted {$inserted} new products and updated {$updated} existing products. (Skipped: {$skipped}). Opening stocks initialized to 0.";
        $messageType = "success";

    } catch (Exception $e) {
        db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
        $message = "Import Failed: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Stats
$totalProducts = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0");
$totalSuppliers = (int)db_fetch_val("SELECT COUNT(*) FROM suppliers WHERE is_active = 1");
$totalCategories = (int)db_fetch_val("SELECT COUNT(*) FROM product_categories WHERE is_active = 1");

$supplierCounts = db_fetch_all("
    SELECT s.supplier_name, COUNT(p.id) as total_products
    FROM suppliers s
    LEFT JOIN products p ON p.supplier_id = s.id AND p.is_deleted = 0
    GROUP BY s.id, s.supplier_name
    ORDER BY total_products DESC
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
            <div>
                <h4 class="mb-0 fw-bold text-navy">
                    <i class="bi bi-file-earmark-excel text-success me-2"></i>Product Catalog Import & Export Center
                </h4>
                <small class="text-muted">Bulk upload supplier invoices, export catalog spreadsheets, and sync company products.</small>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Catalog
                </a>
                <a href="<?= APP_URL ?>/storage/imports/products_master_import.xlsx" class="btn btn-sm btn-outline-success" download>
                    <i class="bi bi-download me-1"></i> Download Master Excel (.xlsx)
                </a>
                <a href="<?= APP_URL ?>/storage/imports/products_master_import.csv" class="btn btn-sm btn-outline-primary" download>
                    <i class="bi bi-filetype-csv me-1"></i> Download CSV
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?> shadow-sm alert-dismissible fade show" role="alert">
                <i class="bi <?= $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm p-3 border-start border-4 border-primary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold text-uppercase">Total Catalog Items</div>
                            <div class="h3 fw-bold text-primary mb-0 mt-1"><?= number_format($totalProducts) ?></div>
                            <small class="text-muted">Unique SKUs in Database</small>
                        </div>
                        <div class="bg-primary-subtle text-primary p-3 rounded-circle fs-3">
                            <i class="bi bi-boxes"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm p-3 border-start border-4 border-success">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold text-uppercase">Supplier Companies</div>
                            <div class="h3 fw-bold text-success mb-0 mt-1"><?= number_format($totalSuppliers) ?></div>
                            <small class="text-muted">Active Suppliers (Ujab, Kunafa, Hashim)</small>
                        </div>
                        <div class="bg-success-subtle text-success p-3 rounded-circle fs-3">
                            <i class="bi bi-building"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm p-3 border-start border-4 border-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-bold text-uppercase">Categories</div>
                            <div class="h3 fw-bold text-info mb-0 mt-1"><?= number_format($totalCategories) ?></div>
                            <small class="text-muted">FMCG Sub-Categories</small>
                        </div>
                        <div class="bg-info-subtle text-info p-3 rounded-circle fs-3">
                            <i class="bi bi-tags"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left Column: Custom Excel Upload -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-upload me-1"></i> Upload Supplier Products Excel / CSV</h6>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-secondary small mb-3">
                            Upload your company price lists, invoice sheets, or inventory spreadsheets. 
                            The system automatically maps columns, detects packing ratios, matches suppliers, and sets opening stock to <strong>0.00</strong>.
                        </p>

                        <form method="POST" action="import.php" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Select Excel (.xlsx, .xls) or CSV File <span class="text-danger">*</span></label>
                                <input type="file" name="catalog_file" class="form-control" accept=".xlsx, .xls, .csv" required>
                                <div class="form-text small">Accepted formats: .xlsx, .xls, .csv (Max 15MB)</div>
                            </div>

                            <div class="p-3 bg-light rounded border mb-3">
                                <div class="fw-bold text-dark small mb-2"><i class="bi bi-info-circle me-1"></i> Recognized Columns (Auto-Mapped):</div>
                                <div class="row g-2 small text-muted">
                                    <div class="col-6">• Product Code / SKU</div>
                                    <div class="col-6">• Product Name</div>
                                    <div class="col-6">• Supplier / Company</div>
                                    <div class="col-6">• Category</div>
                                    <div class="col-6">• Packing (e.g. 108x50gm)</div>
                                    <div class="col-6">• Carton Qty (Pcs/Ctn)</div>
                                    <div class="col-6">• Purchase Rate (Cost)</div>
                                    <div class="col-6">• Wholesale Rate (Sale)</div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary fw-semibold px-4">
                                <i class="bi bi-cloud-arrow-up me-1"></i> Upload & Import Catalog
                            </button>
                        </form>
                    </div>
                </div>

                <!-- 1-Click Quick Reload Card -->
                <div class="card border-0 shadow-sm border-start border-4 border-warning">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-dark mb-1">
                                    <i class="bi bi-arrow-clockwise me-1 text-warning"></i> Re-Sync Pre-Loaded 3-Company Catalog (229 Items)
                                </h6>
                                <p class="text-muted small mb-0">
                                    Quickly re-populate or update all 229 verified items for <strong>Ujab Enterprises</strong>, <strong>Kunafa Foods</strong>, and <strong>Hashim Enterprises</strong> with 1 click.
                                </p>
                            </div>
                            <form method="POST" action="import.php" onsubmit="return confirm('Do you want to re-sync all 229 catalog products from the pre-loaded supplier master?');">
                                <input type="hidden" name="action" value="quick_load_default">
                                <button type="submit" class="btn btn-outline-dark btn-sm fw-semibold">
                                    <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Quick Sync Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Current Supplier Distribution -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-pie-chart me-1"></i> Products by Supplier Company</h6>
                        <span class="badge bg-primary"><?= $totalProducts ?> Active SKUs</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-3">Company / Supplier</th>
                                        <th class="text-end pe-3">Products</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($supplierCounts as $sc): ?>
                                        <tr>
                                            <td class="ps-3">
                                                <div class="fw-bold text-navy"><?= htmlspecialchars($sc['supplier_name']) ?></div>
                                                <small class="text-muted">Primary Supplier Master</small>
                                            </td>
                                            <td class="text-end pe-3">
                                                <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 px-2">
                                                    <?= number_format((int)$sc['total_products']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3 bg-light border-top">
                            <div class="small fw-bold text-dark mb-1"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Ready-to-Use Master Spreadsheets:</div>
                            <p class="small text-muted mb-2">You can download and inspect the complete catalog directly:</p>
                            <div class="d-flex gap-2">
                                <a href="<?= APP_URL ?>/storage/imports/products_master_import.xlsx" class="btn btn-sm btn-outline-success flex-fill" download>
                                    <i class="bi bi-file-excel me-1"></i> Excel (.xlsx)
                                </a>
                                <a href="<?= APP_URL ?>/storage/imports/products_master_import.csv" class="btn btn-sm btn-outline-secondary flex-fill" download>
                                    <i class="bi bi-file-text me-1"></i> CSV (.csv)
                                </a>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
