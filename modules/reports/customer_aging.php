<?php
/**
 * DMS - Distribution Management System
 * Customer Receivables Aging & Parties Credit Analysis (Photo 6 Format)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('customers_statement');

$pageTitle = "Customer Aging & Parties Credit";
$activeMenu = "rpt_daily";

$areaId   = !empty($_GET['area_id']) ? (int)$_GET['area_id'] : null;
$bookerId = !empty($_GET['booker_id']) ? (int)$_GET['booker_id'] : null;
$minBal   = isset($_GET['min_balance']) ? (float)$_GET['min_balance'] : 1.0; // Default to customers with balance > 0

$params = [];
$where = "c.is_deleted = 0 AND c.current_balance >= ?";
$params[] = $minBal;

if ($areaId) {
    $where .= " AND c.area_id = ?";
    $params[] = $areaId;
}
if ($bookerId) {
    $where .= " AND c.booker_id = ?";
    $params[] = $bookerId;
}

// Fetch Customers with balances
$customers = db_fetch_all("
    SELECT 
        c.id as customer_id,
        c.customer_code,
        c.shop_name,
        c.owner_name,
        c.phone,
        c.address,
        c.credit_limit,
        c.current_balance,
        a.area_name,
        b.booker_name
    FROM customers c
    LEFT JOIN areas a ON c.area_id = a.id
    LEFT JOIN bookers b ON c.booker_id = b.id
    WHERE {$where}
    ORDER BY c.current_balance DESC
", $params);

// Calculate Aging Buckets for each customer from unpaid/partial sales invoices
$today = date('Y-m-d');
$todayTs = strtotime($today);

$grandTotals = [
    'current_15' => 0.0,
    'days_16_30' => 0.0,
    'days_31_60' => 0.0,
    'days_60_plus' => 0.0,
    'total_balance' => 0.0
];

foreach ($customers as &$c) {
    $cid = (int)$c['customer_id'];
    $bal = (float)$c['current_balance'];
    $grandTotals['total_balance'] += $bal;

    // Fetch open invoices with remaining credit
    $openInvoices = db_fetch_all("
        SELECT invoice_date, (net_amount - paid_amount - return_amount) as outstanding
        FROM sales_invoices
        WHERE customer_id = ? AND status != 'cancelled' AND (net_amount - paid_amount - return_amount) > 0
        ORDER BY invoice_date DESC
    ", [$cid]);

    $c15 = 0.0;
    $c30 = 0.0;
    $c60 = 0.0;
    $cPlus = 0.0;

    $allocated = 0.0;
    foreach ($openInvoices as $inv) {
        $invTs = strtotime($inv['invoice_date']);
        $days = max(0, (int)floor(($todayTs - $invTs) / 86400));
        $amt = (float)$inv['outstanding'];

        if ($days <= 15) {
            $c15 += $amt;
        } elseif ($days <= 30) {
            $c30 += $amt;
        } elseif ($days <= 60) {
            $c60 += $amt;
        } else {
            $cPlus += $amt;
        }
        $allocated += $amt;
    }

    // Any remaining balance not covered by recent invoices is older opening credit (60+ days)
    $remainder = $bal - $allocated;
    if ($remainder > 0) {
        $cPlus += $remainder;
    }

    $c['b_15']   = max(0, $c15);
    $c['b_30']   = max(0, $c30);
    $c['b_60']   = max(0, $c60);
    $c['b_plus'] = max(0, $cPlus);

    $grandTotals['current_15']   += $c['b_15'];
    $grandTotals['days_16_30']   += $c['b_30'];
    $grandTotals['days_31_60']   += $c['b_60'];
    $grandTotals['days_60_plus'] += $c['b_plus'];
}
unset($c);

$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 ORDER BY area_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 ORDER BY booker_name ASC");

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
                    <i class="bi bi-hourglass-split me-2"></i>Parties Credit & Aging Analysis
                </h4>
                <p class="text-muted small mb-0">Replication of client's <strong>Parties Credit</strong> sheet (Photo 6) with overdue aging brackets.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../customers/statement.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-person-lines-fill me-1"></i> Customer Statements
                </a>
                <a href="../excel/index.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Parties Credit (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="customer_aging.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">Route / Area</label>
                        <select name="area_id" class="form-select form-select-sm">
                            <option value="">-- All Routes --</option>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $areaId === (int)$a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['area_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">Order Booker</label>
                        <select name="booker_id" class="form-select form-select-sm">
                            <option value="">-- All Bookers --</option>
                            <?php foreach ($bookers as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $bookerId === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['booker_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">Minimum Balance (Rs.)</label>
                        <input type="number" step="1" name="min_balance" class="form-control form-control-sm" value="<?= htmlspecialchars($minBal) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="customer_aging.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Total Market Credit</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($grandTotals['total_balance']) ?></div>
                    <div class="small text-muted mt-1"><?= count($customers) ?> parties with balances</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Current (0-15 Days)</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($grandTotals['current_15']) ?></div>
                    <div class="small text-muted mt-1">Normal credit cycle</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">16 - 30 Days</div>
                    <div class="h5 fw-bold text-info mt-1 mb-0"><?= format_currency($grandTotals['days_16_30']) ?></div>
                    <div class="small text-muted mt-1">Due for follow-up</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Overdue (31+ Days)</div>
                    <div class="h5 fw-bold text-danger mt-1 mb-0"><?= format_currency($grandTotals['days_31_60'] + $grandTotals['days_60_plus']) ?></div>
                    <div class="small text-muted mt-1">Aging risk recovery</div>
                </div>
            </div>
        </div>

        <!-- Printable Parties Credit Table (Photo 6 format) -->
        <div class="card border shadow-sm print-container bg-white">
            <div class="card-body p-4">
                
                <div class="text-center border-bottom pb-3 mb-3">
                    <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                    <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                    <div class="mt-2">
                        <span class="badge bg-secondary text-white px-3 py-1 fs-6 text-uppercase fw-bold">Parties Credit & Aging Register</span>
                    </div>
                    <div class="small text-muted mt-1">
                        Report Date: <strong><?= date('d-m-Y') ?></strong>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-nowrap mb-0">
                        <thead class="table-secondary border-dark text-dark fw-bold">
                            <tr>
                                <th style="width: 50px;" class="text-center">Sr.</th>
                                <th>Booker / Shop Name</th>
                                <th>Address / Route</th>
                                <th class="text-end">0 - 15 Days</th>
                                <th class="text-end">16 - 30 Days</th>
                                <th class="text-end">31 - 60 Days</th>
                                <th class="text-end">60+ Days</th>
                                <th class="text-end" style="background-color: #e5b800; color: #000; width: 150px;">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No parties found with outstanding balances.</td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($customers as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted"><?= $sn++ ?></td>
                                        <td>
                                            <div class="fw-bold text-navy"><?= htmlspecialchars($row['shop_name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($row['owner_name'] ?: $row['customer_code']) ?></div>
                                        </td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars($row['address'] ?: $row['area_name']) ?>
                                        </td>
                                        <td class="text-end text-muted small">
                                            <?= $row['b_15'] > 0 ? number_format($row['b_15'], 0) : '—' ?>
                                        </td>
                                        <td class="text-end text-muted small">
                                            <?= $row['b_30'] > 0 ? number_format($row['b_30'], 0) : '—' ?>
                                        </td>
                                        <td class="text-end text-muted small">
                                            <?= $row['b_60'] > 0 ? number_format($row['b_60'], 0) : '—' ?>
                                        </td>
                                        <td class="text-end text-danger fw-semibold small">
                                            <?= $row['b_plus'] > 0 ? number_format($row['b_plus'], 0) : '—' ?>
                                        </td>
                                        <td class="text-end fw-bold text-navy fs-6" style="background-color: #fff9e6;">
                                            <?= number_format($row['current_balance'], 0) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="border-dark fw-bold">
                            <tr style="background-color: #d49a00; color: #000;">
                                <td colspan="3" class="text-center text-uppercase fs-6">Total Parties Credit:</td>
                                <td class="text-end"><?= number_format($grandTotals['current_15'], 0) ?></td>
                                <td class="text-end"><?= number_format($grandTotals['days_16_30'], 0) ?></td>
                                <td class="text-end"><?= number_format($grandTotals['days_31_60'], 0) ?></td>
                                <td class="text-end text-danger"><?= number_format($grandTotals['days_60_plus'], 0) ?></td>
                                <td class="text-end fs-5" style="background-color: #e5b800; color: #000;">
                                    <?= number_format($grandTotals['total_balance'], 0) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row pt-5 mt-4 text-center">
                    <div class="col-6">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Recovery Supervisor / Booker Signature
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Proprietor (Muzzam Enterprises)
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
