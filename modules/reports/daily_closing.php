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
require_once INCLUDES_PATH . '/partner_functions.php';

require_login();
require_permission('reports_daily');

// Handle Cash Handover / Return POST actions from Daily Closing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = sanitize_input($_POST['action'] ?? '');
    $period      = sanitize_input($_POST['period'] ?? 'today');
    $fromDate    = sanitize_input($_POST['from_date'] ?? date('Y-m-d'));
    $toDate      = sanitize_input($_POST['to_date'] ?? date('Y-m-d'));
    $redirectUrl = APP_URL . "/modules/reports/daily_closing.php?period={$period}&from_date={$fromDate}&to_date={$toDate}&tab=overview";

    if ($action === 'partner_cash_handover') {
        $partnerId         = (int)($_POST['partner_id'] ?? 0);
        $amount            = (float)($_POST['amount'] ?? 0);
        $transactionDate   = sanitize_input($_POST['transaction_date'] ?? date('Y-m-d'));
        $paidFromAccountId = (int)($_POST['paid_from_account_id'] ?? 0);
        $description       = sanitize_input($_POST['description'] ?? '');

        if ($partnerId <= 0) {
            set_flash('danger', 'Please select a valid Managing Partner.');
            redirect($redirectUrl);
        }
        if ($amount <= 0) {
            set_flash('danger', 'Handover amount must be greater than zero.');
            redirect($redirectUrl);
        }
        if ($paidFromAccountId <= 0) {
            set_flash('danger', 'Please choose the Cash / Bank drawer account.');
            redirect($redirectUrl);
        }

        try {
            $txnId = record_partner_cash_handover($partnerId, $amount, $transactionDate, $paidFromAccountId, $description);
            $partner = get_partner_by_id($partnerId);
            set_flash('success', "Daily sales cash handover of <strong>" . format_currency($amount) . "</strong> to <strong>" . htmlspecialchars($partner['partner_name'] ?? 'Partner') . "</strong> recorded successfully in Khata & Roznamcha.");
            redirect($redirectUrl);
        } catch (Exception $e) {
            set_flash('danger', "Error recording cash handover: " . $e->getMessage());
            redirect($redirectUrl);
        }
    } elseif ($action === 'partner_cash_return') {
        $partnerId          = (int)($_POST['partner_id'] ?? 0);
        $amount             = (float)($_POST['amount'] ?? 0);
        $transactionDate    = sanitize_input($_POST['transaction_date'] ?? date('Y-m-d'));
        $depositToAccountId = (int)($_POST['deposit_to_account_id'] ?? 0);
        $paymentMode        = sanitize_input($_POST['payment_mode'] ?? 'cash');
        $description        = sanitize_input($_POST['description'] ?? '');

        if ($partnerId <= 0) {
            set_flash('danger', 'Please select a valid Partner.');
            redirect($redirectUrl);
        }
        if ($amount <= 0) {
            set_flash('danger', 'Received amount must be greater than zero.');
            redirect($redirectUrl);
        }
        if ($depositToAccountId <= 0) {
            set_flash('danger', 'Please choose the Cash / Bank account receiving funds.');
            redirect($redirectUrl);
        }

        try {
            $txnId = record_partner_cash_received($partnerId, $amount, $transactionDate, $depositToAccountId, $paymentMode, $description);
            $partner = get_partner_by_id($partnerId);
            set_flash('success', "Cash received / returned of <strong>" . format_currency($amount) . "</strong> from <strong>" . htmlspecialchars($partner['partner_name'] ?? 'Partner') . "</strong> recorded and deposited successfully.");
            redirect($redirectUrl);
        } catch (Exception $e) {
            set_flash('danger', "Error recording received cash: " . $e->getMessage());
            redirect($redirectUrl);
        }
    }
}

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
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$activeTab      = sanitize_input($_GET['tab'] ?? 'overview');

