<?php
/**
 * DMS - Distribution Management System
 * Supplier Payments Register
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('payments_manage');

$pageTitle = "Supplier Payments";
$activeMenu = "supplier_payments";

// Filters
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate   = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate     = clean_input($_GET['to_date'] ?? $today);
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$mode       = clean_input($_GET['mode'] ?? '');

$params = [$fromDate, $toDate];
$whereClauses = ["sp.payment_date >= ? AND sp.payment_date <= ?"];

if ($supplierId) {
    $whereClauses[] = "sp.supplier_id = ?";
    $params[] = $supplierId;
}
if ($mode) {
    $whereClauses[] = "sp.payment_mode = ?";
    $params[] = $mode;
}

$whereSql = implode(" AND ", $whereClauses);

// Fetch Summary
$summary = db_fetch_one("
    SELECT 
        COUNT(sp.id) as total_payments,
        COALESCE(SUM(sp.amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN sp.payment_mode = 'cash' THEN sp.amount ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN sp.payment_mode IN ('bank_transfer', 'online') THEN sp.amount ELSE 0 END), 0) as bank_total,
        COALESCE(SUM(CASE WHEN sp.payment_mode = 'cheque' THEN sp.amount ELSE 0 END), 0) as cheque_total
    FROM supplier_payments sp
    WHERE {$whereSql}
", $params);

// Fetch Payments List
$payments = db_fetch_all("
    SELECT 
        sp.*,
        s.supplier_name,
        s.supplier_code,
        s.company_name,
        s.current_balance as supplier_current_balance,
        acc.account_name as paid_from_account_name,
        acc.account_code as paid_from_account_code,
        u.full_name as recorded_by_name
    FROM supplier_payments sp
    JOIN suppliers s ON sp.supplier_id = s.id
    JOIN accounts acc ON sp.account_id = acc.id
    LEFT JOIN users u ON sp.created_by = u.id
    WHERE {$whereSql}
    ORDER BY sp.payment_date DESC, sp.id DESC
", $params);

// Suppliers for filter dropdown
$suppliers = db_fetch_all("SELECT id, supplier_code, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 ORDER BY supplier_name ASC");

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
                    <i class="bi bi-credit-card me-2"></i>Supplier & Factory Payments
                </h4>
                <p class="text-muted small mb-0">Record and track payments made to manufacturing companies and trade creditors.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../suppliers/ledger.php" class="btn btn-outline-secondary">
                    <i class="bi bi-journal-bookmark me-1"></i> Supplier Ledgers
                </a>
                <a href="create_supplier_payment.php" class="btn btn-primary" title="Shortcut: F4">
                    <i class="bi bi-plus-circle me-1"></i> Record Supplier Payment <span class="badge bg-white text-primary ms-1">F4</span>
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Disbursed</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($summary['total_amount'] ?? 0) ?></div>
                    <div class="small text-muted mt-1"><?= (int)($summary['total_payments'] ?? 0) ?> vouchers</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">Bank Transfers</div>
                    <div class="h5 fw-bold text-info mt-1 mb-0"><?= format_currency($summary['bank_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Direct RTGS / Online</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Cash Disbursements</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($summary['cash_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Paid in cash / driver</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Cheques Issued</div>
                    <div class="h5 fw-bold text-warning mt-1 mb-0"><?= format_currency($summary['cheque_total'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Factory post-dated / bearer</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-2">
                <form method="GET" action="supplier_payments.php" class="row g-2 align-items-end">
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Supplier / Factory</label>
                        <select name="supplier_id" class="form-select form-select-sm">
                            <option value="">-- All Suppliers --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?> (<?= htmlspecialchars($s['company_name'] ?: $s['supplier_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">Payment Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="">-- All Modes --</option>
                            <option value="bank_transfer" <?= $mode === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="cash" <?= $mode === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="cheque" <?= $mode === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                            <option value="online" <?= $mode === 'online' ? 'selected' : '' ?>>Online</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="supplier_payments.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
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
                                <th>Voucher #</th>
                                <th>Supplier / Company</th>
                                <th>Paid From</th>
                                <th class="text-center">Mode</th>
                                <th>Reference / Cheque</th>
                                <th class="text-end">Amount Paid</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                        No supplier payment vouchers found for the selected period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($payments as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="text-nowrap"><?= format_date($row['payment_date']) ?></td>
                                        <td class="fw-bold text-navy text-nowrap"><?= htmlspecialchars($row['payment_number']) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($row['supplier_name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($row['company_name'] ?: $row['supplier_code']) ?></div>
                                        </td>
                                        <td class="small text-nowrap">
                                            <?= htmlspecialchars($row['paid_from_account_name']) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $row['payment_mode'] === 'cash' ? 'bg-success' : 'bg-primary' ?>-subtle text-<?= $row['payment_mode'] === 'cash' ? 'success' : 'primary' ?> border text-uppercase">
                                                <?= htmlspecialchars(str_replace('_', ' ', $row['payment_mode'])) ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars($row['reference_no'] ?: '—') ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger fs-6">
                                            <?= format_currency($row['amount']) ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <a href="view_supplier_payment.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Print Payment Voucher">
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
                                    <td colspan="7" class="text-end">Total Supplier Payments:</td>
                                    <td class="text-end text-danger fs-6"><?= format_currency($summary['total_amount'] ?? 0) ?></td>
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
        window.location.href = 'create_supplier_payment.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
