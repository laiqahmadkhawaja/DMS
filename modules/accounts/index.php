<?php
/**
 * DMS - Distribution Management System
 * Chart of Accounts Explorer & Financial Overview
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('accounts_view');

$pageTitle = "Chart of Accounts";
$activeMenu = "accounts";

// Fetch all active accounts
$accounts = db_fetch_all("
    SELECT a.*,
        (SELECT COUNT(jel.id) FROM journal_entry_lines jel WHERE jel.account_id = a.id) as total_txns
    FROM accounts a
    WHERE a.is_active = 1
    ORDER BY a.account_code ASC
");

// Calculate group totals
$totals = [
    'asset'     => 0.0,
    'liability' => 0.0,
    'equity'    => 0.0,
    'revenue'   => 0.0,
    'expense'   => 0.0
];

$accountsByType = [
    'asset'     => [],
    'liability' => [],
    'equity'    => [],
    'revenue'   => [],
    'expense'   => []
];

foreach ($accounts as $acc) {
    $type = $acc['account_type'];
    $bal  = (float)$acc['current_balance'];
    if (isset($totals[$type])) {
        $totals[$type] += $bal;
        $accountsByType[$type][] = $acc;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header & Quick Financial Actions -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-bank me-2"></i>Chart of Accounts
                </h4>
                <p class="text-muted small mb-0">Double-entry General Ledger structure, real-time balances, and account audit ledgers.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="customer_payments.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-cash-stack me-1"></i> Customer Receipts
                </a>
                <a href="supplier_payments.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-credit-card me-1"></i> Supplier Payments
                </a>
                <a href="../expenses/index.php" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-wallet2 me-1"></i> Operating Expenses
                </a>
                <a href="journal_create.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Journal Voucher
                </a>
            </div>
        </div>

        <!-- Metric Balance Overview Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Assets (1xxx)</div>
                    <div class="h5 fw-bold text-primary mt-1 mb-0"><?= format_currency($totals['asset']) ?></div>
                    <div class="small text-muted mt-1">Cash, Bank, Debtors, Stock</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Liabilities (2xxx)</div>
                    <div class="h5 fw-bold text-danger mt-1 mb-0"><?= format_currency($totals['liability']) ?></div>
                    <div class="small text-muted mt-1">Trade Payables / Creditors</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-secondary">
                    <div class="text-muted small fw-semibold text-uppercase">Equity (3xxx)</div>
                    <div class="h5 fw-bold text-dark mt-1 mb-0"><?= format_currency($totals['equity']) ?></div>
                    <div class="small text-muted mt-1">Owner Capital Investment</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="text-muted small fw-semibold text-uppercase">Total Revenue (4xxx)</div>
                    <div class="h5 fw-bold text-success mt-1 mb-0"><?= format_currency($totals['revenue']) ?></div>
                    <div class="small text-muted mt-1">Gross Sales Revenue</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="text-muted small fw-semibold text-uppercase">Total Expenses (5xxx/6xxx)</div>
                    <div class="h5 fw-bold text-warning-emphasis mt-1 mb-0"><?= format_currency($totals['expense']) ?></div>
                    <div class="small text-muted mt-1">COGS + Operational Overhead</div>
                </div>
            </div>
        </div>

        <!-- Accounts Grouped by Type -->
        <?php 
        $typeTitles = [
            'asset'     => ['title' => 'Assets (Resources Owned)', 'badge' => 'bg-primary', 'normal' => 'Debit (+) Normal'],
            'liability' => ['title' => 'Liabilities (Amounts Owed to Factories & Vendors)', 'badge' => 'bg-danger', 'normal' => 'Credit (+) Normal'],
            'equity'    => ['title' => 'Owner Capital & Equity', 'badge' => 'bg-secondary', 'normal' => 'Credit (+) Normal'],
            'revenue'   => ['title' => 'Revenue & Sales Accounts', 'badge' => 'bg-success', 'normal' => 'Credit (+) Normal'],
            'expense'   => ['title' => 'Cost of Goods Sold & Operating Expenses', 'badge' => 'bg-warning text-dark', 'normal' => 'Debit (+) Normal']
        ];
        ?>

        <?php foreach ($accountsByType as $typeKey => $accList): ?>
            <?php if (!empty($accList)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge <?= $typeTitles[$typeKey]['badge'] ?> px-2 py-1 text-uppercase">
                                <?= strtoupper($typeKey) ?>
                            </span>
                            <span class="fw-bold text-navy fs-6"><?= $typeTitles[$typeKey]['title'] ?></span>
                        </div>
                        <div class="small text-muted">
                            Normal Rule: <span class="fw-semibold text-dark"><?= $typeTitles[$typeKey]['normal'] ?></span>
                            <span class="mx-2">|</span>
                            Group Total: <strong class="text-navy"><?= format_currency($totals[$typeKey]) ?></strong>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 120px;">Code</th>
                                        <th>Account Name</th>
                                        <th>Sub Type</th>
                                        <th class="text-end">Opening Balance</th>
                                        <th class="text-end">Current Balance</th>
                                        <th class="text-center">Vouchers</th>
                                        <th class="text-center" style="width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accList as $row): ?>
                                        <tr>
                                            <td class="fw-bold text-navy"><?= htmlspecialchars($row['account_code']) ?></td>
                                            <td class="fw-semibold">
                                                <a href="ledger.php?account_id=<?= $row['id'] ?>" class="text-decoration-none text-navy">
                                                    <?= htmlspecialchars($row['account_name']) ?>
                                                </a>
                                                <?php if ($row['is_system']): ?>
                                                    <span class="badge bg-light text-muted border ms-1" title="Core System Account">System</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-secondary border text-capitalize">
                                                    <?= str_replace('_', ' ', $row['sub_type']) ?>
                                                </span>
                                            </td>
                                            <td class="text-end text-muted">
                                                <?= format_currency($row['opening_balance']) ?>
                                            </td>
                                            <td class="text-end fw-bold text-navy fs-6">
                                                <?= format_currency($row['current_balance']) ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-light text-dark border"><?= (int)$row['total_txns'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <a href="ledger.php?account_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="View General Ledger Statement">
                                                    <i class="bi bi-book me-1"></i> Ledger
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
