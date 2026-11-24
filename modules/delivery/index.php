<?php
/**
 * DMS - Distribution Management System
 * Delivery Runs - Index Listing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('delivery_view');

$pageTitle = 'Credit Sheets (کریڈٹ شیٹ / سیلز مین شیٹ)';
$activeMenu = 'delivery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Credit Sheets (کریڈٹ شیٹ)', 'url' => '']
];

// Filters
$startDate  = sanitize_input($_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
$endDate    = sanitize_input($_GET['end_date'] ?? date('Y-m-d'));
$salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
$status     = sanitize_input($_GET['status'] ?? '');
$search     = sanitize_input($_GET['q'] ?? '');

$where = ["dr.run_date BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($salesmanId > 0) {
    $where[] = "dr.salesman_id = ?";
    $params[] = $salesmanId;
}
if (!empty($status)) {
    $where[] = "dr.status = ?";
    $params[] = $status;
}
if (!empty($search)) {
    $where[] = "(dr.run_number LIKE ? OR s.salesman_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(" AND ", $where);

// Summary Stats
$statsSql = "
    SELECT 
        COUNT(dr.id) as total_runs,
        SUM(CASE WHEN dr.status = 'assigned' THEN 1 ELSE 0 END) as assigned_count,
        SUM(CASE WHEN dr.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(dr.total_amount) as total_run_value
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    WHERE {$whereClause}
";
$stats = db_fetch_one($statsSql, $params) ?: [
    'total_runs' => 0,
    'assigned_count' => 0,
    'completed_count' => 0,
    'total_run_value' => 0.0
];

// Fetch Paginated Runs
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = (int)db_fetch_val("
    SELECT COUNT(dr.id)
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    WHERE {$whereClause}
", $params);
$totalPages = ceil($totalRows / $perPage);

$runs = db_fetch_all("
    SELECT 
        dr.id,
        dr.run_number,
        dr.run_date,
        dr.salesman_id,
        dr.run_type,
        a.area_name,
        s.salesman_name,
        ls.load_number,
        dr.total_bills,
        dr.total_amount,
        dr.status,
        dr.route_notes,
        dr.created_at
    FROM delivery_runs dr
    JOIN salesmen s ON dr.salesman_id = s.id
    LEFT JOIN load_sheets ls ON dr.load_sheet_id = ls.id
    LEFT JOIN areas a ON dr.area_id = a.id
    WHERE {$whereClause}
    ORDER BY dr.run_date DESC, dr.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Title & Header Actions -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-journal-check me-2"></i>Credit Sheets (کریڈٹ شیٹ / سیلز مین شیٹ)
                </h4>
                <p class="text-muted small mb-0">Salesman recovery sheets, credit given to shops, and field collection records</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (has_permission('delivery_manage')): ?>
                    <a href="create.php" class="btn btn-outline-primary shadow-sm">
                        <i class="bi bi-truck-flatbed me-1"></i> From Load Form (لوڈ فارم سے)
                    </a>
                    <a href="create_manual.php" class="btn btn-primary shadow-sm fw-bold">
                        <i class="bi bi-pencil-square me-1"></i> New Manual Credit Sheet (دستی کریڈٹ شیٹ)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Credit Sheets</div>
                        <div class="fs-4 fw-bold text-dark mt-1"><?= number_format($stats['total_runs']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Assigned / In Route</div>
                        <div class="fs-4 fw-bold text-warning mt-1"><?= number_format($stats['assigned_count']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Completed Runs</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= number_format($stats['completed_count']) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Total Dispatched Value</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$stats['total_run_value']) ?></div>
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

        <!-- Runs Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-journal-check me-1"></i> Credit Sheets Register (کریڈٹ شیٹ رجسٹر) (<?= number_format($totalRows) ?>)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-3">Sheet #</th>
                                <th>Date</th>
                                <th>Salesman</th>
                                <th>Source / Type</th>
                                <th class="text-center">Total Bills</th>
                                <th class="text-end">Credit Value</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($runs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                                        No credit sheets found for the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($runs as $r): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy">
                                            <a href="view.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($r['run_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= date('d-M-Y', strtotime($r['run_date'])) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($r['salesman_name']) ?></td>
                                        <td>
                                            <?php if ($r['run_type'] === 'manual'): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                    <i class="bi bi-pencil-square me-1"></i>Manual Recovery
                                                </span>
                                                <?php if (!empty($r['area_name'])): ?>
                                                    <small class="d-block text-muted"><?= htmlspecialchars($r['area_name']) ?></small>
                                                <?php endif; ?>
                                            <?php elseif ($r['load_number']): ?>
                                                <span class="badge bg-light text-dark border"><i class="bi bi-truck me-1"></i><?= htmlspecialchars($r['load_number']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Direct Run</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary rounded-pill px-2"><?= (int)$r['total_bills'] ?> bills</span>
                                        </td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency((float)$r['total_amount']) ?></td>
                                        <td class="text-center">
                                            <?php if ($r['status'] === 'assigned'): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Assigned</span>
                                            <?php elseif ($r['status'] === 'in_progress'): ?>
                                                <span class="badge bg-info text-dark"><i class="bi bi-truck me-1"></i>In Progress</span>
                                            <?php elseif ($r['status'] === 'completed'): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Completed</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($r['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-outline-primary" title="View & Print Field Recovery Sheet">
                                                    <i class="bi bi-printer"></i> Recovery Sheet
                                                </a>
                                                <?php if ($r['status'] !== 'completed'): ?>
                                                    <a href="../settlements/create.php?delivery_run_id=<?= $r['id'] ?>" class="btn btn-success" title="Settle Route (F8)">
                                                        <i class="bi bi-cash-stack"></i> Settle
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
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
