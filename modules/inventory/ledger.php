<?php
/**
 * DMS - Distribution Management System
 * Inventory - Complete Stock Movement Audit Ledger
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('inventory_view');

$pageTitle = 'Stock Movement Ledger';
$activeMenu = 'stock_ledger';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Inventory', 'url' => 'index.php'],
    ['label' => 'Movement Ledger', 'url' => '']
];

// Filters
$startDate    = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate      = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$productId    = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$movementType = sanitize_input($_GET['movement_type'] ?? '');

$where = ["sm.movement_date BETWEEN ? AND ?"];
$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

if ($productId > 0) {
    $where[] = "sm.product_id = ?";
    $params[] = $productId;
}
if (!empty($movementType)) {
    $where[] = "sm.movement_type = ?";
    $params[] = $movementType;
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(sm.id) as total_movements,
        SUM(sm.qty_in) as total_units_in,
        SUM(sm.qty_out) as total_units_out,
        SUM(sm.total_cost) as total_movement_value
    FROM stock_movements sm
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_movements' => 0,
    'total_units_in' => 0.0,
    'total_units_out' => 0.0,
    'total_movement_value' => 0.0
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(sm.id)
    FROM stock_movements sm
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$movements = db_fetch_all("
    SELECT 
        sm.id,
        sm.movement_date,
        sm.movement_type,
        sm.reference_type,
        sm.reference_id,
        sm.product_id,
        p.product_code,
        p.product_name,
        p.carton_qty as ctn_ratio,
        p.pack_qty as pack_ratio,
        p.base_unit_id,
        COALESCE(u_prod.symbol, 'Ctn') as major_unit_symbol,
        sm.qty_in,
        sm.qty_out,
        sm.base_unit_qty,
        sm.unit_cost,
        sm.total_cost,
        sm.remarks,
        w.warehouse_name,
        u.full_name as creator_name
    FROM stock_movements sm
    JOIN products p ON sm.product_id = p.id
    LEFT JOIN units u_prod ON p.base_unit_id = u_prod.id
    JOIN warehouses w ON sm.warehouse_id = w.id
    LEFT JOIN users u ON sm.created_by = u.id
    WHERE {$whereClause}
    ORDER BY sm.movement_date DESC, sm.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$selectedProduct = null;
if ($productId > 0) {
    $selectedProduct = db_fetch_one("
        SELECT p.*, COALESCE(u.symbol, 'Ctn') as major_unit_symbol 
        FROM products p 
        LEFT JOIN units u ON p.base_unit_id = u.id 
        WHERE p.id = ?
    ", [$productId]);
}

$products = db_fetch_all("SELECT id, product_code, product_name FROM products WHERE is_deleted = 0 ORDER BY product_name ASC");

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
                    <i class="bi bi-journal-text me-2"></i>Stock Movement Ledger (Audit Source of Truth)
                </h4>
                <p class="text-muted small mb-0">Immutable transaction log of every stock inbound, dispatch, return, and adjustment</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Stock Overview
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Stock Movements</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_movements']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Stock Received (In)</div>
                        <?php if ($selectedProduct): 
                            $inBk = breakdown_base_pieces((float)$stats['total_units_in'], (float)$selectedProduct['carton_qty'], (float)$selectedProduct['pack_qty'], $selectedProduct['major_unit_symbol']);
                        ?>
                            <div class="fs-4 fw-bold text-success mt-1">+<?= $inBk['formatted'] ?></div>
                            <div class="small text-muted mt-1">+<?= number_format((float)$stats['total_units_in']) ?> base units</div>
                        <?php else: ?>
                            <div class="fs-4 fw-bold text-success mt-1">+<?= number_format((float)$stats['total_units_in']) ?> units</div>
                            <div class="small text-success mt-1">Purchases, Opening & Returns</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Stock Dispatched (Out)</div>
                        <?php if ($selectedProduct): 
                            $outBk = breakdown_base_pieces((float)$stats['total_units_out'], (float)$selectedProduct['carton_qty'], (float)$selectedProduct['pack_qty'], $selectedProduct['major_unit_symbol']);
                        ?>
                            <div class="fs-4 fw-bold text-danger mt-1">-<?= $outBk['formatted'] ?></div>
                            <div class="small text-muted mt-1">-<?= number_format((float)$stats['total_units_out']) ?> base units</div>
                        <?php else: ?>
                            <div class="fs-4 fw-bold text-danger mt-1">-<?= number_format((float)$stats['total_units_out']) ?> units</div>
                            <div class="small text-danger mt-1">Van loading & direct sales</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Net Stock Balance Delta</div>
                        <?php 
                        $netDelta = (float)$stats['total_units_in'] - (float)$stats['total_units_out'];
                        if ($selectedProduct): 
                            $netBk = breakdown_base_pieces($netDelta, (float)$selectedProduct['carton_qty'], (float)$selectedProduct['pack_qty'], $selectedProduct['major_unit_symbol']);
                        ?>
                            <div class="fs-4 fw-bold text-navy mt-1"><?= ($netDelta >= 0 ? '+' : '') . $netBk['formatted'] ?></div>
                            <div class="small text-muted mt-1"><?= ($netDelta >= 0 ? '+' : '') . number_format($netDelta) ?> base units</div>
                        <?php else: ?>
                            <div class="fs-4 fw-bold text-navy mt-1"><?= ($netDelta >= 0 ? '+' : '') . number_format($netDelta) ?> units</div>
                            <div class="small text-muted mt-1">Filter by product for packaging counts</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="ledger.php" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">From Date</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">To Date</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Product</label>
                        <select name="product_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $productId === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-filter"></i> Filter
                        </button>
                        <a href="ledger.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-journal-check me-1"></i> Stock Ledger Entries (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">Date & Time</th>
                                <th>Movement Type</th>
                                <th>Document Reference</th>
                                <th>Product Details</th>
                                <th class="text-center text-success" style="min-width: 140px;">Qty In (+) <br><span class="fw-normal text-muted" style="font-size: 0.7rem; text-transform: none;">Carton / Pack / Pcs</span></th>
                                <th class="text-center text-danger" style="min-width: 140px;">Qty Out (-) <br><span class="fw-normal text-muted" style="font-size: 0.7rem; text-transform: none;">Carton / Pack / Pcs</span></th>
                                <th class="text-center" style="min-width: 140px;">Net Change <br><span class="fw-normal text-muted" style="font-size: 0.7rem; text-transform: none;">Movement Delta</span></th>
                                <th class="text-end">Valuation</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($movements)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No stock movements found for the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movements as $m): 
                                    $in  = (float)$m['qty_in'];
                                    $out = (float)$m['qty_out'];
                                    $net = $in - $out;

                                    $ctnRatio  = (float)$m['ctn_ratio'] > 0 ? (float)$m['ctn_ratio'] : 1;
                                    $packRatio = (float)$m['pack_ratio'] > 0 ? (float)$m['pack_ratio'] : 1;
                                    $majorUnit = !empty($m['major_unit_symbol']) ? $m['major_unit_symbol'] : 'Ctn';

                                    $inBreakdown  = breakdown_base_pieces($in, $ctnRatio, $packRatio, $majorUnit);
                                    $outBreakdown = breakdown_base_pieces($out, $ctnRatio, $packRatio, $majorUnit);
                                    $netBreakdown = breakdown_base_pieces($net, $ctnRatio, $packRatio, $majorUnit);
                                ?>
                                    <tr>
                                        <td class="ps-3 text-muted small">
                                            <?= date('d-M-Y H:i', strtotime($m['movement_date'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($m['movement_type'] === 'sales_dispatch'): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger"><i class="bi bi-truck me-1"></i>Sales Dispatch</span>
                                            <?php elseif ($m['movement_type'] === 'purchase_grn'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-box-arrow-in-down me-1"></i>Purchase GRN</span>
                                            <?php elseif ($m['movement_type'] === 'opening_stock'): ?>
                                                <span class="badge bg-info-subtle text-info border border-info">Opening Stock</span>
                                            <?php elseif (strpos($m['movement_type'], 'sales_return') !== false): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning"><i class="bi bi-arrow-return-left me-1"></i>Sales Return</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($m['movement_type']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="small fw-bold text-navy">
                                                <?= htmlspecialchars(strtoupper($m['reference_type'])) ?> #<?= (int)$m['reference_id'] ?>
                                            </span>
                                        </td>
                                        <td class="fw-semibold text-dark">
                                            <div><?= htmlspecialchars($m['product_name']) ?></div>
                                            <small class="text-muted" style="font-size: 0.72rem;">
                                                <span class="fw-bold"><?= htmlspecialchars($m['product_code']) ?></span> &bull; 
                                                1 <?= htmlspecialchars($majorUnit) ?> = <?= (int)$ctnRatio ?> Pck<?= (int)$packRatio > 1 ? '*' . (int)$packRatio : '' ?> (<?= (int)($ctnRatio * $packRatio) ?> units)
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($in > 0): ?>
                                                <div class="fw-bold text-success">+<?= $inBreakdown['formatted'] ?></div>
                                                <div class="text-muted" style="font-size: 0.72rem;">(+<?= number_format($in) ?> units)</div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($out > 0): ?>
                                                <div class="fw-bold text-danger">-<?= $outBreakdown['formatted'] ?></div>
                                                <div class="text-muted" style="font-size: 0.72rem;">(-<?= number_format($out) ?> units)</div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold <?= $net >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= ($net >= 0 ? '+' : '') . $netBreakdown['formatted'] ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.72rem;">(<?= ($net >= 0 ? '+' : '') . number_format($net) ?> units)</div>
                                        </td>
                                        <td class="text-end text-muted">
                                            <?= format_currency(((float)$m['unit_cost']) * ((float)$m['base_unit_qty'])) ?>
                                        </td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars($m['remarks'] ?: '') ?>
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
                                    <a class="page-link" href="?page=<?= $i ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&product_id=<?= $productId ?>&movement_type=<?= urlencode($movementType) ?>">
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
