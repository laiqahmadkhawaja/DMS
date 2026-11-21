<?php
/**
 * DMS - Distribution Management System
 * Purchases - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_view');

$pageTitle = 'Purchase Orders';
$activeMenu = 'purchases';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Purchases', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$status     = sanitize_input($_GET['status'] ?? '');
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["po.order_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($supplierId > 0) {
    $where[] = "po.supplier_id = ?";
    $params[] = $supplierId;
}
if (!empty($status)) {
    $where[] = "po.status = ?";
    $params[] = $status;
}
if (!empty($search)) {
    $where[] = "(po.purchase_number LIKE ? OR s.supplier_name LIKE ? OR po.supplier_invoice_no LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(po.id) as total_pos,
        SUM(CASE WHEN po.status = 'ordered' THEN 1 ELSE 0 END) as ordered_count,
        SUM(CASE WHEN po.status = 'received' THEN 1 ELSE 0 END) as received_count,
        SUM(po.net_amount) as grand_po_val
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_pos' => 0,
    'ordered_count' => 0,
    'received_count' => 0,
    'grand_po_val' => 0.0
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(po.id)
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$orders = db_fetch_all("
    SELECT 
        po.id,
        po.purchase_number,
        po.order_date,
        po.expected_delivery_date,
        po.supplier_id,
        s.supplier_name,
        po.supplier_invoice_no,
        po.total_amount,
        po.discount_amount,
        po.net_amount,
        po.status,
        po.created_at,
        u.full_name as creator_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE {$whereClause}
    ORDER BY po.order_date DESC, po.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$suppliers = db_fetch_all("SELECT id, supplier_name FROM suppliers WHERE is_deleted = 0 ORDER BY supplier_name ASC");

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
                    <i class="bi bi-bag-check me-2"></i>Purchase Orders
                </h4>
                <p class="text-muted small mb-0">Manage factory purchase orders, procurement status, and expected shipments</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (has_permission('purchases_create')): ?>
                    <a href="create.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Purchase Order
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Purchase Orders</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_pos']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Active / In Transit</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= number_format($stats['ordered_count']) ?></div>
                        <div class="small text-muted mt-1">Awaiting warehouse receipt</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Received Orders</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= number_format($stats['received_count']) ?></div>
                        <div class="small text-muted mt-1">Inspected & stocked in warehouse</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Procurement Value</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$stats['grand_po_val']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="index.php" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">From Date</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">To Date</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Supplier</label>
                        <select name="supplier_id" class="form-select form-select-sm">
                            <option value="">-- All Suppliers --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

        <!-- Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-card-checklist me-1"></i> Orders Register (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">PO #</th>
                                <th>Order Date</th>
                                <th>Expected Delivery</th>
                                <th>Supplier / Manufacturer</th>
                                <th>Supplier Ref</th>
                                <th class="text-end">Net Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No purchase orders found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $po): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $po['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($po['purchase_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($po['order_date'])) ?></td>
                                        <td><?= $po['expected_delivery_date'] ? date('d-M-Y', strtotime($po['expected_delivery_date'])) : '-' ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($po['supplier_name']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($po['supplier_invoice_no'] ?: '-') ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency((float)$po['net_amount']) ?></td>
                                        <td class="text-center">
                                            <?php if ($po['status'] === 'received'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Received</span>
                                            <?php elseif ($po['status'] === 'partially_received'): ?>
                                                <span class="badge bg-info text-dark">Partial</span>
                                            <?php elseif ($po['status'] === 'ordered'): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-truck me-1"></i>Ordered</span>
                                            <?php elseif ($po['status'] === 'draft'): ?>
                                                <span class="badge bg-secondary">Draft</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><?= htmlspecialchars($po['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?= $po['id'] ?>" class="btn btn-outline-primary" title="View PO">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (in_array($po['status'], ['ordered', 'partially_received'], true) && has_permission('purchases_receive')): ?>
                                                    <a href="../goods_receiving/create.php?po_id=<?= $po['id'] ?>" class="btn btn-success" title="Receive Goods at Warehouse">
                                                        <i class="bi bi-box-arrow-in-down"></i> Receive GRN
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
                                    <a class="page-link" href="?page=<?= $i ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&supplier_id=<?= $supplierId ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($search) ?>">
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
