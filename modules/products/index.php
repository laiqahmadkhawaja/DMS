<?php
/**
 * DMS - Products & Pricing Catalog
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('products_view');

$pageTitle = 'Products & Pricing Catalog — ' . APP_NAME;
$activeMenu = 'products';

$search = clean_input($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$statusFilter = clean_input($_GET['status'] ?? 'all');

$sql = "
    SELECT 
        p.*, 
        c.category_name, 
        u.symbol as unit_symbol,
        COALESCE(sb.saleable_qty, 0) as saleable_qty,
        COALESCE(sb.damaged_qty, 0) as damaged_qty,
        COALESCE(sb.total_qty, 0) as total_qty,
        COALESCE(sb.total_value, 0) as total_stock_value
    FROM products p
    JOIN product_categories c ON c.id = p.category_id
    JOIN units u ON u.id = p.base_unit_id
    LEFT JOIN stock_balances sb ON sb.product_id = p.id
    WHERE p.is_deleted = 0
";
$params = [];

if ($search !== '') {
    $sql .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($categoryFilter > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $categoryFilter;
}

if ($statusFilter === 'active') {
    $sql .= " AND p.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND p.is_active = 0";
}

$sql .= " ORDER BY c.category_name ASC, p.product_name ASC";
$products = db_fetch_all($sql, $params);

$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 AND is_active = 1 ORDER BY category_name ASC");
$units = db_fetch_all("SELECT id, unit_name, symbol FROM units WHERE is_deleted = 0 AND is_active = 1 ORDER BY id ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-tag me-2 text-primary"></i>Product Catalog & Pricing</h5>
        <small class="text-muted">Master product list with carton packing, purchase/wholesale rates, and real-time stock</small>
    </div>
    <div class="d-flex gap-2">
        <?php if (has_permission('masters_manage')): ?>
        <a href="<?= APP_URL ?>/modules/categories/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tags me-1"></i> Manage Categories
        </a>
        <a href="<?= APP_URL ?>/modules/brands/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-bookmark-star me-1"></i> Manage Brands
        </a>
        <?php endif; ?>
        <?php if (has_permission('products_create')): ?>
        <a href="<?= APP_URL ?>/modules/products/create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Add Product (Ctrl+N)
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/products/index.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search by product name, SKU, or barcode... (F2)">
            </div>
        </div>
        <div class="col-md-3">
            <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $categoryFilter ? 'selected' : '' ?>>
                        <?= e($cat['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
        </div>
        <?php if ($search !== '' || $categoryFilter > 0 || $statusFilter !== 'all'): ?>
            <div class="col-md-1">
                <a href="<?= APP_URL ?>/modules/products/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Products Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-box-seam me-1"></i> Products Catalog (<?= count($products) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th style="width: 100px;">SKU / Code</th>
                    <th>Product Name & Description</th>
                    <th>Category</th>
                    <th class="text-center">Carton Pack</th>
                    <th class="text-end">Wholesale Rate</th>
                    <th class="text-end">Sale Rate</th>
                    <th class="text-end">In Stock</th>
                    <th class="text-center" style="width: 80px;">Status</th>
                    <th class="text-end" style="width: 110px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No products found in catalog. Click "Add Product" to create inventory items.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($p['product_code']) ?></span></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($p['product_name']) ?></div>
                                <?php if (!empty($p['barcode'])): ?>
                                    <small class="text-muted"><i class="bi bi-upc me-1"></i><?= e($p['barcode']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary-subtle text-dark border"><?= e($p['category_name']) ?></span></td>
                            <td class="text-center small">
                                1 Ctn = <?= format_qty($p['carton_qty']) ?> <?= e($p['unit_symbol']) ?>
                            </td>
                            <td class="text-end fw-semibold"><?= format_currency($p['wholesale_rate']) ?></td>
                            <td class="text-end text-primary"><?= format_currency($p['default_sale_rate']) ?></td>
                            <td class="text-end">
                                <span class="badge bg-<?= (float)$p['saleable_qty'] <= (float)$p['min_stock_level'] ? 'danger' : 'success' ?>-subtle text-<?= (float)$p['saleable_qty'] <= (float)$p['min_stock_level'] ? 'danger' : 'success' ?> border">
                                    <?= format_qty($p['saleable_qty']) ?> <?= e($p['unit_symbol']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $p['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $p['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php if (has_permission('products_edit')): ?>
                                <a href="<?= APP_URL ?>/modules/products/edit.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Product">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (has_permission('products_delete')): ?>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to remove this product from active catalog?', function(){ window.location.href='<?= APP_URL ?>/modules/products/delete.php?id=<?= $p['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Product">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
