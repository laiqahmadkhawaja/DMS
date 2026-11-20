<?php
/**
 * DMS - Salesman Cash Settlement Ledger
 * Matches Image 4 (Abdur Rehman Sale Ledger) and Section 19 PRD
 */

require_once __DIR__ . '/../../config/config.php';
require_login();

$salesmanId = (int)($_GET['id'] ?? 0);
$salesmen = db_fetch_all("SELECT id, salesman_name, employee_code, role FROM salesmen WHERE is_deleted = 0 ORDER BY salesman_name ASC");

if ($salesmanId === 0 && !empty($salesmen)) {
    $salesmanId = (int)$salesmen[0]['id'];
}

$selectedSalesman = db_fetch_one("SELECT * FROM salesmen WHERE id = ?", [$salesmanId]);

// Date filters
$fromDate = clean_input($_GET['from_date'] ?? date('Y-m-01'));
$toDate   = clean_input($_GET['to_date'] ?? date('Y-m-d'));

// Query Route Settlements & Cash Collections
$sql = "
    SELECT 
        s.id, s.settlement_number, s.settlement_date, 
        s.total_invoices_amount, s.total_cash_received, s.total_credit_given, 
        s.total_returns_amount, s.difference_amount, s.status,
        (SELECT COALESCE(SUM(cs.amount), 0) FROM cash_submissions cs WHERE cs.settlement_id = s.id) as cash_submitted
    FROM settlements s
    WHERE s.salesman_id = ? AND s.settlement_date BETWEEN ? AND ?
    ORDER BY s.settlement_date ASC, s.id ASC
";
$settlementRows = db_fetch_all($sql, [$salesmanId, $fromDate, $toDate]);

// Cash Submissions independent of settlements
$directSubmissions = db_fetch_all("
    SELECT * FROM cash_submissions 
    WHERE salesman_id = ? AND submission_date BETWEEN ? AND ?
    ORDER BY submission_date ASC
", [$salesmanId, $fromDate, $toDate]);

$totalDeliveredBilled = 0;
$totalCashCollected = 0;
$totalCreditIssued = 0;
$totalReturns = 0;
$totalOfficeHandover = 0;

foreach ($settlementRows as $row) {
    $totalDeliveredBilled += (float)$row['total_invoices_amount'];
    $totalCashCollected   += (float)$row['total_cash_received'];
    $totalCreditIssued    += (float)$row['total_credit_given'];
    $totalReturns         += (float)$row['total_returns_amount'];
    $totalOfficeHandover  += (float)$row['cash_submitted'];
}

$netCashDueToOffice = $totalCashCollected - $totalOfficeHandover;

$pageTitle = 'Salesman Cash Ledger — ' . APP_NAME;
$activeMenu = 'sm_ledger';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-journal-check me-2 text-primary"></i>Salesman Cash Collection Ledger</h5>
        <small class="text-muted">Accountability ledger tracking field bill deliveries, cash collections, and office submissions</small>
    </div>
    <div class="no-print">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Ledger (F10)
        </button>
    </div>
</div>

<!-- Selector & Date Filter -->
<div class="card-dms p-2 mb-3 no-print">
    <form method="GET" action="<?= APP_URL ?>/modules/salesmen/ledger.php" class="row g-2 align-items-center">
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Select Delivery Salesman</label>
            <select name="id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($salesmen as $sm): ?>
                    <option value="<?= $sm['id'] ?>" <?= $sm['id'] == $salesmanId ? 'selected' : '' ?>>
                        <?= e($sm['salesman_name']) ?> (<?= e($sm['employee_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">From Date</label>
            <input type="date" name="from_date" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">To Date</label>
            <input type="date" name="to_date" class="form-control form-control-sm" value="<?= e($toDate) ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-filter"></i> Apply Filter
            </button>
        </div>
    </form>
</div>

<!-- KPI Summary Ribbon -->
<div class="row g-2 mb-3">
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="small text-muted">Total Delivered Bills</div>
            <h5 class="fw-bold mb-0 text-dark"><?= format_currency($totalDeliveredBilled) ?></h5>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="small text-muted">Cash Collected From Retailers</div>
            <h5 class="fw-bold mb-0 text-success"><?= format_currency($totalCashCollected) ?></h5>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="small text-muted">Cash Submitted to Office</div>
            <h5 class="fw-bold mb-0 text-primary"><?= format_currency($totalOfficeHandover) ?></h5>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="small text-muted">Pending In-Hand Balance</div>
            <h5 class="fw-bold mb-0 <?= $netCashDueToOffice > 0 ? 'text-danger' : 'text-success' ?>">
                <?= format_currency($netCashDueToOffice) ?>
            </h5>
        </div>
    </div>
</div>

<!-- Ledger Transactions Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span>
            <i class="bi bi-clock-history me-1"></i> 
            Settlement History: <strong class="text-primary"><?= e($selectedSalesman['salesman_name'] ?? 'Staff') ?></strong>
        </span>
        <span class="small text-muted">Period: <?= format_date($fromDate) ?> to <?= format_date($toDate) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th>Date</th>
                    <th>Settlement #</th>
                    <th class="text-end">Total Billed</th>
                    <th class="text-end">Cash Collected</th>
                    <th class="text-end">Credit Given</th>
                    <th class="text-end">Returns</th>
                    <th class="text-end">Submitted to Office</th>
                    <th class="text-end">Difference</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($settlementRows)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No settlement vouchers recorded for this salesman in the selected date range.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($settlementRows as $row): ?>
                        <tr>
                            <td><?= format_date($row['settlement_date']) ?></td>
                            <td>
                                <a href="<?= APP_URL ?>/modules/settlements/view.php?id=<?= $row['id'] ?>" class="fw-semibold text-primary">
                                    <?= e($row['settlement_number']) ?>
                                </a>
                            </td>
                            <td class="text-end"><?= format_currency($row['total_invoices_amount']) ?></td>
                            <td class="text-end text-success fw-semibold"><?= format_currency($row['total_cash_received']) ?></td>
                            <td class="text-end"><?= format_currency($row['total_credit_given']) ?></td>
                            <td class="text-end text-danger"><?= format_currency($row['total_returns_amount']) ?></td>
                            <td class="text-end text-primary fw-semibold"><?= format_currency($row['cash_submitted']) ?></td>
                            <td class="text-end <?= (float)$row['difference_amount'] != 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <?= format_currency($row['difference_amount']) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $row['status'] === 'finalized' ? 'success' : 'warning' ?>-subtle text-<?= $row['status'] === 'finalized' ? 'success' : 'dark' ?> border">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-fmcg-total">
                <tr>
                    <th colspan="2" class="text-end">Period Totals:</th>
                    <th class="text-end"><?= format_currency($totalDeliveredBilled) ?></th>
                    <th class="text-end"><?= format_currency($totalCashCollected) ?></th>
                    <th class="text-end"><?= format_currency($totalCreditIssued) ?></th>
                    <th class="text-end"><?= format_currency($totalReturns) ?></th>
                    <th class="text-end"><?= format_currency($totalOfficeHandover) ?></th>
                    <th class="text-end" colspan="2"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