// Fetch active suppliers for filter
$suppliers = db_fetch_all("SELECT id, supplier_code, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 ORDER BY id ASC");

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
if ($supplierFilter > 0) {
    $invWhereSup = $invWhere;
    $invWhereSup[] = "p.supplier_id = ?";
    $invParamsSup = $invParams;
    $invParamsSup[] = $supplierFilter;
    $invWhereSupSql = implode(" AND ", $invWhereSup);

    $salesSummary = db_fetch_one("
        SELECT 
            COUNT(DISTINCT si.id) as total_bills,
            COALESCE(SUM(sii.line_total), 0) as total_gross,
            COALESCE(SUM(sii.discount_amount), 0) as total_discounts,
            COALESCE(SUM(sii.line_total), 0) as total_net,
            COALESCE(SUM(
                CASE 
                    WHEN si.gross_amount > 0 AND si.paid_amount > 0 
                    THEN (sii.line_total / si.gross_amount) * si.paid_amount 
                    ELSE 0 
                END
            ), 0) as spot_cash_paid,
            COALESCE(SUM(
                CASE 
                    WHEN si.gross_amount > 0 AND (si.net_amount - si.paid_amount) > 0 
                    THEN (sii.line_total / si.gross_amount) * (si.net_amount - si.paid_amount) 
                    ELSE 0 
                END
            ), 0) as credit_extended
        FROM sales_invoice_items sii
        JOIN sales_invoices si ON sii.invoice_id = si.id
        JOIN products p ON sii.product_id = p.id
        WHERE {$invWhereSupSql}
    ", $invParamsSup) ?: [
        'total_bills' => 0, 'total_gross' => 0.0, 'total_discounts' => 0.0,
        'total_net' => 0.0, 'spot_cash_paid' => 0.0, 'credit_extended' => 0.0
    ];
} else {
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
}

