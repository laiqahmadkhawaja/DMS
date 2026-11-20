<?php
/**
 * DMS - Distribution Management System
 * Muzzam Daily Sale Received Register (Photo 1, 3, 8 Format)
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/database_helpers.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Daily Sales Register";
$activeMenu = "rpt_daily";

// Date Filters
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate   = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate     = clean_input($_GET['to_date'] ?? $today);
$bookerId   = !empty($_GET['booker_id']) ? (int)$_GET['booker_id'] : null;
$salesmanId = !empty($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : null;
$viewMode   = clean_input($_GET['view_mode'] ?? 'summary'); // 'summary' (Photo 8) or 'detailed' (Photo 1)

// Summary Query: Grouped by Date
$params = [$fromDate, $toDate];
$whereInvoices = "si.invoice_date >= ? AND si.invoice_date <= ? AND si.status != 'cancelled'";

if ($bookerId) {
    $whereInvoices .= " AND si.booker_id = ?";
    $params[] = $bookerId;
}
if ($salesmanId) {
    $whereInvoices .= " AND si.salesman_id = ?";
    $params[] = $salesmanId;
}

// Fetch Daily Grouped Summary (Strictly matching Photo 8: Date | Invoices | Cash Received | Credit | Return)
$dailySummary = db_fetch_all("
    SELECT 
        si.invoice_date,
        COUNT(si.id) as total_bills,
        COALESCE(SUM(si.net_amount), 0) as total_invoices,
        COALESCE(SUM(si.paid_amount), 0) as cash_received,
        COALESCE(SUM(si.credit_amount), 0) as credit_given,
        COALESCE(SUM(si.return_amount), 0) as return_amount
    FROM sales_invoices si
    WHERE {$whereInvoices}
    GROUP BY si.invoice_date
    ORDER BY si.invoice_date DESC
", $params);

// Fetch Overall Totals
$grandTotals = [
    'bills'    => 0,
    'invoices' => 0.0,
    'cash'     => 0.0,
    'credit'   => 0.0,
    'returns'  => 0.0
];

foreach ($dailySummary as $ds) {
    $grandTotals['bills']    += (int)$ds['total_bills'];
    $grandTotals['invoices'] += (float)$ds['total_invoices'];
    $grandTotals['cash']     += (float)$ds['cash_received'];
    $grandTotals['credit']   += (float)$ds['credit_given'];
    $grandTotals['returns']  += (float)$ds['return_amount'];
}

// Fetch Detailed Shop Breakdown (Matching Photo 1 & 3)
$detailedRows = [];
if ($viewMode === 'detailed') {
    $detailedRows = db_fetch_all("
        SELECT 
            si.id,
            si.invoice_number,
            si.invoice_date,
            si.net_amount as total_amount,
            si.paid_amount as cash_amount,
            si.credit_amount,
            si.return_amount,
            (si.net_amount - (si.paid_amount + si.credit_amount + si.return_amount)) as difference,
            c.shop_name,
            c.owner_name,
            b.booker_name,
            sm.salesman_name
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        LEFT JOIN bookers b ON si.booker_id = b.id
        LEFT JOIN salesmen sm ON si.salesman_id = sm.id
        WHERE {$whereInvoices}
        ORDER BY si.invoice_date DESC, si.id ASC
    ", $params);
}

// Bookers & Salesmen for filters
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_active = 1 AND is_deleted = 0 ORDER BY booker_name ASC");
$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 AND is_deleted = 0 ORDER BY salesman_name ASC");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header & Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-table me-2"></i>Daily Sales & Recovery Register
                </h4>
                <p class="text-muted small mb-0">Replication of client daily sheets (Photos 1, 3, 8: <strong>MUZZAM DAILY SALE RECIVED + CREDIT</strong>).</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../excel/index.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Register (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="daily_sales.php" class="row g-2 align-items-end">
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">Booker</label>
                        <select name="booker_id" class="form-select form-select-sm">
                            <option value="">-- All Bookers --</option>
                            <?php foreach ($bookers as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $bookerId === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['booker_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">Salesman</label>
                        <select name="salesman_id" class="form-select form-select-sm">
                            <option value="">-- All Salesmen --</option>
                            <?php foreach ($salesmen as $sm): ?>
                                <option value="<?= $sm['id'] ?>" <?= $salesmanId === (int)$sm['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sm['salesman_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-12 d-flex gap-2">
                        <select name="view_mode" class="form-select form-select-sm">
                            <option value="summary" <?= $viewMode === 'summary' ? 'selected' : '' ?>>Date Summary (Photo 8)</option>
                            <option value="detailed" <?= $viewMode === 'detailed' ? 'selected' : '' ?>>Shop Details (Photo 1)</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm px-3">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Total Invoiced Amount</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($grandTotals['invoices']) ?></div>
                    <div class="small text-muted mt-1"><?= (int)$grandTotals['bills'] ?> customer bills</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Total Cash Received</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($grandTotals['cash']) ?></div>
                    <div class="small text-muted mt-1">Direct recovery</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Market Credit</div>
                    <div class="h5 fw-bold text-primary mt-1 mb-0"><?= format_currency($grandTotals['credit']) ?></div>
                    <div class="small text-muted mt-1">Added to customer ledger</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Total Sales Returns</div>
                    <div class="h5 fw-bold text-danger mt-1 mb-0"><?= format_currency($grandTotals['returns']) ?></div>
                    <div class="small text-muted mt-1">Returned stock credit</div>
                </div>
            </div>
        </div>

        <!-- Printable Container -->
        <div class="card border shadow-sm print-container bg-white">
            <div class="card-body p-4">
                
                <!-- Report Header -->
                <div class="text-center border-bottom pb-3 mb-3">
                    <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                    <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                    <div class="mt-2">
                        <span class="badge bg-warning text-dark px-3 py-1 fs-6 text-uppercase fw-bold">
                            <?= $viewMode === 'detailed' ? 'DAILY SALE RECIVED + CREDIT (ITEMIZED SHOPS)' : 'MUZZAM DAILY SALE RECIVED (SUMMARY)' ?>
                        </span>
                    </div>
                    <div class="small text-muted mt-1">
                        Period: <strong><?= format_date($fromDate) ?></strong> to <strong><?= format_date($toDate) ?></strong>
                    </div>
                </div>

                <?php if ($viewMode === 'summary'): ?>
                    
                    <!-- SUMMARY VIEW (EXACT REPLICATION OF PHOTO 8) -->
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-nowrap mb-0">
                            <thead class="table-warning border-dark text-dark fw-bold">
                                <tr>
                                    <th style="width: 50px;" class="text-center">Sr.</th>
                                    <th>Date</th>
                                    <th class="text-center">Bills</th>
                                    <th class="text-end" style="background-color: #e5b800; color: #000;">Invoices (Total Amount)</th>
                                    <th class="text-end">Cash Recived</th>
                                    <th class="text-end">Credit</th>
                                    <th class="text-end">Return</th>
                                    <th class="text-center no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dailySummary)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No sales records found for this period.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $sn = 1; foreach ($dailySummary as $row): ?>
                                        <tr>
                                            <td class="text-center text-muted"><?= $sn++ ?></td>
                                            <td class="fw-bold"><?= date('d.m.y', strtotime($row['invoice_date'])) ?></td>
                                            <td class="text-center"><?= (int)$row['total_bills'] ?></td>
                                            <td class="text-end fw-bold text-navy" style="background-color: #fff9e6;">
                                                <?= number_format($row['total_invoices'], 0) ?>
                                            </td>
                                            <td class="text-end fw-semibold text-success">
                                                <?= $row['cash_received'] > 0 ? number_format($row['cash_received'], 0) : '' ?>
                                            </td>
                                            <td class="text-end fw-semibold text-primary">
                                                <?= $row['credit_given'] > 0 ? number_format($row['credit_given'], 0) : '' ?>
                                            </td>
                                            <td class="text-end fw-semibold text-danger">
                                                <?= $row['return_amount'] > 0 ? number_format($row['return_amount'], 0) : '' ?>
                                            </td>
                                            <td class="text-center no-print">
                                                <a href="daily_sales.php?from_date=<?= $row['invoice_date'] ?>&to_date=<?= $row['invoice_date'] ?>&view_mode=detailed" class="btn btn-sm btn-outline-primary py-0 px-2" title="View Shop Breakdown">
                                                    Breakdown
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-warning border-dark fw-bold">
                                <tr>
                                    <td colspan="2" class="text-center" style="background-color: #d49a00; color: #fff;">Total</td>
                                    <td class="text-center" style="background-color: #d49a00; color: #fff;"><?= (int)$grandTotals['bills'] ?></td>
                                    <td class="text-end" style="background-color: #d49a00; color: #fff; font-size: 1.1rem;">
                                        <?= number_format($grandTotals['invoices'], 0) ?>
                                    </td>
                                    <td class="text-end" style="font-size: 1.05rem;"><?= number_format($grandTotals['cash'], 0) ?></td>
                                    <td class="text-end" style="font-size: 1.05rem;"><?= number_format($grandTotals['credit'], 0) ?></td>
                                    <td class="text-end" style="font-size: 1.05rem;"><?= number_format($grandTotals['returns'], 0) ?></td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                <?php else: ?>

                    <!-- DETAILED VIEW (EXACT REPLICATION OF PHOTO 1 & 3) -->
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-nowrap mb-0">
                            <thead class="table-warning border-dark text-dark fw-bold">
                                <tr>
                                    <th style="width: 50px;" class="text-center">Sr.</th>
                                    <th>Shop Name</th>
                                    <th class="text-center">Bill No.</th>
                                    <th class="text-end" style="background-color: #e5b800; color: #000;">Total Amount</th>
                                    <th class="text-end">Credit</th>
                                    <th class="text-end">Return</th>
                                    <th class="text-end">Cash</th>
                                    <th class="text-center">Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detailedRows)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No bill records found for this period.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $sn = 1; foreach ($detailedRows as $row): ?>
                                        <tr>
                                            <td class="text-center text-muted"><?= $sn++ ?></td>
                                            <td class="fw-semibold">
                                                <?= htmlspecialchars($row['shop_name']) ?>
                                                <?php if ($row['owner_name']): ?>
                                                    <span class="small text-muted">(<?= htmlspecialchars($row['owner_name']) ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center fw-bold text-navy"><?= htmlspecialchars($row['invoice_number']) ?></td>
                                            <td class="text-end fw-bold text-navy" style="background-color: #fff9e6;">
                                                <?= number_format($row['total_amount'], 0) ?>
                                            </td>
                                            <td class="text-end fw-semibold text-primary">
                                                <?= $row['credit_amount'] > 0 ? number_format($row['credit_amount'], 0) : '' ?>
                                            </td>
                                            <td class="text-end fw-semibold text-danger">
                                                <?= $row['return_amount'] > 0 ? number_format($row['return_amount'], 0) : '' ?>
                                            </td>
                                            <td class="text-end fw-semibold text-success">
                                                <?= $row['cash_amount'] > 0 ? number_format($row['cash_amount'], 0) : '' ?>
                                            </td>
                                            <td class="text-center small <?= abs($row['difference']) > 0.01 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                                <?= abs($row['difference']) > 0.01 ? number_format($row['difference'], 0) : '0' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-warning border-dark fw-bold">
                                <tr>
                                    <td colspan="3" class="text-center" style="background-color: #d49a00; color: #fff;">Total</td>
                                    <td class="text-end" style="background-color: #d49a00; color: #fff; font-size: 1.1rem;">
                                        <?= number_format($grandTotals['invoices'], 0) ?>
                                    </td>
                                    <td class="text-end" style="font-size: 1.05rem;"><?= number_format($grandTotals['credit'], 0) ?></td>
                                    <td class="text-end" style="font-size: 1.05rem;"><?= number_format($grandTotals['returns'], 0) ?></td>
                                    <td class="text-end" style="font-size: 1.05rem;"><?= number_format($grandTotals['cash'], 0) ?></td>
                                    <td class="text-center text-success">0</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                <?php endif; ?>

                <!-- Signatures -->
                <div class="row pt-5 mt-4 text-center">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Prepared By (Computer Operator)
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Checked By (Sales Manager)
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Cash Received By (Cashier)
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<style>
@media print {
    body { background-color: #fff !important; }
    .no-print, .dms-sidebar, .dms-topbar { display: none !important; }
    .content-wrapper { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    .print-container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
