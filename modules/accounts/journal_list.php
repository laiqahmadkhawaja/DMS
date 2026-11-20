<?php
/**
 * DMS - Distribution Management System
 * General Ledger Journal Entries Register
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('journal_entries');

$pageTitle = "Journal Vouchers";
$activeMenu = "journal_entries";

// Filters
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate   = clean_input($_GET['to_date'] ?? $today);
$refType  = clean_input($_GET['ref_type'] ?? '');

$params = [$fromDate, $toDate];
$whereClauses = ["je.entry_date >= ? AND je.entry_date <= ?"];

if ($refType) {
    $whereClauses[] = "je.reference_type = ?";
    $params[] = $refType;
}

$whereSql = implode(" AND ", $whereClauses);

// Fetch Summary
$summary = db_fetch_one("
    SELECT 
        COUNT(je.id) as total_entries,
        COALESCE(SUM(je.total_debit), 0) as grand_debit,
        COALESCE(SUM(je.total_credit), 0) as grand_credit
    FROM journal_entries je
    WHERE {$whereSql}
", $params);

// Fetch Journal Entries
$entries = db_fetch_all("
    SELECT 
        je.*,
        u.full_name as created_by_name,
        (SELECT COUNT(jel.id) FROM journal_entry_lines jel WHERE jel.journal_entry_id = je.id) as total_lines
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.id
    WHERE {$whereSql}
    ORDER BY je.entry_date DESC, je.id DESC
", $params);

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
                    <i class="bi bi-journal-check me-2"></i>General Ledger Journal Vouchers
                </h4>
                <p class="text-muted small mb-0">Complete audit trail of all automated transaction postings and manual adjustment vouchers.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-bank me-1"></i> Chart of Accounts
                </a>
                <a href="journal_create.php" class="btn btn-primary" title="Shortcut: F4">
                    <i class="bi bi-plus-circle me-1"></i> New Journal Voucher <span class="badge bg-white text-primary ms-1">F4</span>
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Vouchers</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= (int)($summary['total_entries'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Recorded financial entries</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Total Debits</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($summary['grand_debit'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Total posted debits</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Total Credits</div>
                    <div class="h5 fw-bold text-danger mt-1 mb-0"><?= format_currency($summary['grand_credit'] ?? 0) ?></div>
                    <div class="small text-muted mt-1">Total posted credits</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">Ledger Balance Status</div>
                    <?php 
                        $imbalance = abs(($summary['grand_debit'] ?? 0) - ($summary['grand_credit'] ?? 0));
                    ?>
                    <div class="h5 fw-bold <?= $imbalance < 0.01 ? 'text-success' : 'text-danger' ?> mt-1 mb-0">
                        <?= $imbalance < 0.01 ? 'PERFECT (Rs. 0.00)' : 'IMBALANCE ' . format_currency($imbalance) ?>
                    </div>
                    <div class="small text-muted mt-1"><?= $imbalance < 0.01 ? 'Double-entry fully balanced' : 'Action required' ?></div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-2">
                <form method="GET" action="journal_list.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Transaction Source</label>
                        <select name="ref_type" class="form-select form-select-sm">
                            <option value="">-- All Transaction Types --</option>
                            <option value="manual_journal" <?= $refType === 'manual_journal' ? 'selected' : '' ?>>Manual Journal Voucher</option>
                            <option value="sales_invoice" <?= $refType === 'sales_invoice' ? 'selected' : '' ?>>Sales Invoice</option>
                            <option value="settlement" <?= $refType === 'settlement' ? 'selected' : '' ?>>Route Settlement</option>
                            <option value="grn" <?= $refType === 'grn' ? 'selected' : '' ?>>Goods Receipt (GRN)</option>
                            <option value="expense" <?= $refType === 'expense' ? 'selected' : '' ?>>Operating Expense</option>
                            <option value="cash_receipt" <?= $refType === 'cash_receipt' ? 'selected' : '' ?>>Customer Payment Receipt</option>
                            <option value="supplier_payment" <?= $refType === 'supplier_payment' ? 'selected' : '' ?>>Supplier Payment</option>
                            <option value="opening" <?= $refType === 'opening' ? 'selected' : '' ?>>Opening Balances</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="journal_list.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Vouchers Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-nowrap">
                            <tr>
                                <th style="width: 50px;" class="text-center">#</th>
                                <th>Date</th>
                                <th>Voucher #</th>
                                <th>Source / Reference</th>
                                <th>Narration / Particulars</th>
                                <th class="text-center">Lines</th>
                                <th class="text-end">Total Debit</th>
                                <th class="text-end">Total Credit</th>
                                <th class="text-center">Status</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                        No journal entries found for the selected period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($entries as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="text-nowrap"><?= format_date($row['entry_date']) ?></td>
                                        <td class="fw-bold text-navy text-nowrap">
                                            <a href="journal_view.php?id=<?= $row['id'] ?>" class="text-decoration-none text-navy">
                                                <?= htmlspecialchars($row['entry_number']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border text-uppercase">
                                                <?= htmlspecialchars(str_replace('_', ' ', $row['reference_type'])) ?>
                                            </span>
                                            <?php if ($row['reference_id']): ?>
                                                <span class="small text-muted ms-1">#<?= (int)$row['reference_id'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?= htmlspecialchars($row['narration'] ?: '—') ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-muted border"><?= (int)$row['total_lines'] ?></span>
                                        </td>
                                        <td class="text-end fw-semibold text-primary">
                                            <?= format_currency($row['total_debit']) ?>
                                        </td>
                                        <td class="text-end fw-semibold text-danger">
                                            <?= format_currency($row['total_credit']) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                POSTED
                                            </span>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <a href="journal_view.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Voucher">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($entries)): ?>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="6" class="text-end">Total Period Journal Activity:</td>
                                    <td class="text-end text-primary"><?= format_currency($summary['grand_debit'] ?? 0) ?></td>
                                    <td class="text-end text-danger"><?= format_currency($summary['grand_credit'] ?? 0) ?></td>
                                    <td colspan="2" class="text-center text-success">BALANCED</td>
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
        window.location.href = 'journal_create.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