// Company-Wise Sales & Udhar Breakdown (Always computed for executive multi-brand analysis)
$companyBreakdown = db_fetch_all("
    SELECT 
        s.id as supplier_id,
        s.supplier_name,
        s.company_name,
        COUNT(DISTINCT si.id) as bills_count,
        COALESCE(SUM(sii.line_total), 0) as sales_amount,
        COALESCE(SUM(sii.discount_amount), 0) as discount_amount,
        COALESCE(SUM(
            CASE 
                WHEN si.gross_amount > 0 AND si.paid_amount > 0 
                THEN (sii.line_total / si.gross_amount) * si.paid_amount 
                ELSE 0 
            END
        ), 0) as cash_received,
        COALESCE(SUM(
            CASE 
                WHEN si.gross_amount > 0 AND (si.net_amount - si.paid_amount) > 0 
                THEN (sii.line_total / si.gross_amount) * (si.net_amount - si.paid_amount) 
                ELSE 0 
            END
        ), 0) as udhar_extended
    FROM sales_invoice_items sii
    JOIN sales_invoices si ON sii.invoice_id = si.id
    JOIN products p ON sii.product_id = p.id
    JOIN suppliers s ON p.supplier_id = s.id
    WHERE {$invWhereSql}
    GROUP BY s.id, s.supplier_name, s.company_name
    ORDER BY s.id ASC
", $invParams);

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

if ($supplierFilter > 0) {
    $retWhereSup = $retWhere;
    $retWhereSup[] = "p.supplier_id = ?";
    $retParamsSup = $retParams;
    $retParamsSup[] = $supplierFilter;
    $retWhereSupSql = implode(" AND ", $retWhereSup);

    $returnsSummary = db_fetch_one("
        SELECT 
            COUNT(DISTINCT sr.id) as return_count,
            COALESCE(SUM(sri.amount), 0) as total_returns
        FROM sales_return_items sri
        JOIN sales_returns sr ON sri.sales_return_id = sr.id
        JOIN products p ON sri.product_id = p.id
        JOIN customers c ON sr.customer_id = c.id
        WHERE {$retWhereSupSql}
    ", $retParamsSup) ?: ['return_count' => 0, 'total_returns' => 0.0];
} else {
    $returnsSummary = db_fetch_one("
        SELECT 
            COUNT(sr.id) as return_count,
            COALESCE(SUM(sr.total_amount), 0) as total_returns
        FROM sales_returns sr
        JOIN customers c ON sr.customer_id = c.id
        WHERE {$retWhereSql}
    ", $retParams) ?: ['return_count' => 0, 'total_returns' => 0.0];
}

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

// 5.5 Partner Cash Movements (Sales Handover & Cash Received Back)
$managingPartner = get_managing_partner();
$allPartners = get_partners(true);

$partnerCashSummary = db_fetch_one("
    SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'sales_cash_handover' THEN debit ELSE 0 END), 0) as total_handover,
        COALESCE(SUM(CASE WHEN transaction_type = 'cash_returned' THEN credit ELSE 0 END), 0) as total_returned
    FROM partner_transactions
    WHERE transaction_date BETWEEN ? AND ?
", [$fromDate, $toDate]) ?: ['total_handover' => 0.0, 'total_returned' => 0.0];

$partnerHandoversToday = db_fetch_all("
    SELECT pt.*, p.partner_name, p.urdu_name, p.partner_code, u.full_name as creator_name
    FROM partner_transactions pt
    JOIN partners p ON pt.partner_id = p.id
    LEFT JOIN users u ON pt.created_by = u.id
    WHERE pt.transaction_type IN ('sales_cash_handover', 'cash_returned')
      AND pt.transaction_date BETWEEN ? AND ?
    ORDER BY pt.id DESC
", [$fromDate, $toDate]);

// 6. Item-Wise Sales Breakdown & Estimated Profit / COGS
$itemWhere = $invWhere;
$itemParams = $invParams;
if ($supplierFilter > 0) {
    $itemWhere[] = "p.supplier_id = ?";
    $itemParams[] = $supplierFilter;
}
$itemWhereSql = implode(" AND ", $itemWhere);

$itemBreakdown = db_fetch_all("
    SELECT 
        p.id as product_id,
        p.product_code,
        p.product_name,
        p.supplier_id,
        s.supplier_name,
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
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN product_categories cat ON p.category_id = cat.id
    WHERE {$itemWhereSql}
    GROUP BY p.id, p.product_code, p.product_name, p.supplier_id, s.supplier_name, cat.category_name, p.carton_qty, p.purchase_rate
    ORDER BY total_sale_amount DESC
", $itemParams);

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
if ($supplierFilter > 0) {
    $invoicesList = db_fetch_all("
        SELECT 
            si.*, 
            c.shop_name, 
            a.area_name, 
            b.booker_name,
            sm.salesman_name,
            COALESCE(SUM(sii.line_total), 0) as supplier_items_total
        FROM sales_invoices si
        JOIN sales_invoice_items sii ON si.id = sii.invoice_id
        JOIN products p ON sii.product_id = p.id
        JOIN customers c ON si.customer_id = c.id
        LEFT JOIN areas a ON si.area_id = a.id
        LEFT JOIN bookers b ON si.booker_id = b.id
        LEFT JOIN salesmen sm ON si.salesman_id = sm.id
        WHERE {$invWhereSql} AND p.supplier_id = ?
        GROUP BY si.id
        ORDER BY si.invoice_date DESC, si.id DESC
    ", array_merge($invParams, [$supplierFilter]));
} else {
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
}

// 8. Detailed Daily Expenses List
$expensesList = db_fetch_all("
    SELECT e.*, ec.category_name, a.account_name, u.full_name as creator_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.expense_category_id = ec.id
    LEFT JOIN accounts a ON e.paid_from_account_id = a.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
", [$fromDate, $toDate]);

// Master lists for filters
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 ORDER BY area_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");
$closingLiquidAccounts = db_fetch_all("
    SELECT id, account_code, account_name, sub_type, bank_name, current_balance 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank', 'wallet') AND is_active = 1 
    ORDER BY CASE sub_type WHEN 'cash' THEN 1 WHEN 'bank' THEN 2 WHEN 'wallet' THEN 3 END, account_code ASC
");

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

                    <!-- Company / Supplier Filter -->
                    <div class="col-md-2">
                        <select name="supplier_id" class="form-select form-select-sm fw-semibold">
                            <option value="0">🏢 All Companies (اکٹھا)</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $supplierFilter === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?>
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

                <!-- Company Quick Switcher Pills -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 mt-2 border-top">
                    <div class="d-flex align-items-center flex-wrap gap-1">
                        <span class="small fw-bold text-secondary me-1"><i class="bi bi-building me-1 text-primary"></i> Company Filter (کمپنی وائز فلٹر):</span>
                        <a href="daily_closing.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&area_id=<?= $areaFilter ?>&supplier_id=0&tab=<?= $activeTab ?>" 
                           class="btn btn-xs rounded-pill px-3 <?= $supplierFilter === 0 ? 'btn-navy fw-bold shadow-sm' : 'btn-outline-secondary bg-white' ?>">
                            <i class="bi bi-grid-fill me-1"></i> Both Combined (دونوں اکٹھا)
                        </a>
                        <a href="daily_closing.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&area_id=<?= $areaFilter ?>&supplier_id=1&tab=<?= $activeTab ?>" 
                           class="btn btn-xs rounded-pill px-3 <?= $supplierFilter === 1 ? 'btn-success fw-bold shadow-sm' : 'btn-outline-success bg-white' ?>">
                            <i class="bi bi-box-seam me-1"></i> Ujab Enterprises (اجاب)
                        </a>
                        <a href="daily_closing.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&area_id=<?= $areaFilter ?>&supplier_id=2&tab=<?= $activeTab ?>" 
                           class="btn btn-xs rounded-pill px-3 <?= $supplierFilter === 2 ? 'btn-primary fw-bold shadow-sm' : 'btn-outline-primary bg-white' ?>">
                            <i class="bi bi-box-seam me-1"></i> SMS Supplier (ایس ایم ایس)
                        </a>
                    </div>
                    <?php if ($supplierFilter > 0): ?>
                        <span class="badge bg-warning text-dark px-3 py-1 border shadow-xs">
                            <i class="bi bi-funnel-fill me-1"></i> Showing records filtered for: <strong><?= $supplierFilter === 1 ? 'Ujab Enterprises' : 'SMS' ?></strong>
                        </span>
                    <?php endif; ?>
                </div>
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

        <!-- COMPANY BREAKDOWN COMPARISON WIDGET (SMS vs Ujab Enterprises) -->
        <?php if (!empty($companyBreakdown)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-navy"><i class="bi bi-buildings-fill text-primary me-2"></i>Company-Wise Sales & Market Udhar (کمپنی وائز سیلز اور ادھار سمری)</span>
                    <a href="company_sales.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" class="btn btn-xs btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Full Company Exposure Report
                    </a>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <?php foreach ($companyBreakdown as $cb): 
                            $isSMS = ((int)$cb['supplier_id'] === 2 || stripos($cb['supplier_name'], 'SMS') !== false);
                            $badgeColor = $isSMS ? 'primary' : 'success';
                            $borderClass = $isSMS ? 'border-primary' : 'border-success';
                        ?>
                            <div class="col-md-6">
                                <div class="p-3 rounded border border-2 <?= $borderClass ?> bg-light h-100 position-relative">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="badge bg-<?= $badgeColor ?> text-white fw-bold mb-1">
                                                <?= htmlspecialchars($cb['supplier_name']) ?>
                                            </span>
                                            <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($cb['company_name'] ?: $cb['supplier_name']) ?></h6>
                                        </div>
                                        <a href="daily_closing.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&area_id=<?= $areaFilter ?>&supplier_id=<?= $cb['supplier_id'] ?>&tab=<?= $activeTab ?>" 
                                           class="btn btn-xs btn-<?= $badgeColor ?> shadow-xs">
                                            <i class="bi bi-filter me-1"></i> View Only <?= htmlspecialchars($cb['supplier_name']) ?>
                                        </a>
                                    </div>

                                    <div class="row g-2 mt-2 pt-2 border-top">
                                        <div class="col-4">
                                            <div class="text-muted small">Company Sales:</div>
                                            <div class="fw-bold text-navy fs-6"><?= format_currency((float)$cb['sales_amount']) ?></div>
                                            <small class="text-muted"><?= number_format($cb['bills_count']) ?> bills</small>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-muted small">Cash Inflow:</div>
                                            <div class="fw-bold text-success fs-6"><?= format_currency((float)$cb['cash_received']) ?></div>
                                            <small class="text-muted">Proportional cash</small>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-muted small">Market Udhar (ادھار):</div>
                                            <div class="fw-bold text-danger fs-6"><?= format_currency((float)$cb['udhar_extended']) ?></div>
                                            <small class="text-danger">Unpaid balance</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

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
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'companies' ? 'active text-primary' : 'text-muted' ?>" 
                                id="companies-tab" data-bs-toggle="tab" data-bs-target="#companies-pane" type="button" role="tab">
                            <i class="bi bi-buildings-fill me-1"></i> 2. Company-Wise Sales & Udhar (کمپنی وائز حساب)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'items' ? 'active text-primary' : 'text-muted' ?>" 
                                id="items-tab" data-bs-toggle="tab" data-bs-target="#items-pane" type="button" role="tab">
                            <i class="bi bi-boxes me-1"></i> 3. Item-Wise Sales & Profitability (اشیاء اور منافع)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'bills' ? 'active text-primary' : 'text-muted' ?>" 
                                id="bills-tab" data-bs-toggle="tab" data-bs-target="#bills-pane" type="button" role="tab">
                            <i class="bi bi-receipt me-1"></i> 4. Customer Billing & Udhaar Register (بل اور ادھار)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'expenses' ? 'active text-primary' : 'text-muted' ?>" 
                                id="expenses-tab" data-bs-toggle="tab" data-bs-target="#expenses-pane" type="button" role="tab">
                            <i class="bi bi-cash-stack me-1"></i> 5. Daily Expenses (اخراجات)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body p-0">
                <div class="tab-content" id="closingTabsContent">
                    
                    <!-- TAB 1: CASH ROZNAMCHA / CASH FLOW RECONCILIATION -->
                    <div class="tab-pane fade <?= $activeTab === 'overview' ? 'show active' : '' ?>" id="overview-pane" role="tabpanel">
                        <div class="p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold text-navy mb-0"><i class="bi bi-cash-coin text-success me-2"></i>Day End Cash Reconciliation (یومیہ کیش روزنامچہ)</h6>
                                <div class="d-flex gap-2 no-print">
                                    <button type="button" class="btn btn-sm btn-danger shadow-xs" data-bs-toggle="modal" data-bs-target="#handoverCashModal">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Handover Cash to Partner (مینجنگ پارٹنر کو کیش سپردگی)
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success shadow-xs" data-bs-toggle="modal" data-bs-target="#receiveCashModal">
                                        <i class="bi bi-box-arrow-in-left me-1"></i> Receive Cash from Partner (پارٹنر سے کیش واپسی)
                                    </button>
                                </div>
                            </div>
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
                                        <?php if (is_partner_expense_sharing_enabled() && (float)$partnerCashSummary['total_returned'] > 0): ?>
                                        <tr>
                                            <td><i class="bi bi-arrow-down-left-circle-fill text-success me-2"></i> Cash Received Back from Partner (پارٹنر سے کیش واپسی / وصولی)</td>
                                            <td class="text-end text-success fw-bold"><?= format_currency((float)$partnerCashSummary['total_returned']) ?></td>
                                            <td class="text-end text-muted">-</td>
                                            <td class="text-end">-</td>
                                        </tr>
                                        <?php endif; ?>
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
                                        <?php if (is_partner_expense_sharing_enabled()): ?>
                                        <tr>
                                            <td>
                                                <i class="bi bi-person-fill-up text-danger me-2"></i> 
                                                Sales Cash Handed Over to Managing Partner (مینجنگ پارٹنر کو کیش سپردگی)
                                                <?php if ($managingPartner): ?>
                                                    <span class="badge bg-danger-subtle text-danger border ms-1 font-sans-serif"><?= htmlspecialchars($managingPartner['partner_name']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-muted">-</td>
                                            <td class="text-end text-danger fw-bold"><?= format_currency((float)$partnerCashSummary['total_handover']) ?></td>
                                            <td class="text-end">-</td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php 
                                        $totalDayInflow = (float)$salesSummary['spot_cash_paid'] + (float)$recoverySummary['cash_recovered'] + (float)$partnerCashSummary['total_returned'];
                                        $totalDayOutflow = (float)$expenseSummary['cash_expenses'] + (float)$supplierPaymentSummary['cash_supplier_paid'] + (float)$partnerCashSummary['total_handover'];
                                        $netCashDrawerMovement = $totalDayInflow - $totalDayOutflow;
                                        ?>
                                        <tr class="table-warning fw-bold fs-6">
                                            <td class="text-uppercase"><i class="bi bi-safe2 text-dark me-2"></i> Net Office Cash Drawer Balance (دراز میں باقی کیش)</td>
                                            <td class="text-end text-success"><?= format_currency($totalDayInflow) ?></td>
                                            <td class="text-end text-danger"><?= format_currency($totalDayOutflow) ?></td>
                                            <td class="text-end text-navy fw-bold"><?= format_currency($netCashDrawerMovement) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Managing Partner Cash Handover & Khata Position Widget -->
                            <?php if ($managingPartner && is_partner_expense_sharing_enabled()): 
                                $mpBal = (float)$managingPartner['current_balance'];
                            ?>
                            <div class="mt-4 pt-3 border-top">
                                <div class="card border border-danger-subtle bg-light shadow-xs mb-3">
                                    <div class="card-body p-3">
                                        <div class="row align-items-center">
                                            <div class="col-md-7">
                                                <div class="d-flex align-items-center mb-1">
                                                    <span class="badge bg-danger text-white me-2"><i class="bi bi-shield-lock-fill me-1"></i> Cash Custodian</span>
                                                    <h6 class="fw-bold mb-0 text-navy">
                                                        <?= htmlspecialchars($managingPartner['partner_name']) ?>
                                                        <?php if (!empty($managingPartner['urdu_name'])): ?>
                                                            <small class="text-muted ms-1">(<?= htmlspecialchars($managingPartner['urdu_name']) ?>)</small>
                                                        <?php endif; ?>
                                                    </h6>
                                                </div>
                                                <div class="small text-muted mb-2">
                                                    Daily sales cash handed over to Managing Partner is tracked in his personal Khata ledger.
                                                </div>
                                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                                    <span class="badge bg-white text-dark border font-monospace px-2 py-1">
                                                        Today's Handover: <strong class="text-danger"><?= format_currency((float)$partnerCashSummary['total_handover']) ?></strong>
                                                    </span>
                                                    <?php if ((float)$partnerCashSummary['total_returned'] > 0): ?>
                                                    <span class="badge bg-white text-dark border font-monospace px-2 py-1">
                                                        Today's Return: <strong class="text-success"><?= format_currency((float)$partnerCashSummary['total_returned']) ?></strong>
                                                    </span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-white text-dark border font-monospace px-2 py-1">
                                                        Current Khata Balance: 
                                                        <strong class="<?= $mpBal < 0 ? 'text-danger' : ($mpBal > 0 ? 'text-success' : 'text-muted') ?>">
                                                            <?= format_currency(abs($mpBal)) ?> 
                                                            <?= $mpBal < 0 ? '(Receivable / بقایا)' : ($mpBal > 0 ? '(Payable / واجب الادا)' : '(Nil)') ?>
                                                        </strong>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex flex-wrap justify-content-md-end gap-2 no-print">
                                                <button type="button" class="btn btn-danger btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#handoverCashModal">
                                                    <i class="bi bi-box-arrow-up-right me-1"></i> Handover Cash (کیش سپردگی)
                                                </button>
                                                <button type="button" class="btn btn-success btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#receiveCashModal">
                                                    <i class="bi bi-box-arrow-in-left me-1"></i> Receive Cash (کیش واپسی)
                                                </button>
                                                <a href="<?= APP_URL ?>/modules/partners/ledger.php?partner_id=<?= $managingPartner['id'] ?>" class="btn btn-outline-secondary btn-sm shadow-xs" title="View Full Khata Ledger">
                                                    <i class="bi bi-journal-text me-1"></i> Khata Ledger
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Today's Handover & Return Details Table (if any) -->
                                    <?php if (!empty($partnerHandoversToday)): ?>
                                    <div class="border-top bg-white p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="small fw-bold text-navy"><i class="bi bi-clock-history me-1 text-danger"></i>Recorded Partner Cash Movements for Period (درج شدہ سپردگی و واپسی):</span>
                                            <span class="badge bg-secondary-subtle text-secondary font-monospace"><?= count($partnerHandoversToday) ?> records</span>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered align-middle mb-0 font-monospace small">
                                                <thead class="table-light text-muted">
                                                    <tr>
                                                        <th style="width: 110px;">Date</th>
                                                        <th>Txn #</th>
                                                        <th>Partner</th>
                                                        <th>Type (قسم)</th>
                                                        <th>Description / Remarks</th>
                                                        <th class="text-end">Amount (PKR)</th>
                                                        <th class="text-center">Recorded By</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($partnerHandoversToday as $pht): 
                                                        $isHandover = ($pht['transaction_type'] === 'sales_cash_handover');
                                                    ?>
                                                    <tr>
                                                        <td><?= format_date($pht['transaction_date']) ?></td>
                                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($pht['transaction_number']) ?></span></td>
                                                        <td class="fw-semibold">
                                                            <?= htmlspecialchars($pht['partner_name']) ?>
                                                            <?php if (!empty($pht['urdu_name'])): ?>
                                                                <small class="text-muted">(<?= htmlspecialchars($pht['urdu_name']) ?>)</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($isHandover): ?>
                                                                <span class="badge bg-danger text-white">Sales Cash Handover (سپردگی)</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success text-white">Cash Returned (واپسی)</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-muted"><?= htmlspecialchars($pht['description']) ?></td>
                                                        <td class="text-end fw-bold <?= $isHandover ? 'text-danger' : 'text-success' ?>">
                                                            <?= $isHandover ? '-' : '+' ?><?= format_currency($isHandover ? (float)$pht['debit'] : (float)$pht['credit']) ?>
                                                        </td>
                                                        <td class="text-center text-muted"><?= htmlspecialchars($pht['creator_name'] ?? 'Admin') ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Live Liquid Accounts Balance Position -->
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold text-navy mb-0"><i class="bi bi-wallet2 me-2"></i>Current Liquid Position (موجودہ کیش، بینک اور والٹس پوزیشن)</h6>
                                    <a href="<?= APP_URL ?>/modules/accounts/manage_accounts.php" class="small text-decoration-none fw-semibold no-print">
                                        Manage Accounts <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($closingLiquidAccounts as $cla): ?>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="p-2 border rounded bg-light">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="small fw-semibold text-truncate"><?= htmlspecialchars($cla['account_name']) ?></span>
                                                    <span class="badge bg-secondary-subtle text-secondary font-monospace"><?= htmlspecialchars($cla['account_code']) ?></span>
                                                </div>
                                                <div class="h6 fw-bold mb-0 mt-1 <?= (float)$cla['current_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= format_currency((float)$cla['current_balance']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: COMPANY-WISE SALES & MARKET UDHAR BREAKDOWN -->
                    <div class="tab-pane fade <?= $activeTab === 'companies' ? 'show active' : '' ?>" id="companies-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">Multi-Brand Separation: Company sales revenue, cash recoveries, and market credit (ادھار) balance</span>
                            <a href="company_sales.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" class="btn btn-xs btn-primary shadow-xs">
                                <i class="bi bi-box-arrow-up-right me-1"></i> Open In-Depth Company Exposure Report
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0 font-monospace small">
                                <thead class="table-light text-uppercase">
                                    <tr>
                                        <th>Company / Supplier</th>
                                        <th>Legal / Trading Entity</th>
                                        <th class="text-center">Bills Count</th>
                                        <th class="text-end">Gross Sales</th>
                                        <th class="text-end">Discounts</th>
                                        <th class="text-end">Cash Inflow (نقد وصولی)</th>
                                        <th class="text-end">Market Udhar (مارکیٹ ادھار)</th>
                                        <th class="text-end">Share %</th>
                                        <th class="text-center no-print">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($companyBreakdown)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-5 text-muted">
                                                <i class="bi bi-buildings fs-1 d-block mb-2 text-secondary"></i>
                                                No company sales data found for the selected period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $cbTotalSales = 0; $cbTotalCash = 0; $cbTotalUdhar = 0; $cbTotalDisc = 0;
                                        foreach ($companyBreakdown as $c) {
                                            $cbTotalSales += (float)$c['sales_amount'];
                                            $cbTotalCash  += (float)$c['cash_received'];
                                            $cbTotalUdhar += (float)$c['udhar_extended'];
                                            $cbTotalDisc  += (float)$c['discount_amount'];
                                        }
                                        ?>
                                        <?php foreach ($companyBreakdown as $c): 
                                            $isSMS = ((int)$c['supplier_id'] === 2 || stripos($c['supplier_name'], 'SMS') !== false);
                                            $pct = $cbTotalSales > 0 ? round(((float)$c['sales_amount'] / $cbTotalSales) * 100, 1) : 0;
                                        ?>
                                            <tr>
                                                <td class="fw-bold font-sans">
                                                    <span class="badge bg-<?= $isSMS ? 'primary' : 'success' ?> text-white me-1">
                                                        <?= $isSMS ? 'SMS' : 'UJAB' ?>
                                                    </span>
                                                    <?= htmlspecialchars($c['supplier_name']) ?>
                                                </td>
                                                <td><?= htmlspecialchars($c['company_name'] ?: $c['supplier_name']) ?></td>
                                                <td class="text-center fw-bold"><?= number_format($c['bills_count']) ?></td>
                                                <td class="text-end fw-bold text-navy"><?= format_currency((float)$c['sales_amount']) ?></td>
                                                <td class="text-end text-muted"><?= format_currency((float)$c['discount_amount']) ?></td>
                                                <td class="text-end fw-bold text-success"><?= format_currency((float)$c['cash_received']) ?></td>
                                                <td class="text-end fw-bold text-danger"><?= format_currency((float)$c['udhar_extended']) ?></td>
                                                <td class="text-end">
                                                    <div class="d-flex align-items-center justify-content-end gap-1">
                                                        <span class="fw-bold"><?= $pct ?>%</span>
                                                        <div class="progress" style="width: 45px; height: 6px;">
                                                            <div class="progress-bar bg-<?= $isSMS ? 'primary' : 'success' ?>" style="width: <?= $pct ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center no-print">
                                                    <a href="daily_closing.php?period=<?= $period ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&area_id=<?= $areaFilter ?>&supplier_id=<?= $c['supplier_id'] ?>" 
                                                       class="btn btn-xs btn-outline-dark">
                                                        <i class="bi bi-funnel me-1"></i> Filter Daily Roznamcha
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-light fw-bold fs-6">
                                            <td colspan="3" class="text-uppercase text-end">Total Distribution:</td>
                                            <td class="text-end text-navy"><?= format_currency($cbTotalSales) ?></td>
                                            <td class="text-end text-muted"><?= format_currency($cbTotalDisc) ?></td>
                                            <td class="text-end text-success"><?= format_currency($cbTotalCash) ?></td>
                                            <td class="text-end text-danger"><?= format_currency($cbTotalUdhar) ?></td>
                                            <td class="text-end">100%</td>
                                            <td></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 3: ITEM-WISE SALES & PROFITABILITY BREAKDOWN -->
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
                                        <th>Company</th>
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
                                            <td colspan="11" class="text-center py-5 text-muted">
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
                                            $isSMS = ((int)$row['supplier_id'] === 2 || stripos($row['supplier_name'] ?? '', 'SMS') !== false);
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['product_code']) ?></span></td>
                                                <td class="fw-bold font-sans"><?= htmlspecialchars($row['product_name']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $isSMS ? 'primary' : 'success' ?>-subtle text-<?= $isSMS ? 'primary' : 'success' ?> border border-<?= $isSMS ? 'primary' : 'success' ?>-subtle">
                                                        <?= htmlspecialchars($row['supplier_name'] ?? 'Ujab') ?>
                                                    </span>
                                                </td>
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
                                            <td colspan="4" class="text-uppercase text-end">Grand Totals:</td>
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
                                                <td class="font-sans"><?= htmlspecialchars($exp['payee_name'] ?? $exp['paid_to'] ?? '-') ?></td>
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

<!-- MODAL: HANDOVER CASH TO MANAGING PARTNER -->
<div class="modal fade" id="handoverCashModal" tabindex="-1" aria-labelledby="handoverCashModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="daily_closing.php">
                <input type="hidden" name="action" value="partner_cash_handover">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">

                <div class="modal-header bg-danger text-white py-3">
                    <h6 class="modal-title fw-bold" id="handoverCashModalLabel">
                        <i class="bi bi-box-arrow-up-right me-2"></i>Handover Sales Cash to Managing Partner (مینجنگ پارٹنر کو کیش سپردگی)
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-danger-subtle border-danger-subtle small py-2 px-3 mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        Daily sales revenue handed over will be deducted from office cash drawer and charged to the partner's Khata (receivable from partner).
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-navy">Managing Partner (پارٹنر) <span class="text-danger">*</span></label>
                        <select name="partner_id" class="form-select" required>
                            <?php foreach ($allPartners as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($managingPartner && (int)$managingPartner['id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['partner_name']) ?> <?= !empty($p['urdu_name']) ? '(' . htmlspecialchars($p['urdu_name']) . ')' : '' ?> [<?= htmlspecialchars($p['partner_code']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-navy">Handover Date (تاریخ) <span class="text-danger">*</span></label>
                            <input type="date" name="transaction_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-navy">Amount (رقم - PKR) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" step="0.01" min="1" class="form-control form-control-lg fw-bold text-danger" placeholder="0.00" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-navy">Paid From Drawer / Account (کھاتہ) <span class="text-danger">*</span></label>
                        <select name="paid_from_account_id" class="form-select" required>
                            <?php foreach ($closingLiquidAccounts as $cla): ?>
                                <option value="<?= $cla['id'] ?>" <?= ($cla['account_code'] === '1001' || $cla['account_code'] === '1010' || $cla['sub_type'] === 'cash') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cla['account_name']) ?> (<?= htmlspecialchars($cla['account_code']) ?>) - Bal: <?= format_currency((float)$cla['current_balance']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-navy">Remarks / Notes (تفصیل / وجہ)</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="e.g. Daily sales cash collection handover to managing partner at closing..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">
                        <i class="bi bi-check-circle me-1"></i> Save Cash Handover (سپردگی درج کریں)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: RECEIVE CASH BACK FROM PARTNER -->
<div class="modal fade" id="receiveCashModal" tabindex="-1" aria-labelledby="receiveCashModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="daily_closing.php">
                <input type="hidden" name="action" value="partner_cash_return">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">

                <div class="modal-header bg-success text-white py-3">
                    <h6 class="modal-title fw-bold" id="receiveCashModalLabel">
                        <i class="bi bi-box-arrow-in-left me-2"></i>Receive Cash from Partner (پارٹنر سے کیش واپسی / وصولی)
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-success-subtle border-success-subtle small py-2 px-3 mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        Cash returned by partner will be deposited into the office drawer/bank and credited to the partner's Khata (settling drawn funds).
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-navy">Partner Returning Cash (پارٹنر) <span class="text-danger">*</span></label>
                        <select name="partner_id" class="form-select" required>
                            <?php foreach ($allPartners as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($managingPartner && (int)$managingPartner['id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['partner_name']) ?> <?= !empty($p['urdu_name']) ? '(' . htmlspecialchars($p['urdu_name']) . ')' : '' ?> [<?= htmlspecialchars($p['partner_code']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-navy">Date (تاریخ) <span class="text-danger">*</span></label>
                            <input type="date" name="transaction_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-navy">Amount (رقم - PKR) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" step="0.01" min="1" class="form-control form-control-lg fw-bold text-success" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-bold text-navy">Deposit To Account (کھاتہ) <span class="text-danger">*</span></label>
                            <select name="deposit_to_account_id" class="form-select" required>
                                <?php foreach ($closingLiquidAccounts as $cla): ?>
                                    <option value="<?= $cla['id'] ?>" <?= ($cla['account_code'] === '1001' || $cla['account_code'] === '1010' || $cla['sub_type'] === 'cash') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cla['account_name']) ?> (<?= htmlspecialchars($cla['account_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-bold text-navy">Payment Mode</label>
                            <select name="payment_mode" class="form-select">
                                <option value="cash" selected>Cash (نقد)</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-navy">Remarks / Notes (تفصیل / وجہ)</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="e.g. Cash returned by partner for expenses or reimbursement..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm fw-bold">
                        <i class="bi bi-check-circle me-1"></i> Save Cash Received (وصولی درج کریں)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
