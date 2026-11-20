<?php
/**
 * DMS - Distribution Management System
 * Profit & Loss Statement (Income Statement)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('reports_financial');

$pageTitle = "Profit & Loss Statement";
$activeMenu = "reports";

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate   = clean_input($_GET['to_date'] ?? $today);

// Helper function to calculate net movement for an account during period
function get_account_period_net(int $accountId, string $from, string $to): float {
    $row = db_fetch_one("
        SELECT 
            COALESCE(SUM(jel.debit), 0) as d,
            COALESCE(SUM(jel.credit), 0) as c
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date >= ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [$accountId, $from, $to]);

    $d = (float)$row['d'];
    $c = (float)$row['c'];

    $type = db_fetch_val("SELECT account_type FROM accounts WHERE id = ?", [$accountId]);
    if (in_array($type, ['asset', 'expense'], true)) {
        return $d - $c;
    } else {
        return $c - $d;
    }
}

// 1. Revenues
$grossSales  = get_account_period_net(ACC_SALES, $fromDate, $toDate);
$discounts   = get_account_period_net(ACC_DISCOUNTS, $fromDate, $toDate);
$returns     = get_account_period_net(ACC_RETURNS, $fromDate, $toDate);
$netSales    = $grossSales - $discounts - $returns;

// 2. Cost of Goods Sold
$cogs = get_account_period_net(ACC_COGS, $fromDate, $toDate);

// If COGS is not recorded separately per sale line in this setup, calculate COGS from Sales Dispatches:
if ($cogs <= 0 && $netSales > 0) {
    // Calculate dispatch cost from stock movements
    $cogs = (float)db_fetch_val("
        SELECT COALESCE(SUM(total_cost), 0) 
        FROM stock_movements 
        WHERE movement_type = 'sales_dispatch' 
          AND movement_date >= '{$fromDate} 00:00:00' 
          AND movement_date <= '{$toDate} 23:59:59'
    ");
}

$grossProfit = $netSales - $cogs;
$grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 1) : 0;

// 3. Operating Expenses
$fuelExp   = get_account_period_net(6010, $fromDate, $toDate);
$salaryExp = get_account_period_net(6020, $fromDate, $toDate);
$rentExp   = get_account_period_net(6030, $fromDate, $toDate);
$maintExp  = get_account_period_net(6040, $fromDate, $toDate);
$officeExp = get_account_period_net(6050, $fromDate, $toDate);

$totalExpenses = $fuelExp + $salaryExp + $rentExp + $maintExp + $officeExp;
$netProfit = $grossProfit - $totalExpenses;
$netMargin = $netSales > 0 ? round(($netProfit / $netSales) * 100, 1) : 0;

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
                    <i class="bi bi-currency-dollar me-2"></i>Profit & Loss Statement (Income Statement)
                </h4>
                <p class="text-muted small mb-0">Operational profitability, gross trade margin, and operating overhead breakdown.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print P&L (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="profit_loss.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-calculator"></i> Calculate
                        </button>
                        <a href="profit_loss.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Metric Cards -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Net Sales Revenue</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($netSales) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Gross Profit</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($grossProfit) ?></div>
                    <div class="small text-muted mt-1">Margin: <?= $grossMargin ?>%</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Total Operating Costs</div>
                    <div class="h5 fw-bold text-warning-emphasis mt-1 mb-0"><?= format_currency($totalExpenses) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 <?= $netProfit >= 0 ? 'border-success' : 'border-danger' ?>">
                    <div class="text-muted small fw-semibold text-uppercase">Net Operating Income</div>
                    <div class="h5 fw-bold <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?> mt-1 mb-0"><?= format_currency($netProfit) ?></div>
                    <div class="small text-muted mt-1">Net Margin: <?= $netMargin ?>%</div>
                </div>
            </div>
        </div>

        <!-- Printable Financial Statement -->
        <div class="row justify-content-center">
            <div class="col-lg-9 col-md-11">
                <div class="card border shadow-sm print-container bg-white p-4">
                    
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                        <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                        <div class="mt-2">
                            <span class="badge bg-success text-white px-3 py-1 fs-6 text-uppercase">STATEMENT OF PROFIT & LOSS</span>
                        </div>
                        <div class="small text-muted mt-1">
                            For the Period: <strong><?= format_date($fromDate) ?></strong> to <strong><?= format_date($toDate) ?></strong>
                        </div>
                    </div>

                    <table class="table table-sm table-bordered align-middle mb-4">
                        <thead class="table-light">
                            <tr>
                                <th>Account Particulars</th>
                                <th style="width: 160px;" class="text-end">Sub-Total (Rs.)</th>
                                <th style="width: 180px;" class="text-end">Amount (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            
                            <!-- 1. Revenue Section -->
                            <tr class="table-light fw-bold">
                                <td colspan="3"><i class="bi bi-graph-up me-1 text-primary"></i> 1. OPERATING REVENUE</td>
                            </tr>
                            <tr>
                                <td class="ps-4">Gross Sales Revenue (4010)</td>
                                <td class="text-end"><?= format_currency($grossSales) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4 text-muted">Less: Sales Discounts Allowed (4020)</td>
                                <td class="text-end text-danger">(<?= format_currency($discounts) ?>)</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4 text-muted">Less: Sales Returns & Allowances (4030)</td>
                                <td class="text-end text-danger">(<?= format_currency($returns) ?>)</td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold bg-light">
                                <td class="ps-3 text-navy">NET SALES REVENUE:</td>
                                <td></td>
                                <td class="text-end text-navy fs-6"><?= format_currency($netSales) ?></td>
                            </tr>

                            <!-- 2. Cost of Sales -->
                            <tr class="table-light fw-bold">
                                <td colspan="3"><i class="bi bi-boxes me-1 text-secondary"></i> 2. COST OF GOODS SOLD</td>
                            </tr>
                            <tr>
                                <td class="ps-4">Cost of Goods Sold (COGS - 5010)</td>
                                <td class="text-end text-muted">(<?= format_currency($cogs) ?>)</td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold" style="background-color: #e8f5e9;">
                                <td class="ps-3 text-success fs-6">GROSS PROFIT:</td>
                                <td class="text-end text-muted small">Margin: <?= $grossMargin ?>%</td>
                                <td class="text-end text-success fs-5"><?= format_currency($grossProfit) ?></td>
                            </tr>

                            <!-- 3. Operating Expenses -->
                            <tr class="table-light fw-bold">
                                <td colspan="3"><i class="bi bi-wallet2 me-1 text-danger"></i> 3. OPERATING EXPENSES</td>
                            </tr>
                            <tr>
                                <td class="ps-4">Fuel & Van Transport (6010)</td>
                                <td class="text-end"><?= format_currency($fuelExp) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Staff Salaries & Daily Wages (6020)</td>
                                <td class="text-end"><?= format_currency($salaryExp) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Warehouse Rent & Utilities (6030)</td>
                                <td class="text-end"><?= format_currency($rentExp) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Vehicle Maintenance & Repairs (6040)</td>
                                <td class="text-end"><?= format_currency($maintExp) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">General Office & Tea Expenses (6050)</td>
                                <td class="text-end"><?= format_currency($officeExp) ?></td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold bg-light">
                                <td class="ps-3 text-danger">TOTAL OPERATING OVERHEAD:</td>
                                <td></td>
                                <td class="text-end text-danger fs-6">(<?= format_currency($totalExpenses) ?>)</td>
                            </tr>

                            <!-- 4. Net Profit -->
                            <tr class="fw-bold border-top-2" style="background-color: <?= $netProfit >= 0 ? '#d4edda' : '#f8d7da' ?>;">
                                <td class="ps-3 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?> fs-5">
                                    NET OPERATING <?= $netProfit >= 0 ? 'PROFIT' : 'LOSS' ?>:
                                </td>
                                <td class="text-end text-muted small">Net Margin: <?= $netMargin ?>%</td>
                                <td class="text-end <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?> fs-4">
                                    <?= format_currency($netProfit) ?>
                                </td>
                            </tr>

                        </tbody>
                    </table>

                    <div class="row pt-5 mt-4 text-center">
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Prepared By (Chief Accountant)
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Audited By
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Approved By (Proprietor)
                            </div>
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
