<?php
/**
 * DMS - Distribution Management System
 * Order Booker Sales Performance Register
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/database_helpers.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Booker Sales Performance";
$activeMenu = "rpt_daily";

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate   = clean_input($_GET['to_date'] ?? $today);
$bookerId = !empty($_GET['booker_id']) ? (int)$_GET['booker_id'] : null;

$params = [$fromDate, $toDate];
$where = "si.invoice_date >= ? AND si.invoice_date <= ? AND si.status != 'cancelled'";

if ($bookerId) {
    $where .= " AND si.booker_id = ?";
    $params[] = $bookerId;
}

// Fetch Booker Aggregates
$bookerPerformance = db_fetch_all("
    SELECT 
        b.id as booker_id,
        b.booker_name,
        b.booker_code,
        b.phone,
        b.commission_rate,
        COUNT(DISTINCT si.id) as total_bills,
        COALESCE(SUM(sii.carton_qty), 0) as total_cartons,
        COALESCE(SUM(sii.pcs_qty), 0) as total_pieces,
        COALESCE(SUM(si.gross_amount), 0) as gross_sales,
        COALESCE(SUM(si.item_discount_amount + si.extra_discount_amount), 0) as total_discounts,
        COALESCE(SUM(si.net_amount), 0) as net_sales,
        COALESCE(SUM(si.credit_amount), 0) as credit_booked,
        COALESCE(SUM(si.paid_amount), 0) as cash_collected
    FROM bookers b
    LEFT JOIN sales_invoices si ON b.id = si.booker_id AND {$where}
    LEFT JOIN sales_invoice_items sii ON si.id = sii.invoice_id
    WHERE b.is_deleted = 0
    GROUP BY b.id
    ORDER BY net_sales DESC
", $params);

// Overall Totals
$totals = [
    'bills'       => 0,
    'cartons'     => 0.0,
    'gross'       => 0.0,
    'discounts'   => 0.0,
    'net'         => 0.0,
    'commission'  => 0.0
];

foreach ($bookerPerformance as &$bp) {
    $comm = ($bp['net_sales'] * (float)$bp['commission_rate']) / 100;
    $bp['commission_earned'] = $comm;

    $totals['bills']      += (int)$bp['total_bills'];
    $totals['cartons']    += (float)$bp['total_cartons'];
    $totals['gross']      += (float)$bp['gross_sales'];
    $totals['discounts']  += (float)$bp['total_discounts'];
    $totals['net']        += (float)$bp['net_sales'];
    $totals['commission'] += $comm;
}
unset($bp);

$allBookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_active = 1 AND is_deleted = 0 ORDER BY booker_name ASC");

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
                    <i class="bi bi-person-badge me-2"></i>Order Booker Performance Register
                </h4>
                <p class="text-muted small mb-0">Market booking volumes, cartons booked, revenue generation, and commission calculations.</p>
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
                <form method="GET" action="booker_sales.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Select Booker</label>
                        <select name="booker_id" class="form-select form-select-sm">
                            <option value="">-- All Bookers --</option>
                            <?php foreach ($allBookers as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $bookerId === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['booker_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="booker_sales.php" class="btn btn-outline-secondary btn-sm" title="Reset">
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
                        <span class="badge bg-primary text-white px-3 py-1 fs-6 text-uppercase">ORDER BOOKER PERFORMANCE REGISTER</span>
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
                                <th>Booker Code</th>
                                <th>Booker Name</th>
                                <th class="text-center">Bills</th>
                                <th class="text-center">Cartons</th>
                                <th class="text-end">Gross Sales</th>
                                <th class="text-end">Discounts</th>
                                <th class="text-end fw-bold text-navy">Net Sales</th>
                                <th class="text-center">Rate</th>
                                <th class="text-end">Commission (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookerPerformance)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">No booking records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($bookerPerformance as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="fw-bold text-navy"><?= htmlspecialchars($row['booker_code']) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['booker_name']) ?></td>
                                        <td class="text-center"><?= (int)$row['total_bills'] ?></td>
                                        <td class="text-center fw-semibold"><?= number_format($row['total_cartons'], 1) ?></td>
                                        <td class="text-end text-muted"><?= format_currency($row['gross_sales']) ?></td>
                                        <td class="text-end text-danger"><?= format_currency($row['total_discounts']) ?></td>
                                        <td class="text-end fw-bold text-navy fs-6"><?= format_currency($row['net_sales']) ?></td>
                                        <td class="text-center small text-muted"><?= number_format($row['commission_rate'], 1) ?>%</td>
                                        <td class="text-end fw-semibold text-success"><?= format_currency($row['commission_earned']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="3" class="text-end">Grand Total:</td>
                                <td class="text-center"><?= (int)$totals['bills'] ?></td>
                                <td class="text-center"><?= number_format($totals['cartons'], 1) ?></td>
                                <td class="text-end"><?= format_currency($totals['gross']) ?></td>
                                <td class="text-end text-danger"><?= format_currency($totals['discounts']) ?></td>
                                <td class="text-end text-navy"><?= format_currency($totals['net']) ?></td>
                                <td></td>
                                <td class="text-end text-success"><?= format_currency($totals['commission']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row pt-5 mt-4 text-center">
                    <div class="col-6">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Prepared By (Computer Operator)
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Verified By (Sales Incharge)
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
