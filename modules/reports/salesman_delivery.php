<?php
/**
 * DMS - Distribution Management System
 * Delivery Salesman Performance & Recovery Register (Photo 4 Format)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Salesman Delivery Register";
$activeMenu = "rpt_daily";

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate   = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate     = clean_input($_GET['to_date'] ?? $today);
$salesmanId = !empty($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : null;

$params = [$fromDate, $toDate];
$where = "si.invoice_date >= ? AND si.invoice_date <= ? AND si.status != 'cancelled'";

if ($salesmanId) {
    $where .= " AND si.salesman_id = ?";
    $params[] = $salesmanId;
}

// Fetch Salesman Performance
$salesmenData = db_fetch_all("
    SELECT 
        sm.id as salesman_id,
        sm.salesman_name,
        sm.employee_code,
        sm.vehicle_number,
        sm.role,
        a.area_name,
        COUNT(DISTINCT si.id) as total_bills,
        COALESCE(SUM(si.net_amount), 0) as total_dispatched,
        COALESCE(SUM(si.paid_amount), 0) as cash_collected,
        COALESCE(SUM(si.credit_amount), 0) as credit_given,
        COALESCE(SUM(si.return_amount), 0) as returns_handled
    FROM salesmen sm
    LEFT JOIN areas a ON sm.assigned_area_id = a.id
    LEFT JOIN sales_invoices si ON sm.id = si.salesman_id AND {$where}
    WHERE sm.is_deleted = 0
    GROUP BY sm.id
    ORDER BY total_dispatched DESC
", $params);

// Overall Totals
$totals = [
    'bills'      => 0,
    'dispatched' => 0.0,
    'cash'       => 0.0,
    'credit'     => 0.0,
    'returns'    => 0.0
];

foreach ($salesmenData as $s) {
    $totals['bills']      += (int)$s['total_bills'];
    $totals['dispatched'] += (float)$s['total_dispatched'];
    $totals['cash']       += (float)$s['cash_collected'];
    $totals['credit']     += (float)$s['credit_given'];
    $totals['returns']    += (float)$s['returns_handled'];
}

$allSalesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 AND is_deleted = 0 ORDER BY salesman_name ASC");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-truck me-2"></i>Delivery Salesman Recovery Register
                </h4>
                <p class="text-muted small mb-0">Delivery route reconciliations, van dispatch values, cash collected, and field credits.</p>
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
                <form method="GET" action="salesman_delivery.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Select Delivery Staff</label>
                        <select name="salesman_id" class="form-select form-select-sm">
                            <option value="">-- All Delivery Staff --</option>
                            <?php foreach ($allSalesmen as $sm): ?>
                                <option value="<?= $sm['id'] ?>" <?= $salesmanId === (int)$sm['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sm['salesman_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="salesman_delivery.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Printable Report Card -->
        <div class="card border shadow-sm print-container bg-white">
            <div class="card-body p-4">
                
                <div class="text-center border-bottom pb-3 mb-3">
                    <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                    <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                    <div class="mt-2">
                        <span class="badge bg-navy text-white px-3 py-1 fs-6 text-uppercase">DELIVERY SALESMAN RECOVERY REGISTER</span>
                    </div>
                    <div class="small text-muted mt-1">
                        Period: <strong><?= format_date($fromDate) ?></strong> to <strong><?= format_date($toDate) ?></strong>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-nowrap mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;" class="text-center">#</th>
                                <th>Salesman Name</th>
                                <th>Vehicle / Van</th>
                                <th>Assigned Beat</th>
                                <th class="text-center">Bills</th>
                                <th class="text-end fw-bold text-navy">Total Dispatched</th>
                                <th class="text-end text-success">Cash Brought In</th>
                                <th class="text-end text-primary">Market Credit</th>
                                <th class="text-end text-danger">Returns</th>
                                <th class="text-center">Recovery %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($salesmenData)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">No delivery records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($salesmenData as $row): ?>
                                    <?php 
                                        $recPercent = $row['total_dispatched'] > 0 ? round(($row['cash_collected'] / $row['total_dispatched']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="fw-semibold">
                                            <a href="../salesmen/ledger.php?salesman_id=<?= $row['salesman_id'] ?>" class="text-decoration-none text-navy">
                                                <?= htmlspecialchars($row['salesman_name']) ?>
                                            </a>
                                        </td>
                                        <td class="small text-muted"><?= htmlspecialchars($row['vehicle_number'] ?: '—') ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($row['area_name'] ?: 'General') ?></td>
                                        <td class="text-center"><?= (int)$row['total_bills'] ?></td>
                                        <td class="text-end fw-bold text-navy fs-6"><?= format_currency($row['total_dispatched']) ?></td>
                                        <td class="text-end fw-semibold text-success"><?= format_currency($row['cash_collected']) ?></td>
                                        <td class="text-end fw-semibold text-primary"><?= format_currency($row['credit_given']) ?></td>
                                        <td class="text-end fw-semibold text-danger"><?= format_currency($row['returns_handled']) ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $recPercent >= 50 ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                <?= $recPercent ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="4" class="text-end">Grand Total:</td>
                                <td class="text-center"><?= (int)$totals['bills'] ?></td>
                                <td class="text-end text-navy"><?= format_currency($totals['dispatched']) ?></td>
                                <td class="text-end text-success"><?= format_currency($totals['cash']) ?></td>
                                <td class="text-end text-primary"><?= format_currency($totals['credit']) ?></td>
                                <td class="text-end text-danger"><?= format_currency($totals['returns']) ?></td>
                                <td class="text-center text-muted">—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row pt-5 mt-4 text-center">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Prepared By
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Dispatch Supervisor
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Office Cashier
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
