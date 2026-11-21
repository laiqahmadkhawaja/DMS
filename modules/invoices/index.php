<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('invoices_view');

$pageTitle = 'Sales Invoices & Bills';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Invoices', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$bookerId   = isset($_GET['booker_id']) ? (int)$_GET['booker_id'] : 0;
$salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
$status     = sanitize_input($_GET['status'] ?? '');
$recStatus  = sanitize_input($_GET['rec_status'] ?? '');
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["inv.invoice_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($customerId > 0) {
    $where[] = "inv.customer_id = ?";
    $params[] = $customerId;
}
if ($bookerId > 0) {
    $where[] = "inv.booker_id = ?";
    $params[] = $bookerId;
}
if ($salesmanId > 0) {
    $where[] = "inv.salesman_id = ?";
    $params[] = $salesmanId;
}
if (!empty($status)) {
    $where[] = "inv.status = ?";
    $params[] = $status;
}
if (!empty($recStatus)) {
    $where[] = "inv.reconciliation_status = ?";
    $params[] = $recStatus;
}
if (!empty($search)) {
    $where[] = "(inv.invoice_number LIKE ? OR c.shop_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(inv.id) as total_bills,
        SUM(CASE WHEN inv.status = 'finalized' THEN 1 ELSE 0 END) as in_route_count,
        SUM(CASE WHEN inv.status = 'settled' THEN 1 ELSE 0 END) as settled_count,
        SUM(inv.net_amount) as total_gross_value,
        SUM(inv.paid_amount) as total_cash_received,
        SUM(inv.credit_amount) as total_credit_given,
        SUM(inv.return_amount) as total_returns_val
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_bills' => 0,
    'in_route_count' => 0,
    'settled_count' => 0,
    'total_gross_value' => 0.0,
    'total_cash_received' => 0.0,
    'total_credit_given' => 0.0,
    'total_returns_val' => 0.0
];

// Fetch Paginated Invoices
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(inv.id)
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$invoices = db_fetch_all("
    SELECT 
        inv.id,
        inv.invoice_number,
        inv.invoice_date,
        inv.order_id,
        inv.load_sheet_id,
        inv.customer_id,
        c.shop_name as customer_name,
        a.area_name,
        b.booker_name,
        s.salesman_name,
        inv.gross_amount,
        inv.net_amount,
        inv.paid_amount,
        inv.credit_amount,
        inv.return_amount,
        inv.status,
        inv.reconciliation_status,
        inv.created_at
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    LEFT JOIN salesmen s ON inv.salesman_id = s.id
    WHERE {$whereClause}
    ORDER BY inv.invoice_date DESC, inv.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// Dropdown lists
$customers = db_fetch_all("SELECT id, shop_name FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");
$bookers   = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_active = 1 ORDER BY booker_name ASC");
$salesmen  = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");

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
                    <i class="bi bi-receipt me-2"></i>Sales Invoices & Bills
                </h4>
                <p class="text-muted small mb-0">Manage customer sales memos, route dispatch billing, printouts and recovery status</p>
            </div>
            <div class="d-flex gap-2">
                <a href="batch_print.php?date=<?= urlencode($startDate) ?>" target="_blank" class="btn btn-outline-primary shadow-sm" title="Batch Print Bills for Selected Date">
                    <i class="bi bi-printer me-1"></i> Batch Print Date
                </a>
                <?php if (has_permission('invoices_create')): ?>
                    <a href="create.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> Direct Invoice <kbd class="bg-navy ms-1">F7</kbd>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Bills</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_bills']) ?></div>
                        <div class="small text-muted mt-1">Total Invoiced: <?= format_currency((float)$stats['total_gross_value']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Active in Route</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= number_format($stats['in_route_count']) ?></div>
                        <div class="small text-muted mt-1">Pending route settlement</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Settled Bills</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= number_format($stats['settled_count']) ?></div>
                        <div class="small text-success mt-1">Cash: <?= format_currency((float)$stats['total_cash_received']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Credit Given & Returns</div>
                        <div class="fs-5 fw-bold text-danger mt-1">Cr: <?= format_currency((float)$stats['total_credit_given']) ?></div>
                        <div class="small text-muted mt-1">Ret: <?= format_currency((float)$stats['total_returns_val']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
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
                        <label class="form-label small fw-bold">Customer</label>
                        <select name="customer_id" class="form-select form-select-sm">
                            <option value="">-- All Customers --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['shop_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Booker</label>
                        <select name="booker_id" class="form-select form-select-sm">
                            <option value="">-- All Bookers --</option>
                            <?php foreach ($bookers as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $bookerId === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['booker_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">-- All Statuses --</option>
                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="finalized" <?= $status === 'finalized' ? 'selected' : '' ?>>In Route (Dispatched)</option>
                            <option value="settled" <?= $status === 'settled' ? 'selected' : '' ?>>Settled</option>
                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
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

        <!-- Invoices Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-receipt-cutoff me-1"></i> Sales Bills (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">Bill #</th>
                                <th>Date</th>
                                <th>Customer / Shop</th>
                                <th>Route / Area</th>
                                <th>Booker</th>
                                <th>Salesman</th>
                                <th class="text-end">Bill Net</th>
                                <th class="text-end">Cash</th>
                                <th class="text-end">Credit</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No sales invoices found matching the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $inv['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($inv['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($inv['customer_name']) ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($inv['area_name']) ?></span></td>
                                        <td class="small text-muted"><?= htmlspecialchars($inv['booker_name']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($inv['salesman_name'] ?: 'Route Delivery') ?></td>
                                        <td class="text-end fw-bold text-dark">
                                            <?= format_currency((float)$inv['net_amount']) ?>
                                        </td>
                                        <td class="text-end text-success fw-semibold">
                                            <?= (float)$inv['paid_amount'] > 0 ? format_currency((float)$inv['paid_amount']) : '-' ?>
                                        </td>
                                        <td class="text-end text-danger fw-semibold">
                                            <?= (float)$inv['credit_amount'] > 0 ? format_currency((float)$inv['credit_amount']) : '-' ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($inv['status'] === 'settled'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i>Settled</span>
                                            <?php elseif ($inv['status'] === 'finalized'): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-truck me-1"></i>In Route</span>
                                            <?php elseif ($inv['status'] === 'draft'): ?>
                                                <span class="badge bg-secondary">Draft</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><?= htmlspecialchars($inv['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?= $inv['id'] ?>" class="btn btn-outline-primary" title="View Bill Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?= $inv['id'] ?>" class="btn btn-outline-warning" title="Edit Bill & Pricing">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="print.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print Customer Memo">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <?php if ($inv['status'] !== 'cancelled' && $inv['status'] !== 'settled' && has_permission('invoices_cancel')): ?>
                                                    <a href="action.php?action=cancel&id=<?= $inv['id'] ?>" class="btn btn-outline-danger" title="Cancel Invoice" onclick="return confirm('Are you sure you want to cancel this invoice?');">
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
                                    <a class="page-link" href="?page=<?= $i ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&customer_id=<?= $customerId ?>&booker_id=<?= $bookerId ?>&salesman_id=<?= $salesmanId ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($search) ?>">
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
    if (e.key === 'F7') {
        e.preventDefault();
        window.location.href = 'create.php';
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
