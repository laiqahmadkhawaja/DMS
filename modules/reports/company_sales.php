<?php
/**
 * DMS - Distribution Management System
 * Company-Wise Sales & Market Receivables Exposure Report
 * (کمپنی وائز سیلز اور مارکیٹ ادھار رپورٹ)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Company-Wise Sales & Market Exposure";
$activeMenu = "rpt_company";

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

// 1. Fetch Item-Level Aggregates within the Date Range
$query = "
    SELECT 
        COALESCE(s.id, 0) as supplier_id,
        COALESCE(s.supplier_name, 'Direct Unassigned Brand') as supplier_name,
        COALESCE(s.company_name, 'General Dist.') as company_name,
        s.city as supplier_city,
        p.id as product_id,
        p.product_code,
        p.product_name,
        p.purchase_rate,
        p.wholesale_rate,
        p.carton_qty as packs_per_ctn,
        p.pack_qty as pcs_per_pack,
        cat.id as category_id,
        cat.category_name,
        COALESCE(SUM(sii.carton_qty), 0) as cartons_sold,
        COALESCE(SUM(sii.pack_qty), 0) as packs_sold,
        COALESCE(SUM(sii.pcs_qty), 0) as pcs_sold,
        COALESCE(SUM(sii.quantity), 0) as total_units_sold,
        COALESCE(SUM(sii.line_total), 0) as gross_amount,
        COALESCE(SUM(sii.discount_amount), 0) as discount_amount,
        -- Market Receivables share proportional to item's line total on unpaid invoices
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
    GROUP BY COALESCE(s.id, 0), p.id
    ORDER BY supplier_name ASC, gross_amount DESC
";

$itemRows = db_fetch_all($query, $params);

// Fetch Returns within the same period
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

$returnsQuery = "
    SELECT 
        sri.product_id,
        COALESCE(SUM(sri.carton_qty), 0) as return_cartons,
        COALESCE(SUM(sri.pcs_qty), 0) as return_pcs,
        COALESCE(SUM(sri.quantity), 0) as return_units,
        COALESCE(SUM(sri.amount), 0) as return_amount
    FROM sales_return_items sri
    JOIN sales_returns sr ON sri.sales_return_id = sr.id
    JOIN products p ON sri.product_id = p.id
    WHERE {$returnWhere}
    GROUP BY sri.product_id
";

$returnsRaw = db_fetch_all($returnsQuery, $returnParams);
$returnsByProduct = [];
foreach ($returnsRaw as $ret) {
    $returnsByProduct[$ret['product_id']] = $ret;
}

// Group into Company Structures with Totals and Profit calculations
$companies = [];
$grandTotals = [
    'cartons'          => 0.0,
    'packs'            => 0.0,
    'pieces'           => 0.0,
    'gross_amount'     => 0.0,
    'return_amount'    => 0.0,
    'net_amount'       => 0.0,
    'cost_amount'      => 0.0,
    'gross_profit'     => 0.0,
    'market_receivables' => 0.0
];

foreach ($itemRows as $row) {
    $supId = (int)$row['supplier_id'];
    $prodId = (int)$row['product_id'];

    if (!isset($companies[$supId])) {
        $companies[$supId] = [
            'supplier_id'         => $supId,
            'supplier_name'       => $row['supplier_name'],
            'company_name'        => $row['company_name'],
            'supplier_city'       => $row['supplier_city'],
            'total_items_count'   => 0,
            'cartons_sold'        => 0.0,
            'packs_sold'          => 0.0,
            'pieces_sold'         => 0.0,
            'gross_amount'        => 0.0,
            'return_amount'       => 0.0,
            'net_amount'          => 0.0,
            'cost_amount'         => 0.0,
            'gross_profit'        => 0.0,
            'market_receivables'  => 0.0,
            'items'               => []
        ];
    }

    $retData = $returnsByProduct[$prodId] ?? ['return_cartons' => 0, 'return_pcs' => 0, 'return_amount' => 0];
    $retAmount = (float)$retData['return_amount'];
    $gross = (float)$row['gross_amount'];
    $net = max(0, $gross - $retAmount);

    // Packaging & Cost of Goods calculation
    $packsPerCtn = max(1, (float)$row['packs_per_ctn']);
    $pcsPerPack  = max(1, (float)$row['pcs_per_pack']);
    $pcsPerCtn   = $packsPerCtn * $pcsPerPack;

    $cartonEquivalent = (float)$row['cartons_sold'] + ((float)$row['packs_sold'] / $packsPerCtn) + ((float)$row['pcs_sold'] / $pcsPerCtn);
    $costPrice = (float)$row['purchase_rate'];
    if ($costPrice <= 0) {
        $costPrice = (float)$row['wholesale_rate'] * 0.85; // Fallback standard FMCG 15% wholesale margin if unentered
    }
    $cogs = $cartonEquivalent * $costPrice;
    $profit = $net - $cogs;
    $margin = $net > 0 ? ($profit / $net) * 100 : 0.0;
    $marketReceivables = (float)$row['market_receivables_stuck'];

    $companies[$supId]['total_items_count']++;
    $companies[$supId]['cartons_sold']       += (float)$row['cartons_sold'];
    $companies[$supId]['packs_sold']         += (float)$row['packs_sold'];
    $companies[$supId]['pieces_sold']        += (float)$row['pcs_sold'];
    $companies[$supId]['gross_amount']       += $gross;
    $companies[$supId]['return_amount']      += $retAmount;
    $companies[$supId]['net_amount']         += $net;
    $companies[$supId]['cost_amount']        += $cogs;
    $companies[$supId]['gross_profit']       += $profit;
    $companies[$supId]['market_receivables'] += $marketReceivables;

    $companies[$supId]['items'][] = [
        'product_id'         => $prodId,
        'product_code'       => $row['product_code'],
        'product_name'       => $row['product_name'],
        'category_name'      => $row['category_name'],
        'cartons_sold'       => (float)$row['cartons_sold'],
        'packs_sold'         => (float)$row['packs_sold'],
        'pcs_sold'           => (float)$row['pcs_sold'],
        'gross_amount'       => $gross,
        'return_amount'      => $retAmount,
        'net_amount'         => $net,
        'cost_amount'        => $cogs,
        'gross_profit'       => $profit,
        'margin_percent'     => $margin,
        'market_receivables' => $marketReceivables
    ];

    $grandTotals['cartons']          += (float)$row['cartons_sold'];
    $grandTotals['packs']            += (float)$row['packs_sold'];
    $grandTotals['pieces']           += (float)$row['pcs_sold'];
    $grandTotals['gross_amount']     += $gross;
    $grandTotals['return_amount']    += $retAmount;
    $grandTotals['net_amount']       += $net;
    $grandTotals['cost_amount']      += $cogs;
    $grandTotals['gross_profit']     += $profit;
    $grandTotals['market_receivables'] += $marketReceivables;
}

$grandMargin = $grandTotals['net_amount'] > 0 ? ($grandTotals['gross_profit'] / $grandTotals['net_amount']) * 100 : 0.0;

// Fetch Comparative Partner Stats for all active agency partners
$agencyPartners = db_fetch_all("
    SELECT id, partner_name, urdu_name, partner_code, supplier_id 
    FROM partners 
    WHERE is_active = 1 AND supplier_id IS NOT NULL 
    ORDER BY id ASC
");
$partnerSummaries = [];
foreach ($agencyPartners as $ap) {
    $partnerSummaries[$ap['id']] = get_partner_sales_profit_summary($ap['id'], $fromDate, $toDate);
}
// Backward compatibility variables for existing tests:
$moazzamStats = $partnerSummaries[1] ?? get_partner_sales_profit_summary(1, $fromDate, $toDate);
$rehmanStats  = $partnerSummaries[2] ?? get_partner_sales_profit_summary(2, $fromDate, $toDate);

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
                    <i class="bi bi-building-check text-primary me-2"></i>Company-Wise Sales & Market Receivables Exposure
                </h4>
                <p class="text-muted small mb-0">
                    Track exactly how much stock each company/supplier sold, your profit earned, and market credit (پھنسا ہوا ادھار) tied to each company's items.
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

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-3">
                <!-- Quick Company Switcher Pills -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small fw-bold"><i class="bi bi-buildings me-1"></i>کمپنی سوئچر:</span>
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="company_sales.php?period=<?= urlencode($period) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&category_id=<?= $selectedCategoryId ?>&supplier_id=0" 
                               class="btn <?= $selectedSupplierId === 0 ? 'btn-primary fw-bold' : 'btn-outline-secondary' ?>">
                                <i class="bi bi-collection me-1"></i> دونوں کمپنیاں اکٹھا (Combined)
                            </a>
                            <a href="company_sales.php?period=<?= urlencode($period) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&category_id=<?= $selectedCategoryId ?>&supplier_id=1" 
                               class="btn <?= $selectedSupplierId === 1 ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>">
                                <i class="bi bi-box-seam me-1"></i> اجاب انٹرپرائزز (Ujab)
                            </a>
                            <a href="company_sales.php?period=<?= urlencode($period) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&category_id=<?= $selectedCategoryId ?>&supplier_id=2" 
                               class="btn <?= $selectedSupplierId === 2 ? 'btn-info text-white fw-bold' : 'btn-outline-secondary' ?>">
                                <i class="bi bi-building me-1"></i> ایس ایم ایس (SMS / کنافہ / جج)
                            </a>
                        </div>
                    </div>
                    <?php if ($selectedSupplierId > 0): ?>
                        <span class="badge bg-warning text-dark px-3 py-2 border fs-7">
                            <i class="bi bi-funnel-fill me-1"></i> فلٹر چالو ہے: <strong><?= $selectedSupplierId === 1 ? 'Ujab Enterprises' : 'SMS Agency' ?></strong>
                        </span>
                    <?php endif; ?>
                </div>

                <form method="GET" action="company_sales.php" class="row g-2 align-items-end">
                    
                    <!-- Date Presets -->
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">Quick Date Filter</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <a href="company_sales.php?period=today&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= $period === 'today' ? 'active fw-bold' : '' ?>">Today</a>
                            <a href="company_sales.php?period=week&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= $period === 'week' ? 'active fw-bold' : '' ?>">This Week</a>
                            <a href="company_sales.php?period=month&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= ($period === 'month' || empty($period)) ? 'active fw-bold' : '' ?>">This Month</a>
                            <a href="company_sales.php?period=all&supplier_id=<?= $selectedSupplierId ?>&category_id=<?= $selectedCategoryId ?>" class="btn btn-outline-secondary <?= $period === 'all' ? 'active fw-bold' : '' ?>">All Time</a>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">Company / Supplier (کمپنی)</label>
                        <select name="supplier_id" class="form-select form-select-sm">
                            <option value="0">-- All Companies (دونوں اکٹھا) --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $selectedSupplierId === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?> (<?= (int)$s['id'] === 1 ? 'Ujab' : 'SMS' ?>)
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

                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                            <i class="bi bi-filter"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($partnerSummaries)): ?>
        <!-- Comparative Partner Performance -->
        <div class="row g-3 mb-4 no-print">
            <?php 
            $borderColors = ['border-info', 'border-success', 'border-primary', 'border-warning'];
            $ci = 0;
            foreach ($partnerSummaries as $pid => $pStats): 
                $bColor = $borderColors[$ci % count($borderColors)];
                $ci++;
                $pInfo = $pStats['partner'] ?? [];
            ?>
            <div class="col-md-6 col-lg-<?= count($partnerSummaries) >= 3 ? '4' : '6' ?>">
                <div class="card border-0 shadow-sm border-start border-4 <?= $bColor ?> bg-white h-100">
                    <div class="card-header bg-white py-2 border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-info-subtle text-info border border-info-subtle me-1">Agency Partner</span>
                            <strong class="text-navy fs-6"><?= htmlspecialchars($pInfo['partner_name'] ?? 'Partner') ?></strong>
                            <?php if (!empty($pInfo['urdu_name'])): ?>
                                <small class="text-muted ms-1">(<?= htmlspecialchars($pInfo['urdu_name']) ?>)</small>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($pInfo['supplier_name'])): ?>
                            <span class="badge bg-light text-dark border"><i class="bi bi-building me-1"></i> <?= htmlspecialchars($pInfo['supplier_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 text-center mb-3">
                            <div class="col-4 border-end">
                                <div class="text-muted small">Net Sales</div>
                                <div class="fw-bold fs-6 text-dark"><?= format_currency($pStats['net_sales']) ?></div>
                                <div class="text-muted" style="font-size: 11px;">Gross: <?= format_currency($pStats['gross_sales']) ?></div>
                            </div>
                            <div class="col-4 border-end">
                                <div class="text-muted small">Gross Profit</div>
                                <div class="fw-bold fs-6 <?= $pStats['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($pStats['gross_profit']) ?></div>
                                <div class="text-muted" style="font-size: 11px;">Margin: <?= $pStats['profit_margin'] ?>%</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">Market Udhaar</div>
                                <div class="fw-bold fs-6 text-warning-emphasis"><?= format_currency($pStats['market_receivables']) ?></div>
                                <div class="text-muted" style="font-size: 11px;">Returns: <?= format_currency($pStats['return_amount']) ?></div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded small">
                            <span>Khata Balance: <strong class="<?= (float)($pInfo['current_balance'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= format_currency($pInfo['current_balance'] ?? 0) ?></strong></span>
                            <a href="<?= APP_URL ?>/modules/partners/ledger.php?partner_id=<?= $pid ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" class="btn btn-sm btn-outline-primary py-0">
                                <i class="bi bi-journal-text me-1"></i> View Khata
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 4 Executive KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Net Sales Volume</div>
                    <div class="h4 fw-bold text-navy mt-1 mb-0"><?= number_format($grandTotals['cartons'], 1) ?> <small class="fs-6 fw-normal text-muted">Ctns</small></div>
                    <div class="small text-muted mt-1"><?= count($companies) ?> Companies Active in Period</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Net Realized Revenue</div>
                    <div class="h4 fw-bold text-success mt-1 mb-0"><?= format_currency($grandTotals['net_amount']) ?></div>
                    <div class="small text-muted mt-1">Gross: <?= format_currency($grandTotals['gross_amount']) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Market Udhaar Exposure</div>
                    <div class="h4 fw-bold text-warning-emphasis mt-1 mb-0"><?= format_currency($grandTotals['market_receivables']) ?></div>
                    <div class="small text-danger fw-semibold mt-1"><i class="bi bi-exclamation-circle me-1"></i>Phansa Hua Paisa in Market</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">Gross Profit & Margin</div>
                    <div class="h4 fw-bold text-info mt-1 mb-0"><?= format_currency($grandTotals['gross_profit']) ?></div>
                    <div class="small text-muted mt-1">Overall Margin: <strong class="text-success"><?= number_format($grandMargin, 1) ?>%</strong></div>
                </div>
            </div>
        </div>

        <!-- Master Company-Wise Table -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-navy">
                    <i class="bi bi-building me-2 text-primary"></i>Company-Wise Breakdown & Market Exposure
                </h6>
                <div class="small text-muted">Period: <strong><?= date('d M Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d M Y', strtotime($toDate)) ?></strong></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-uppercase small text-muted">
                            <tr>
                                <th style="width: 30px;"></th>
                                <th>Company / Supplier</th>
                                <th>City / Brand</th>
                                <th class="text-center">Items Sold</th>
                                <th class="text-center">Cartons</th>
                                <th class="text-end">Gross Sales</th>
                                <th class="text-end">Returns</th>
                                <th class="text-end">Net Revenue</th>
                                <th class="text-end text-warning-emphasis bg-warning-subtle bg-opacity-25">Market Udhaar</th>
                                <th class="text-end text-success">Gross Profit</th>
                                <th class="text-center">Margin %</th>
                                <th class="text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($companies)): ?>
                                <tr>
                                    <td colspan="12" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                        <div class="fw-semibold">No company sales recorded in this period.</div>
                                        <small>Try selecting a broader date range or removing filters.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($companies as $supId => $comp): 
                                    $compMargin = $comp['net_amount'] > 0 ? ($comp['gross_profit'] / $comp['net_amount']) * 100 : 0.0;
                                    $receivableRatio = $comp['net_amount'] > 0 ? ($comp['market_receivables'] / $comp['net_amount']) * 100 : 0.0;
                                ?>
                                    <!-- Company Summary Row -->
                                    <tr class="table-row-company">
                                        <td class="text-center">
                                            <button class="btn btn-xs btn-outline-primary py-0 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#items_comp_<?= $supId ?>" aria-expanded="false" title="Click to view item details">
                                                <i class="bi bi-chevron-down"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-navy"><?= htmlspecialchars($comp['supplier_name']) ?></div>
                                            <?php if (!empty($comp['company_name']) && $comp['company_name'] !== $comp['supplier_name']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($comp['company_name']) ?></small>
                                            <?php endif; ?>
                                            <?php 
                                            $linkedPartner = get_partner_by_supplier($supId);
                                            if ($linkedPartner): 
                                            ?>
                                                <div>
                                                    <span class="badge bg-navy-subtle text-navy border border-navy-subtle mt-1" style="font-size: 10px;">
                                                        <i class="bi bi-person-fill me-1"></i> Partner: <?= e($linkedPartner['partner_name']) ?> <?= !empty($linkedPartner['urdu_name']) ? '(' . e($linkedPartner['urdu_name']) . ')' : '' ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= !empty($comp['supplier_city']) ? '<span class="badge bg-light text-dark border">' . htmlspecialchars($comp['supplier_city']) . '</span>' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-center fw-semibold"><?= $comp['total_items_count'] ?></td>
                                        <td class="text-center fw-bold"><?= number_format($comp['cartons_sold'], 1) ?></td>
                                        <td class="text-end"><?= format_currency($comp['gross_amount']) ?></td>
                                        <td class="text-end text-danger"><?= $comp['return_amount'] > 0 ? '-' . format_currency($comp['return_amount']) : '-' ?></td>
                                        <td class="text-end fw-bold text-navy"><?= format_currency($comp['net_amount']) ?></td>
                                        
                                        <!-- Market Receivables Stuck -->
                                        <td class="text-end fw-bold text-warning-emphasis bg-warning-subtle bg-opacity-25">
                                            <?= format_currency($comp['market_receivables']) ?>
                                            <?php if ($receivableRatio > 50): ?>
                                                <span class="badge bg-danger ms-1" style="font-size: 10px;" title="<?= number_format($receivableRatio, 0) ?>% of sales still unpaid in market">High Udhaar</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end fw-bold text-success"><?= format_currency($comp['gross_profit']) ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $compMargin >= 20 ? 'bg-success' : ($compMargin >= 10 ? 'bg-primary' : 'bg-secondary') ?>">
                                                <?= number_format($compMargin, 1) ?>%
                                            </span>
                                        </td>
                                        <td class="text-center no-print">
                                            <div class="d-flex justify-content-center gap-1">
                                                <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#items_comp_<?= $supId ?>" title="View Items">
                                                    <i class="bi bi-list-ul me-1"></i> Items
                                                </button>
                                                <?php if ($linkedPartner): ?>
                                                    <a href="<?= APP_URL ?>/modules/partners/ledger.php?partner_id=<?= $linkedPartner['id'] ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="View Partner Khata">
                                                        <i class="bi bi-journal-text me-1"></i> Khata
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Collapsible Detail: Individual Items Under This Company -->
                                    <tr class="p-0 border-0">
                                        <td colspan="12" class="p-0 border-0">
                                            <div class="collapse bg-light p-3 border-bottom" id="items_comp_<?= $supId ?>">
                                                <div class="card border border-primary-subtle shadow-sm">
                                                    <div class="card-header bg-primary-subtle py-2 d-flex justify-content-between align-items-center">
                                                        <span class="small fw-bold text-primary">
                                                            <i class="bi bi-box-seam me-1"></i> Products Breakdown for <?= htmlspecialchars($comp['supplier_name']) ?>
                                                        </span>
                                                        <span class="small text-muted"><?= count($comp['items']) ?> products sold</span>
                                                    </div>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-striped align-middle mb-0 font-monospace small">
                                                            <thead>
                                                                <tr class="table-light">
                                                                    <th>SKU / Code</th>
                                                                    <th>Product Name</th>
                                                                    <th>Category</th>
                                                                    <th class="text-center">Cartons</th>
                                                                    <th class="text-center">Pcs</th>
                                                                    <th class="text-end">Sale Revenue</th>
                                                                    <th class="text-end">Returns</th>
                                                                    <th class="text-end">Net Sale</th>
                                                                    <th class="text-end text-warning-emphasis">Market Udhaar Stuck</th>
                                                                    <th class="text-end text-success">Gross Profit</th>
                                                                    <th class="text-center">Margin %</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($comp['items'] as $item): ?>
                                                                    <tr>
                                                                        <td class="fw-bold"><?= htmlspecialchars($item['product_code']) ?></td>
                                                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                                                                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($item['category_name']) ?></span></td>
                                                                        <td class="text-center fw-bold"><?= number_format($item['cartons_sold'], 1) ?></td>
                                                                        <td class="text-center"><?= number_format($item['pcs_sold'], 0) ?></td>
                                                                        <td class="text-end"><?= format_currency($item['gross_amount']) ?></td>
                                                                        <td class="text-end text-danger"><?= $item['return_amount'] > 0 ? '-' . format_currency($item['return_amount']) : '-' ?></td>
                                                                        <td class="text-end fw-bold"><?= format_currency($item['net_amount']) ?></td>
                                                                        <td class="text-end fw-bold text-warning-emphasis bg-warning-subtle bg-opacity-25">
                                                                            <?= format_currency($item['market_receivables']) ?>
                                                                        </td>
                                                                        <td class="text-end fw-bold text-success"><?= format_currency($item['gross_profit']) ?></td>
                                                                        <td class="text-center">
                                                                            <span class="badge <?= $item['margin_percent'] >= 20 ? 'bg-success' : 'bg-secondary' ?>">
                                                                                <?= number_format($item['margin_percent'], 1) ?>%
                                                                            </span>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($companies)): ?>
                            <tfoot class="table-dark fw-bold">
                                <tr>
                                    <td></td>
                                    <td colspan="3">GRAND TOTAL SUMMARY</td>
                                    <td class="text-center"><?= number_format($grandTotals['cartons'], 1) ?></td>
                                    <td class="text-end"><?= format_currency($grandTotals['gross_amount']) ?></td>
                                    <td class="text-end text-danger-subtle"><?= $grandTotals['return_amount'] > 0 ? '-' . format_currency($grandTotals['return_amount']) : '-' ?></td>
                                    <td class="text-end text-white"><?= format_currency($grandTotals['net_amount']) ?></td>
                                    <td class="text-end text-warning"><?= format_currency($grandTotals['market_receivables']) ?></td>
                                    <td class="text-end text-success"><?= format_currency($grandTotals['gross_profit']) ?></td>
                                    <td class="text-center"><?= number_format($grandMargin, 1) ?>%</td>
                                    <td class="no-print"></td>
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
