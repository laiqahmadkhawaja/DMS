<?php
/**
 * DMS - Distribution Management System
 * Customer Payment Receipts Register
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('payments_manage');

$pageTitle = "Customer Payment Receipts";
$activeMenu = "customer_payments";

// Filters
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate   = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate     = clean_input($_GET['to_date'] ?? $today);
$customerId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$mode       = clean_input($_GET['mode'] ?? '');

$params = [$fromDate, $toDate];
$whereClauses = ["cp.payment_date >= ? AND cp.payment_date <= ?"];

if ($customerId) {
    $whereClauses[] = "cp.customer_id = ?";
    $params[] = $customerId;
}
if ($mode) {
    $whereClauses[] = "cp.payment_mode = ?";
    $params[] = $mode;
}

$whereSql = implode(" AND ", $whereClauses);

// Fetch Summary
$summary = db_fetch_one("
    SELECT 
        COUNT(cp.id) as total_receipts,
        COALESCE(SUM(cp.amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN cp.payment_mode = 'cash' THEN cp.amount ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN cp.payment_mode IN ('bank_transfer', 'online') THEN cp.amount ELSE 0 END), 0) as bank_total,
        COALESCE(SUM(CASE WHEN cp.payment_mode = 'cheque' THEN cp.amount ELSE 0 END), 0) as cheque_total
    FROM customer_payments cp
    WHERE {$whereSql}
", $params);

// Fetch Payments List
$payments = db_fetch_all("
    SELECT 
        cp.*,
        c.shop_name,
        c.owner_name,
        c.customer_code,
        c.current_balance as customer_current_balance,
        a.area_name,
        acc.account_name as deposited_account_name,
        acc.account_code as deposited_account_code,
        sm.salesman_name,
        u.full_name as recorded_by_name
    FROM customer_payments cp
    JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN areas a ON c.area_id = a.id
    JOIN accounts acc ON cp.account_id = acc.id
    LEFT JOIN salesmen sm ON cp.salesman_id = sm.id
    LEFT JOIN users u ON cp.created_by = u.id
    WHERE {$whereSql}
    ORDER BY cp.payment_date DESC, cp.id DESC
", $params);

// Customers for filter dropdown
$customers = db_fetch_all("SELECT id, customer_code, shop_name, owner_name FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-cash-stack me-2"></i>Customer Receipts & Recoveries
                </h4>
                <p class="text-muted small mb-0">Record and track credit recoveries, bank transfers, cheques, and office cash collections from retail shops.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../customers/statement.php" class="btn btn-outline-secondary">
                    <i class="bi bi-person-lines-fill me-1"></i> Customer Statements
                </a>
                <a href="create_customer_payment.php" class="btn btn-primary" title="Shortcut: F4">
                    <i class="bi bi-plus-circle me-1"></i> Record Customer Receipt <span class="badge bg-white text-primary ms-1">F4</span>
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Collections</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($summary['total_amount'] ?? 0) ?></div>
                    <div class="small text-muted mt-1"><?= (int)($summary['total_receipts'] ?? 0) ?> payment receipts</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Cash Receipts</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($summary['cash_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Cash drawer collections</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">Bank Transfers</div>
                    <div class="h5 fw-bold text-info mt-1 mb-0"><?= format_currency($summary['bank_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Online & Direct deposits</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Cheques Received</div>
                    <div class="h5 fw-bold text-warning mt-1 mb-0"><?= format_currency($summary['cheque_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Clearing / Party cheques</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-2">
                <form method="GET" action="customer_payments.php" class="row g-2 align-items-end">
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Customer / Shop</label>
                        <select name="customer_id" class="form-select form-select-sm">
                            <option value="">-- All Customers --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['owner_name'] ?: $c['customer_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">Payment Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="">-- All Modes --</option>
                            <option value="cash" <?= $mode === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="bank_transfer" <?= $mode === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="cheque" <?= $mode === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                            <option value="online" <?= $mode === 'online' ? 'selected' : '' ?>>Online</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="customer_payments.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-nowrap">
                            <tr>
                                <th style="width: 50px;" class="text-center">#</th>
                                <th>Date</th>
                                <th>Receipt #</th>
                                <th>Customer / Shop</th>
                                <th>Deposited To</th>
                                <th>Collector / Salesman</th>
                                <th class="text-center">Mode</th>
                                <th>Reference / Cheque</th>
                                <th class="text-end">Amount Paid</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                        No customer payment receipts found for the selected period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($payments as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="text-nowrap"><?= format_date($row['payment_date']) ?></td>
                                        <td class="fw-bold text-navy text-nowrap"><?= htmlspecialchars($row['payment_number']) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($row['shop_name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($row['owner_name'] ?: $row['area_name'] ?: '—') ?></div>
                                        </td>
                                        <td class="small text-nowrap">
                                            <?= htmlspecialchars($row['deposited_account_name']) ?>
                                        </td>
                                        <td class="small">
                                            <?= htmlspecialchars($row['salesman_name'] ?: 'Office Direct') ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $row['payment_mode'] === 'cash' ? 'bg-success' : 'bg-primary' ?>-subtle text-<?= $row['payment_mode'] === 'cash' ? 'success' : 'primary' ?> border text-uppercase">
                                                <?= htmlspecialchars(str_replace('_', ' ', $row['payment_mode'])) ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars($row['reference_no'] ?: '—') ?>
                                        </td>
                                        <td class="text-end fw-bold text-success fs-6">
                                            <?= format_currency($row['amount']) ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <a href="view_customer_payment.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Print Receipt">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($payments)): ?>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="8" class="text-end">Total Period Recovery:</td>
                                    <td class="text-end text-success fs-6"><?= format_currency($summary['total_amount'] ?? 0) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'F4') {
        e.preventDefault();
        window.location.href = 'create_customer_payment.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
