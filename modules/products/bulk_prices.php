<?php
/**
 * DMS - Distribution Management System
 * Products - Bulk Price & Rates Setting Manager
 * 
 * Features:
 * 1. Live Instant Searching & Multi-Selection (Client & Server-side)
 * 2. Real-time Carton <-> Piece Rate Auto-Synchronization based on packaging hierarchy
 * 3. Live Profit Margin & Markup indicators
 * 4. Multi-Select Floating Bulk Action Tools (% increase, fixed rate, margin on cost, rounding)
 * 5. High-Speed Row-Level & Batch AJAX/POST Saving (with F9 / Ctrl+S keyboard shortcuts)
 * 6. Export Current Price List to Excel (.xlsx)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
if (!has_permission('products_edit') && !has_permission('masters_manage')) {
    require_permission('products_edit');
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1');

// -------------------------------------------------------------------------
// 1. EXPORT PRICE LIST TO EXCEL
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_prices') {
    $vendorAutoload = BASE_PATH . '/vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Price List');

        $headers = [
            'A1' => 'Product Code',
            'B1' => 'Product Name',
            'C1' => 'Category',
            'D1' => 'Brand',
            'E1' => 'Packaging',
            'F1' => 'Purchase Carton Cost',
            'G1' => 'Purchase Piece Cost',
            'H1' => 'Wholesale Carton Rate',
            'I1' => 'Wholesale Piece Rate',
            'J1' => 'Retail Carton Rate',
            'K1' => 'Retail Piece Rate',
            'L1' => 'Margin %'
        ];

        foreach ($headers as $cell => $val) {
            $sheet->setCellValue($cell, $val);
        }

        $items = db_fetch_all("
            SELECT 
                p.product_code, p.product_name, c.category_name, b.brand_name,
                p.carton_qty, p.pack_qty, p.packing_description,
                p.purchase_carton_rate, p.purchase_piece_rate, p.purchase_rate,
                p.carton_rate, p.piece_rate, p.wholesale_rate,
                p.retail_carton_rate, p.retail_piece_rate, p.retail_rate
            FROM products p
            LEFT JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE p.is_deleted = 0 AND p.is_active = 1
            ORDER BY c.category_name ASC, p.product_name ASC
        ");

        $rowIdx = 2;
        foreach ($items as $item) {
            $ratios = get_product_packaging_ratios($item);
            $pcsPerCtn = $ratios['pcs_per_ctn'];

            $costCarton = (float)($item['purchase_carton_rate'] > 0 ? $item['purchase_carton_rate'] : $item['purchase_rate']);
            $costPiece  = (float)($item['purchase_piece_rate'] > 0 ? $item['purchase_piece_rate'] : ($pcsPerCtn > 0 ? round($costCarton / $pcsPerCtn, 4) : 0));

            $saleCarton = (float)($item['carton_rate'] > 0 ? $item['carton_rate'] : $item['wholesale_rate']);
            $salePiece  = (float)($item['piece_rate'] > 0 ? $item['piece_rate'] : ($pcsPerCtn > 0 ? round($saleCarton / $pcsPerCtn, 4) : 0));

            $retCarton  = (float)($item['retail_carton_rate'] > 0 ? $item['retail_carton_rate'] : $item['retail_rate']);
            $retPiece   = (float)($item['retail_piece_rate'] > 0 ? $item['retail_piece_rate'] : ($pcsPerCtn > 0 ? round($retCarton / $pcsPerCtn, 4) : 0));

            $margin = $costCarton > 0 ? round((($saleCarton - $costCarton) / $costCarton) * 100, 2) : 0.0;

            $sheet->setCellValue('A' . $rowIdx, $item['product_code']);
            $sheet->setCellValue('B' . $rowIdx, $item['product_name']);
            $sheet->setCellValue('C' . $rowIdx, $item['category_name'] ?? '-');
            $sheet->setCellValue('D' . $rowIdx, $item['brand_name'] ?? '-');
            $sheet->setCellValue('E' . $rowIdx, "1*{$ratios['packs_per_ctn']}*{$ratios['pcs_per_pack']}");
            $sheet->setCellValue('F' . $rowIdx, $costCarton);
            $sheet->setCellValue('G' . $rowIdx, $costPiece);
            $sheet->setCellValue('H' . $rowIdx, $saleCarton);
            $sheet->setCellValue('I' . $rowIdx, $salePiece);
            $sheet->setCellValue('J' . $rowIdx, $retCarton);
            $sheet->setCellValue('K' . $rowIdx, $retPiece);
            $sheet->setCellValue('L' . $rowIdx, $margin . '%');

            $rowIdx++;
        }

        $headerRange = 'A1:L1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('198754'); // Green
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="price_list_' . date('Ymd_His') . '.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

// -------------------------------------------------------------------------
// 2. POST ACTION HANDLERS (SINGLE & BATCH SAVING)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_input($_POST['action'] ?? '');

    // A. Single Product Rate Save (AJAX or direct form)
    if ($action === 'save_single_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            $isAjax ? json_error('Invalid Product ID.') : redirect_back_with('danger', 'Invalid Product ID.');
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ? AND is_deleted = 0", [$productId]);
        if (!$prod) {
            $isAjax ? json_error('Product not found.') : redirect_back_with('danger', 'Product not found.');
        }

        $ratios = get_product_packaging_ratios($prod);
        $pcsPerCtn = max(1.0, (float)$ratios['pcs_per_ctn']);

        $cRate = max(0, (float)($_POST['carton_rate'] ?? 0));
        $pRate = max(0, (float)($_POST['piece_rate'] ?? 0));

        $purCRate = max(0, (float)($_POST['purchase_carton_rate'] ?? 0));
        $purPRate = max(0, (float)($_POST['purchase_piece_rate'] ?? 0));

        $retCRate = max(0, (float)($_POST['retail_carton_rate'] ?? 0));
        $retPRate = max(0, (float)($_POST['retail_piece_rate'] ?? 0));

        // Auto-synchronize piece rate if carton rate provided
        if ($cRate > 0 && $pRate <= 0) {
            $pRate = round($cRate / $pcsPerCtn, 4);
        } elseif ($pRate > 0 && $cRate <= 0) {
            $cRate = round($pRate * $pcsPerCtn, 2);
        }

        if ($purCRate > 0 && $purPRate <= 0) {
            $purPRate = round($purCRate / $pcsPerCtn, 4);
        } elseif ($purPRate > 0 && $purCRate <= 0) {
            $purCRate = round($purPRate * $pcsPerCtn, 2);
        }

        if ($retCRate > 0 && $retPRate <= 0) {
            $retPRate = round($retCRate / $pcsPerCtn, 4);
        } elseif ($retPRate > 0 && $retCRate <= 0) {
            $retCRate = round($retPRate * $pcsPerCtn, 2);
        }

        try {
            $stmt = db()->prepare("
                UPDATE products SET
                    carton_rate          = ?,
                    piece_rate           = ?,
                    wholesale_rate       = ?,
                    default_sale_rate    = ?,
                    purchase_carton_rate = ?,
                    purchase_piece_rate  = ?,
                    purchase_rate        = ?,
                    retail_carton_rate   = ?,
                    retail_piece_rate    = ?,
                    retail_rate          = ?,
                    updated_at           = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $cRate, $pRate, $cRate, $cRate,
                $purCRate, $purPRate, $purCRate,
                $retCRate, $retPRate, $retCRate,
                $productId
            ]);

            log_audit('update_product_rates_single', 'products', $productId, "Updated rates: Carton Sale: {$cRate}, Pcs Sale: {$pRate}, Cost: {$purCRate}");

            if ($isAjax) {
                json_success("Rates saved for '{$prod['product_name']}'.", [
                    'product_id'           => $productId,
                    'carton_rate'          => $cRate,
                    'piece_rate'           => $pRate,
                    'purchase_carton_rate' => $purCRate,
                    'purchase_piece_rate'  => $purPRate,
                    'retail_carton_rate'   => $retCRate,
                    'retail_piece_rate'    => $retPRate
                ]);
            }
            set_flash_message('success', "Rates saved for '{$prod['product_name']}'.");
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            $isAjax ? json_error("Failed to save: " . $e->getMessage()) : redirect_back_with('danger', $e->getMessage());
        }
    }

    // B. Batch Save Multiple Products
    if ($action === 'save_batch_products') {
        $ratesData = $_POST['rates'] ?? [];
        if (empty($ratesData) || !is_array($ratesData)) {
            $isAjax ? json_error('No rates data submitted.') : redirect_back_with('danger', 'No rates data submitted.');
        }

        try {
            db()->beginTransaction();

            $stmtUpdate = db()->prepare("
                UPDATE products SET
                    carton_rate          = ?,
                    piece_rate           = ?,
                    wholesale_rate       = ?,
                    default_sale_rate    = ?,
                    purchase_carton_rate = ?,
                    purchase_piece_rate  = ?,
                    purchase_rate        = ?,
                    retail_carton_rate   = ?,
                    retail_piece_rate    = ?,
                    retail_rate          = ?,
                    updated_at           = NOW()
                WHERE id = ?
            ");

            $updatedCount = 0;
            foreach ($ratesData as $pId => $r) {
                $productId = (int)$pId;
                if ($productId <= 0) continue;

                $prod = db_fetch_one("SELECT id, carton_qty, pack_qty FROM products WHERE id = ?", [$productId]);
                if (!$prod) continue;

                $ratios = get_product_packaging_ratios($prod);
                $pcsPerCtn = max(1.0, (float)$ratios['pcs_per_ctn']);

                $cRate    = max(0, (float)($r['carton_rate'] ?? 0));
                $pRate    = max(0, (float)($r['piece_rate'] ?? 0));
                $purCRate = max(0, (float)($r['purchase_carton_rate'] ?? 0));
                $purPRate = max(0, (float)($r['purchase_piece_rate'] ?? 0));
                $retCRate = max(0, (float)($r['retail_carton_rate'] ?? 0));
                $retPRate = max(0, (float)($r['retail_piece_rate'] ?? 0));

                // Sync rates if only one was modified
                if ($cRate > 0 && $pRate <= 0) {
                    $pRate = round($cRate / $pcsPerCtn, 4);
                } elseif ($pRate > 0 && $cRate <= 0) {
                    $cRate = round($pRate * $pcsPerCtn, 2);
                }

                if ($purCRate > 0 && $purPRate <= 0) {
                    $purPRate = round($purCRate / $pcsPerCtn, 4);
                } elseif ($purPRate > 0 && $purCRate <= 0) {
                    $purCRate = round($purPRate * $pcsPerCtn, 2);
                }

                if ($retCRate > 0 && $retPRate <= 0) {
                    $retPRate = round($retCRate / $pcsPerCtn, 4);
                } elseif ($retPRate > 0 && $retCRate <= 0) {
                    $retCRate = round($retPRate * $pcsPerCtn, 2);
                }

                $stmtUpdate->execute([
                    $cRate, $pRate, $cRate, $cRate,
                    $purCRate, $purPRate, $purCRate,
                    $retCRate, $retPRate, $retCRate,
                    $productId
                ]);
                $updatedCount++;
            }

            db()->commit();
            log_audit('bulk_prices_batch_update', 'products', null, "Batch updated rates for {$updatedCount} products");

            $msg = "Successfully saved price changes for {$updatedCount} product(s)!";
            if ($isAjax) {
                json_success($msg, ['updated_count' => $updatedCount]);
            }
            set_flash_message('success', $msg);
            redirect($_SERVER['REQUEST_URI']);

        } catch (Exception $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $isAjax ? json_error("Batch save failed: " . $e->getMessage()) : redirect_back_with('danger', $e->getMessage());
        }
    }
}

// -------------------------------------------------------------------------
// 3. QUERY PRODUCTS FOR LIVE BULK PRICING GRID
// -------------------------------------------------------------------------

$search         = clean_input($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$brandFilter    = (int)($_GET['brand_id'] ?? 0);
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$priceStatus    = clean_input($_GET['price_status'] ?? 'all'); // 'all', 'zero_sale', 'zero_cost'

$where = ["p.is_deleted = 0", "p.is_active = 1"];
$params = [];

if ($search !== '') {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($categoryFilter > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $categoryFilter;
}

if ($brandFilter > 0) {
    $where[] = "p.brand_id = ?";
    $params[] = $brandFilter;
}

if ($supplierFilter > 0) {
    $where[] = "p.supplier_id = ?";
    $params[] = $supplierFilter;
}

if ($priceStatus === 'zero_sale') {
    $where[] = "(p.carton_rate <= 0 AND p.wholesale_rate <= 0)";
} elseif ($priceStatus === 'zero_cost') {
    $where[] = "(p.purchase_carton_rate <= 0 AND p.purchase_rate <= 0)";
}

$whereClause = implode(" AND ", $where);

$products = db_fetch_all("
    SELECT 
        p.id, 
        p.product_code, 
        p.barcode, 
        p.product_name, 
        p.category_id, 
        p.brand_id, 
        p.supplier_id,
        p.carton_qty, 
        p.pack_qty, 
        p.packing_description,
        p.purchase_carton_rate, 
        p.purchase_piece_rate, 
        p.purchase_rate,
        p.carton_rate, 
        p.piece_rate, 
        p.wholesale_rate,
        p.retail_carton_rate, 
        p.retail_piece_rate, 
        p.retail_rate,
        c.category_name,
        b.brand_name,
        s.supplier_name,
        COALESCE(sb.saleable_qty, 0) as current_stock_pieces
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = 1
    WHERE {$whereClause}
    ORDER BY c.category_name ASC, p.product_name ASC
", $params);

// Lookups for filter dropdowns
$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_active = 1 ORDER BY category_name ASC");
$brands     = db_fetch_all("SELECT id, brand_name FROM brands WHERE is_deleted = 0 AND is_active = 1 ORDER BY brand_name ASC");
$suppliers  = db_fetch_all("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");

$pageTitle = 'Bulk Product Prices Setting — ' . APP_NAME;
$activeMenu = 'bulk_prices';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-currency-dollar text-success me-2"></i>Bulk Product Prices &amp; Rates Setting
                </h4>
                <p class="text-muted small mb-0">Live search, multi-select, and mass update wholesale, retail, and purchase rates across products with instant packaging synchronization.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success fw-bold shadow-sm" onclick="saveAllModifiedRates()">
                    <i class="bi bi-check-all me-1"></i> Save All Price Changes (F9 / Ctrl+S)
                </button>
                <a href="?action=export_prices" class="btn btn-sm btn-outline-success shadow-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel (.xlsx)
                </a>
                <a href="<?= APP_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Product Catalog
                </a>
            </div>
        </div>

        <!-- Filter & Live Search Toolbar -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3">
                <form method="GET" action="" id="filterForm" class="row g-2 align-items-center">
                    
                    <!-- Instant Live Client Search -->
                    <div class="col-lg-4 col-md-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" id="liveSearchInput" name="search" class="form-control" 
                                   placeholder="Live search by name, SKU, barcode... (Press F2)" 
                                   value="<?= e($search) ?>" autocomplete="off">
                            <?php if ($search !== ''): ?>
                                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('liveSearchInput').value=''; document.getElementById('filterForm').submit();">
                                    <i class="bi bi-x"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Category Filter -->
                    <div class="col-lg-2 col-md-3">
                        <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $categoryFilter ? 'selected' : '' ?>>
                                    <?= e($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Brand Filter -->
                    <div class="col-lg-2 col-md-3">
                        <select name="brand_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="0">All Brands</option>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $b['id'] == $brandFilter ? 'selected' : '' ?>>
                                    <?= e($b['brand_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Supplier Filter -->
                    <div class="col-lg-2 col-md-3">
                        <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="0">All Suppliers</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>" <?= $sup['id'] == $supplierFilter ? 'selected' : '' ?>>
                                    <?= e($sup['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Price Status -->
                    <div class="col-lg-2 col-md-3">
                        <select name="price_status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $priceStatus === 'all' ? 'selected' : '' ?>>All Price Statuses</option>
                            <option value="zero_sale" <?= $priceStatus === 'zero_sale' ? 'selected' : '' ?>>Missing / Zero Sale Rate</option>
                            <option value="zero_cost" <?= $priceStatus === 'zero_cost' ? 'selected' : '' ?>>Missing / Zero Cost Rate</option>
                        </select>
                    </div>

                </form>
            </div>
        </div>

        <!-- Sticky Floating Multi-Select Bulk Actions Toolbar -->
        <div id="bulkToolbar" class="card border-0 shadow-lg mb-3 bg-dark text-white d-none" style="transition: all 0.2s ease-in-out;">
            <div class="card-body p-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-warning text-dark fs-6" id="selectedCountBadge">0 Selected</span>
                        <span class="small text-light">Apply mass price change to selected items:</span>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        
                        <!-- Tool 1: % Increase / Decrease -->
                        <div class="input-group input-group-sm" style="width: 290px;">
                            <span class="input-group-text bg-secondary text-white border-secondary">Rate</span>
                            <select id="bulkTargetRate" class="form-select form-select-sm border-secondary">
                                <option value="carton_rate">Wholesale Sale Rate</option>
                                <option value="purchase_carton_rate">Purchase Cost Rate</option>
                                <option value="retail_carton_rate">Retail MRP Rate</option>
                            </select>
                            <input type="number" id="bulkPercentInput" class="form-control form-control-sm border-secondary text-end" placeholder="% eg. +5" step="0.5" style="width: 75px;">
                            <button type="button" class="btn btn-warning btn-sm fw-bold" onclick="applyBulkPercentage()">
                                Apply %
                            </button>
                        </div>

                        <!-- Tool 2: Margin on Cost -->
                        <div class="input-group input-group-sm" style="width: 220px;">
                            <span class="input-group-text bg-secondary text-white border-secondary">Cost +</span>
                            <input type="number" id="bulkMarginInput" class="form-control form-control-sm border-secondary text-end" placeholder="Margin %" step="0.5">
                            <button type="button" class="btn btn-info btn-sm fw-bold text-dark" onclick="applyBulkMarginOnCost()">
                                Set Margin
                            </button>
                        </div>

                        <!-- Tool 3: Set Fixed Rate -->
                        <div class="input-group input-group-sm" style="width: 220px;">
                            <span class="input-group-text bg-secondary text-white border-secondary">Rs.</span>
                            <input type="number" id="bulkFixedInput" class="form-control form-control-sm border-secondary text-end" placeholder="Fixed Rate" step="10">
                            <button type="button" class="btn btn-light btn-sm fw-bold" onclick="applyBulkFixedRate()">
                                Set Flat
                            </button>
                        </div>

                        <!-- Deselect Button -->
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="deselectAllRows()">
                            <i class="bi bi-x-circle me-1"></i> Deselect
                        </button>

                    </div>

                </div>
            </div>
        </div>

        <!-- Products Live Price Setting Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <div>
                    <h6 class="mb-0 fw-bold text-navy">
                        <i class="bi bi-table me-2"></i>Product Price Register 
                        <span class="badge bg-light text-dark border ms-2" id="gridCountDisplay">Showing <?= count($products) ?> Products</span>
                    </h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="saveStatusIndicator" class="small text-muted">All prices synced</span>
                    <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="saveAllModifiedRates()">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                </div>
            </div>

            <div class="table-responsive" style="max-height: calc(100vh - 280px); overflow-y: auto;">
                <table class="table table-hover table-bordered align-middle mb-0" id="pricingTable">
                    <thead class="table-light sticky-top shadow-sm" style="z-index: 10;">
                        <tr class="text-uppercase small fw-bold text-secondary">
                            <th class="text-center" style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" class="form-check-input" onchange="toggleSelectAll(this.checked)">
                            </th>
                            <th style="min-width: 260px;">Product &amp; SKU</th>
                            <th style="min-width: 140px;">Packaging Ratio</th>
                            <th class="bg-light-info text-center" style="min-width: 180px;">
                                <span class="text-primary"><i class="bi bi-cart me-1"></i>Purchase / Cost Rates</span>
                                <div class="d-flex justify-content-between mt-1 text-muted" style="font-size: 0.75rem;">
                                    <span>Carton Rate</span>
                                    <span>Piece Rate</span>
                                </div>
                            </th>
                            <th class="bg-light-success text-center" style="min-width: 220px;">
                                <span class="text-success fw-bold"><i class="bi bi-tag-fill me-1"></i>Wholesale / Sale Rates</span>
                                <div class="d-flex justify-content-between mt-1 text-muted" style="font-size: 0.75rem;">
                                    <span>Carton Rate (Rs.)</span>
                                    <span>Piece Rate (Rs.)</span>
                                </div>
                            </th>
                            <th class="bg-light text-center" style="min-width: 180px;">
                                <span class="text-secondary"><i class="bi bi-receipt me-1"></i>Retail MRP Rates</span>
                                <div class="d-flex justify-content-between mt-1 text-muted" style="font-size: 0.75rem;">
                                    <span>Carton MRP</span>
                                    <span>Piece MRP</span>
                                </div>
                            </th>
                            <th class="text-center" style="width: 110px;">Margin %</th>
                            <th class="text-center" style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="pricingTableBody">
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block text-secondary mb-2"></i>
                                    No active products found matching the criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $p): 
                                $ratios = get_product_packaging_ratios($p);
                                $packsPerCtn = max(1.0, (float)$ratios['packs_per_ctn']);
                                $pcsPerPack  = max(1.0, (float)$ratios['pcs_per_pack']);
                                $pcsPerCtn   = max(1.0, (float)$ratios['pcs_per_ctn']);

                                $costCarton = (float)($p['purchase_carton_rate'] > 0 ? $p['purchase_carton_rate'] : $p['purchase_rate']);
                                $costPiece  = (float)($p['purchase_piece_rate'] > 0 ? $p['purchase_piece_rate'] : ($pcsPerCtn > 0 ? round($costCarton / $pcsPerCtn, 4) : 0));

                                $saleCarton = (float)($p['carton_rate'] > 0 ? $p['carton_rate'] : $p['wholesale_rate']);
                                $salePiece  = (float)($p['piece_rate'] > 0 ? $p['piece_rate'] : ($pcsPerCtn > 0 ? round($saleCarton / $pcsPerCtn, 4) : 0));

                                $retCarton  = (float)($p['retail_carton_rate'] > 0 ? $p['retail_carton_rate'] : $p['retail_rate']);
                                $retPiece   = (float)($p['retail_piece_rate'] > 0 ? $p['retail_piece_rate'] : ($pcsPerCtn > 0 ? round($retCarton / $pcsPerCtn, 4) : 0));

                                $marginPct = $costCarton > 0 ? round((($saleCarton - $costCarton) / $costCarton) * 100, 1) : 0.0;
                                $marginClass = $marginPct > 0 ? 'bg-success bg-opacity-10 text-success border border-success' : ($marginPct < 0 ? 'bg-danger bg-opacity-10 text-danger border border-danger' : 'bg-light text-muted border');
                            ?>
                                <tr id="row-<?= $p['id'] ?>" 
                                    class="price-row" 
                                    data-id="<?= $p['id'] ?>"
                                    data-pcs-per-ctn="<?= $pcsPerCtn ?>"
                                    data-packs-per-ctn="<?= $packsPerCtn ?>"
                                    data-pcs-per-pack="<?= $pcsPerPack ?>"
                                    data-search="<?= strtolower(e($p['product_name'] . ' ' . $p['product_code'] . ' ' . $p['barcode'] . ' ' . $p['category_name'] . ' ' . $p['brand_name'])) ?>">
                                    
                                    <!-- Checkbox -->
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input row-select-checkbox" value="<?= $p['id'] ?>" onchange="updateSelectedCount()">
                                    </td>

                                    <!-- Product Info -->
                                    <td>
                                        <div class="fw-bold text-navy"><?= e($p['product_name']) ?></div>
                                        <div class="d-flex align-items-center gap-1 mt-1 small">
                                            <span class="badge bg-light text-secondary border font-monospace"><?= e($p['product_code']) ?></span>
                                            <?php if (!empty($p['category_name'])): ?>
                                                <span class="badge bg-primary bg-opacity-10 text-primary"><?= e($p['category_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($p['brand_name'])): ?>
                                                <span class="badge bg-warning bg-opacity-25 text-dark"><?= e($p['brand_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Packaging Ratio -->
                                    <td>
                                        <div class="small fw-semibold text-dark">
                                            1 Ctn = <?= (int)$packsPerCtn ?> Pck &times; <?= (int)$pcsPerPack ?> Pcs
                                        </div>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            = <?= number_format($pcsPerCtn) ?> Total Pieces
                                        </div>
                                    </td>

                                    <!-- Purchase / Cost Rates -->
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control form-control-sm text-end cost-carton-input" 
                                                   id="cost_carton_<?= $p['id'] ?>"
                                                   value="<?= number_format($costCarton, 2, '.', '') ?>" 
                                                   step="0.01" min="0" placeholder="Carton Cost"
                                                   oninput="onCostCartonChange(<?= $p['id'] ?>)">
                                            <span class="input-group-text px-1 bg-light text-muted">/</span>
                                            <input type="number" class="form-control form-control-sm text-end cost-piece-input" 
                                                   id="cost_piece_<?= $p['id'] ?>"
                                                   value="<?= number_format($costPiece, 4, '.', '') ?>" 
                                                   step="0.0001" min="0" placeholder="Piece Cost"
                                                   oninput="onCostPieceChange(<?= $p['id'] ?>)">
                                        </div>
                                    </td>

                                    <!-- Wholesale / Sale Rates -->
                                    <td class="bg-light-success">
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control form-control-sm text-end fw-bold text-success sale-carton-input" 
                                                   id="sale_carton_<?= $p['id'] ?>"
                                                   value="<?= number_format($saleCarton, 2, '.', '') ?>" 
                                                   step="0.01" min="0" placeholder="Carton Sale"
                                                   oninput="onSaleCartonChange(<?= $p['id'] ?>)">
                                            <span class="input-group-text px-1 bg-light text-muted">/</span>
                                            <input type="number" class="form-control form-control-sm text-end fw-bold text-success sale-piece-input" 
                                                   id="sale_piece_<?= $p['id'] ?>"
                                                   value="<?= number_format($salePiece, 4, '.', '') ?>" 
                                                   step="0.0001" min="0" placeholder="Piece Sale"
                                                   oninput="onSalePieceChange(<?= $p['id'] ?>)">
                                        </div>
                                    </td>

                                    <!-- Retail MRP Rates -->
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control form-control-sm text-end retail-carton-input" 
                                                   id="ret_carton_<?= $p['id'] ?>"
                                                   value="<?= number_format($retCarton, 2, '.', '') ?>" 
                                                   step="0.01" min="0" placeholder="Carton MRP"
                                                   oninput="onRetailCartonChange(<?= $p['id'] ?>)">
                                            <span class="input-group-text px-1 bg-light text-muted">/</span>
                                            <input type="number" class="form-control form-control-sm text-end retail-piece-input" 
                                                   id="ret_piece_<?= $p['id'] ?>"
                                                   value="<?= number_format($retPiece, 4, '.', '') ?>" 
                                                   step="0.0001" min="0" placeholder="Piece MRP"
                                                   oninput="onRetailPieceChange(<?= $p['id'] ?>)">
                                        </div>
                                    </td>

                                    <!-- Margin % -->
                                    <td class="text-center">
                                        <span class="badge <?= $marginClass ?> px-2 py-1 font-monospace" id="margin_badge_<?= $p['id'] ?>">
                                            <?= ($marginPct > 0 ? '+' : '') . number_format($marginPct, 1) ?>%
                                        </span>
                                    </td>

                                    <!-- Quick Save Action -->
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-success p-1 px-2" 
                                                id="btn_save_<?= $p['id'] ?>"
                                                title="Quick save this product"
                                                onclick="saveSingleRow(<?= $p['id'] ?>)">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer summary -->
            <div class="card-footer bg-light py-2 d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    <span class="fw-bold">Shortcuts:</span> <kbd>F2</kbd> Focus Search | <kbd>F9</kbd> / <kbd>Ctrl+S</kbd> Save All Modified | <kbd>Tab</kbd> Next Cell
                </span>
                <div>
                    <button type="button" class="btn btn-sm btn-success fw-bold" onclick="saveAllModifiedRates()">
                        <i class="bi bi-cloud-check me-1"></i> Save All Price Changes
                    </button>
                </div>
            </div>

        </div>

    </div>
</div>

<script>
// Track modified (dirty) rows
const dirtyRows = new Set();

// -------------------------------------------------------------------------
// LIVE CLIENT SEARCH (INSTANT FILTERING ACROSS DOM ROWS)
// -------------------------------------------------------------------------
document.getElementById('liveSearchInput').addEventListener('input', function(e) {
    const query = e.target.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#pricingTableBody tr.price-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowText = row.getAttribute('data-search') || '';
        if (query === '' || rowText.includes(query)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('gridCountDisplay').textContent = `Showing ${visibleCount} Products`;
});

// F2 Shortcut to focus search
window.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        const searchInput = document.getElementById('liveSearchInput');
        searchInput.focus();
        searchInput.select();
    } else if (e.key === 'F9' || (e.ctrlKey && e.key.toLowerCase() === 's')) {
        e.preventDefault();
        saveAllModifiedRates();
    }
});

// -------------------------------------------------------------------------
// AUTO-SYNCHRONIZATION FUNCTIONS (CARTON <-> PIECE RATES & MARGIN)
// -------------------------------------------------------------------------

function markRowDirty(id) {
    dirtyRows.add(id);
    const row = document.getElementById('row-' + id);
    if (row) {
        row.classList.add('table-warning');
    }
    const indicator = document.getElementById('saveStatusIndicator');
    indicator.innerHTML = `<span class="text-warning fw-bold"><i class="bi bi-circle-fill" style="font-size:8px;"></i> ${dirtyRows.size} product(s) modified</span>`;
}

function markRowClean(id) {
    dirtyRows.delete(id);
    const row = document.getElementById('row-' + id);
    if (row) {
        row.classList.remove('table-warning');
        row.classList.add('table-success');
        setTimeout(() => row.classList.remove('table-success'), 1200);
    }
    const indicator = document.getElementById('saveStatusIndicator');
    if (dirtyRows.size === 0) {
        indicator.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>All prices saved</span>`;
    } else {
        indicator.innerHTML = `<span class="text-warning fw-bold"><i class="bi bi-circle-fill" style="font-size:8px;"></i> ${dirtyRows.size} product(s) modified</span>`;
    }
}

function updateMarginBadge(id) {
    const costCarton = parseFloat(document.getElementById('cost_carton_' + id).value) || 0;
    const saleCarton = parseFloat(document.getElementById('sale_carton_' + id).value) || 0;
    const badge = document.getElementById('margin_badge_' + id);

    if (costCarton > 0) {
        const margin = (((saleCarton - costCarton) / costCarton) * 100).toFixed(1);
        badge.textContent = (margin > 0 ? '+' : '') + margin + '%';
        badge.className = 'badge px-2 py-1 font-monospace ' + 
            (margin > 0 ? 'bg-success bg-opacity-10 text-success border border-success' : 
            (margin < 0 ? 'bg-danger bg-opacity-10 text-danger border border-danger' : 'bg-light text-muted border'));
    } else {
        badge.textContent = '0.0%';
        badge.className = 'badge bg-light text-muted border px-2 py-1 font-monospace';
    }
}

// Sale Rates
function onSaleCartonChange(id) {
    const row = document.getElementById('row-' + id);
    const pcsPerCtn = parseFloat(row.getAttribute('data-pcs-per-ctn')) || 1;
    const cartonRate = parseFloat(document.getElementById('sale_carton_' + id).value) || 0;
    
    if (pcsPerCtn > 0) {
        document.getElementById('sale_piece_' + id).value = (cartonRate / pcsPerCtn).toFixed(4);
    }
    markRowDirty(id);
    updateMarginBadge(id);
}

function onSalePieceChange(id) {
    const row = document.getElementById('row-' + id);
    const pcsPerCtn = parseFloat(row.getAttribute('data-pcs-per-ctn')) || 1;
    const pieceRate = parseFloat(document.getElementById('sale_piece_' + id).value) || 0;
    
    document.getElementById('sale_carton_' + id).value = (pieceRate * pcsPerCtn).toFixed(2);
    markRowDirty(id);
    updateMarginBadge(id);
}

// Cost Rates
function onCostCartonChange(id) {
    const row = document.getElementById('row-' + id);
    const pcsPerCtn = parseFloat(row.getAttribute('data-pcs-per-ctn')) || 1;
    const cartonCost = parseFloat(document.getElementById('cost_carton_' + id).value) || 0;
    
    if (pcsPerCtn > 0) {
        document.getElementById('cost_piece_' + id).value = (cartonCost / pcsPerCtn).toFixed(4);
    }
    markRowDirty(id);
    updateMarginBadge(id);
}

function onCostPieceChange(id) {
    const row = document.getElementById('row-' + id);
    const pcsPerCtn = parseFloat(row.getAttribute('data-pcs-per-ctn')) || 1;
    const pieceCost = parseFloat(document.getElementById('cost_piece_' + id).value) || 0;
    
    document.getElementById('cost_carton_' + id).value = (pieceCost * pcsPerCtn).toFixed(2);
    markRowDirty(id);
    updateMarginBadge(id);
}

// Retail Rates
function onRetailCartonChange(id) {
    const row = document.getElementById('row-' + id);
    const pcsPerCtn = parseFloat(row.getAttribute('data-pcs-per-ctn')) || 1;
    const cartonMRP = parseFloat(document.getElementById('ret_carton_' + id).value) || 0;
    
    if (pcsPerCtn > 0) {
        document.getElementById('ret_piece_' + id).value = (cartonMRP / pcsPerCtn).toFixed(4);
    }
    markRowDirty(id);
}

function onRetailPieceChange(id) {
    const row = document.getElementById('row-' + id);
    const pcsPerCtn = parseFloat(row.getAttribute('data-pcs-per-ctn')) || 1;
    const pieceMRP = parseFloat(document.getElementById('ret_piece_' + id).value) || 0;
    
    document.getElementById('ret_carton_' + id).value = (pieceMRP * pcsPerCtn).toFixed(2);
    markRowDirty(id);
}

// -------------------------------------------------------------------------
// MULTI-SELECTION & BULK FLOATING TOOLBAR
// -------------------------------------------------------------------------

function updateSelectedCount() {
    const checked = document.querySelectorAll('.row-select-checkbox:checked');
    const toolbar = document.getElementById('bulkToolbar');
    const badge = document.getElementById('selectedCountBadge');

    badge.textContent = checked.length + ' Selected';

    if (checked.length > 0) {
        toolbar.classList.remove('d-none');
    } else {
        toolbar.classList.add('d-none');
    }
}

function toggleSelectAll(isChecked) {
    const rows = document.querySelectorAll('#pricingTableBody tr.price-row');
    rows.forEach(r => {
        if (r.style.display !== 'none') {
            const cb = r.querySelector('.row-select-checkbox');
            if (cb) cb.checked = isChecked;
        }
    });
    updateSelectedCount();
}

function deselectAllRows() {
    document.querySelectorAll('.row-select-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
}

function getSelectedIds() {
    const checked = document.querySelectorAll('.row-select-checkbox:checked');
    const ids = [];
    checked.forEach(cb => ids.push(parseInt(cb.value)));
    return ids;
}

// Bulk Tool 1: Apply Percentage
function applyBulkPercentage() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one product using the checkboxes.');
        return;
    }

    const pct = parseFloat(document.getElementById('bulkPercentInput').value);
    if (isNaN(pct) || pct === 0) {
        alert('Please enter a valid percentage adjustment (e.g., 5 for +5%, or -3 for -3%).');
        return;
    }

    const target = document.getElementById('bulkTargetRate').value;
    const factor = 1 + (pct / 100);

    ids.forEach(id => {
        if (target === 'carton_rate') {
            const cur = parseFloat(document.getElementById('sale_carton_' + id).value) || 0;
            document.getElementById('sale_carton_' + id).value = (cur * factor).toFixed(2);
            onSaleCartonChange(id);
        } else if (target === 'purchase_carton_rate') {
            const cur = parseFloat(document.getElementById('cost_carton_' + id).value) || 0;
            document.getElementById('cost_carton_' + id).value = (cur * factor).toFixed(2);
            onCostCartonChange(id);
        } else if (target === 'retail_carton_rate') {
            const cur = parseFloat(document.getElementById('ret_carton_' + id).value) || 0;
            document.getElementById('ret_carton_' + id).value = (cur * factor).toFixed(2);
            onRetailCartonChange(id);
        }
    });

    document.getElementById('bulkPercentInput').value = '';
}

// Bulk Tool 2: Margin on Cost
function applyBulkMarginOnCost() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one product.');
        return;
    }

    const marginPct = parseFloat(document.getElementById('bulkMarginInput').value);
    if (isNaN(marginPct)) {
        alert('Please enter a margin percentage (e.g. 10 for 10% above cost).');
        return;
    }

    ids.forEach(id => {
        const cost = parseFloat(document.getElementById('cost_carton_' + id).value) || 0;
        if (cost > 0) {
            const targetSale = cost * (1 + (marginPct / 100));
            document.getElementById('sale_carton_' + id).value = targetSale.toFixed(2);
            onSaleCartonChange(id);
        }
    });

    document.getElementById('bulkMarginInput').value = '';
}

// Bulk Tool 3: Set Flat Fixed Rate
function applyBulkFixedRate() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one product.');
        return;
    }

    const flat = parseFloat(document.getElementById('bulkFixedInput').value);
    if (isNaN(flat) || flat <= 0) {
        alert('Please enter a positive fixed rate amount.');
        return;
    }

    const target = document.getElementById('bulkTargetRate').value;
    ids.forEach(id => {
        if (target === 'carton_rate') {
            document.getElementById('sale_carton_' + id).value = flat.toFixed(2);
            onSaleCartonChange(id);
        } else if (target === 'purchase_carton_rate') {
            document.getElementById('cost_carton_' + id).value = flat.toFixed(2);
            onCostCartonChange(id);
        } else if (target === 'retail_carton_rate') {
            document.getElementById('ret_carton_' + id).value = flat.toFixed(2);
            onRetailCartonChange(id);
        }
    });

    document.getElementById('bulkFixedInput').value = '';
}

// -------------------------------------------------------------------------
// SAVING ACTIONS (ROW-LEVEL & BATCH AJAX)
// -------------------------------------------------------------------------

function saveSingleRow(id) {
    const btn = document.getElementById('btn_save_' + id);
    const origHtml = btn.innerHTML;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span>`;
    btn.disabled = true;

    const data = new FormData();
    data.append('action', 'save_single_product');
    data.append('product_id', id);
    data.append('carton_rate', document.getElementById('sale_carton_' + id).value);
    data.append('piece_rate', document.getElementById('sale_piece_' + id).value);
    data.append('purchase_carton_rate', document.getElementById('cost_carton_' + id).value);
    data.append('purchase_piece_rate', document.getElementById('cost_piece_' + id).value);
    data.append('retail_carton_rate', document.getElementById('ret_carton_' + id).value);
    data.append('retail_piece_rate', document.getElementById('ret_piece_' + id).value);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        if (res.success) {
            markRowClean(id);
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success');
            setTimeout(() => {
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-success');
            }, 1000);
        } else {
            alert(res.message || 'Error saving product rates.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        alert('Network/Server error: ' + err);
    });
}

function saveAllModifiedRates() {
    if (dirtyRows.size === 0) {
        alert('No rates have been modified. Edit any rate field to make changes.');
        return;
    }

    const saveBtn = document.querySelector('button[onclick="saveAllModifiedRates()"]');
    const origText = saveBtn.innerHTML;
    saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Saving ${dirtyRows.size} Products...`;
    saveBtn.disabled = true;

    const data = new FormData();
    data.append('action', 'save_batch_products');

    dirtyRows.forEach(id => {
        data.append(`rates[${id}][carton_rate]`, document.getElementById('sale_carton_' + id).value);
        data.append(`rates[${id}][piece_rate]`, document.getElementById('sale_piece_' + id).value);
        data.append(`rates[${id}][purchase_carton_rate]`, document.getElementById('cost_carton_' + id).value);
        data.append(`rates[${id}][purchase_piece_rate]`, document.getElementById('cost_piece_' + id).value);
        data.append(`rates[${id}][retail_carton_rate]`, document.getElementById('ret_carton_' + id).value);
        data.append(`rates[${id}][retail_piece_rate]`, document.getElementById('ret_piece_' + id).value);
    });

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
    })
    .then(r => r.json())
    .then(res => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origText;
        if (res.success) {
            const count = dirtyRows.size;
            dirtyRows.forEach(id => markRowClean(id));
            dirtyRows.clear();
            alert(`Success: ${res.message || 'Updated successfully.'}`);
        } else {
            alert('Save failed: ' + (res.message || 'Unknown error'));
        }
    })
    .catch(err => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origText;
        alert('Network error: ' + err);
    });
}
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
