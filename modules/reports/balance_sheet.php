<?php
/**
 * DMS - Distribution Management System
 * Balance Sheet (Statement of Financial Position)
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/database_helpers.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('reports_financial');

$pageTitle = "Balance Sheet";
$activeMenu = "reports";

$asOfDate = clean_input($_GET['as_of_date'] ?? date('Y-m-d'));

// Helper to get cumulative balance as of $asOfDate
function get_account_cumulative_balance(int $accountId, string $asOf): float {
    $row = db_fetch_one("
        SELECT 
            COALESCE(SUM(jel.debit), 0) as d,
            COALESCE(SUM(jel.credit), 0) as c
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [$accountId, $asOf]);

    $d = (float)$row['d'];
    $c = (float)$row['c'];

    $acc = db_fetch_one("SELECT account_type, opening_balance FROM accounts WHERE id = ?", [$accountId]);
    $initial = (float)($acc['opening_balance'] ?? 0);
    $type = $acc['account_type'] ?? 'asset';

    if (in_array($type, ['asset', 'expense'], true)) {
        return $initial + ($d - $c);
    } else {
        return $initial + ($c - $d);
    }
}

// 1. Assets
$cashOffice   = get_account_cumulative_balance(ACC_CASH_MAIN, $asOfDate);
$pettyCash    = get_account_cumulative_balance(ACC_PETTY_CASH, $asOfDate);
$bankBalance  = get_account_cumulative_balance(ACC_BANK_MAIN, $asOfDate);
$receivables  = get_account_cumulative_balance(ACC_RECEIVABLES, $asOfDate);
$inventoryVal = get_account_cumulative_balance(ACC_INVENTORY, $asOfDate);

$totalAssets = $cashOffice + $pettyCash + $bankBalance + $receivables + $inventoryVal;

// 2. Liabilities
$payables = get_account_cumulative_balance(ACC_PAYABLES, $asOfDate);
$totalLiabilities = $payables;

// 3. Equity
$capital = get_account_cumulative_balance(ACC_CAPITAL, $asOfDate);

// Cumulative Net Profit up to $asOfDate = Cumulative Revenues - Cumulative Expenses
$cumRevenues = (float)db_fetch_val("
    SELECT COALESCE(SUM(jel.credit - jel.debit), 0)
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.journal_entry_id = je.id
    JOIN accounts a ON jel.account_id = a.id
    WHERE a.account_type = 'revenue' AND je.entry_date <= ? AND je.is_posted = 1
", [$asOfDate]);

$cumExpenses = (float)db_fetch_val("
    SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.journal_entry_id = je.id
    JOIN accounts a ON jel.account_id = a.id
    WHERE a.account_type = 'expense' AND je.entry_date <= ? AND je.is_posted = 1
", [$asOfDate]);

$retainedEarnings = $cumRevenues - $cumExpenses;
$totalEquity = $capital + $retainedEarnings;

$totalLiabEquity = $totalLiabilities + $totalEquity;
$diff = abs($totalAssets - $totalLiabEquity);

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
                    <i class="bi bi-briefcase me-2"></i>Balance Sheet (Statement of Financial Position)
                </h4>
                <p class="text-muted small mb-0">Assets, trade liabilities, owner capital, and distributor net worth position.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Balance Sheet (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="balance_sheet.php" class="row g-2 align-items-end">
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Balance Sheet As Of Date</label>
                        <input type="date" name="as_of_date" class="form-control form-control-sm" value="<?= htmlspecialchars($asOfDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-calculator"></i> Calculate
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Printable Statement -->
        <div class="row justify-content-center">
            <div class="col-lg-9 col-md-11">
                <div class="card border shadow-sm print-container bg-white p-4">
                    
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                        <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                        <div class="mt-2">
                            <span class="badge bg-navy text-white px-3 py-1 fs-6 text-uppercase">STATEMENT OF FINANCIAL POSITION (BALANCE SHEET)</span>
                        </div>
                        <div class="small text-muted mt-1">
                            As of: <strong><?= format_date($asOfDate) ?></strong>
                        </div>
                    </div>

                    <table class="table table-sm table-bordered align-middle mb-4">
                        <thead class="table-light">
                            <tr>
                                <th>Assets, Liabilities & Equity</th>
                                <th style="width: 160px;" class="text-end">Sub-Total (Rs.)</th>
                                <th style="width: 180px;" class="text-end">Total (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            
                            <!-- 1. Assets Section -->
                            <tr class="table-light fw-bold">
                                <td colspan="3" class="text-primary"><i class="bi bi-building me-1"></i> 1. CURRENT ASSETS</td>
                            </tr>
                            <tr>
                                <td class="ps-4">Main Office Cash Drawer (1001)</td>
                                <td class="text-end"><?= format_currency($cashOffice) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Daily Route Delivery Cash Buffer (1002)</td>
                                <td class="text-end"><?= format_currency($pettyCash) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Bank Al-Habib / MCB Current Account (1010)</td>
                                <td class="text-end"><?= format_currency($bankBalance) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Accounts Receivable / Trade Debtors (1020)</td>
                                <td class="text-end"><?= format_currency($receivables) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Inventory Asset / Warehouse Stock (1030)</td>
                                <td class="text-end"><?= format_currency($inventoryVal) ?></td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold" style="background-color: #e3f2fd;">
                                <td class="ps-3 text-primary fs-6">TOTAL ASSETS:</td>
                                <td></td>
                                <td class="text-end text-primary fs-5"><?= format_currency($totalAssets) ?></td>
                            </tr>

                            <!-- 2. Liabilities Section -->
                            <tr class="table-light fw-bold">
                                <td colspan="3" class="text-danger"><i class="bi bi-credit-card me-1"></i> 2. CURRENT LIABILITIES</td>
                            </tr>
                            <tr>
                                <td class="ps-4">Accounts Payable / Manufacturing Trade Creditors (2010)</td>
                                <td class="text-end"><?= format_currency($payables) ?></td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold bg-light">
                                <td class="ps-3 text-danger">TOTAL LIABILITIES:</td>
                                <td></td>
                                <td class="text-end text-danger fs-6"><?= format_currency($totalLiabilities) ?></td>
                            </tr>

                            <!-- 3. Owner Equity Section -->
                            <tr class="table-light fw-bold">
                                <td colspan="3" class="text-success"><i class="bi bi-piggy-bank me-1"></i> 3. OWNER'S CAPITAL & EQUITY</td>
                            </tr>
                            <tr>
                                <td class="ps-4">Proprietor Capital Investment (3010)</td>
                                <td class="text-end"><?= format_currency($capital) ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Current Operating Surplus / Retained Earnings</td>
                                <td class="text-end"><?= format_currency($retainedEarnings) ?></td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold bg-light">
                                <td class="ps-3 text-success">TOTAL OWNER'S EQUITY:</td>
                                <td></td>
                                <td class="text-end text-success fs-6"><?= format_currency($totalEquity) ?></td>
                            </tr>

                            <!-- Total Liabilities & Equity -->
                            <tr class="fw-bold border-top-2" style="background-color: #e8f5e9;">
                                <td class="ps-3 text-navy fs-5">TOTAL LIABILITIES & EQUITY:</td>
                                <td class="text-end text-muted small">Assets = Liab + Equity</td>
                                <td class="text-end text-navy fs-5"><?= format_currency($totalLiabEquity) ?></td>
                            </tr>

                            <!-- Balancing Check -->
                            <tr class="table-light">
                                <td colspan="2" class="text-end small text-muted">Accounting Equation Verification:</td>
                                <td class="text-center text-success small fw-bold">
                                    <i class="bi bi-check-circle me-1"></i> <?= $diff < 0.01 ? 'PERFECTLY BALANCED' : 'IMBALANCE ' . format_currency($diff) ?>
                                </td>
                            </tr>

                        </tbody>
                    </table>

                    <div class="row pt-5 mt-4 text-center">
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Prepared By (Accountant)
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Internal Auditor
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Managing Proprietor
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
