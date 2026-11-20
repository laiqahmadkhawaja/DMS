<?php
/**
 * DMS - Distribution Management System
 * System Audit Trail & Security Log Explorer
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

require_login();
require_permission('audit_view');

$pageTitle = 'System Audit Trail';
$activeMenu = 'audit';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settings & Control', 'url' => 'index.php'],
    ['label' => 'Audit Trail', 'url' => '']
];

// Filters
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate   = clean_input($_GET['to_date'] ?? $today);
$module   = clean_input($_GET['module'] ?? '');
$action   = clean_input($_GET['action'] ?? '');
$keyword  = clean_input($_GET['search'] ?? '');

$where = ["DATE(al.created_at) >= ?", "DATE(al.created_at) <= ?"];
$params = [$fromDate, $toDate];

if (!empty($module)) {
    $where[] = "al.module = ?";
    $params[] = $module;
}

if (!empty($action)) {
    $where[] = "al.action = ?";
    $params[] = $action;
}

if (!empty($keyword)) {
    $where[] = "(al.details LIKE ? OR al.record_id LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    $term = "%{$keyword}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereClause = implode(" AND ", $where);

// Pagination
$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalCount = (int)db_fetch_val("
    SELECT COUNT(al.id)
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE {$whereClause}
", $params);

$totalPages = max(1, ceil($totalCount / $perPage));

$logs = db_fetch_all("
    SELECT 
        al.id,
        al.user_id,
        al.action,
        al.module,
        al.record_id,
        al.details,
        al.ip_address,
        al.created_at,
        u.username,
        u.full_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE {$whereClause}
    ORDER BY al.id DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// Distinct modules and actions for filter dropdowns
$availableModules = db_fetch_all("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL AND module != '' ORDER BY module ASC");
$availableActions = db_fetch_all("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action != '' ORDER BY action ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-clock-history me-2"></i>System Audit Trail
                </h4>
                <p class="text-muted small mb-0">Immutable chronological record of user transactions, logins, inventory dispatches, and financial ledger postings</p>
            </div>
            <div>
                <a href="<?= APP_URL ?>/modules/settings/index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-gear me-1"></i> System Settings
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="audit.php" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Module</label>
                        <select name="module" class="form-select form-select-sm">
                            <option value="">-- All Modules --</option>
                            <?php foreach ($availableModules as $m): ?>
                                <option value="<?= htmlspecialchars($m['module']) ?>" <?= $module === $m['module'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($m['module'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Action</label>
                        <select name="action" class="form-select form-select-sm">
                            <option value="">-- All Actions --</option>
                            <?php foreach ($availableActions as $a): ?>
                                <option value="<?= htmlspecialchars($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(strtoupper($a['action'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Keyword Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Record #, User or Details..." value="<?= htmlspecialchars($keyword) ?>">
                    </div>
                    <div class="col-md-1 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-navy w-100" title="Apply Filters">
                            <i class="bi bi-funnel"></i>
                        </button>
                        <a href="audit.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Audit Records Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-list-check me-1"></i> Audit Activity Logs (Total: <?= number_format($totalCount) ?> records)
                </span>
                <span class="badge bg-light text-dark border">Page <?= $page ?> of <?= $totalPages ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0" style="font-size: 13px;">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%;">Date & Time</th>
                            <th style="width: 14%;">User</th>
                            <th style="width: 10%;">Action</th>
                            <th style="width: 12%;">Module</th>
                            <th style="width: 12%;">Record Ref</th>
                            <th style="width: 25%;">Activity Details</th>
                            <th style="width: 12%;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-info-circle me-1"></i> No audit log records found for the selected criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): 
                                $actionBadge = 'bg-secondary';
                                switch (strtolower($log['action'])) {
                                    case 'create':
                                    case 'insert':
                                        $actionBadge = 'bg-success';
                                        break;
                                    case 'update':
                                    case 'edit':
                                        $actionBadge = 'bg-info text-dark';
                                        break;
                                    case 'delete':
                                    case 'cancel':
                                        $actionBadge = 'bg-danger';
                                        break;
                                    case 'login':
                                        $actionBadge = 'bg-primary';
                                        break;
                                    case 'dispatch':
                                    case 'reconcile':
                                        $actionBadge = 'bg-warning text-dark';
                                        break;
                                }
                            ?>
                                <tr>
                                    <td class="text-nowrap fw-semibold text-secondary">
                                        <?= date('d-m-Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-navy"><?= htmlspecialchars($log['full_name'] ?: ($log['username'] ?: 'System')) ?></div>
                                        <small class="text-muted">@<?= htmlspecialchars($log['username'] ?: 'system') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $actionBadge ?> text-uppercase px-2 py-1" style="font-size: 10.5px;">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-semibold text-dark text-capitalize">
                                        <?= htmlspecialchars($log['module'] ?: 'System') ?>
                                    </td>
                                    <td class="fw-mono">
                                        <?= htmlspecialchars($log['record_id'] ?: '-') ?>
                                    </td>
                                    <td class="text-wrap">
                                        <?= htmlspecialchars($log['details']) ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white border-top py-3 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Showing <?= min($offset + 1, $totalCount) ?> to <?= min($offset + $perPage, $totalCount) ?> of <?= $totalCount ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                                </li>
                            <?php endif; ?>

                            <?php 
                            $startP = max(1, $page - 2);
                            $endP   = min($totalPages, $page + 2);
                            for ($i = $startP; $i <= $endP; $i++): 
                            ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                                </li>
                            <?php endif; ?>
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
