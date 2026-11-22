<?php
/**
 * DMS - Bulk Product Category Updater
 * High-speed batch category reclassification, keyword auto-matching, and inline updates
 */

require_once __DIR__ . '/../../config/config.php';
if (!has_permission('masters_manage') && !has_permission('products_edit')) {
    require_permission('masters_manage');
}

$pageTitle = 'Bulk Category Updater — ' . APP_NAME;
$activeMenu = 'bulk_categories';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1');

// -------------------------------------------------------------
// POST ACTION HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_input($_POST['action'] ?? '');

    // 1. Single Product Category Update (Row-level AJAX / Quick Save)
    if ($action === 'single_update') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if ($productId <= 0) {
            $isAjax ? json_error('Invalid product ID.') : redirect_back_with('danger', 'Invalid product ID.');
        }

        if ($categoryId <= 0) {
            $isAjax ? json_error('A valid category is required.') : redirect_back_with('danger', 'A valid category is required.');
        }

        try {
            $cat = db_fetch_one("SELECT category_name FROM product_categories WHERE id = ? AND is_deleted = 0", [$categoryId]);
            if (!$cat) {
                throw new Exception("Selected category not found.");
            }

            $stmt = db()->prepare("UPDATE products SET category_id = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$categoryId, $productId]);

            log_audit('update_category_single', 'products', $productId, "Reclassified product to category: {$cat['category_name']}");

            if ($isAjax) {
                json_success("Category updated to {$cat['category_name']}.", [
                    'product_id'    => $productId,
                    'category_id'   => $categoryId,
                    'category_name' => $cat['category_name']
                ]);
            }
            set_flash_message('success', "Category updated to {$cat['category_name']}.");
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
            $isAjax ? json_error('Please select at least one product to reclassify.') : redirect_back_with('danger', 'Please select at least one product.');
        }

        $categoryId = (int)($_POST['target_category_id'] ?? 0);
        if ($categoryId <= 0) {
            $isAjax ? json_error('Please select a target category.') : redirect_back_with('danger', 'Please select a target category.');
        }

        try {
            $cat = db_fetch_one("SELECT category_name FROM product_categories WHERE id = ? AND is_deleted = 0", [$categoryId]);
            if (!$cat) {
                throw new Exception("Selected category does not exist.");
            }

            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $params = array_merge([$categoryId], $selectedIds);

            $stmt = db()->prepare("
                UPDATE products 
                SET category_id = ?, updated_at = NOW() 
                WHERE id IN ($placeholders) AND is_deleted = 0
            ");
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            log_audit('bulk_assign_category', 'products', null, "Reclassified {$affected} products to category '{$cat['category_name']}'");

            $msg = "Successfully assigned category '{$cat['category_name']}' to {$affected} product(s).";
            if ($isAjax) {
                json_success($msg, ['affected' => $affected, 'category_id' => $categoryId, 'category_name' => $cat['category_name']]);
            }
            set_flash_message('success', $msg);
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            $isAjax ? json_error("Bulk category assignment failed: " . $e->getMessage()) : redirect_back_with('danger', "Bulk category assignment failed: " . $e->getMessage());
        }
    }

    // 3. Keyword Match Rule (Auto-classify products matching a keyword)
    if ($action === 'keyword_assign') {
        $keyword = trim(clean_input($_POST['keyword'] ?? ''));
        $categoryId = (int)($_POST['target_category_id'] ?? 0);

        if (empty($keyword) || strlen($keyword) < 2) {
            $isAjax ? json_error('Please enter a valid keyword (at least 2 characters).') : redirect_back_with('danger', 'Please enter a valid keyword.');
        }

        if ($categoryId <= 0) {
            $isAjax ? json_error('Please select a target category.') : redirect_back_with('danger', 'Please select a target category.');
        }

        try {
            $cat = db_fetch_one("SELECT category_name FROM product_categories WHERE id = ? AND is_deleted = 0", [$categoryId]);
            if (!$cat) {
                throw new Exception("Selected category does not exist.");
            }

            $stmt = db()->prepare("
                UPDATE products 
                SET category_id = ?, updated_at = NOW() 
                WHERE is_deleted = 0 AND (product_name LIKE ? OR product_code LIKE ?)
            ");
            $stmt->execute([$categoryId, "%{$keyword}%", "%{$keyword}%"]);
            $affected = $stmt->rowCount();

            log_audit('keyword_category_assign', 'products', null, "Keyword '{$keyword}' assigned to category '{$cat['category_name']}' ({$affected} products)");

            $msg = "Keyword match applied! Reclassified {$affected} product(s) matching '{$keyword}' to category '{$cat['category_name']}'.";
            if ($isAjax) {
                json_success($msg, ['affected' => $affected, 'category_name' => $cat['category_name']]);
            }
            set_flash_message('success', $msg);
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            $isAjax ? json_error("Keyword categorization failed: " . $e->getMessage()) : redirect_back_with('danger', "Keyword categorization failed: " . $e->getMessage());
        }
    }

    // 4. Batch Save All Modified Rows on Table
    if ($action === 'batch_save') {
        $productCategories = $_POST['product_categories'] ?? [];
        if (!is_array($productCategories) || empty($productCategories)) {
            $isAjax ? json_error('No changes submitted.') : redirect_back_with('warning', 'No changes submitted.');
        }

        try {
            db()->beginTransaction();
            $stmt = db()->prepare("UPDATE products SET category_id = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $savedCount = 0;

            foreach ($productCategories as $pId => $cId) {
                $pId = (int)$pId;
                $cId = (int)$cId;
                if ($pId > 0 && $cId > 0) {
                    $stmt->execute([$cId, $pId]);
                    $savedCount++;
                }
            }
            db()->commit();

            log_audit('batch_save_categories', 'products', null, "Batch updated categories for {$savedCount} products");

            $msg = "Batch save complete! Successfully updated categories for {$savedCount} product(s).";
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
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$brandFilter    = clean_input($_GET['brand_filter'] ?? 'all');
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$perPage        = clean_input($_GET['limit'] ?? '100');
$page           = max(1, (int)($_GET['p'] ?? 1));

// Statistics
$totalProducts   = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE is_deleted = 0");
$totalCategories = (int)db_fetch_val("SELECT COUNT(*) FROM product_categories WHERE is_deleted = 0 AND is_active = 1");

// Distribution by Category
$catStats = db_fetch_all("
    SELECT c.id, c.category_name, COUNT(p.id) as prod_count 
    FROM product_categories c 
    LEFT JOIN products p ON p.category_id = c.id AND p.is_deleted = 0 
    WHERE c.is_deleted = 0 AND c.is_active = 1 
    GROUP BY c.id, c.category_name 
    ORDER BY prod_count DESC
");

// Build Query
$where = ["p.is_deleted = 0"];
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

if ($brandFilter === 'unassigned') {
    $where[] = "(p.brand_id IS NULL OR p.brand_id = 0)";
} elseif (is_numeric($brandFilter) && (int)$brandFilter > 0) {
    $where[] = "p.brand_id = ?";
    $params[] = (int)$brandFilter;
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
        c.category_name,
        c.category_code,
        s.supplier_name
    FROM products p
    JOIN product_categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id AND b.is_deleted = 0
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE {$whereClause}
    ORDER BY c.category_name ASC, p.product_name ASC
    {$limitSql}
";
$products = db_fetch_all($dataSql, $params);

// Master lookups
$categoryList = db_fetch_all("SELECT id, category_code, category_name FROM product_categories WHERE is_deleted = 0 AND is_active = 1 ORDER BY category_name ASC");
$brandList    = db_fetch_all("SELECT id, brand_code, brand_name FROM brands WHERE is_deleted = 0 AND is_active = 1 ORDER BY brand_name ASC");
$supplierList = db_fetch_all("SELECT id, supplier_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");

$nextCatCode = 'CAT-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM product_categories") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark">
            <i class="bi bi-tags-fill text-info me-2"></i>Bulk Category Updater
        </h5>
        <small class="text-muted">Fast mass classification and group re-assignment for catalog products</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/categories/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tags me-1"></i> Categories Master
        </a>
        <a href="<?= APP_URL ?>/modules/brands/bulk_update.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-bookmark-star-fill me-1"></i> Bulk Brand Updater
        </a>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-quick-category')">
            <i class="bi bi-plus-lg me-1"></i> Quick Add Category (Ctrl+N)
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
            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Total Active Categories</small>
            <div class="fs-4 fw-bold text-info mt-1"><?= number_format($totalCategories) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card-dms p-2">
            <small class="text-muted text-uppercase fw-semibold d-block mb-1" style="font-size:0.72rem;">Current Top Categories Distribution</small>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach (array_slice($catStats, 0, 6) as $cs): ?>
                    <span class="badge bg-light text-dark border">
                        <?= e($cs['category_name']) ?>: <strong class="text-primary"><?= $cs['prod_count'] ?></strong>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Smart Keyword Match Tool (Accordion / Collapsible) -->
<div class="card-dms mb-3">
    <div class="card-dms-header d-flex justify-content-between align-items-center py-2" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#collapseKeywordCatTool">
        <span class="fw-semibold text-info">
            <i class="bi bi-magic me-2"></i>Smart Keyword Auto-Categorization Rule
            <small class="text-muted fw-normal ms-2">(Reclassify products matching a keyword e.g. "Wafer", "Biscuit", "Nimko", "Soap")</small>
        </span>
        <i class="bi bi-chevron-down"></i>
    </div>
    <div class="collapse" id="collapseKeywordCatTool">
        <div class="p-3 bg-light-subtle border-top">
            <form id="keywordCatMatchForm" method="POST" action="<?= APP_URL ?>/modules/categories/bulk_update.php" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="keyword_assign">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">If Product Title / Code Contains:</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="keyword" id="catRuleKeyword" class="form-control" placeholder="e.g. Wafer, Biscuit, Candy, Puffs" required>
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Reclassify to Category:</label>
                    <select name="target_category_id" id="catRuleTargetCategory" class="form-select form-select-sm cat-select-target" required>
                        <option value="">-- Choose Target Category --</option>
                        <?php foreach ($categoryList as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['category_name']) ?> (<?= e($c['category_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-info text-white w-100" id="btnApplyCatKeyword">
                        <i class="bi bi-check-circle me-1"></i> Apply Rule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/categories/bulk_update.php" class="row g-2 align-items-center">
        <div class="col-md-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search product name, code, barcode... (F2)">
            </div>
        </div>
        <div class="col-md-3">
            <select name="category_id" class="form-select form-select-sm">
                <option value="0">All Categories</option>
                <?php foreach ($categoryList as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['category_name']) ?> (<?= e($c['category_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="brand_filter" class="form-select form-select-sm">
                <option value="all" <?= $brandFilter === 'all' ? 'selected' : '' ?>>All Brands</option>
                <option value="unassigned" <?= $brandFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned Brand</option>
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
        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                <i class="bi bi-funnel"></i>
            </button>
            <?php if ($search !== '' || $categoryFilter > 0 || $brandFilter !== 'all' || $supplierFilter > 0 || $perPage !== '100'): ?>
                <a href="<?= APP_URL ?>/modules/categories/bulk_update.php" class="btn btn-sm btn-outline-danger" title="Clear Filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Mass Action Toolbar (Sticky) -->
<div class="card-dms p-2 mb-2 bg-light border-info shadow-sm" id="massActionCatBar">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="form-check m-0">
                <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select all on this page">
                <label class="form-check-label small fw-semibold" for="selectAllCheckbox">Select All</label>
            </div>
            <span class="badge bg-secondary" id="selectedCounter">0 selected</span>
        </div>

        <div class="d-flex align-items-center gap-2 flex-grow-1 justify-content-end">
            <span class="small fw-semibold text-muted d-none d-md-inline">Reclassify Selected To:</span>
            <div style="min-width: 240px;">
                <select id="bulkTargetCategory" class="form-select form-select-sm cat-select-target">
                    <option value="">-- Choose Category --</option>
                    <?php foreach ($categoryList as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-info text-white" id="btnApplyBulkCategory">
                <i class="bi bi-check2-all me-1"></i> Apply Category
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
            <i class="bi bi-info-circle me-1"></i> Tip: Changing any category dropdown auto-saves instantly via AJAX.
        </div>
    </div>

    <form id="batchSaveForm" method="POST" action="<?= APP_URL ?>/modules/categories/bulk_update.php">
        <input type="hidden" name="action" value="batch_save">
        
        <div class="table-responsive" style="max-height: 65vh; overflow-y: auto;">
            <table class="table table-compact table-hover align-middle mb-0" id="bulkCategoryTable">
                <thead class="table-light sticky-top" style="z-index: 10;">
                    <tr>
                        <th style="width: 40px;" class="text-center">#</th>
                        <th style="width: 120px;">Product Code</th>
                        <th>Product Name</th>
                        <th style="width: 140px;">Brand</th>
                        <th style="width: 140px;">Supplier</th>
                        <th style="width: 250px;">Assigned Category</th>
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
                        <?php foreach ($products as $idx => $p): ?>
                            <tr id="row-prod-<?= $p['id'] ?>">
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
                                    <?php if (!empty($p['brand_name'])): ?>
                                        <span class="badge bg-primary-subtle text-primary border">
                                            <?= e($p['brand_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-truncate" style="max-width: 140px;" title="<?= e($p['supplier_name'] ?? '') ?>">
                                    <small class="text-muted"><?= e($p['supplier_name'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <select name="product_categories[<?= $p['id'] ?>]" 
                                                class="form-select form-select-sm row-category-select" 
                                                data-product-id="<?= $p['id'] ?>"
                                                data-original-val="<?= $p['category_id'] ?>">
                                            <?php foreach ($categoryList as $c): ?>
                                                <option value="<?= $c['id'] ?>" <?= ($p['category_id'] == $c['id']) ? 'selected' : '' ?>>
                                                    <?= e($c['category_name']) ?>
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

<!-- Non-Modal Slide Drawer: Quick Add Category -->
<div id="drawer-quick-category" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-tag me-2 text-info"></i> Create Product Category</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form id="quickCategoryForm" method="POST" action="<?= APP_URL ?>/modules/categories/create.php">
        <input type="hidden" name="ajax" value="1">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Category Code <span class="text-danger">*</span></label>
                <input type="text" name="category_code" id="newCatCode" class="form-control form-control-sm" value="<?= e($nextCatCode) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                <input type="text" name="category_name" id="newCatName" class="form-control form-control-sm" placeholder="e.g. Wafers, Biscuits, Confectionery, Juices" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Category classification details..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="newCatActive" checked>
                <label class="form-check-label small" for="newCatActive">Active in Catalog</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-info text-white" id="btnSaveCatModal"><i class="bi bi-check2 me-1"></i> Save Category</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const productCheckboxes = document.querySelectorAll('.product-check');
    const selectedCounter = document.getElementById('selectedCounter');
    const bulkTargetCategory = document.getElementById('bulkTargetCategory');
    const btnApplyBulkCategory = document.getElementById('btnApplyBulkCategory');
    const btnBatchSaveForm = document.getElementById('btnBatchSaveForm');
    const batchSaveForm = document.getElementById('batchSaveForm');
    const quickCategoryForm = document.getElementById('quickCategoryForm');
    const keywordCatMatchForm = document.getElementById('keywordCatMatchForm');

    // Update selected counter
    function updateCounter() {
        const checked = document.querySelectorAll('.product-check:checked');
        const count = checked.length;
        selectedCounter.textContent = `${count} selected`;
        if (count > 0) {
            selectedCounter.className = 'badge bg-info text-white';
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
    document.querySelectorAll('.row-category-select').forEach(select => {
        select.addEventListener('change', async function () {
            const productId = this.dataset.productId;
            const categoryId = this.value;
            const badge = document.getElementById(`save-badge-${productId}`);

            // Visual indicator
            this.disabled = true;

            const formData = new FormData();
            formData.append('action', 'single_update');
            formData.append('product_id', productId);
            formData.append('category_id', categoryId);

            try {
                const res = await fetch('<?= APP_URL ?>/modules/categories/bulk_update.php', {
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
                    showToast(data.message || 'Category updated', 'success');
                } else {
                    showToast(data.message || 'Update failed', 'danger');
                }
            } catch (err) {
                showToast('Network error updating category', 'danger');
            } finally {
                this.disabled = false;
            }
        });
    });

    // Mass Apply Category to Selected
    if (btnApplyBulkCategory) {
        btnApplyBulkCategory.addEventListener('click', async function () {
            const checked = Array.from(document.querySelectorAll('.product-check:checked')).map(cb => cb.value);
            if (checked.length === 0) {
                alert('Please select at least one product using the checkboxes.');
                return;
            }

            const targetCategoryId = bulkTargetCategory.value;
            if (!targetCategoryId) {
                alert('Please select a target category from the dropdown.');
                bulkTargetCategory.focus();
                return;
            }

            const catName = bulkTargetCategory.options[bulkTargetCategory.selectedIndex].text;
            if (!confirm(`Reclassify ${checked.length} selected product(s) to Category "${catName}"?`)) {
                return;
            }

            btnApplyBulkCategory.disabled = true;
            btnApplyBulkCategory.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating...';

            const formData = new FormData();
            formData.append('action', 'bulk_assign');
            formData.append('target_category_id', targetCategoryId);
            checked.forEach(id => formData.append('selected_ids[]', id));

            try {
                const res = await fetch('<?= APP_URL ?>/modules/categories/bulk_update.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    // Update table UI rows immediately
                    checked.forEach(id => {
                        const select = document.querySelector(`.row-category-select[data-product-id="${id}"]`);
                        if (select) {
                            select.value = targetCategoryId;
                        }
                    });
                } else {
                    showToast(data.message || 'Bulk assignment failed', 'danger');
                }
            } catch (err) {
                showToast('Error executing bulk assignment', 'danger');
            } finally {
                btnApplyBulkCategory.disabled = false;
                btnApplyBulkCategory.innerHTML = '<i class="bi bi-check2-all me-1"></i> Apply Category';
            }
        });
    }

    // Batch Save All modified rows button
    if (btnBatchSaveForm && batchSaveForm) {
        btnBatchSaveForm.addEventListener('click', function () {
            batchSaveForm.submit();
        });
    }

    // Quick Add Category AJAX
    if (quickCategoryForm) {
        quickCategoryForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveCatModal');
            btn.disabled = true;

            const formData = new FormData(quickCategoryForm);
            try {
                const res = await fetch(quickCategoryForm.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    closeDrawer();

                    // Inject new category into all select dropdowns on the page
                    const cat = data.data;
                    document.querySelectorAll('.cat-select-target, .row-category-select').forEach(sel => {
                        const opt = document.createElement('option');
                        opt.value = cat.id;
                        opt.textContent = `${cat.category_name}`;
                        sel.appendChild(opt);
                    });

                    // Select it in bulk dropdown
                    if (bulkTargetCategory) {
                        bulkTargetCategory.value = cat.id;
                    }

                    // Reset form
                    quickCategoryForm.reset();
                } else {
                    showToast(data.message || 'Failed to create category', 'danger');
                }
            } catch (err) {
                showToast('Error creating category', 'danger');
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
