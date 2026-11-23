<?php
/**
 * DMS - Distribution Management System
 * Daily Operating Expenses Register
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('expenses_manage');

$pageTitle = "Daily Expenses";
$activeMenu = "expenses";

// Date & Category Filters
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate   = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate     = clean_input($_GET['to_date'] ?? $today);
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$accountId  = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;

// Build query
$params = [$fromDate, $toDate];
$whereClauses = ["e.expense_date >= ? AND e.expense_date <= ?"];

if ($categoryId) {
    $whereClauses[] = "e.expense_category_id = ?";
    $params[] = $categoryId;
}
if ($accountId) {
    $whereClauses[] = "e.paid_from_account_id = ?";
    $params[] = $accountId;
}

$whereSql = implode(" AND ", $whereClauses);

// Fetch Summary
$summary = db_fetch_one("
    SELECT 
        COUNT(e.id) as total_count,
        COALESCE(SUM(e.amount), 0) as total_expenses,
        COALESCE(SUM(CASE WHEN ec.category_code = 'EXP-FUEL' THEN e.amount ELSE 0 END), 0) as fuel_total,
        COALESCE(SUM(CASE WHEN ec.category_code = 'EXP-SALARY' THEN e.amount ELSE 0 END), 0) as salary_total,
        COALESCE(SUM(CASE WHEN ec.category_code = 'EXP-RENT' THEN e.amount ELSE 0 END), 0) as rent_total,
        COALESCE(SUM(CASE WHEN ec.category_code = 'EXP-MAINT' THEN e.amount ELSE 0 END), 0) as maint_total,
        COALESCE(SUM(CASE WHEN ec.category_code = 'EXP-OFFICE' THEN e.amount ELSE 0 END), 0) as office_total
    FROM expenses e
    JOIN expense_categories ec ON e.expense_category_id = ec.id
    WHERE {$whereSql}
", $params);

// Fetch List
$expenses = db_fetch_all("
    SELECT 
        e.*,
        ec.category_name,
        ec.category_code,
        a.account_name as paid_from_name,
        a.account_code as paid_from_code,
        u.full_name as recorded_by_name
    FROM expenses e
    JOIN expense_categories ec ON e.expense_category_id = ec.id
    JOIN accounts a ON e.paid_from_account_id = a.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE {$whereSql}
    ORDER BY e.expense_date DESC, e.id DESC
", $params);

// Categories & Accounts for filter dropdowns
$categories = get_expense_categories();
$paymentAccounts = db_fetch_all("
    SELECT id, account_code, account_name 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank') AND is_active = 1 
    ORDER BY account_code ASC
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

        <!-- Header & Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-wallet2 me-2"></i>Daily Operating Expenses
                </h4>
                <p class="text-muted small mb-0">Record and monitor day-to-day distributor operational costs, vehicle fuel, wages, and maintenance.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="categories.php" class="btn btn-outline-secondary">
                    <i class="bi bi-tags me-1"></i> Categories
                </a>
                <a href="create.php" class="btn btn-primary" id="btnNewExpense" title="Shortcut: F4">
                    <i class="bi bi-plus-circle me-1"></i> Record Expense <span class="badge bg-white text-primary ms-1">F4</span>
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Expenses</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($summary['total_expenses'] ?? 0) ?></div>
                    <div class="small text-muted mt-1"><?= (int)($summary['total_count'] ?? 0) ?> vouchers</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Fuel & Transport</div>
                    <div class="h5 fw-bold text-dark mt-1 mb-0"><?= format_currency($summary['fuel_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Van & Route fuel</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Salaries & Wages</div>
                    <div class="h5 fw-bold text-dark mt-1 mb-0"><?= format_currency($summary['salary_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Daily / Monthly staff</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">Vehicle Maint</div>
                    <div class="h5 fw-bold text-dark mt-1 mb-0"><?= format_currency($summary['maint_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Repairs & Oil change</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-secondary">
                    <div class="text-muted small fw-semibold text-uppercase">Rent & Power</div>
                    <div class="h5 fw-bold text-dark mt-1 mb-0"><?= format_currency($summary['rent_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Warehouse premises</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Office & Tea</div>
                    <div class="h5 fw-bold text-dark mt-1 mb-0"><?= format_currency($summary['office_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Daily office expense</div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-2">
                <form method="GET" action="index.php" class="row g-2 align-items-end">
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">Category</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">-- All Expense Categories --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?> (<?= htmlspecialchars($cat['category_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">Paid From</label>
                        <select name="account_id" class="form-select form-select-sm">
                            <option value="">-- All Payment Accounts --</option>
                            <?php foreach ($paymentAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= $accountId === (int)$acc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expenses Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-nowrap">
                            <tr>
                                <th style="width: 50px;" class="text-center">#</th>
                                <th>Date</th>
                                <th>Voucher #</th>
                                <th>Category</th>
                                <th>Payee / Recipient</th>
                                <th>Description / Purpose</th>
                                <th>Paid From</th>
                                <th class="text-center">Mode</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                        No expense records found for the selected date range.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($expenses as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="text-nowrap"><?= format_date($row['expense_date']) ?></td>
                                        <td class="fw-bold text-navy text-nowrap"><?= htmlspecialchars($row['expense_number']) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?= htmlspecialchars($row['category_name']) ?>
                                            </span>
                                            <?php if (!empty($row['split_type']) && $row['split_type'] !== 'none'): ?>
                                                <div><span class="badge bg-info-subtle text-info border border-info-subtle small mt-1" style="font-size: 10px;"><i class="bi bi-people-fill me-1"></i><?= ucfirst(str_replace('_', ' ', $row['split_type'])) ?></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['payee_name'] ?: '—') ?></td>
                                        <td class="text-muted small">
                                            <?= htmlspecialchars($row['description'] ?: '—') ?>
                                            <?php if (!empty($row['reference_voucher'])): ?>
                                                <div class="small text-secondary">Ref: <?= htmlspecialchars($row['reference_voucher']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-nowrap">
                                            <?= htmlspecialchars($row['paid_from_name']) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $row['payment_mode'] === 'cash' ? 'bg-success' : 'bg-primary' ?>-subtle text-<?= $row['payment_mode'] === 'cash' ? 'success' : 'primary' ?> border text-uppercase">
                                                <?= htmlspecialchars($row['payment_mode']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-bold text-navy">
                                            <?= format_currency($row['amount']) ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <a href="view.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="View / Print Voucher">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($expenses)): ?>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="8" class="text-end">Total Period Expenses:</td>
                                    <td class="text-end text-navy"><?= format_currency($summary['total_expenses'] ?? 0) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
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

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
