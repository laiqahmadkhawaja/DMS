<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('loading_view');

$pageTitle = 'Warehouse Loading Sheets';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Warehouse Loading', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
$status     = sanitize_input($_GET['status'] ?? '');
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["ls.load_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($salesmanId > 0) {
    $where[] = "ls.salesman_id = ?";
    $params[] = $salesmanId;
}

if (!empty($status)) {
    $where[] = "ls.status = ?";
    $params[] = $status;
}

if (!empty($search)) {
    $where[] = "(ls.load_number LIKE ? OR s.salesman_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(ls.id) as total_sheets,
        SUM(CASE WHEN ls.status = 'issued' THEN 1 ELSE 0 END) as issued_count,
        SUM(CASE WHEN ls.status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN ls.status = 'returned_closed' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN ls.status = 'issued' THEN ls.total_amount ELSE 0 END) as total_dispatched_val
    FROM load_sheets ls
    LEFT JOIN salesmen s ON ls.salesman_id = s.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_sheets' => 0,
    'issued_count' => 0,
    'draft_count' => 0,
    'closed_count' => 0,
    'total_dispatched_val' => 0.00
];

// Fetch Paginated Load Sheets
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(ls.id)
    FROM load_sheets ls
    LEFT JOIN salesmen s ON ls.salesman_id = s.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$sheets = db_fetch_all("
    SELECT 
        ls.id,
        ls.load_number,
        ls.load_date,
        ls.delivery_date,
        ls.salesman_id,
        s.salesman_name,
        w.warehouse_name,
        ls.total_orders,
        ls.total_amount,
        ls.status,
        ls.remarks,
        ls.created_at,
        u.full_name as creator_name
    FROM load_sheets ls
    JOIN salesmen s ON ls.salesman_id = s.id
    JOIN warehouses w ON ls.warehouse_id = w.id
    LEFT JOIN users u ON ls.created_by = u.id
    WHERE {$whereClause}
    ORDER BY ls.load_date DESC, ls.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// Salesmen for filter dropdown
$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Title & Header Actions -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-truck me-2"></i>Warehouse Loading & Dispatch
                </h4>
                <p class="text-muted small mb-0">Consolidate market orders into delivery load sheets and issue stock from warehouse</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (has_permission('loading_create')): ?>
                    <a href="create.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Load Sheet <kbd class="bg-navy ms-1">F4</kbd>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Sheets</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_sheets']) ?></div>
                        <div class="small text-muted mt-1">Filtered date range</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Issued / Dispatched</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= number_format($stats['issued_count']) ?></div>
                        <div class="small text-success mt-1"><i class="bi bi-box-arrow-right"></i> Active on route</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Draft / Prepared</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= number_format($stats['draft_count']) ?></div>
                        <div class="small text-muted mt-1">Awaiting physical issue</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Dispatched Value</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$stats['total_dispatched_val']) ?></div>
                        <div class="small text-muted mt-1">Issued stock value</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search & Filter Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="index.php" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">From Date</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">To Date</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Salesman</label>
                        <select name="salesman_id" class="form-select form-select-sm">
                            <option value="">-- All Salesmen --</option>
                            <?php foreach ($salesmen as $sm): ?>
                                <option value="<?= $sm['id'] ?>" <?= $salesmanId === (int)$sm['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sm['salesman_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">-- All Statuses --</option>
                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Issued / Dispatched</option>
                            <option value="returned_closed" <?= $status === 'returned_closed' ? 'selected' : '' ?>>Returned & Closed</option>
                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Search Sheet #</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="LOAD-..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
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

        <!-- Load Sheets Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-card-checklist me-1"></i> Loading Sheets (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">Sheet #</th>
                                <th>Load Date</th>
                                <th>Delivery Date</th>
                                <th>Salesman</th>
                                <th>Warehouse</th>
                                <th class="text-center">Orders</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sheets)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No loading sheets found for the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sheets as $ls): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $ls['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($ls['load_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($ls['load_date'])) ?></td>
                                        <td><?= date('d-M-Y', strtotime($ls['delivery_date'])) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($ls['salesman_name']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($ls['warehouse_name']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary rounded-pill px-2">
                                                <?= (int)$ls['total_orders'] ?> bills
                                            </span>
                                        </td>
                                        <td class="text-end fw-bold text-dark">
                                            <?= format_currency((float)$ls['total_amount']) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($ls['status'] === 'draft'): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass me-1"></i>Draft</span>
                                            <?php elseif ($ls['status'] === 'issued'): ?>
                                                <span class="badge bg-success"><i class="bi bi-truck me-1"></i>Issued</span>
                                            <?php elseif ($ls['status'] === 'returned_closed'): ?>
                                                <span class="badge bg-info text-dark"><i class="bi bi-check2-all me-1"></i>Closed</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($ls['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?= $ls['id'] ?>" class="btn btn-outline-primary" title="View & Print Issue Summary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($ls['status'] !== 'cancelled' && $ls['status'] !== 'returned_closed'): ?>
                                                    <a href="edit.php?id=<?= $ls['id'] ?>" class="btn btn-outline-secondary" title="Edit Load Sheet">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($ls['status'] === 'draft' && has_permission('loading_issue')): ?>
                                                    <a href="issue.php?id=<?= $ls['id'] ?>" class="btn btn-success" title="Confirm Stock Dispatch">
                                                        <i class="bi bi-box-arrow-right"></i> Issue
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($ls['status'] !== 'cancelled' && has_permission('loading_cancel')): ?>
                                                    <a href="cancel.php?id=<?= $ls['id'] ?>" class="btn btn-outline-danger" title="Cancel Sheet" onclick="return confirm('Are you sure you want to cancel this load sheet?');">
                                                        <i class="bi bi-x-circle"></i>
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
                                    <a class="page-link" href="?page=<?= $i ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&salesman_id=<?= $salesmanId ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($search) ?>">
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

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'F4') {
        e.preventDefault();
        window.location.href = 'create.php';
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
