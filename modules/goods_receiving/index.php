<?php
/**
 * DMS - Distribution Management System
 * Goods Receiving Note (GRN) - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_receive');

$pageTitle = 'Goods Receiving Notes (GRN)';
$activeMenu = 'purchases';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Goods Receiving', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["gr.grn_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($supplierId > 0) {
    $where[] = "gr.supplier_id = ?";
    $params[] = $supplierId;
}
if (!empty($search)) {
    $where[] = "(gr.grn_number LIKE ? OR s.supplier_name LIKE ? OR gr.supplier_bill_no LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(gr.id) as total_grns,
        SUM(gr.total_amount) as grand_received_val
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_grns' => 0,
    'grand_received_val' => 0.0
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(gr.id)
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$grns = db_fetch_all("
    SELECT 
        gr.id,
        gr.grn_number,
        gr.grn_date,
        gr.supplier_id,
        s.supplier_name,
        po.purchase_number,
        gr.supplier_bill_no,
        w.warehouse_name,
        gr.total_amount,
        gr.status,
        gr.created_at,
        u.full_name as receiver_name
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN warehouses w ON gr.warehouse_id = w.id
    LEFT JOIN purchase_orders po ON gr.purchase_order_id = po.id
    LEFT JOIN users u ON gr.received_by = u.id
    WHERE {$whereClause}
    ORDER BY gr.grn_date DESC, gr.id DESC
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
                    <i class="bi bi-box-arrow-in-down me-2"></i>Goods Receiving Notes (GRN)
                </h4>
                <p class="text-muted small mb-0">Record factory arrivals, carton inspections, and warehouse stock additions</p>
            </div>
            <div class="d-flex gap-2">
                <a href="create.php" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Goods Receipt (GRN)
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total GRN Shipments</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_grns']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Inventory Received</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= format_currency((float)$stats['grand_received_val']) ?></div>
                        <div class="small text-success mt-1"><i class="bi bi-boxes"></i> Stock added to warehouse</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Suppliers Payable Added</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$stats['grand_received_val']) ?></div>
                        <div class="small text-muted mt-1">Posted to Trade Creditors</div>
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
                    <i class="bi bi-card-checklist me-1"></i> Goods Receipts Register (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">GRN #</th>
                                <th>Receipt Date</th>
                                <th>Supplier / Factory</th>
                                <th>PO #</th>
                                <th>Supplier Bill #</th>
                                <th>Warehouse</th>
                                <th class="text-end">Received Value</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($grns)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No goods receipts recorded.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($grns as $gr): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $gr['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($gr['grn_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($gr['grn_date'])) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($gr['supplier_name']) ?></td>
                                        <td>
                                            <?php if ($gr['purchase_number']): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($gr['purchase_number']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Direct Delivery</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= htmlspecialchars($gr['supplier_bill_no'] ?: '-') ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($gr['warehouse_name']) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency((float)$gr['total_amount']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($gr['status']) ?></span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="view.php?id=<?= $gr['id'] ?>" class="btn btn-sm btn-outline-primary" title="View GRN Note">
                                                <i class="bi bi-printer"></i> GRN Slip
                                            </a>
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
                                    <a class="page-link" href="?page=<?= $i ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&supplier_id=<?= $supplierId ?>&q=<?= urlencode($search) ?>">
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
