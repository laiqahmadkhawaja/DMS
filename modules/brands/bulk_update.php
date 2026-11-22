<?php
/**
 * DMS - Bulk Product Brand Updater
 * High-speed batch brand assignment, keyword matching, and inline updates
 */

require_once __DIR__ . '/../../config/config.php';
if (!has_permission('masters_manage') && !has_permission('products_edit')) {
    require_permission('masters_manage');
}

$pageTitle = 'Bulk Brand Updater — ' . APP_NAME;
$activeMenu = 'bulk_brands';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1');

// -------------------------------------------------------------
// POST ACTION HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_input($_POST['action'] ?? '');

    // 1. Single Product Brand Update (Row-level AJAX / Quick Save)
    if ($action === 'single_update') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $rawBrandId = $_POST['brand_id'] ?? '';
        $brandId = ($rawBrandId === '' || $rawBrandId === '0' || $rawBrandId === 'null') ? null : (int)$rawBrandId;

        if ($productId <= 0) {
            $isAjax ? json_error('Invalid product ID.') : redirect_back_with('danger', 'Invalid product ID.');
        }

        try {
            $stmt = db()->prepare("UPDATE products SET brand_id = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$brandId, $productId]);

            $brandName = 'None (Unassigned)';
            if ($brandId) {
                $b = db_fetch_one("SELECT brand_name FROM brands WHERE id = ?", [$brandId]);
                if ($b) $brandName = $b['brand_name'];
            }

            log_audit('update_brand_single', 'products', $productId, "Assigned brand: {$brandName}");

            if ($isAjax) {
                json_success("Product brand updated to {$brandName}.", [
                    'product_id' => $productId,
                    'brand_id'   => $brandId,
                    'brand_name' => $brandName
                ]);
            }
            set_flash_message('success', "Product brand updated to {$brandName}.");
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            $isAjax ? json_error("Update failed: " . $e->getMessage()) : redirect_back_with('danger', "Update failed: " . $e->getMessage());
        }
    }

    // 2. Bulk Assign Selected Products
    if ($action === 'bulk_assign') {
        $selectedIds = $_POST['selected_ids'] ?? [];
        if (!is_array($selectedIds) && is_string($selectedIds)) {
            $selectedIds = explode(',', $selectedIds);
        }
        $selectedIds = array_filter(array_map('intval', (array)$selectedIds));

        if (empty($selectedIds)) {
            $isAjax ? json_error('Please select at least one product to update.') : redirect_back_with('danger', 'Please select at least one product to update.');
        }

        $rawBrandId = $_POST['target_brand_id'] ?? '';
        $brandId = ($rawBrandId === '' || $rawBrandId === 'clear' || $rawBrandId === '0') ? null : (int)$rawBrandId;

        try {
            $brandName = 'Unassigned';
            if ($brandId) {
                $b = db_fetch_one("SELECT brand_name FROM brands WHERE id = ? AND is_deleted = 0", [$brandId]);
                if (!$b) {
                    throw new Exception("Selected brand does not exist.");
                }
                $brandName = $b['brand_name'];
            }

            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $params = array_merge([$brandId], $selectedIds);

            $stmt = db()->prepare("
                UPDATE products 
                SET brand_id = ?, updated_at = NOW() 
                WHERE id IN ($placeholders) AND is_deleted = 0
            ");
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            log_audit('bulk_assign_brand', 'products', null, "Assigned brand '{$brandName}' to {$affected} products");

            $msg = "Successfully assigned brand '{$brandName}' to {$affected} product(s).";
            if ($isAjax) {
                json_success($msg, ['affected' => $affected, 'brand_id' => $brandId, 'brand_name' => $brandName]);
            }
            set_flash_message('success', $msg);
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            $isAjax ? json_error("Bulk assignment failed: " . $e->getMessage()) : redirect_back_with('danger', "Bulk assignment failed: " . $e->getMessage());
        }
    }

    // 3. Keyword Match Rule (Auto-assign brand to all products matching keyword)
    if ($action === 'keyword_assign') {
        $keyword = trim(clean_input($_POST['keyword'] ?? ''));
        $rawBrandId = $_POST['target_brand_id'] ?? '';
        $brandId = (int)$rawBrandId;

        if (empty($keyword) || strlen($keyword) < 2) {
            $isAjax ? json_error('Please enter a valid search keyword (at least 2 characters).') : redirect_back_with('danger', 'Please enter a valid search keyword.');
        }

        if ($brandId <= 0) {
            $isAjax ? json_error('Please select a target brand.') : redirect_back_with('danger', 'Please select a target brand.');
        }

        try {
            $brand = db_fetch_one("SELECT brand_name FROM brands WHERE id = ? AND is_deleted = 0", [$brandId]);
            if (!$brand) {
                throw new Exception("Selected brand does not exist.");
            }

            $onlyUnassigned = !empty($_POST['only_unassigned']) ? 1 : 0;
            $sql = "UPDATE products SET brand_id = ?, updated_at = NOW() WHERE is_deleted = 0 AND (product_name LIKE ? OR product_code LIKE ?)";
            $params = [$brandId, "%{$keyword}%", "%{$keyword}%"];

            if ($onlyUnassigned) {
                $sql .= " AND (brand_id IS NULL OR brand_id = 0)";
            }

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            log_audit('keyword_brand_assign', 'products', null, "Keyword '{$keyword}' assigned to brand '{$brand['brand_name']}' ({$affected} products)");

            $msg = "Keyword match applied! Assigned brand '{$brand['brand_name']}' to {$affected} product(s) matching '{$keyword}'.";
            if ($isAjax) {
                json_success($msg, ['affected' => $affected, 'brand_name' => $brand['brand_name']]);
            }
            set_flash_message('success', $msg);
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            $isAjax ? json_error("Keyword assignment failed: " . $e->getMessage()) : redirect_back_with('danger', "Keyword assignment failed: " . $e->getMessage());
        }
    }

    // 4. Batch Save All Modified Rows on Table
    if ($action === 'batch_save') {
        $productBrands = $_POST['product_brands'] ?? [];
        if (!is_array($productBrands) || empty($productBrands)) {
            $isAjax ? json_error('No changes submitted.') : redirect_back_with('warning', 'No changes submitted.');
        }

        try {
            db()->beginTransaction();
            $stmt = db()->prepare("UPDATE products SET brand_id = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $savedCount = 0;

            foreach ($productBrands as $pId => $bId) {
                $pId = (int)$pId;
                $bId = ($bId === '' || $bId === '0' || $bId === 'null') ? null : (int)$bId;
                $stmt->execute([$bId, $pId]);
                $savedCount++;
            }
            db()->commit();

            log_audit('batch_save_brands', 'products', null, "Batch updated brands for {$savedCount} products");

            $msg = "Batch save complete! Successfully updated {$savedCount} product(s).";
            if ($isAjax) {
                json_success($msg, ['saved_count' => $savedCount]);
            }
            set_flash_message('success', $msg);
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            db()->rollBack();
            $isAjax ? json_error("Batch save failed: " . $e->getMessage()) : redirect_back_with('danger', "Batch save failed: " . $e->getMessage());
        }
    }
}

// -------------------------------------------------------------
// GET FILTERS & PRODUCT LISTING
// -------------------------------------------------------------
$search         = clean_input($_GET['search'] ?? '');
$brandFilter    = clean_input($_GET['brand_filter'] ?? 'all'); // 'all', 'unassigned', or specific brand ID
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$perPage        = clean_input($_GET['limit'] ?? '100'); // 50, 100, 200, 'all'
$page           = max(1, (int)($_GET['p'] ?? 1));

// Statistics
$totalProducts   = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0");
$assignedBrands  = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0 AND brand_id IS NOT NULL AND brand_id > 0");
$unassignedCount = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0 AND (brand_id IS NULL OR brand_id = 0)");
$totalBrands     = (int)db_fetch_val("SELECT COUNT(*) FROM brands WHERE is_deleted = 0 AND is_active = 1");

// Build Query
$where = ["p.is_deleted = 0"];
$params = [];

if ($search !== '') {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($brandFilter === 'unassigned') {
    $where[] = "(p.brand_id IS NULL OR p.brand_id = 0)";
} elseif (is_numeric($brandFilter) && (int)$brandFilter > 0) {
    $where[] = "p.brand_id = ?";
    $params[] = (int)$brandFilter;
}

if ($categoryFilter > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $categoryFilter;
}

if ($supplierFilter > 0) {
    $where[] = "p.supplier_id = ?";
    $params[] = $supplierFilter;
}

$whereClause = implode(" AND ", $where);

// Count matching records for pagination
$countSql = "SELECT COUNT(*) FROM products p WHERE {$whereClause}";
$filteredTotal = (int)db_fetch_val($countSql, $params);

// Pagination limits
$limitSql = "";
if ($perPage !== 'all') {
    $limitInt = max(10, min(500, (int)$perPage));
    $offset = ($page - 1) * $limitInt;
    $limitSql = " LIMIT {$limitInt} OFFSET {$offset}";
    $totalPages = ceil($filteredTotal / $limitInt);
} else {
    $totalPages = 1;
}

// Fetch products
$dataSql = "
    SELECT 
        p.id, 
        p.product_code, 
        p.barcode, 
        p.product_name, 
        p.brand_id, 
        p.category_id, 
        p.supplier_id,
        p.purchase_rate,
        p.default_sale_rate,
        p.carton_qty,
        p.packing_description,
        p.is_active,
        b.brand_name,
        b.brand_code,
        c.category_name,
        s.supplier_name
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id AND b.is_deleted = 0
    LEFT JOIN product_categories c ON c.id = p.category_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE {$whereClause}
    ORDER BY (p.brand_id IS NULL OR p.brand_id = 0) DESC, p.product_name ASC
    {$limitSql}
";
$products = db_fetch_all($dataSql, $params);

// Master lookups
$brandList = db_fetch_all("SELECT id, brand_code, brand_name FROM brands WHERE is_deleted = 0 AND is_active = 1 ORDER BY brand_name ASC");
$categoryList = db_fetch_all("SELECT id, category_code, category_name FROM product_categories WHERE is_deleted = 0 AND is_active = 1 ORDER BY category_name ASC");
$supplierList = db_fetch_all("SELECT id, supplier_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");

$nextBrandCode = 'BRD-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM brands") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark">
            <i class="bi bi-bookmark-star-fill text-primary me-2"></i>Bulk Brand Updater
        </h5>
        <small class="text-muted">Mass assign and rapidly align manufacturer brands across inventory products</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/brands/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-bookmark-star me-1"></i> Brands Master
        </a>
        <a href="<?= APP_URL ?>/modules/categories/bulk_update.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tags-fill me-1"></i> Bulk Category Updater
        </a>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-quick-brand')">
            <i class="bi bi-plus-lg me-1"></i> Quick Add Brand (Ctrl+N)
        </button>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card-dms p-2 text-center">
            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Total Catalog Products</small>
            <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($totalProducts) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-dms p-2 text-center">
            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Brand Assigned</small>
            <div class="fs-4 fw-bold text-success mt-1">
                <?= number_format($assignedBrands) ?>
                <small class="fs-6 fw-normal text-muted">(<?= $totalProducts > 0 ? round(($assignedBrands / $totalProducts) * 100) : 0 ?>%)</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-dms p-2 text-center bg-warning-subtle border-warning">
            <small class="text-warning-emphasis text-uppercase fw-semibold" style="font-size:0.72rem;">Unassigned / Missing Brand</small>
            <div class="fs-4 fw-bold text-danger mt-1"><?= number_format($unassignedCount) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-dms p-2 text-center">
            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Active Brands</small>
            <div class="fs-4 fw-bold text-primary mt-1"><?= number_format($totalBrands) ?></div>
        </div>
    </div>
</div>

<!-- Smart Keyword Match Tool (Accordion / Collapsible) -->
<div class="card-dms mb-3">
    <div class="card-dms-header d-flex justify-content-between align-items-center py-2" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#collapseKeywordTool">
        <span class="fw-semibold text-primary">
            <i class="bi bi-magic me-2"></i>Smart Keyword Auto-Assign Rule
            <small class="text-muted fw-normal ms-2">(Automatically link products containing a keyword like "Brunik", "Wafer", "Mitchells")</small>
        </span>
        <i class="bi bi-chevron-down"></i>
    </div>
    <div class="collapse <?= ($unassignedCount > 0 && $assignedBrands === 0) ? 'show' : '' ?>" id="collapseKeywordTool">
        <div class="p-3 bg-light-subtle border-top">
            <form id="keywordMatchForm" method="POST" action="<?= APP_URL ?>/modules/brands/bulk_update.php" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="keyword_assign">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">If Product Title / Code Contains:</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="keyword" id="ruleKeyword" class="form-control" placeholder="e.g. Brunik, Wafer, Choclate" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Assign to Brand:</label>
                    <select name="target_brand_id" id="ruleTargetBrand" class="form-select form-select-sm brand-select-target" required>
                        <option value="">-- Choose Target Brand --</option>
                        <?php foreach ($brandList as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= e($br['brand_name']) ?> (<?= e($br['brand_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check form-switch pt-4">
                        <input class="form-check-input" type="checkbox" name="only_unassigned" id="ruleOnlyUnassigned" value="1" checked>
                        <label class="form-check-label small" for="ruleOnlyUnassigned">Only Unassigned</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100" id="btnApplyKeyword">
                        <i class="bi bi-check-circle me-1"></i> Apply Rule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/brands/bulk_update.php" class="row g-2 align-items-center">
        <div class="col-md-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search product name, code, barcode... (F2)">
            </div>
        </div>
        <div class="col-md-2">
            <select name="brand_filter" class="form-select form-select-sm">
                <option value="all" <?= $brandFilter === 'all' ? 'selected' : '' ?>>All Brands</option>
                <option value="unassigned" <?= $brandFilter === 'unassigned' ? 'selected' : '' ?>>⚠️ Missing Brand (Unassigned)</option>
                <optgroup label="Filter by Brand:">
                    <?php foreach ($brandList as $br): ?>
                        <option value="<?= $br['id'] ?>" <?= $brandFilter === (string)$br['id'] ? 'selected' : '' ?>>
                            <?= e($br['brand_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div class="col-md-2">
            <select name="category_id" class="form-select form-select-sm">
                <option value="0">All Categories</option>
                <?php foreach ($categoryList as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="supplier_id" class="form-select form-select-sm">
                <option value="0">All Suppliers</option>
                <?php foreach ($supplierList as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $supplierFilter === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['supplier_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <select name="limit" class="form-select form-select-sm">
                <option value="50" <?= $perPage === '50' ? 'selected' : '' ?>>50 / p</option>
                <option value="100" <?= $perPage === '100' ? 'selected' : '' ?>>100 / p</option>
                <option value="200" <?= $perPage === '200' ? 'selected' : '' ?>>200 / p</option>
                <option value="all" <?= $perPage === 'all' ? 'selected' : '' ?>>Show All</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline-secondary flex-grow-1">
                <i class="bi bi-funnel"></i> Filter
            </button>
            <?php if ($search !== '' || $brandFilter !== 'all' || $categoryFilter > 0 || $supplierFilter > 0 || $perPage !== '100'): ?>
                <a href="<?= APP_URL ?>/modules/brands/bulk_update.php" class="btn btn-sm btn-outline-danger" title="Clear Filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Mass Action Toolbar (Sticky) -->
<div class="card-dms p-2 mb-2 bg-light border-primary shadow-sm" id="massActionBar">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="form-check m-0">
                <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select all on this page">
                <label class="form-check-label small fw-semibold" for="selectAllCheckbox">Select All</label>
            </div>
            <span class="badge bg-secondary" id="selectedCounter">0 selected</span>
        </div>

        <div class="d-flex align-items-center gap-2 flex-grow-1 justify-content-end">
            <span class="small fw-semibold text-muted d-none d-md-inline">Assign Selected To:</span>
            <div style="min-width: 220px;">
                <select id="bulkTargetBrand" class="form-select form-select-sm brand-select-target">
                    <option value="">-- Choose Brand --</option>
                    <?php foreach ($brandList as $br): ?>
                        <option value="<?= $br['id'] ?>"><?= e($br['brand_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-primary" id="btnApplyBulkBrand">
                <i class="bi bi-check2-all me-1"></i> Apply Brand
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="btnClearBulkBrand" title="Set selected products to Unassigned">
                <i class="bi bi-eraser me-1"></i> Clear Brand
            </button>
            <button type="button" class="btn btn-sm btn-success" id="btnBatchSaveForm" title="Save all changed dropdowns in the table">
                <i class="bi bi-floppy me-1"></i> Save All Table Changes
            </button>
        </div>
    </div>
</div>

<!-- Products Table -->
<div class="card-dms mb-3">
    <div class="card-dms-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-table me-1"></i> Catalog Products 
            <span class="badge bg-light text-dark border ms-1"><?= count($products) ?> showing</span>
            <?php if ($filteredTotal > count($products)): ?>
                <span class="text-muted small ms-1">(<?= $filteredTotal ?> matching filter)</span>
            <?php endif; ?>
        </span>
        <div class="small text-muted">
            <i class="bi bi-info-circle me-1"></i> Tip: Changing any brand dropdown auto-saves instantly via AJAX.
        </div>
    </div>

    <form id="batchSaveForm" method="POST" action="<?= APP_URL ?>/modules/brands/bulk_update.php">
        <input type="hidden" name="action" value="batch_save">
        
        <div class="table-responsive" style="max-height: 65vh; overflow-y: auto;">
            <table class="table table-compact table-hover align-middle mb-0" id="bulkBrandTable">
                <thead class="table-light sticky-top" style="z-index: 10;">
                    <tr>
                        <th style="width: 40px;" class="text-center">#</th>
                        <th style="width: 120px;">Product Code</th>
                        <th>Product Name</th>
                        <th style="width: 150px;">Category</th>
                        <th style="width: 150px;">Supplier</th>
                        <th style="width: 250px;">Manufacturer Brand</th>
                        <th style="width: 90px;" class="text-end">Carton</th>
                        <th style="width: 100px;" class="text-end">Sale Rate</th>
                        <th style="width: 60px;" class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block text-secondary mb-2"></i>
                                No products found matching your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $idx => $p): 
                            $hasBrand = !empty($p['brand_id']);
                        ?>
                            <tr id="row-prod-<?= $p['id'] ?>" class="<?= !$hasBrand ? 'table-warning-subtle' : '' ?>">
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input product-check" name="selected_ids[]" value="<?= $p['id'] ?>" data-product-id="<?= $p['id'] ?>">
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace"><?= e($p['product_code']) ?></span>
                                    <?php if (!empty($p['barcode'])): ?>
                                        <div class="text-muted" style="font-size:0.7rem;"><?= e($p['barcode']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($p['product_name']) ?></div>
                                    <?php if (!empty($p['packing_description'])): ?>
                                        <small class="text-muted"><?= e($p['packing_description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border">
                                        <?= e($p['category_name'] ?? 'None') ?>
                                    </span>
                                </td>
                                <td class="text-truncate" style="max-width: 140px;" title="<?= e($p['supplier_name'] ?? '') ?>">
                                    <small class="text-muted"><?= e($p['supplier_name'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <select name="product_brands[<?= $p['id'] ?>]" 
                                                class="form-select form-select-sm row-brand-select <?= !$hasBrand ? 'border-warning fw-bold text-danger' : '' ?>" 
                                                data-product-id="<?= $p['id'] ?>"
                                                data-original-val="<?= $p['brand_id'] ?? '' ?>">
                                            <option value="" class="text-danger">⚠️ -- Unassigned --</option>
                                            <?php foreach ($brandList as $br): ?>
                                                <option value="<?= $br['id'] ?>" <?= ($p['brand_id'] == $br['id']) ? 'selected' : '' ?>>
                                                    <?= e($br['brand_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="save-status-badge badge bg-success-subtle text-success border d-none" id="save-badge-<?= $p['id'] ?>">
                                            <i class="bi bi-check2"></i>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-end font-monospace small">
                                    <?= (float)$p['carton_qty'] ?>
                                </td>
                                <td class="text-end font-monospace small">
                                    <?= number_format((float)$p['default_sale_rate'], 2) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($p['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">Off</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <!-- Pagination Footer -->
    <?php if ($totalPages > 1): ?>
        <div class="card-dms-footer d-flex justify-content-between align-items-center p-2 border-top">
            <span class="text-muted small">
                Showing page <?= $page ?> of <?= $totalPages ?> (Total: <?= $filteredTotal ?> products)
            </span>
            <ul class="pagination pagination-sm m-0">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p' => $page - 1])) ?>">Previous</a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p' => $page + 1])) ?>">Next</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- Non-Modal Slide Drawer: Quick Add Brand -->
<div id="drawer-quick-brand" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-bookmark-plus me-2 text-primary"></i> Create Brand Master</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form id="quickBrandForm" method="POST" action="<?= APP_URL ?>/modules/brands/create.php">
        <input type="hidden" name="ajax" value="1">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Brand Code <span class="text-danger">*</span></label>
                <input type="text" name="brand_code" id="newBrandCode" class="form-control form-control-sm" value="<?= e($nextBrandCode) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Brand / Manufacturer Name <span class="text-danger">*</span></label>
                <input type="text" name="brand_name" id="newBrandName" class="form-control form-control-sm" placeholder="e.g. Brunik, National, Mitchells, Hash" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Brand notes or manufacturer details..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="newBrandActive" checked>
                <label class="form-check-label small" for="newBrandActive">Active in Catalog</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary" id="btnSaveBrandModal"><i class="bi bi-check2 me-1"></i> Save Brand</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const productCheckboxes = document.querySelectorAll('.product-check');
    const selectedCounter = document.getElementById('selectedCounter');
    const bulkTargetBrand = document.getElementById('bulkTargetBrand');
    const btnApplyBulkBrand = document.getElementById('btnApplyBulkBrand');
    const btnClearBulkBrand = document.getElementById('btnClearBulkBrand');
    const btnBatchSaveForm = document.getElementById('btnBatchSaveForm');
    const batchSaveForm = document.getElementById('batchSaveForm');
    const quickBrandForm = document.getElementById('quickBrandForm');
    const keywordMatchForm = document.getElementById('keywordMatchForm');

    // Update selected counter
    function updateCounter() {
        const checked = document.querySelectorAll('.product-check:checked');
        const count = checked.length;
        selectedCounter.textContent = `${count} selected`;
        if (count > 0) {
            selectedCounter.className = 'badge bg-primary';
        } else {
            selectedCounter.className = 'badge bg-secondary';
        }
    }

    // Select all toggle
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            productCheckboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
            });
            updateCounter();
        });
    }

    productCheckboxes.forEach(cb => {
        cb.addEventListener('change', function () {
            if (!this.checked && selectAllCheckbox.checked) {
                selectAllCheckbox.checked = false;
            }
            updateCounter();
        });
    });

    // Inline AJAX Auto-Save on Dropdown Change
    document.querySelectorAll('.row-brand-select').forEach(select => {
        select.addEventListener('change', async function () {
            const productId = this.dataset.productId;
            const brandId = this.value;
            const badge = document.getElementById(`save-badge-${productId}`);
            const row = document.getElementById(`row-prod-${productId}`);

            // Visual indicator
            this.disabled = true;

            const formData = new FormData();
            formData.append('action', 'single_update');
            formData.append('product_id', productId);
            formData.append('brand_id', brandId);

            try {
                const res = await fetch('<?= APP_URL ?>/modules/brands/bulk_update.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    if (badge) {
                        badge.classList.remove('d-none');
                        setTimeout(() => badge.classList.add('d-none'), 2500);
                    }
                    if (brandId === '' || brandId === '0') {
                        this.classList.add('border-warning', 'fw-bold', 'text-danger');
                        if (row) row.classList.add('table-warning-subtle');
                    } else {
                        this.classList.remove('border-warning', 'fw-bold', 'text-danger');
                        if (row) row.classList.remove('table-warning-subtle');
                    }
                    showToast(data.message || 'Brand updated', 'success');
                } else {
                    showToast(data.message || 'Update failed', 'danger');
                }
            } catch (err) {
                showToast('Network error updating brand', 'danger');
            } finally {
                this.disabled = false;
            }
        });
    });

    // Mass Apply Brand to Selected
    if (btnApplyBulkBrand) {
        btnApplyBulkBrand.addEventListener('click', async function () {
            const checked = Array.from(document.querySelectorAll('.product-check:checked')).map(cb => cb.value);
            if (checked.length === 0) {
                alert('Please select at least one product using the checkboxes.');
                return;
            }

            const targetBrandId = bulkTargetBrand.value;
            if (!targetBrandId) {
                alert('Please select a target brand from the dropdown.');
                bulkTargetBrand.focus();
                return;
            }

            const brandName = bulkTargetBrand.options[bulkTargetBrand.selectedIndex].text;
            if (!confirm(`Apply Brand "${brandName}" to ${checked.length} selected product(s)?`)) {
                return;
            }

            btnApplyBulkBrand.disabled = true;
            btnApplyBulkBrand.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating...';

            const formData = new FormData();
            formData.append('action', 'bulk_assign');
            formData.append('target_brand_id', targetBrandId);
            checked.forEach(id => formData.append('selected_ids[]', id));

            try {
                const res = await fetch('<?= APP_URL ?>/modules/brands/bulk_update.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    // Update table UI rows immediately
                    checked.forEach(id => {
                        const select = document.querySelector(`.row-brand-select[data-product-id="${id}"]`);
                        if (select) {
                            select.value = targetBrandId;
                            select.classList.remove('border-warning', 'fw-bold', 'text-danger');
                        }
                        const row = document.getElementById(`row-prod-${id}`);
                        if (row) row.classList.remove('table-warning-subtle');
                    });
                } else {
                    showToast(data.message || 'Bulk assignment failed', 'danger');
                }
            } catch (err) {
                showToast('Error executing bulk assignment', 'danger');
            } finally {
                btnApplyBulkBrand.disabled = false;
                btnApplyBulkBrand.innerHTML = '<i class="bi bi-check2-all me-1"></i> Apply Brand';
            }
        });
    }

    // Mass Clear Brand (Unassigned)
    if (btnClearBulkBrand) {
        btnClearBulkBrand.addEventListener('click', async function () {
            const checked = Array.from(document.querySelectorAll('.product-check:checked')).map(cb => cb.value);
            if (checked.length === 0) {
                alert('Please select at least one product using the checkboxes.');
                return;
            }

            if (!confirm(`Clear and remove brand for ${checked.length} selected product(s)?`)) {
                return;
            }

            btnClearBulkBrand.disabled = true;

            const formData = new FormData();
            formData.append('action', 'bulk_assign');
            formData.append('target_brand_id', 'clear');
            checked.forEach(id => formData.append('selected_ids[]', id));

            try {
                const res = await fetch('<?= APP_URL ?>/modules/brands/bulk_update.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    checked.forEach(id => {
                        const select = document.querySelector(`.row-brand-select[data-product-id="${id}"]`);
                        if (select) {
                            select.value = '';
                            select.classList.add('border-warning', 'fw-bold', 'text-danger');
                        }
                        const row = document.getElementById(`row-prod-${id}`);
                        if (row) row.classList.add('table-warning-subtle');
                    });
                } else {
                    showToast(data.message || 'Operation failed', 'danger');
                }
            } catch (err) {
                showToast('Error removing brand', 'danger');
            } finally {
                btnClearBulkBrand.disabled = false;
            }
        });
    }

    // Batch Save All modified rows button
    if (btnBatchSaveForm && batchSaveForm) {
        btnBatchSaveForm.addEventListener('click', function () {
            batchSaveForm.submit();
        });
    }

    // Quick Add Brand AJAX
    if (quickBrandForm) {
        quickBrandForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveBrandModal');
            btn.disabled = true;

            const formData = new FormData(quickBrandForm);
            try {
                const res = await fetch(quickBrandForm.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    closeDrawer();

                    // Inject new brand into all select dropdowns on the page
                    const brand = data.data;
                    document.querySelectorAll('.brand-select-target, .row-brand-select').forEach(sel => {
                        const opt = document.createElement('option');
                        opt.value = brand.id;
                        opt.textContent = `${brand.brand_name}`;
                        sel.appendChild(opt);
                    });

                    // Select it in bulk dropdown
                    if (bulkTargetBrand) {
                        bulkTargetBrand.value = brand.id;
                    }

                    // Reset form
                    quickBrandForm.reset();
                } else {
                    showToast(data.message || 'Failed to create brand', 'danger');
                }
            } catch (err) {
                showToast('Error creating brand', 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
