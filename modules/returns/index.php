<?php
/**
 * DMS - Distribution Management System
 * Sales Returns - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('returns_view');

$pageTitle = 'Sales Returns';
$activeMenu = 'returns';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Returns', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$condition  = sanitize_input($_GET['condition_type'] ?? '');
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["sr.return_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($customerId > 0) {
    $where[] = "sr.customer_id = ?";
    $params[] = $customerId;
}
if (!empty($condition)) {
    $where[] = "sr.condition_type = ?";
    $params[] = $condition;
}
if (!empty($search)) {
    $where[] = "(sr.return_number LIKE ? OR c.shop_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(sr.id) as total_returns,
        SUM(CASE WHEN sr.condition_type = 'saleable' THEN sr.total_amount ELSE 0 END) as saleable_val,
        SUM(CASE WHEN sr.condition_type = 'damaged' THEN sr.total_amount ELSE 0 END) as damaged_val,
        SUM(CASE WHEN sr.condition_type = 'expired' THEN sr.total_amount ELSE 0 END) as expired_val,
        SUM(sr.total_amount) as grand_return_val
    FROM sales_returns sr
    JOIN customers c ON sr.customer_id = c.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_returns' => 0,
    'saleable_val' => 0.0,
    'damaged_val' => 0.0,
    'expired_val' => 0.0,
    'grand_return_val' => 0.0
];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(sr.id)
    FROM sales_returns sr
    JOIN customers c ON sr.customer_id = c.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$returns = db_fetch_all("
    SELECT 
        sr.id,
        sr.return_number,
        sr.return_date,
        sr.customer_id,
        c.shop_name as customer_name,
        inv.invoice_number,
        st.settlement_number,
        sr.total_amount,
        sr.condition_type,
        sr.reason,
        sr.status,
        sr.created_at
    FROM sales_returns sr
    JOIN customers c ON sr.customer_id = c.id
    LEFT JOIN sales_invoices inv ON sr.invoice_id = inv.id
    LEFT JOIN settlements st ON sr.settlement_id = st.id
    WHERE {$whereClause}
    ORDER BY sr.return_date DESC, sr.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$customers = db_fetch_all("SELECT id, shop_name FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");

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
                    <i class="bi bi-arrow-return-left me-2"></i>Sales Returns
                </h4>
                <p class="text-muted small mb-0">Track goods returned from market: Saleable restock, damaged write-offs, and expired claims</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (has_permission('returns_create')): ?>
                    <a href="create.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Return Voucher
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Returns</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_returns']) ?></div>
                        <div class="small text-muted mt-1">Total: <?= format_currency((float)$stats['grand_return_val']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Saleable Stock Returned</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= format_currency((float)$stats['saleable_val']) ?></div>
                        <div class="small text-success mt-1">Restocked in warehouse</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Damaged Returns</div>
                        <div class="fs-4 fw-bold text-danger mt-1"><?= format_currency((float)$stats['damaged_val']) ?></div>
                        <div class="small text-danger mt-1">Pending company claim</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Expired Goods</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= format_currency((float)$stats['expired_val']) ?></div>
                        <div class="small text-muted mt-1">Supplier write-off claim</div>
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
                        <label class="form-label small fw-bold">Condition</label>
                        <select name="condition_type" class="form-select form-select-sm">
                            <option value="">-- All Conditions --</option>
                            <option value="saleable" <?= $condition === 'saleable' ? 'selected' : '' ?>>Saleable (Good Stock)</option>
                            <option value="damaged" <?= $condition === 'damaged' ? 'selected' : '' ?>>Damaged / Leaked</option>
                            <option value="expired" <?= $condition === 'expired' ? 'selected' : '' ?>>Expired / Out of Date</option>
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
                    <i class="bi bi-list-check me-1"></i> Sales Return Vouchers (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">Return #</th>
                                <th>Date</th>
                                <th>Customer / Shop</th>
                                <th>Source</th>
                                <th class="text-center">Condition</th>
                                <th class="text-end">Return Value</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returns)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No sales returns found for the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($returns as $r): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($r['return_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($r['return_date'])) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($r['customer_name']) ?></td>
                                        <td class="small text-muted">
                                            <?php if ($r['settlement_number']): ?>
                                                Settlement: <?= htmlspecialchars($r['settlement_number']) ?>
                                            <?php elseif ($r['invoice_number']): ?>
                                                Bill: <?= htmlspecialchars($r['invoice_number']) ?>
                                            <?php else: ?>
                                                Direct Return
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($r['condition_type'] === 'saleable'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success">Saleable</span>
                                            <?php elseif ($r['condition_type'] === 'damaged'): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger">Damaged</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning">Expired</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency((float)$r['total_amount']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="bi bi-eye"></i>
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
                                    <a class="page-link" href="?page=<?= $i ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&condition_type=<?= urlencode($condition) ?>&q=<?= urlencode($search) ?>">
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
