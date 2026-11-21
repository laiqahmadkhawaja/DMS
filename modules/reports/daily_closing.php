<?php
/**
 * DMS - Distribution Management System
 * Daily Business Closing Sheet & Roznamcha (یومیہ کلوزنگ شیٹ و منافع سمری)
 * Unified executive report: Sales, Cash Recoveries, Udhaar/Credit, Returns, Expenses, Profitability, and Cash Drawer Position
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('reports_daily');

// Period & Date Range Logic
$period = sanitize_input($_GET['period'] ?? 'today');
$today  = date('Y-m-d');

switch ($period) {
    case 'yesterday':
        $fromDate = date('Y-m-d', strtotime('-1 day'));
        $toDate   = $fromDate;
        break;
    case 'week':
        $fromDate = date('Y-m-d', strtotime('monday this week'));
        $toDate   = $today;
        break;
    case 'month':
        $fromDate = date('Y-m-01');
        $toDate   = $today;
        break;
    case 'custom':
        $fromDate = sanitize_input($_GET['from_date'] ?? $today);
        $toDate   = sanitize_input($_GET['to_date'] ?? $today);
        break;
    case 'today':
    default:
        $period   = 'today';
        $fromDate = sanitize_input($_GET['from_date'] ?? $today);
        $toDate   = sanitize_input($_GET['to_date'] ?? $today);
        break;
}

// Optional Filters
$areaFilter     = (int)($_GET['area_id'] ?? 0);
$bookerFilter   = (int)($_GET['booker_id'] ?? 0);
$salesmanFilter = (int)($_GET['salesman_id'] ?? 0);
$activeTab      = sanitize_input($_GET['tab'] ?? 'overview');

// Base Where Clauses for Sales Invoices
$invWhere = ["si.invoice_date BETWEEN ? AND ?", "si.status != 'cancelled'"];
$invParams = [$fromDate, $toDate];

if ($areaFilter > 0) {
    $invWhere[] = "si.area_id = ?";
    $invParams[] = $areaFilter;
}
if ($bookerFilter > 0) {
    $invWhere[] = "si.booker_id = ?";
    $invParams[] = $bookerFilter;
}
if ($salesmanFilter > 0) {
    $invWhere[] = "si.salesman_id = ?";
    $invParams[] = $salesmanFilter;
}

$invWhereSql = implode(" AND ", $invWhere);

// 1. Executive Sales Totals
$salesSummary = db_fetch_one("
    SELECT 
        COUNT(si.id) as total_bills,
        COALESCE(SUM(si.gross_amount), 0) as total_gross,
        COALESCE(SUM(si.item_discount_amount + si.extra_discount_amount), 0) as total_discounts,
        COALESCE(SUM(si.net_amount), 0) as total_net,
        COALESCE(SUM(si.paid_amount), 0) as spot_cash_paid,
        COALESCE(SUM(si.net_amount - si.paid_amount), 0) as credit_extended
    FROM sales_invoices si
    WHERE {$invWhereSql}
", $invParams) ?: [
    'total_bills' => 0, 'total_gross' => 0.0, 'total_discounts' => 0.0,
    'total_net' => 0.0, 'spot_cash_paid' => 0.0, 'credit_extended' => 0.0
];

// 2. Customer Payments & Market Recoveries
$recParams = [$fromDate, $toDate];
$recWhere = ["cp.payment_date BETWEEN ? AND ?"];
if ($areaFilter > 0) {
    $recWhere[] = "c.area_id = ?";
    $recParams[] = $areaFilter;
}
$recWhereSql = implode(" AND ", $recWhere);

$recoverySummary = db_fetch_one("
    SELECT 
        COUNT(cp.id) as total_receipts,
        COALESCE(SUM(cp.amount), 0) as total_recovered,
        COALESCE(SUM(CASE WHEN cp.payment_mode = 'cash' THEN cp.amount ELSE 0 END), 0) as cash_recovered,
        COALESCE(SUM(CASE WHEN cp.payment_mode != 'cash' THEN cp.amount ELSE 0 END), 0) as bank_cheque_recovered
    FROM customer_payments cp
    JOIN customers c ON cp.customer_id = c.id
    WHERE {$recWhereSql}
", $recParams) ?: ['total_receipts' => 0, 'total_recovered' => 0.0, 'cash_recovered' => 0.0, 'bank_cheque_recovered' => 0.0];

// 3. Sales Returns Received
$retParams = [$fromDate, $toDate];
$retWhere = ["sr.return_date BETWEEN ? AND ?", "sr.status != 'draft'"];
if ($areaFilter > 0) {
    $retWhere[] = "c.area_id = ?";
    $retParams[] = $areaFilter;
}
$retWhereSql = implode(" AND ", $retWhere);

$returnsSummary = db_fetch_one("
    SELECT 
        COUNT(sr.id) as return_count,
        COALESCE(SUM(sr.total_amount), 0) as total_returns
    FROM sales_returns sr
    JOIN customers c ON sr.customer_id = c.id
    WHERE {$retWhereSql}
", $retParams) ?: ['return_count' => 0, 'total_returns' => 0.0];

// 4. Daily Operational Expenses
$expenseSummary = db_fetch_one("
    SELECT 
        COUNT(id) as expense_count,
        COALESCE(SUM(amount), 0) as total_expenses,
        COALESCE(SUM(CASE WHEN payment_mode = 'cash' THEN amount ELSE 0 END), 0) as cash_expenses
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
", [$fromDate, $toDate]) ?: ['expense_count' => 0, 'total_expenses' => 0.0, 'cash_expenses' => 0.0];

// 5. Supplier Payments Disbursed
$supplierPaymentSummary = db_fetch_one("
    SELECT 
        COUNT(id) as count,
        COALESCE(SUM(amount), 0) as total_supplier_paid,
        COALESCE(SUM(CASE WHEN payment_mode = 'cash' THEN amount ELSE 0 END), 0) as cash_supplier_paid
    FROM supplier_payments
    WHERE payment_date BETWEEN ? AND ?
", [$fromDate, $toDate]) ?: ['count' => 0, 'total_supplier_paid' => 0.0, 'cash_supplier_paid' => 0.0];

// 6. Item-Wise Sales Breakdown & Estimated Profit / COGS
$itemBreakdown = db_fetch_all("
    SELECT 
        p.id as product_id,
        p.product_code,
        p.product_name,
        cat.category_name,
        p.carton_qty as pcs_per_carton,
        p.purchase_rate as cost_rate,
        COALESCE(SUM(sii.carton_qty), 0) as total_ctn_sold,
        COALESCE(SUM(sii.pack_qty), 0) as total_pck_sold,
        COALESCE(SUM(sii.pcs_qty), 0) as total_pcs_sold,
        COALESCE(SUM(sii.quantity), 0) as total_base_pieces,
        COALESCE(SUM(sii.line_total), 0) as total_sale_amount
    FROM sales_invoice_items sii
    JOIN sales_invoices si ON sii.invoice_id = si.id
    JOIN products p ON sii.product_id = p.id
    LEFT JOIN product_categories cat ON p.category_id = cat.id
    WHERE {$invWhereSql}
    GROUP BY p.id, p.product_code, p.product_name, cat.category_name, p.carton_qty, p.purchase_rate
    ORDER BY total_sale_amount DESC
", $invParams);

// Compute total COGS & Gross Profit across items
$totalEstimatedCOGS = 0.0;
foreach ($itemBreakdown as &$item) {
    $pcsPerCtn = (int)$item['pcs_per_carton'] > 0 ? (int)$item['pcs_per_carton'] : 1;
    $costPerPiece = (float)$item['cost_rate'] / $pcsPerCtn;
    $lineCOGS = (float)$item['total_base_pieces'] * $costPerPiece;
    $lineProfit = (float)$item['total_sale_amount'] - $lineCOGS;
    $lineMargin = (float)$item['total_sale_amount'] > 0 ? round(($lineProfit / (float)$item['total_sale_amount']) * 100, 1) : 0.0;

    $item['cogs'] = $lineCOGS;
    $item['profit'] = $lineProfit;
    $item['margin_pct'] = $lineMargin;

    $totalEstimatedCOGS += $lineCOGS;
}
unset($item);

$grossProfit = (float)$salesSummary['total_net'] - $totalEstimatedCOGS - (float)$returnsSummary['total_returns'];
$grossMarginPct = (float)$salesSummary['total_net'] > 0 ? round(($grossProfit / (float)$salesSummary['total_net']) * 100, 1) : 0.0;
$netOperatingProfit = $grossProfit - (float)$expenseSummary['total_expenses'];

// 7. Invoices List for the Tab
$invoicesList = db_fetch_all("
    SELECT 
        si.*, 
        c.shop_name, 
        a.area_name, 
        b.booker_name,
        sm.salesman_name
    FROM sales_invoices si
    JOIN customers c ON si.customer_id = c.id
    LEFT JOIN areas a ON si.area_id = a.id
    LEFT JOIN bookers b ON si.booker_id = b.id
    LEFT JOIN salesmen sm ON si.salesman_id = sm.id
    WHERE {$invWhereSql}
    ORDER BY si.invoice_date DESC, si.id DESC
", $invParams);

// 8. Detailed Daily Expenses List
$expensesList = db_fetch_all("
    SELECT e.*, ec.category_name, a.account_name, u.full_name as creator_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN accounts a ON e.account_id = a.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
", [$fromDate, $toDate]);

// Master lists for filters
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 ORDER BY area_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");

$pageTitle = 'Daily Closing Sheet & Roznamcha — ' . APP_NAME;
$activeMenu = 'reports';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        <!-- Breadcrumb & Top Bar -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom no-print">
            <div>
                <h4 class="mb-0 text-navy fw-bold">
                    <i class="bi bi-journal-bookmark-fill me-2 text-primary"></i>Daily Closing Sheet & Roznamcha (یومیہ کلوزنگ)
                </h4>
                <small class="text-muted">Master daily reconciliation: Sales billing, Cash recovered, New Udhaar, Returns, Expenses, and Profit margins</small>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-navy shadow-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Formal Closing Sheet (F10)
                </button>
                <a href="<?= APP_URL ?>/modules/reports/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Reports Center Hub
                </a>
            </div>
        </div>

        <!-- Filter & Date Preset Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body p-3 bg-light rounded">
                <form method="GET" action="daily_closing.php" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                    
                    <!-- Quick Preset Buttons -->
                    <div class="col-md-4 d-flex gap-1">
                        <a href="daily_closing.php?period=today&area_id=<?= $areaFilter ?>" 
                           class="btn btn-sm <?= $period === 'today' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white' ?> flex-fill">
                            Today (آج)
                        </a>
                        <a href="daily_closing.php?period=yesterday&area_id=<?= $areaFilter ?>" 
                           class="btn btn-sm <?= $period === 'yesterday' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white' ?> flex-fill">
                            Yesterday (کل)
                        </a>
                        <a href="daily_closing.php?period=week&area_id=<?= $areaFilter ?>" 
                           class="btn btn-sm <?= $period === 'week' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white' ?> flex-fill">
                            This Week (ہفتہ)
                        </a>
                        <a href="daily_closing.php?period=month&area_id=<?= $areaFilter ?>" 
                           class="btn btn-sm <?= $period === 'month' ? 'btn-primary fw-bold' : 'btn-outline-secondary bg-white' ?> flex-fill">
                            This Month (ماہ)
                        </a>
                    </div>

                    <!-- Custom Date Range -->
                    <div class="col-md-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white">From</span>
                            <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white">To</span>
                            <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
                        </div>
                    </div>

                    <!-- Route / Area Filter -->
                    <div class="col-md-2">
                        <select name="area_id" class="form-select form-select-sm">
                            <option value="0">All Routes / Areas</option>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $areaFilter === (int)$a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['area_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-filter me-1"></i> Apply Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- PRINT HEADER (Only visible when printing) -->
        <div class="d-none d-print-block mb-4 text-center border-bottom pb-3">
            <h3 class="fw-bold mb-1"><?= e(CLIENT_NAME) ?></h3>
            <p class="mb-1 text-muted small"><?= htmlspecialchars(get_setting('distributor_address', 'Jandiala Bhagwala G.T. Road Gujranwala')) ?> | Ph: <?= htmlspecialchars(get_setting('distributor_phone', '03000673276')) ?></p>
            <h5 class="fw-bold text-uppercase mt-2 border-top border-bottom py-1 d-inline-block">
                Daily Business Closing Sheet & Roznamcha (یومیہ کلوزنگ شیٹ)
            </h5>
            <div class="d-flex justify-content-between mt-2 small text-muted">
                <span>Period: <strong><?= date('d-M-Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d-M-Y', strtotime($toDate)) ?></strong></span>
                <span>Generated At: <strong><?= date('d-M-Y h:i A') ?></strong> by <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></strong></span>
            </div>
        </div>

        <!-- 6 EXECUTIVE KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <!-- 1. Total Net Sales -->
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm border-start border-4 border-primary h-100 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-bold text-uppercase">Total Net Billed Sales (کل سیل)</span>
                        <span class="badge bg-primary-subtle text-primary"><?= number_format($salesSummary['total_bills']) ?> Bills</span>
                    </div>
                    <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$salesSummary['total_net']) ?></div>
                    <div class="small text-muted mt-1">
                        Gross: <?= format_currency((float)$salesSummary['total_gross']) ?> | Disc: <?= format_currency((float)$salesSummary['total_discounts']) ?>
                    </div>
                </div>
            </div>

            <!-- 2. Gross Profit & Margin -->
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm border-start border-4 border-success h-100 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-bold text-uppercase">Gross Profit & Margin (منافع)</span>
                        <span class="badge bg-success-subtle text-success"><?= $grossMarginPct ?>% Margin</span>
                    </div>
                    <div class="fs-4 fw-bold text-success mt-1"><?= format_currency($grossProfit) ?></div>
                    <div class="small text-muted mt-1">
                        COGS: <?= format_currency($totalEstimatedCOGS) ?> | Returns: <?= format_currency((float)$returnsSummary['total_returns']) ?>
                    </div>
                </div>
            </div>

            <!-- 3. Cash Recoveries Collected -->
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm border-start border-4 border-info h-100 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-bold text-uppercase">Cash Collections (نقدی وصولیاں)</span>
                        <span class="badge bg-info-subtle text-info"><?= number_format($recoverySummary['total_receipts']) ?> Receipts</span>
                    </div>
                    <?php $totalCashCollected = (float)$salesSummary['spot_cash_paid'] + (float)$recoverySummary['cash_recovered']; ?>
                    <div class="fs-4 fw-bold text-info mt-1"><?= format_currency($totalCashCollected) ?></div>
                    <div class="small text-muted mt-1">
                        Spot Cash: <?= format_currency((float)$salesSummary['spot_cash_paid']) ?> | Market: <?= format_currency((float)$recoverySummary['cash_recovered']) ?>
                    </div>
                </div>
            </div>

            <!-- 4. New Udhaar / Credit Extended -->
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm border-start border-4 border-warning h-100 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-bold text-uppercase">New Credit Extended (نیا ادھار)</span>
                        <span class="badge bg-warning-subtle text-warning">Market Credit</span>
                    </div>
                    <div class="fs-4 fw-bold text-dark mt-1"><?= format_currency((float)$salesSummary['credit_extended']) ?></div>
                    <div class="small text-muted mt-1">
                        Unpaid balance carried to customer ledgers
                    </div>
                </div>
            </div>

            <!-- 5. Daily Expenses Paid -->
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm border-start border-4 border-danger h-100 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-bold text-uppercase">Daily Expenses (روزمرہ اخراجات)</span>
                        <span class="badge bg-danger-subtle text-danger"><?= number_format($expenseSummary['expense_count']) ?> Vouchers</span>
                    </div>
                    <div class="fs-4 fw-bold text-danger mt-1"><?= format_currency((float)$expenseSummary['total_expenses']) ?></div>
                    <div class="small text-muted mt-1">
                        Cash: <?= format_currency((float)$expenseSummary['cash_expenses']) ?>
                    </div>
                </div>
            </div>

            <!-- 6. Net Operating Profit -->
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm border-start border-4 border-secondary h-100 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-bold text-uppercase">Net Profit after Expenses (خالص بچت)</span>
                        <span class="badge bg-secondary-subtle text-secondary">Final Net</span>
                    </div>
                    <div class="fs-4 fw-bold <?= $netOperatingProfit >= 0 ? 'text-success' : 'text-danger' ?> mt-1">
                        <?= format_currency($netOperatingProfit) ?>
                    </div>
                    <div class="small text-muted mt-1">
                        Gross Profit minus Operating Expenses
                    </div>
                </div>
            </div>
        </div>

        <!-- TABS CONTAINER -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom p-0 no-print">
                <ul class="nav nav-tabs card-header-tabs m-0 px-3 border-bottom-0" id="closingTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'overview' ? 'active text-primary' : 'text-muted' ?>" 
                                id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview-pane" type="button" role="tab">
                            <i class="bi bi-wallet2 me-1"></i> 1. Cash Roznamcha (کیش بک سمری)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'items' ? 'active text-primary' : 'text-muted' ?>" 
                                id="items-tab" data-bs-toggle="tab" data-bs-target="#items-pane" type="button" role="tab">
                            <i class="bi bi-boxes me-1"></i> 2. Item-Wise Sales & Profitability (اشیاء اور منافع)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'bills' ? 'active text-primary' : 'text-muted' ?>" 
                                id="bills-tab" data-bs-toggle="tab" data-bs-target="#bills-pane" type="button" role="tab">
                            <i class="bi bi-receipt me-1"></i> 3. Customer Billing & Udhaar Register (بل اور ادھار)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'expenses' ? 'active text-primary' : 'text-muted' ?>" 
                                id="expenses-tab" data-bs-toggle="tab" data-bs-target="#expenses-pane" type="button" role="tab">
                            <i class="bi bi-cash-stack me-1"></i> 4. Daily Expenses (اخراجات)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body p-0">
                <div class="tab-content" id="closingTabsContent">
                    
                    <!-- TAB 1: CASH ROZNAMCHA / CASH FLOW RECONCILIATION -->
                    <div class="tab-pane fade <?= $activeTab === 'overview' ? 'show active' : '' ?>" id="overview-pane" role="tabpanel">
                        <div class="p-4">
                            <h6 class="fw-bold text-navy mb-3"><i class="bi bi-cash-coin text-success me-2"></i>Day End Cash Reconciliation (یومیہ کیش روزنامچہ)</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle mb-0 font-monospace">
                                    <thead class="table-light text-uppercase small">
                                        <tr>
                                            <th>Description / Transaction Head (تفصیل)</th>
                                            <th class="text-end" style="width: 200px;">Cash Inflow (آمدن)</th>
                                            <th class="text-end" style="width: 200px;">Cash Outflow (خرچ / ادائیگی)</th>
                                            <th class="text-end" style="width: 220px;">Net Position (بیلنس)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><i class="bi bi-receipt text-primary me-2"></i> Spot Cash Sales (آج کی نقد بلنگ)</td>
                                            <td class="text-end text-success fw-bold"><?= format_currency((float)$salesSummary['spot_cash_paid']) ?></td>
                                            <td class="text-end text-muted">-</td>
                                            <td class="text-end">-</td>
                                        </tr>
                                        <tr>
                                            <td><i class="bi bi-person-check text-info me-2"></i> Market Customer Cash Recoveries (مارکیٹ کیش وصولیاں)</td>
                                            <td class="text-end text-success fw-bold"><?= format_currency((float)$recoverySummary['cash_recovered']) ?></td>
                                            <td class="text-end text-muted">-</td>
                                            <td class="text-end">-</td>
                                        </tr>
                                        <tr>
                                            <td><i class="bi bi-wallet2 text-danger me-2"></i> Daily Operational Expenses Paid in Cash (روزمرہ کیش اخراجات)</td>
                                            <td class="text-end text-muted">-</td>
                                            <td class="text-end text-danger fw-bold"><?= format_currency((float)$expenseSummary['cash_expenses']) ?></td>
                                            <td class="text-end">-</td>
                                        </tr>
                                        <tr>
                                            <td><i class="bi bi-building text-warning me-2"></i> Cash Paid to Suppliers / Vendors (فیکٹری کیش ادائیگی)</td>
                                            <td class="text-end text-muted">-</td>
                                            <td class="text-end text-danger fw-bold"><?= format_currency((float)$supplierPaymentSummary['cash_supplier_paid']) ?></td>
                                            <td class="text-end">-</td>
                                        </tr>
                                        <?php 
                                        $netCashDrawerMovement = ((float)$salesSummary['spot_cash_paid'] + (float)$recoverySummary['cash_recovered']) - ((float)$expenseSummary['cash_expenses'] + (float)$supplierPaymentSummary['cash_supplier_paid']);
                                        ?>
                                        <tr class="table-warning fw-bold fs-6">
                                            <td class="text-uppercase"><i class="bi bi-safe2 text-dark me-2"></i> Net Day Cash Movement (دن کا خالص کیش اضافہ)</td>
                                            <td class="text-end text-success"><?= format_currency((float)$salesSummary['spot_cash_paid'] + (float)$recoverySummary['cash_recovered']) ?></td>
                                            <td class="text-end text-danger"><?= format_currency((float)$expenseSummary['cash_expenses'] + (float)$supplierPaymentSummary['cash_supplier_paid']) ?></td>
                                            <td class="text-end text-navy fw-bold"><?= format_currency($netCashDrawerMovement) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: ITEM-WISE SALES & PROFITABILITY BREAKDOWN -->
                    <div class="tab-pane fade <?= $activeTab === 'items' ? 'show active' : '' ?>" id="items-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">Breakdown of Products Sold, Cost of Goods Sold (COGS), and Gross Margin</span>
                            <span class="badge bg-secondary"><?= count($itemBreakdown) ?> Products Billed</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0 font-monospace small">
                                <thead class="table-light text-uppercase">
                                    <tr>
                                        <th>Code</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th class="text-end">Cartons</th>
                                        <th class="text-end">Packs</th>
                                        <th class="text-end">Total Pcs</th>
                                        <th class="text-end">Total Billed</th>
                                        <th class="text-end">Total Cost (COGS)</th>
                                        <th class="text-end">Gross Profit</th>
                                        <th class="text-end">Margin %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($itemBreakdown)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-5 text-muted">
                                                <i class="bi bi-box-seam fs-1 d-block mb-2 text-secondary"></i>
                                                No sales invoice items recorded for the selected period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $totCtns = 0; $totPacks = 0; $totPcs = 0; $totSale = 0; $totCogs = 0; $totProfit = 0;
                                        ?>
                                        <?php foreach ($itemBreakdown as $row): ?>
                                            <?php 
                                            $totCtns += (float)$row['total_ctn_sold'];
                                            $totPacks += (float)$row['total_pck_sold'];
                                            $totPcs += (float)$row['total_base_pieces'];
                                            $totSale += (float)$row['total_sale_amount'];
                                            $totCogs += (float)$row['cogs'];
                                            $totProfit += (float)$row['profit'];
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['product_code']) ?></span></td>
                                                <td class="fw-bold font-sans"><?= htmlspecialchars($row['product_name']) ?></td>
                                                <td><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                                <td class="text-end"><?= number_format((float)$row['total_ctn_sold'], 1) ?></td>
                                                <td class="text-end"><?= number_format((float)$row['total_pck_sold']) ?></td>
                                                <td class="text-end fw-bold"><?= number_format((float)$row['total_base_pieces']) ?></td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency((float)$row['total_sale_amount']) ?></td>
                                                <td class="text-end text-muted"><?= format_currency((float)$row['cogs']) ?></td>
                                                <td class="text-end fw-bold text-success"><?= format_currency((float)$row['profit']) ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                        <?= $row['margin_pct'] ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-light fw-bold fs-6">
                                            <td colspan="3" class="text-uppercase text-end">Grand Totals:</td>
                                            <td class="text-end"><?= number_format($totCtns, 1) ?></td>
                                            <td class="text-end"><?= number_format($totPacks) ?></td>
                                            <td class="text-end"><?= number_format($totPcs) ?></td>
                                            <td class="text-end text-navy"><?= format_currency($totSale) ?></td>
                                            <td class="text-end text-muted"><?= format_currency($totCogs) ?></td>
                                            <td class="text-end text-success"><?= format_currency($totProfit) ?></td>
                                            <td class="text-end"><?= $totSale > 0 ? round(($totProfit / $totSale) * 100, 1) : 0 ?>%</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 3: CUSTOMER BILLING & UDHAAR REGISTER -->
                    <div class="tab-pane fade <?= $activeTab === 'bills' ? 'show active' : '' ?>" id="bills-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">Customer-wise Sales, Cash Collections on Delivery, and Udhaar Ledger Additions</span>
                            <span class="badge bg-secondary"><?= count($invoicesList) ?> Invoices</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0 font-monospace small">
                                <thead class="table-light text-uppercase">
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Customer Shop Name</th>
                                        <th>Route / Area</th>
                                        <th>Booker</th>
                                        <th class="text-end">Net Bill</th>
                                        <th class="text-end">Cash Paid</th>
                                        <th class="text-end">Credit (ادھار)</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($invoicesList)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-5 text-muted">
                                                <i class="bi bi-receipt fs-1 d-block mb-2 text-secondary"></i>
                                                No customer sales invoices generated during this period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($invoicesList as $inv): ?>
                                            <?php 
                                            $net = (float)$inv['net_amount'];
                                            $paid = (float)$inv['paid_amount'];
                                            $credit = max(0, $net - $paid);
                                            ?>
                                            <tr>
                                                <td class="fw-bold font-monospace">
                                                    <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="text-decoration-none text-navy">
                                                        <?= htmlspecialchars($inv['invoice_number']) ?>
                                                    </a>
                                                </td>
                                                <td><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></td>
                                                <td class="fw-bold font-sans">
                                                    <a href="<?= APP_URL ?>/modules/customers/view.php?id=<?= $inv['customer_id'] ?>" class="text-decoration-none text-dark">
                                                        <?= htmlspecialchars($inv['shop_name']) ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($inv['area_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($inv['booker_name'] ?? '-') ?></td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency($net) ?></td>
                                                <td class="text-end text-success fw-bold"><?= $paid > 0 ? format_currency($paid) : '-' ?></td>
                                                <td class="text-end text-danger fw-bold"><?= $credit > 0 ? format_currency($credit) : '-' ?></td>
                                                <td class="text-center">
                                                    <?php if ($paid >= $net): ?>
                                                        <span class="badge bg-success">Paid</span>
                                                    <?php elseif ($paid > 0): ?>
                                                        <span class="badge bg-warning text-dark">Partial</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Full Credit</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end no-print">
                                                    <a href="<?= APP_URL ?>/modules/invoices/print.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Print Bill">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 4: DAILY EXPENSES REGISTER -->
                    <div class="tab-pane fade <?= $activeTab === 'expenses' ? 'show active' : '' ?>" id="expenses-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">Operating Overheads, Fuel, Wages, and Utilities</span>
                            <span class="badge bg-secondary"><?= count($expensesList) ?> Vouchers</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0 font-monospace small">
                                <thead class="table-light text-uppercase">
                                    <tr>
                                        <th>Voucher #</th>
                                        <th>Date</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Paid To</th>
                                        <th>Mode</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expensesList)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                                                No daily expenses recorded for this period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($expensesList as $exp): ?>
                                            <tr>
                                                <td class="fw-bold font-monospace"><?= htmlspecialchars($exp['expense_number'] ?? ('EXP-' . $exp['id'])) ?></td>
                                                <td><?= date('d-M-Y', strtotime($exp['expense_date'])) ?></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($exp['category_name'] ?? 'General') ?></span></td>
                                                <td class="text-wrap font-sans"><?= htmlspecialchars($exp['description']) ?></td>
                                                <td class="font-sans"><?= htmlspecialchars($exp['paid_to'] ?? '-') ?></td>
                                                <td class="text-uppercase"><?= htmlspecialchars($exp['payment_mode']) ?></td>
                                                <td class="text-end fw-bold text-danger"><?= format_currency((float)$exp['amount']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- FORMAL SIGNATURE BOXES (Visible on Print and Screen Closing) -->
        <div class="row g-4 mt-4 pt-3 border-top">
            <div class="col-3 text-center">
                <div class="border-top border-dark pt-2 mx-auto" style="max-width: 200px;">
                    <div class="fw-bold small">Prepared By</div>
                    <div class="text-muted" style="font-size: 0.72rem;">Computer Incharge / Operator</div>
                </div>
            </div>
            <div class="col-3 text-center">
                <div class="border-top border-dark pt-2 mx-auto" style="max-width: 200px;">
                    <div class="fw-bold small">Cash Received By</div>
                    <div class="text-muted" style="font-size: 0.72rem;">Cashier / Office Vault</div>
                </div>
            </div>
            <div class="col-3 text-center">
                <div class="border-top border-dark pt-2 mx-auto" style="max-width: 200px;">
                    <div class="fw-bold small">Verified By</div>
                    <div class="text-muted" style="font-size: 0.72rem;">Sales / Delivery Manager</div>
                </div>
            </div>
            <div class="col-3 text-center">
                <div class="border-top border-dark pt-2 mx-auto" style="max-width: 200px;">
                    <div class="fw-bold small">Approved By</div>
                    <div class="text-muted" style="font-size: 0.72rem;">Director / Business Owner</div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
