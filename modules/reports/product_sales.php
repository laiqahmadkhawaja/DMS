<?php
/**
 * DMS - Distribution Management System
 * Product-Wise Sales Analysis Register
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/database_helpers.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Product-Wise Sales Summary";
$activeMenu = "rpt_daily";

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate   = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate     = clean_input($_GET['to_date'] ?? $today);
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;

$params = [$fromDate, $toDate];
$where = "si.invoice_date >= ? AND si.invoice_date <= ? AND si.status != 'cancelled'";

if ($categoryId) {
    $where .= " AND p.category_id = ?";
    $params[] = $categoryId;
}

// Fetch Product Aggregates
$productSales = db_fetch_all("
    SELECT 
        p.id as product_id,
        p.product_code,
        p.product_name,
        p.carton_qty as ratio,
        pc.category_name,
        COALESCE(SUM(sii.carton_qty), 0) as cartons_sold,
        COALESCE(SUM(sii.pcs_qty), 0) as pcs_sold,
        COALESCE(SUM(sii.quantity), 0) as total_units_sold,
        COALESCE(SUM(sii.line_total), 0) as gross_amount,
        COALESCE(SUM(sii.discount_amount), 0) as discount_amount,
        COALESCE((
            SELECT SUM(sri.quantity)
            FROM sales_return_items sri
            JOIN sales_returns sr ON sri.sales_return_id = sr.id
            WHERE sri.product_id = p.id AND sr.return_date >= '{$fromDate}' AND sr.return_date <= '{$toDate}'
        ), 0) as return_units,
        COALESCE((
            SELECT SUM(sri.line_total)
            FROM sales_return_items sri
            JOIN sales_returns sr ON sri.sales_return_id = sr.id
            WHERE sri.product_id = p.id AND sr.return_date >= '{$fromDate}' AND sr.return_date <= '{$toDate}'
        ), 0) as return_amount
    FROM products p
    JOIN product_categories pc ON p.category_id = pc.id
    LEFT JOIN sales_invoice_items sii ON p.id = sii.product_id
    LEFT JOIN sales_invoices si ON sii.invoice_id = si.id AND {$where}
    WHERE p.is_deleted = 0
    GROUP BY p.id
    HAVING total_units_sold > 0 OR return_units > 0
    ORDER BY gross_amount DESC
", $params);

// Calculate Totals
$totals = [
    'cartons' => 0.0,
    'pieces'  => 0.0,
    'gross'   => 0.0,
    'returns' => 0.0,
    'net'     => 0.0
];

foreach ($productSales as &$ps) {
    $net = $ps['gross_amount'] - $ps['return_amount'];
    $ps['net_amount'] = $net;

    $totals['cartons'] += (float)$ps['cartons_sold'];
    $totals['pieces']  += (float)$ps['pcs_sold'];
    $totals['gross']   += (float)$ps['gross_amount'];
    $totals['returns'] += (float)$ps['return_amount'];
    $totals['net']     += $net;
}
unset($ps);

$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 ORDER BY category_name ASC");

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
                    <i class="bi bi-box-seam me-2"></i>Product-Wise Sales Summary
                </h4>
                <p class="text-muted small mb-0">Product volume metrics, carton sales ratios, returns deducted, and net sales realization.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../excel/index.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Summary (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="product_sales.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Category</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">-- All Categories --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="product_sales.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Printable Card -->
        <div class="card border shadow-sm print-container bg-white">
            <div class="card-body p-4">
                
                <div class="text-center border-bottom pb-3 mb-3">
                    <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                    <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                    <div class="mt-2">
                        <span class="badge bg-navy text-white px-3 py-1 fs-6 text-uppercase">PRODUCT-WISE SALES & VOLUME REGISTER</span>
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
                                <th>Item Code</th>
                                <th>Product Description</th>
                                <th>Category</th>
                                <th class="text-center">Ratio</th>
                                <th class="text-center">Cartons</th>
                                <th class="text-center">Pieces</th>
                                <th class="text-end">Gross Sales</th>
                                <th class="text-end text-danger">Returns</th>
                                <th class="text-end fw-bold text-navy">Net Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($productSales)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">No product sales recorded in this period.</td>
                                </tr>
                            <?php else: ?>
                                <?php $sn = 1; foreach ($productSales as $row): ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $sn++ ?></td>
                                        <td class="fw-bold text-navy"><?= htmlspecialchars($row['product_code']) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['product_name']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($row['category_name']) ?></td>
                                        <td class="text-center small"><?= (int)$row['ratio'] ?> pcs</td>
                                        <td class="text-center fw-semibold"><?= number_format($row['cartons_sold'], 1) ?></td>
                                        <td class="text-center"><?= number_format($row['pcs_sold'], 0) ?></td>
                                        <td class="text-end text-muted"><?= format_currency($row['gross_amount']) ?></td>
                                        <td class="text-end text-danger">
                                            <?= $row['return_amount'] > 0 ? format_currency($row['return_amount']) : '—' ?>
                                        </td>
                                        <td class="text-end fw-bold text-navy fs-6"><?= format_currency($row['net_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="5" class="text-end">Grand Total:</td>
                                <td class="text-center"><?= number_format($totals['cartons'], 1) ?></td>
                                <td class="text-center"><?= number_format($totals['pieces'], 0) ?></td>
                                <td class="text-end"><?= format_currency($totals['gross']) ?></td>
                                <td class="text-end text-danger"><?= format_currency($totals['returns']) ?></td>
                                <td class="text-end text-navy"><?= format_currency($totals['net']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row pt-5 mt-4 text-center">
                    <div class="col-6">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Warehouse Incharge
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Proprietor / Partner
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
