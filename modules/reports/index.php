<?php
/**
 * DMS - Distribution Management System
 * Reports Center & Business Intelligence Hub
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('reports_daily');

$pageTitle = "Reports & Analytics Center";
$activeMenu = "reports";

// Real-time quick snapshot metrics
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$monthSales = (float)db_fetch_val("
    SELECT COALESCE(SUM(net_amount), 0) 
    FROM sales_invoices 
    WHERE invoice_date >= ? AND invoice_date <= ? AND status != 'cancelled'
", [$firstOfMonth, $today]);

$todaySales = (float)db_fetch_val("
    SELECT COALESCE(SUM(net_amount), 0) 
    FROM sales_invoices 
    WHERE invoice_date = ? AND status != 'cancelled'
", [$today]);

$totalReceivables = (float)db_fetch_val("
    SELECT COALESCE(SUM(current_balance), 0) 
    FROM customers 
    WHERE is_deleted = 0 AND current_balance > 0
");

$totalPayables = (float)db_fetch_val("
    SELECT COALESCE(SUM(current_balance), 0) 
    FROM suppliers 
    WHERE is_deleted = 0 AND current_balance > 0
");

$monthExpenses = (float)db_fetch_val("
    SELECT COALESCE(SUM(amount), 0) 
    FROM expenses 
    WHERE expense_date >= ? AND expense_date <= ?
", [$firstOfMonth, $today]);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-graph-up-arrow me-2"></i>Reports & Analytics Center
                </h4>
                <p class="text-muted small mb-0">Operational daily sales sheets, booker performance, receivables aging, and financial accounting statements.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../excel/index.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Excel Data Center
                </a>
            </div>
        </div>

        <!-- Quick Snapshot Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Today's Billing</div>
                    <div class="h5 fw-bold text-navy mt-1 mb-0"><?= format_currency($todaySales) ?></div>
                    <div class="small text-muted mt-1"><?= date('d M Y') ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Month-to-Date Sales</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($monthSales) ?></div>
                    <div class="small text-muted mt-1">Since <?= date('01 M Y') ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Market Receivables</div>
                    <div class="h5 fw-bold text-warning-emphasis mt-1 mb-0"><?= format_currency($totalReceivables) ?></div>
                    <div class="small text-muted mt-1">Customer credit balances</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Factory Payables</div>
                    <div class="h5 fw-bold text-danger mt-1 mb-0"><?= format_currency($totalPayables) ?></div>
                    <div class="small text-muted mt-1">Trade creditors</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="text-muted small fw-semibold text-uppercase">MTD Expenses</div>
                    <div class="h5 fw-bold text-info mt-1 mb-0"><?= format_currency($monthExpenses) ?></div>
                    <div class="small text-muted mt-1">Operating costs</div>
                </div>
            </div>
        </div>

        <!-- Featured Master Closing Sheet Banner -->
        <div class="card border-0 shadow-sm mb-4 bg-gradient bg-navy text-white">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-warning text-dark fw-bold text-uppercase px-2 py-1">Master Closing Sheet</span>
                            <span class="text-light small"><i class="bi bi-clock me-1"></i> End of Day Settlement</span>
                        </div>
                        <h4 class="fw-bold mb-2 text-white">Daily Business Closing Sheet & Roznamcha (یومیہ کلوزنگ شیٹ)</h4>
                        <p class="text-light mb-0 small opacity-75">
                            Unified executive audit: Today's sales revenue, gross profit & margins, cash recoveries, customer udhaar extended, sales returns, and cash in hand closing balance.
                        </p>
                    </div>
                    <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                        <div class="d-flex flex-wrap justify-content-lg-end gap-2">
                            <a href="daily_closing.php?period=today" class="btn btn-warning text-dark fw-bold px-3 shadow-sm">
                                <i class="bi bi-journal-bookmark-fill me-1"></i> Today's Closing
                            </a>
                            <a href="daily_closing.php?period=week" class="btn btn-outline-light px-3">
                                <i class="bi bi-calendar-week me-1"></i> This Week
                            </a>
                            <a href="daily_closing.php?period=month" class="btn btn-outline-light px-3">
                                <i class="bi bi-calendar-month me-1"></i> This Month
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Categories Grid -->
        <div class="row g-4">
            
            <!-- Category 1: Daily Operational Registers -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                        <span class="badge bg-primary p-2"><i class="bi bi-calendar-check fs-6 text-white"></i></span>
                        <div>
                            <h6 class="mb-0 fw-bold text-navy">Daily Operational & Logistics Registers</h6>
                            <div class="text-muted small">Daily delivery reconciliations matching client spreadsheet formats</div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            
                            <a href="daily_closing.php?period=today" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center bg-primary-subtle bg-opacity-25">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Daily Closing Sheet & Roznamcha
                                        <span class="badge bg-danger ms-2">Featured Master Sheet</span>
                                    </div>
                                    <div class="text-muted small">All-in-one day closing: Sales, Gross Profit, Cash in hand, Udhaar, Returns & Expenses.</div>
                                </div>
                                <i class="bi bi-chevron-right text-primary fw-bold"></i>
                            </a>

                            <a href="daily_sales.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-table text-primary me-2"></i>Daily Sales Register
                                        <span class="badge bg-warning text-dark ms-2">Photo 1 & 8 Format</span>
                                    </div>
                                    <div class="text-muted small">Daily date-wise breakdown: Total Invoices, Cash Received, Credit Issued, and Sales Returns.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="booker_sales.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-person-badge text-primary me-2"></i>Order Booker Performance Register
                                    </div>
                                    <div class="text-muted small">Sales volume, carton quantities, net revenue, and commissions by individual order booker.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="salesman_delivery.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-truck text-primary me-2"></i>Delivery Salesman Recovery Register
                                        <span class="badge bg-info text-dark ms-2">Photo 4 Format</span>
                                    </div>
                                    <div class="text-muted small">Route dispatches, bills delivered, cash brought to office, credit given, and return goods.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="product_sales.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-box-seam text-primary me-2"></i>Product-Wise Sales Summary
                                    </div>
                                    <div class="text-muted small">Units and cartons sold per item, revenue contribution, and sales return percentage.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Category 2: Financial Statements & General Ledger -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                        <span class="badge bg-success p-2"><i class="bi bi-bank fs-6 text-white"></i></span>
                        <div>
                            <h6 class="mb-0 fw-bold text-navy">Financial Statements & Accounting Reports</h6>
                            <div class="text-muted small">Double-entry audit statements, profit analysis, and balance sheet</div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            
                            <a href="trial_balance.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-scale text-success me-2"></i>Trial Balance
                                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-2">Zero Imbalance</span>
                                    </div>
                                    <div class="text-muted small">Comprehensive verification of total debits vs total credits across all accounts.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="profit_loss.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-currency-dollar text-success me-2"></i>Profit & Loss Statement (Income Statement)
                                    </div>
                                    <div class="text-muted small">Sales Revenue - Discounts - Returns - COGS = Gross Profit, minus Operating Expenses = Net Profit.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="balance_sheet.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-briefcase text-success me-2"></i>Balance Sheet (Statement of Financial Position)
                                    </div>
                                    <div class="text-muted small">Enterprise solvency: Total Assets = Total Liabilities + Owner Equity.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="../accounts/ledger.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-book text-success me-2"></i>General Ledger Account Statement
                                    </div>
                                    <div class="text-muted small">Running balance audit ledger for any account in the Chart of Accounts.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Category 3: Customers & Parties Credit -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                        <span class="badge bg-warning p-2"><i class="bi bi-person-lines-fill fs-6 text-dark"></i></span>
                        <div>
                            <h6 class="mb-0 fw-bold text-navy">Customer Credit & Receivables Analysis</h6>
                            <div class="text-muted small">Retail customer balance sheets and aging analysis</div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            
                            <a href="customer_aging.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-hourglass-split text-warning me-2"></i>Customer Receivables Aging Analysis
                                    </div>
                                    <div class="text-muted small">Categorized overdue credit: 0-15 days, 16-30 days, 31-60 days, and 60+ days overdue.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="../customers/statement.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-file-earmark-spreadsheet text-warning me-2"></i>Parties Credit Balance Sheet
                                        <span class="badge bg-secondary ms-2">Photo 6 Format</span>
                                    </div>
                                    <div class="text-muted small">Exact replication of the distributor's "Parties Credit" Excel recovery sheet.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Category 4: Excel Import & Export Center -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                        <span class="badge bg-success p-2"><i class="bi bi-file-earmark-excel fs-6 text-white"></i></span>
                        <div>
                            <h6 class="mb-0 fw-bold text-navy">Excel Import & Export Data Center</h6>
                            <div class="text-muted small">Native XLSX file generation and batch data migration</div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            
                            <a href="../excel/index.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-download text-success me-2"></i>Export Transactions to XLSX
                                    </div>
                                    <div class="text-muted small">Export daily sales, load sheets, customer balances, product catalog, and invoices into real Excel sheets.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                            <a href="../excel/import.php" class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-navy mb-1">
                                        <i class="bi bi-upload text-success me-2"></i>Import Masters & Opening Balances
                                    </div>
                                    <div class="text-muted small">Validate and import customer lists, product catalogs, and opening ledger balances from Excel.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>

                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
