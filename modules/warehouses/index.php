<?php
/**
 * DMS - Warehouse & Depot Management
 * Master storage facilities, distribution depots, and stock-holding hubs
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Warehouse & Depot Management — ' . APP_NAME;
$activeMenu = 'warehouses';

$search = clean_input($_GET['search'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? 'all');

$sql = "
    SELECT 
        w.*,
        (SELECT COUNT(DISTINCT sb.product_id) FROM stock_balances sb WHERE sb.warehouse_id = w.id AND sb.total_qty > 0) as stocked_products_count,
        (SELECT COALESCE(SUM(sb.total_value), 0) FROM stock_balances sb WHERE sb.warehouse_id = w.id) as total_stock_value,
        (SELECT COUNT(*) FROM load_sheets ls WHERE ls.warehouse_id = w.id) as total_load_sheets
    FROM warehouses w 
    WHERE w.is_deleted = 0
";
$params = [];

if ($search !== '') {
    $sql .= " AND (w.warehouse_name LIKE ? OR w.warehouse_code LIKE ? OR w.location LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($statusFilter === 'active') {
    $sql .= " AND w.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND w.is_active = 0";
}

$sql .= " ORDER BY w.is_primary DESC, w.warehouse_name ASC";
$warehouses = db_fetch_all($sql, $params);

// Calculate overall summary metrics
$totalWarehouses = count($warehouses);
$activeCount = 0;
$primaryWarehouse = null;
$totalSystemStockVal = 0.0;

foreach ($warehouses as $wh) {
    if ($wh['is_active']) $activeCount++;
    if ($wh['is_primary']) $primaryWarehouse = $wh;
    $totalSystemStockVal += (float)$wh['total_stock_value'];
}

// Next suggested warehouse code
$maxNum = 0;
$allCodes = db_fetch_all("SELECT warehouse_code FROM warehouses");
foreach ($allCodes as $c) {
    if (preg_match('/WH-(\d+)/i', $c['warehouse_code'], $m)) {
        $val = (int)$m[1];
        if ($val > $maxNum) $maxNum = $val;
    }
}
$nextCode = 'WH-' . sprintf('%02d', max($maxNum + 1, count($allCodes) + 1));

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-building-gear me-2 text-primary"></i>Warehouse & Depot Management</h5>
        <small class="text-muted">Master distribution warehouses, regional godowns, and vehicle loading points</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-boxes me-1"></i> Stock Overview
        </a>
        <a href="<?= APP_URL ?>/modules/loading/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-truck me-1"></i> Loading Sheets
        </a>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-warehouse')">
            <i class="bi bi-plus-lg me-1"></i> Add Warehouse (Ctrl+N)
        </button>
    </div>
</div>

<!-- KPI Summary Ribbon -->
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center">
                <div class="p-3 bg-primary-subtle text-primary rounded-3 me-3">
                    <i class="bi bi-building fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Total Facilities</div>
                    <div class="fs-5 fw-bold text-dark"><?= $totalWarehouses ?> Warehouses</div>
                    <small class="text-success"><?= $activeCount ?> operational</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center">
                <div class="p-3 bg-warning-subtle text-warning rounded-3 me-3">
                    <i class="bi bi-star-fill fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Primary Central Hub</div>
                    <div class="fs-6 fw-bold text-dark text-truncate" style="max-width: 170px;" title="<?= e($primaryWarehouse['warehouse_name'] ?? 'Not Assigned') ?>">
                        <?= e($primaryWarehouse['warehouse_name'] ?? 'None') ?>
                    </div>
                    <small class="text-muted font-monospace"><?= e($primaryWarehouse['warehouse_code'] ?? 'N/A') ?></small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center">
                <div class="p-3 bg-info-subtle text-info rounded-3 me-3">
                    <i class="bi bi-box-seam fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Total Valuation</div>
                    <div class="fs-5 fw-bold text-navy">Rs. <?= number_format($totalSystemStockVal, 2) ?></div>
                    <small class="text-muted">Total inventory across hubs</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center">
                <div class="p-3 bg-success-subtle text-success rounded-3 me-3">
                    <i class="bi bi-truck-front fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Dispatch Ready</div>
                    <div class="fs-5 fw-bold text-dark">Active Hubs</div>
                    <small class="text-muted">Available in Load Sheets</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/warehouses/index.php" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search warehouse by name, code, or address... (F2)">
            </div>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Facilities Only</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                <i class="bi bi-funnel"></i> Search
            </button>
        </div>
        <?php if ($search !== '' || $statusFilter !== 'all'): ?>
            <div class="col-md-1">
                <a href="<?= APP_URL ?>/modules/warehouses/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Warehouses Table -->
<div class="card-dms">
    <div class="card-dms-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-buildings me-1"></i> Warehouse Facilities List (<?= count($warehouses) ?> Total)</span>
        <small class="text-muted">FMCG Storage Points & Loading Depots</small>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width: 110px;">Code</th>
                    <th>Warehouse Name</th>
                    <th>Physical Location / Address</th>
                    <th class="text-center" style="width: 140px;">Depot Role</th>
                    <th class="text-center" style="width: 120px;">Stocked SKUs</th>
                    <th class="text-end" style="width: 160px;">Stock Valuation</th>
                    <th class="text-center" style="width: 90px;">Status</th>
                    <th class="text-end" style="width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($warehouses)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No warehouses found matching your criteria. Click "Add Warehouse" to register new facilities.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($warehouses as $w): ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace fw-bold">
                                    <?= e($w['warehouse_code']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark">
                                    <?= e($w['warehouse_name']) ?>
                                    <?php if ($w['is_primary']): ?>
                                        <span class="badge bg-warning text-dark border border-warning-subtle ms-1" title="Primary Dispatch Hub for Orders & Deliveries">
                                            <i class="bi bi-star-fill me-1"></i>Default Central Hub
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><?= (int)$w['total_load_sheets'] ?> delivery load sheets dispatched</small>
                            </td>
                            <td>
                                <div class="text-dark small">
                                    <i class="bi bi-geo-alt me-1 text-danger"></i><?= e($w['location'] ?: 'Location not specified') ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php if ($w['is_primary']): ?>
                                    <span class="badge bg-primary-subtle text-primary border">Primary Hub</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border">Secondary Depot</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info-subtle text-info border">
                                    <?= (int)$w['stocked_products_count'] ?> SKUs
                                </span>
                            </td>
                            <td class="text-end fw-bold text-navy">
                                Rs. <?= number_format((float)$w['total_stock_value'], 2) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $w['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $w['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $w['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-xs">
                                    <a href="<?= APP_URL ?>/modules/inventory/index.php?warehouse_id=<?= $w['id'] ?>" class="btn btn-xs btn-outline-info py-0 px-2" title="View Stock Balances">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <?php if (!$w['is_primary'] && $w['is_active']): ?>
                                        <a href="<?= APP_URL ?>/modules/warehouses/set_primary.php?id=<?= $w['id'] ?>" class="btn btn-xs btn-outline-warning py-0 px-2" title="Make Primary Hub">
                                            <i class="bi bi-star"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= APP_URL ?>/modules/warehouses/edit.php?id=<?= $w['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Warehouse">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!$w['is_primary']): ?>
                                        <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to remove or deactivate warehouse \'<?= e(addslashes($w['warehouse_name'])) ?>\'?', function(){ window.location.href='<?= APP_URL ?>/modules/warehouses/delete.php?id=<?= $w['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete / Deactivate">
                                            <i class="bi bi-trash"></i>
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

<!-- Slide Drawer: Add Warehouse -->
<div id="drawer-add-warehouse" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-building-add me-2 text-primary"></i> Create Warehouse / Depot</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/warehouses/create.php">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Warehouse Code <span class="text-danger">*</span></label>
                <input type="text" name="warehouse_code" class="form-control form-control-sm font-monospace" value="<?= e($nextCode) ?>" required>
                <small class="text-muted">Unique warehouse identifier (e.g. WH-01, WH-02)</small>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Warehouse / Depot Name <span class="text-danger">*</span></label>
                <input type="text" name="warehouse_name" class="form-control form-control-sm" placeholder="e.g. Main Central Warehouse, Rahwali Depot, City Godown" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Physical Location / Address</label>
                <textarea name="location" class="form-control form-control-sm" rows="3" placeholder="Full address or location (e.g. Jandiala Bhagwala G.T. Road Gujranwala)"></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="whPrimary">
                <label class="form-check-label small" for="whPrimary">Set as Default Primary Warehouse Hub</label>
                <div class="text-muted small">Will be preselected by default across loading, GRN, and purchases.</div>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="whActive" checked>
                <label class="form-check-label small" for="whActive">Warehouse Active & Operational</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Warehouse (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
