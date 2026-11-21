<?php
/**
 * DMS - Distribution Management System
 * Inventory - Real-time Stock Overview & Valuation
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('inventory_view');

$pageTitle = 'Warehouse Inventory Overview';
$activeMenu = 'inventory';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Inventory', 'url' => '']
];

// Filters
$warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 1;
$categoryId  = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$stockFilter = sanitize_input($_GET['stock_filter'] ?? ''); // 'low', 'out', 'available'
$search      = sanitize_input($_GET['q'] ?? '');

$where = ["p.is_active = 1", "p.is_deleted = 0"];
$params = [$warehouseId];

if ($categoryId > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $categoryId;
}
if (!empty($search)) {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary metrics across inventory
$statsSql = "
    SELECT 
        COUNT(p.id) as total_products,
        SUM(sb.total_value) as total_inventory_valuation,
        SUM(sb.saleable_qty) as total_units_in_stock,
        SUM(CASE WHEN sb.saleable_qty <= p.min_stock_level THEN 1 ELSE 0 END) as low_stock_count,
        SUM(sb.damaged_qty * p.purchase_rate) as damaged_stock_valuation
    FROM products p
    LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = ?
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_products' => 0,
    'total_inventory_valuation' => 0.0,
    'total_units_in_stock' => 0.0,
    'low_stock_count' => 0,
    'damaged_stock_valuation' => 0.0
];

// Fetch Paginated Products Stock
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(p.id)
    FROM products p
    LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = ?
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$products = db_fetch_all("
    SELECT 
        p.id,
        p.product_code,
        p.product_name,
        p.carton_qty as ctn_ratio,
        p.purchase_rate as cost_price,
        p.wholesale_rate,
        p.min_stock_level,
        c.category_name,
        u.unit_code as base_unit_code,
        COALESCE(sb.saleable_qty, 0) as saleable_qty,
        COALESCE(sb.damaged_qty, 0) as damaged_qty,
        COALESCE(sb.expired_qty, 0) as expired_qty,
        COALESCE(sb.total_qty, 0) as total_qty,
        COALESCE(sb.total_value, 0) as total_value,
        sb.last_updated_at
    FROM products p
    LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = ?
    LEFT JOIN product_categories c ON p.category_id = c.id
    LEFT JOIN units u ON p.base_unit_id = u.id
    WHERE {$whereClause}
    ORDER BY c.category_name ASC, p.product_name ASC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$warehouses = db_fetch_all("SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC");
$categories = db_fetch_all("SELECT id, category_name FROM product_categories ORDER BY category_name ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-boxes me-2"></i>Warehouse Inventory & Stock Overview
                </h4>
                <p class="text-muted small mb-0">Real-time carton/pack stock balances, inventory valuation, and stock health alerts</p>
            </div>
            <div class="d-flex gap-2">
                <a href="ledger.php" class="btn btn-outline-primary shadow-sm">
                    <i class="bi bi-journal-text me-1"></i> Stock Movement Ledger
                </a>
                <?php if (has_permission('inventory_adjust')): ?>
                    <a href="adjust.php" class="btn btn-warning text-dark shadow-sm fw-bold">
                        <i class="bi bi-sliders me-1"></i> Stock Audit / Adjust
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Stock Valuation</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$stats['total_inventory_valuation']) ?></div>
                        <div class="small text-muted mt-1">Based on purchase cost</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Active SKUs</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_products']) ?></div>
                        <div class="small text-success mt-1"><?= number_format((float)$stats['total_units_in_stock']) ?> total units in warehouse</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Low Stock Warning</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= number_format($stats['low_stock_count']) ?></div>
                        <div class="small text-muted mt-1">Below minimum threshold</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Quarantine / Damaged</div>
                        <div class="fs-4 fw-bold text-danger mt-1"><?= format_currency((float)$stats['damaged_stock_valuation']) ?></div>
                        <div class="small text-danger mt-1">Non-saleable stock</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="index.php" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Warehouse</label>
                        <select name="warehouse_id" class="form-select form-select-sm">
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $warehouseId === (int)$wh['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($wh['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Category</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">-- All Categories --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Search Product / SKU</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Name or code..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-filter"></i> Filter
                        </button>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stock Balances Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-box-seam me-1"></i> Stock Ledger Register (<?= number_format($totalRows) ?> Items)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">SKU</th>
                                <th>Product Description</th>
                                <th>Category</th>
                                <th class="text-center">Cartons + Pcs</th>
                                <th class="text-center">Total Units</th>
                                <th class="text-end">Cost Price</th>
                                <th class="text-end">Wholesale Rate</th>
                                <th class="text-end">Valuation</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No stock records found for the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): 
                                    $saleable = (float)$p['saleable_qty'];
                                    $ctnRatio = (float)$p['ctn_ratio'] > 0 ? (float)$p['ctn_ratio'] : 1;
                                    $ctns = floor($saleable / $ctnRatio);
                                    $pcs  = fmod($saleable, $ctnRatio);
                                    $isLow = ($saleable <= (float)$p['min_stock_level']);
                                ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy small">
                                            <?= htmlspecialchars($p['product_code']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($p['product_name']) ?></div>
                                            <small class="text-muted">Pack: 1*<?= (int)$ctnRatio ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category_name']) ?></span></td>
                                        <td class="text-center fw-bold text-dark">
                                            <?= $ctns ?> Ctns <?= $pcs > 0 ? "+ {$pcs} Pcs" : '' ?>
                                        </td>
                                        <td class="text-center fw-bold fs-6 <?= $saleable <= 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= number_format($saleable) ?>
                                        </td>
                                        <td class="text-end"><?= format_currency((float)$p['cost_price']) ?></td>
                                        <td class="text-end fw-semibold"><?= format_currency((float)$p['wholesale_rate']) ?></td>
                                        <td class="text-end fw-bold text-navy"><?= format_currency((float)$p['total_value']) ?></td>
                                        <td class="text-center">
                                            <?php if ($saleable <= 0): ?>
                                                <span class="badge bg-danger">Out of Stock</span>
                                            <?php elseif ($isLow): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Low Stock</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success">In Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="ledger.php?product_id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="View Stock Movement Audit Trail">
                                                    <i class="bi bi-journal-text"></i> Ledger
                                                </a>
                                                <?php if (has_permission('inventory_adjust')): ?>
                                                    <a href="adjust.php?product_id=<?= $p['id'] ?>" class="btn btn-outline-warning" title="Perform Stock Adjustment">
                                                        <i class="bi bi-sliders"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                    <span class="small text-muted">Showing page <?= $page ?> of <?= $totalPages ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&warehouse_id=<?= $warehouseId ?>&category_id=<?= $categoryId ?>&q=<?= urlencode($search) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
