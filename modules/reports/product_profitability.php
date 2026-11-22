<?php
/**
 * DMS - Distribution Management System
 * Product Profitability & Top Performing Products Report
 * (سب سے زیادہ منافع بخش اشیاء - ٹاپ 5 / 10 رینکنگ)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Product Profitability & Top 5 / 10 Ranking";
$activeMenu = "rpt_profitability";

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

// Filter Inputs
$period = clean_input($_GET['period'] ?? '');
if ($period === 'today') {
    $fromDate = $today;
    $toDate   = $today;
} elseif ($period === 'week') {
    $fromDate = date('Y-m-d', strtotime('monday this week'));
    $toDate   = $today;
} elseif ($period === 'month') {
    $fromDate = $firstOfMonth;
    $toDate   = $today;
} elseif ($period === 'all') {
    $fromDate = '2020-01-01';
    $toDate   = $today;
} else {
    $fromDate = clean_input($_GET['from_date'] ?? $firstOfMonth);
    $toDate   = clean_input($_GET['to_date'] ?? $today);
}

// Preset Ranking Limits: 5, 10, 20, 0 (all)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$sortBy = clean_input($_GET['sort'] ?? 'profit_desc'); // profit_desc, margin_desc, revenue_desc, volume_desc

$selectedSupplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$selectedCategoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Fetch suppliers & categories for filter bar
$suppliers = db_fetch_all("
    SELECT s.id, s.supplier_code, s.supplier_name, s.company_name, s.city 
    FROM suppliers s 
    WHERE s.is_deleted = 0 
    ORDER BY s.supplier_name ASC
");

$categories = db_fetch_all("
    SELECT id, category_name 
    FROM product_categories 
    WHERE is_active = 1 
    ORDER BY category_name ASC
");

// Build Where Clause for Invoices
$where = "si.invoice_date >= ? AND si.invoice_date <= ? AND si.status != 'cancelled'";
$params = [$fromDate, $toDate];

if ($selectedSupplierId > 0) {
    $where .= " AND p.supplier_id = ?";
    $params[] = $selectedSupplierId;
}
if ($selectedCategoryId > 0) {
    $where .= " AND p.category_id = ?";
    $params[] = $selectedCategoryId;
}

// Fetch Sold Products with Packaging and Cost details
$query = "
    SELECT 
        p.id as product_id,
        p.product_code,
        p.product_name,
        p.purchase_rate,
        p.wholesale_rate,
        p.carton_qty as packs_per_ctn,
        p.pack_qty as pcs_per_pack,
        COALESCE(s.id, 0) as supplier_id,
        COALESCE(s.supplier_name, 'Direct Brand') as supplier_name,
        COALESCE(s.company_name, 'General Dist.') as company_name,
        cat.id as category_id,
        cat.category_name,
        COALESCE(SUM(sii.carton_qty), 0) as cartons_sold,
        COALESCE(SUM(sii.pack_qty), 0) as packs_sold,
        COALESCE(SUM(sii.pcs_qty), 0) as pcs_sold,
        COALESCE(SUM(sii.quantity), 0) as total_units_sold,
        COALESCE(SUM(sii.line_total), 0) as gross_amount,
        COALESCE(SUM(sii.discount_amount), 0) as discount_amount,
        COALESCE(SUM(
            CASE 
                WHEN si.gross_amount > 0 AND si.credit_amount > 0 
                THEN (sii.line_total / si.gross_amount) * si.credit_amount 
                ELSE 0 
            END
        ), 0) as market_receivables_stuck
    FROM sales_invoice_items sii
    JOIN sales_invoices si ON sii.invoice_id = si.id
    JOIN products p ON sii.product_id = p.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN product_categories cat ON p.category_id = cat.id
    WHERE {$where}
    GROUP BY p.id
    HAVING gross_amount > 0
";

$rawProducts = db_fetch_all($query, $params);

// Fetch Returns
$returnParams = [$fromDate, $toDate];
$returnWhere = "sr.return_date >= ? AND sr.return_date <= ? AND sr.status != 'rejected'";
if ($selectedSupplierId > 0) {
    $returnWhere .= " AND p.supplier_id = ?";
    $returnParams[] = $selectedSupplierId;
}
if ($selectedCategoryId > 0) {
    $returnWhere .= " AND p.category_id = ?";
    $returnParams[] = $selectedCategoryId;
}

$returnsRaw = db_fetch_all("
    SELECT 
        sri.product_id,
        COALESCE(SUM(sri.amount), 0) as return_amount
    FROM sales_return_items sri
    JOIN sales_returns sr ON sri.sales_return_id = sr.id
    JOIN products p ON sri.product_id = p.id
    WHERE {$returnWhere}
    GROUP BY sri.product_id
", $returnParams);

$returnsMap = [];
foreach ($returnsRaw as $ret) {
    $returnsMap[$ret['product_id']] = (float)$ret['return_amount'];
}

// Calculate individual profitability
$profitList = [];
$totalProfit = 0.0;
$totalRevenue = 0.0;
$totalCost = 0.0;
$totalReceivables = 0.0;

foreach ($rawProducts as $row) {
    $prodId = (int)$row['product_id'];
    $gross = (float)$row['gross_amount'];
    $returnAmount = $returnsMap[$prodId] ?? 0.0;
    $netRevenue = max(0, $gross - $returnAmount);

    $packsPerCtn = max(1, (float)$row['packs_per_ctn']);
    $pcsPerPack  = max(1, (float)$row['pcs_per_pack']);
    $pcsPerCtn   = $packsPerCtn * $pcsPerPack;

    $cartonEquivalent = (float)$row['cartons_sold'] + ((float)$row['packs_sold'] / $packsPerCtn) + ((float)$row['pcs_sold'] / $pcsPerCtn);
    $purchaseRate = (float)$row['purchase_rate'];
    if ($purchaseRate <= 0) {
        $purchaseRate = (float)$row['wholesale_rate'] * 0.85;
    }

    $cogs = $cartonEquivalent * $purchaseRate;
    $grossProfit = $netRevenue - $cogs;
    $profitMargin = $netRevenue > 0 ? ($grossProfit / $netRevenue) * 100 : 0.0;
    $receivablesStuck = (float)$row['market_receivables_stuck'];

    $totalProfit += $grossProfit;
    $totalRevenue += $netRevenue;
    $totalCost += $cogs;
    $totalReceivables += $receivablesStuck;

    $profitList[] = [
        'product_id'          => $prodId,
        'product_code'        => $row['product_code'],
        'product_name'        => $row['product_name'],
        'supplier_id'         => (int)$row['supplier_id'],
        'supplier_name'       => $row['supplier_name'],
        'category_name'       => $row['category_name'],
        'purchase_rate'       => $purchaseRate,
        'wholesale_rate'      => (float)$row['wholesale_rate'],
        'cartons_sold'        => (float)$row['cartons_sold'],
        'pcs_sold'            => (float)$row['pcs_sold'],
        'gross_revenue'       => $gross,
        'return_amount'       => $returnAmount,
        'net_revenue'         => $netRevenue,
        'cogs'                => $cogs,
        'gross_profit'        => $grossProfit,
        'profit_margin'       => $profitMargin,
        'market_receivables'  => $receivablesStuck
    ];
}

// Sorting
usort($profitList, function ($a, $b) use ($sortBy) {
    if ($sortBy === 'margin_desc') {
        return $b['profit_margin'] <=> $a['profit_margin'];
    } elseif ($sortBy === 'revenue_desc') {
        return $b['net_revenue'] <=> $a['net_revenue'];
    } elseif ($sortBy === 'volume_desc') {
        return $b['cartons_sold'] <=> $a['cartons_sold'];
    }
    // Default: profit_desc
    return $b['gross_profit'] <=> $a['gross_profit'];
});

// Apply Limit (Top 5, Top 10, Top 20 or All)
$displayList = ($limit > 0) ? array_slice($profitList, 0, $limit) : $profitList;

// Find champions for podium cards
$topProfitProd = !empty($profitList) ? $profitList[0] : null;

// Find highest margin item (min 1 ctn sold)
$highestMarginProd = null;
foreach ($profitList as $p) {
    if ($p['cartons_sold'] >= 1) {
        if ($highestMarginProd === null || $p['profit_margin'] > $highestMarginProd['profit_margin']) {
            $highestMarginProd = $p;
        }
    }
}

// Find highest volume item
$highestVolumeProd = null;
foreach ($profitList as $p) {
    if ($highestVolumeProd === null || $p['cartons_sold'] > $highestVolumeProd['cartons_sold']) {
        $highestVolumeProd = $p;
    }
}

$overallMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0.0;

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-trophy-fill text-warning me-2"></i>Product Profitability & Top Performing Products
                </h4>
                <p class="text-muted small mb-0">
                    Discover your highest profit-generating items, product margin %, and market receivables exposure by product and company.
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="../excel/index.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Report (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter & Limit Selector Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="product_profitability.php" class="row g-2 align-items-end">

                    <!-- Limit Preset Pills -->
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">Rank Limit</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <a href="product_profitability.php?limit=5&period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>&sort=<?= $sortBy ?>" class="btn btn-outline-primary <?= $limit === 5 ? 'active fw-bold' : '' ?>">Top 5</a>
                            <a href="product_profitability.php?limit=10&period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>&sort=<?= $sortBy ?>" class="btn btn-outline-primary <?= $limit === 10 ? 'active fw-bold' : '' ?>">Top 10</a>
                            <a href="product_profitability.php?limit=20&period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>&sort=<?= $sortBy ?>" class="btn btn-outline-primary <?= $limit === 20 ? 'active fw-bold' : '' ?>">Top 20</a>
                            <a href="product_profitability.php?limit=0&period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>&sort=<?= $sortBy ?>" class="btn btn-outline-secondary <?= $limit === 0 ? 'active fw-bold' : '' ?>">All Items</a>
                        </div>
                    </div>

                    <!-- Date Quick Filters -->
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">Period</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <a href="product_profitability.php?period=today&limit=<?= $limit ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= $period === 'today' ? 'active fw-bold' : '' ?>">Today</a>
                            <a href="product_profitability.php?period=week&limit=<?= $limit ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= $period === 'week' ? 'active fw-bold' : '' ?>">Week</a>
                            <a href="product_profitability.php?period=month&limit=<?= $limit ?>&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= ($period === 'month' || empty($period)) ? 'active fw-bold' : '' ?>">Month</a>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">Company / Supplier</label>
                        <select name="supplier_id" class="form-select form-select-sm">
                            <option value="">-- All Companies --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $selectedSupplierId === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">Category</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">-- All Categories --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $selectedCategoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">Sort Ranking By</label>
                        <select name="sort" class="form-select form-select-sm">
                            <option value="profit_desc" <?= $sortBy === 'profit_desc' ? 'selected' : '' ?>>Highest Profit (Rs.)</option>
                            <option value="margin_desc" <?= $sortBy === 'margin_desc' ? 'selected' : '' ?>>Highest Margin (%)</option>
                            <option value="revenue_desc" <?= $sortBy === 'revenue_desc' ? 'selected' : '' ?>>Highest Sales Value</option>
                            <option value="volume_desc" <?= $sortBy === 'volume_desc' ? 'selected' : '' ?>>Highest Carton Volume</option>
                        </select>
                    </div>

                    <div class="col-md-1">
                        <input type="hidden" name="limit" value="<?= $limit ?>">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                            <i class="bi bi-filter"></i> Apply
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 3 Champion Podium Cards -->
        <?php if (!empty($profitList)): ?>
            <div class="row g-3 mb-4">
                <!-- #1 Top Profit Item -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="badge bg-warning text-dark fw-bold text-uppercase"><i class="bi bi-award-fill me-1"></i>#1 Top Profit Maker</span>
                            <span class="badge bg-success-subtle text-success fw-bold"><?= number_format($topProfitProd['profit_margin'], 1) ?>% Margin</span>
                        </div>
                        <h6 class="fw-bold text-navy mt-2 mb-1"><?= htmlspecialchars($topProfitProd['product_name']) ?></h6>
                        <div class="text-muted small mb-2"><i class="bi bi-building me-1"></i><?= htmlspecialchars($topProfitProd['supplier_name']) ?></div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="text-muted small">Total Profit:</span>
                            <span class="h5 fw-bold text-success mb-0"><?= format_currency($topProfitProd['gross_profit']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- #1 Highest Margin % Item -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="badge bg-success text-white fw-bold text-uppercase"><i class="bi bi-percent me-1"></i>Highest Margin %</span>
                            <span class="badge bg-primary-subtle text-primary fw-bold"><?= number_format($highestMarginProd['cartons_sold'] ?? 0, 1) ?> Ctns</span>
                        </div>
                        <h6 class="fw-bold text-navy mt-2 mb-1"><?= htmlspecialchars($highestMarginProd['product_name'] ?? 'N/A') ?></h6>
                        <div class="text-muted small mb-2"><i class="bi bi-building me-1"></i><?= htmlspecialchars($highestMarginProd['supplier_name'] ?? '') ?></div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="text-muted small">Profit Margin:</span>
                            <span class="h5 fw-bold text-success mb-0"><?= number_format($highestMarginProd['profit_margin'] ?? 0, 1) ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- #1 Best-Seller Volume Item -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="badge bg-primary text-white fw-bold text-uppercase"><i class="bi bi-box-seam me-1"></i>Volume Leader</span>
                            <span class="badge bg-info-subtle text-navy fw-bold"><?= format_currency($highestVolumeProd['net_revenue'] ?? 0) ?></span>
                        </div>
                        <h6 class="fw-bold text-navy mt-2 mb-1"><?= htmlspecialchars($highestVolumeProd['product_name'] ?? 'N/A') ?></h6>
                        <div class="text-muted small mb-2"><i class="bi bi-building me-1"></i><?= htmlspecialchars($highestVolumeProd['supplier_name'] ?? '') ?></div>
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="text-muted small">Cartons Sold:</span>
                            <span class="h5 fw-bold text-primary mb-0"><?= number_format($highestVolumeProd['cartons_sold'] ?? 0, 1) ?> Ctns</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profitability Ranking Table Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold text-navy">
                        <i class="bi bi-list-ol me-2 text-primary"></i>
                        <?= $limit > 0 ? "Top {$limit} Most Profitable Products" : "All Products Ranked by Profitability" ?>
                    </h6>
                    <small class="text-muted">Showing <?= count($displayList) ?> products of <?= count($profitList) ?> total sold</small>
                </div>
                <div class="small text-muted">Period: <strong><?= date('d M Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d M Y', strtotime($toDate)) ?></strong></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-uppercase small text-muted">
                            <tr>
                                <th style="width: 50px;" class="text-center">Rank</th>
                                <th>Product Details</th>
                                <th>Company / Factory</th>
                                <th>Category</th>
                                <th class="text-center">Cartons</th>
                                <th class="text-end">Net Revenue</th>
                                <th class="text-end">Cost (COGS)</th>
                                <th class="text-end text-success bg-success-subtle bg-opacity-25">Gross Profit</th>
                                <th class="text-center" style="width: 140px;">Margin %</th>
                                <th class="text-end text-warning-emphasis bg-warning-subtle bg-opacity-25">Market Udhaar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($displayList)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5 text-muted">
                                        <i class="bi bi-trophy fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                        <div class="fw-semibold">No product sales recorded in this period.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($displayList as $rank => $prod): 
                                    $rankNum = $rank + 1;
                                    $badgeClass = 'bg-secondary text-white';
                                    if ($rankNum === 1) $badgeClass = 'bg-warning text-dark fw-bold';
                                    elseif ($rankNum === 2) $badgeClass = 'bg-secondary-subtle text-dark border fw-bold';
                                    elseif ($rankNum === 3) $badgeClass = 'bg-danger-subtle text-danger border fw-bold';
                                    elseif ($rankNum <= 10) $badgeClass = 'bg-primary-subtle text-primary fw-bold';

                                    $marginClass = $prod['profit_margin'] >= 25 ? 'bg-success' : ($prod['profit_margin'] >= 15 ? 'bg-primary' : ($prod['profit_margin'] >= 5 ? 'bg-warning text-dark' : 'bg-danger'));
                                ?>
                                    <tr>
                                        <td class="text-center">
                                            <span class="badge <?= $badgeClass ?> rounded-pill px-2 py-1" style="font-size: 11px;">#<?= $rankNum ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-navy"><?= htmlspecialchars($prod['product_name']) ?></div>
                                            <small class="text-muted font-monospace"><?= htmlspecialchars($prod['product_code']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-building me-1 text-primary"></i><?= htmlspecialchars($prod['supplier_name']) ?>
                                            </span>
                                        </td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($prod['category_name']) ?></span></td>
                                        <td class="text-center fw-bold"><?= number_format($prod['cartons_sold'], 1) ?></td>
                                        <td class="text-end fw-semibold"><?= format_currency($prod['net_revenue']) ?></td>
                                        <td class="text-end text-muted"><?= format_currency($prod['cogs']) ?></td>
                                        <td class="text-end fw-bold text-success bg-success-subtle bg-opacity-25 fs-6">
                                            <?= format_currency($prod['gross_profit']) ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <span class="badge <?= $marginClass ?>" style="min-width: 48px;"><?= number_format($prod['profit_margin'], 1) ?>%</span>
                                            </div>
                                            <div class="progress mt-1" style="height: 4px;">
                                                <div class="progress-bar <?= $marginClass ?>" role="progressbar" style="width: <?= min(100, max(0, $prod['profit_margin'])) ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold text-warning-emphasis bg-warning-subtle bg-opacity-25">
                                            <?= format_currency($prod['market_receivables']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($displayList)): ?>
                            <tfoot class="table-dark fw-bold">
                                <tr>
                                    <td></td>
                                    <td colspan="4">TOTALS OF DISPLAYED PRODUCTS</td>
                                    <td class="text-end"><?= format_currency(array_sum(array_column($displayList, 'net_revenue'))) ?></td>
                                    <td class="text-end text-light opacity-75"><?= format_currency(array_sum(array_column($displayList, 'cogs'))) ?></td>
                                    <td class="text-end text-success"><?= format_currency(array_sum(array_column($displayList, 'gross_profit'))) ?></td>
                                    <td class="text-center text-white">
                                        <?php 
                                            $dispRev = array_sum(array_column($displayList, 'net_revenue'));
                                            $dispProf = array_sum(array_column($displayList, 'gross_profit'));
                                            echo $dispRev > 0 ? number_format(($dispProf / $dispRev) * 100, 1) . '%' : '0%';
                                        ?>
                                    </td>
                                    <td class="text-end text-warning"><?= format_currency(array_sum(array_column($displayList, 'market_receivables'))) ?></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
