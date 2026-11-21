<?php
/**
 * DMS - Distribution Management System
 * Route Settlements & Daily Reconciliation - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('settlements_view');

$pageTitle = 'Route Settlements & Reconciliation';
$activeMenu = 'settlements';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settlements', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
$status     = sanitize_input($_GET['status'] ?? '');
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["st.settlement_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($salesmanId > 0) {
    $where[] = "st.salesman_id = ?";
    $params[] = $salesmanId;
}
if (!empty($status)) {
    $where[] = "st.status = ?";
    $params[] = $status;
}
if (!empty($search)) {
    $where[] = "(st.settlement_number LIKE ? OR s.salesman_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(st.id) as total_settlements,
        SUM(st.total_invoices_amount) as grand_invoices_val,
        SUM(st.total_cash_received) as grand_cash_val,
        SUM(st.total_credit_given) as grand_credit_val,
        SUM(st.total_returns_amount) as grand_returns_val,
        SUM(st.difference_amount) as grand_difference_val
    FROM settlements st
    JOIN salesmen s ON st.salesman_id = s.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_settlements' => 0,
    'grand_invoices_val' => 0.0,
    'grand_cash_val' => 0.0,
    'grand_credit_val' => 0.0,
    'grand_returns_val' => 0.0,
    'grand_difference_val' => 0.0
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(st.id)
    FROM settlements st
    JOIN salesmen s ON st.salesman_id = s.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$settlements = db_fetch_all("
    SELECT 
        st.id,
        st.settlement_number,
        st.settlement_date,
        st.salesman_id,
        s.salesman_name,
        dr.run_number,
        ls.load_number,
        st.total_invoices_amount,
        st.total_cash_received,
        st.total_credit_given,
        st.total_returns_amount,
        st.difference_amount,
        st.status,
        st.created_at
    FROM settlements st
    JOIN salesmen s ON st.salesman_id = s.id
    LEFT JOIN delivery_runs dr ON st.delivery_run_id = dr.id
    LEFT JOIN load_sheets ls ON st.load_sheet_id = ls.id
    WHERE {$whereClause}
    ORDER BY st.settlement_date DESC, st.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");

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
                    <i class="bi bi-cash-stack me-2"></i>Route Settlements & Daily Reconciliation
                </h4>
                <p class="text-muted small mb-0">Balance evening route returns: Invoices = Cash Collected + Credit Given + Returns (Photo 1)</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (has_permission('settlements_create')): ?>
                    <a href="create.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Route Settlement <kbd class="bg-navy ms-1">F8</kbd>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Invoices Dispatched</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= format_currency((float)$stats['grand_invoices_val']) ?></div>
                        <div class="small text-muted mt-1"><?= number_format($stats['total_settlements']) ?> settlements completed</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Cash Recovered</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= format_currency((float)$stats['grand_cash_val']) ?></div>
                        <div class="small text-success mt-1"><i class="bi bi-cash"></i> Handed over to office cash drawer</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Parties Credit Added</div>
                        <div class="fs-4 fw-bold text-danger mt-1"><?= format_currency((float)$stats['grand_credit_val']) ?></div>
                        <div class="small text-muted mt-1">Posted to Customer Accounts Receivable</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Goods Returns</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= format_currency((float)$stats['grand_returns_val']) ?></div>
                        <div class="small text-muted mt-1">Stock received back into warehouse</div>
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
                        <label class="form-label small fw-bold">Salesman</label>
                        <select name="salesman_id" class="form-select form-select-sm">
                            <option value="">-- All Salesmen --</option>
                            <?php foreach ($salesmen as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $salesmanId === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['salesman_name']) ?>
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

        <!-- Settlements Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-journal-check me-1"></i> Settlements History (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">Settlement #</th>
                                <th>Date</th>
                                <th>Salesman</th>
                                <th class="text-end">Total Bills</th>
                                <th class="text-end text-success">Cash Received</th>
                                <th class="text-end text-danger">Credit Given</th>
                                <th class="text-end text-warning">Returns</th>
                                <th class="text-end">Difference</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($settlements)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No settlement records found for this period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($settlements as $st): 
                                    $diff = (float)$st['difference_amount'];
                                ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $st['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($st['settlement_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($st['settlement_date'])) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($st['salesman_name']) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency((float)$st['total_invoices_amount']) ?></td>
                                        <td class="text-end fw-bold text-success"><?= format_currency((float)$st['total_cash_received']) ?></td>
                                        <td class="text-end fw-bold text-danger"><?= format_currency((float)$st['total_credit_given']) ?></td>
                                        <td class="text-end fw-bold text-warning"><?= format_currency((float)$st['total_returns_amount']) ?></td>
                                        <td class="text-end fw-bold <?= abs($diff) > 0.01 ? 'text-danger' : 'text-success' ?>">
                                            <?= abs($diff) > 0.01 ? format_currency($diff) : '<i class="bi bi-check-circle-fill text-success"></i> 0.00' ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($st['status'] === 'finalized'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Finalized</span>
                                            <?php elseif ($st['status'] === 'reconciled'): ?>
                                                <span class="badge bg-info text-dark">Reconciled</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><?= htmlspecialchars($st['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit.php?id=<?= $st['id'] ?>" class="btn btn-outline-secondary" title="Edit Settlement & Adjust Balances">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="view.php?id=<?= $st['id'] ?>" class="btn btn-outline-primary" title="View & Print Settlement Voucher">
                                                    <i class="bi bi-printer"></i>
                                                </a>
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
    if (e.key === 'F8') {
        e.preventDefault();
        window.location.href = 'create.php';
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
