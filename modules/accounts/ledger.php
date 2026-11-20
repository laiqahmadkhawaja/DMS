<?php
/**
 * DMS - Distribution Management System
 * General Ledger Account Statement
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';
require_once __DIR__ . '/../../includes/accounting_functions.php';

require_login();
require_permission('accounts_view');

$pageTitle = "General Ledger Statement";
$activeMenu = "general_ledger";

// Active Accounts for selector
$allAccounts = db_fetch_all("SELECT id, account_code, account_name, account_type FROM accounts WHERE is_active = 1 ORDER BY account_code ASC");

// Default to first account (e.g. 1001 Cash in Hand) or query param
$accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : (int)($allAccounts[0]['id'] ?? ACC_CASH_MAIN);

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$fromDate = clean_input($_GET['from_date'] ?? $firstOfMonth);
$toDate   = clean_input($_GET['to_date'] ?? $today);

$ledgerData = null;
$error = null;

try {
    $ledgerData = get_account_ledger($accountId, $fromDate, $toDate);
} catch (Exception $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header & Action Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-book me-2"></i>General Ledger Statement
                </h4>
                <p class="text-muted small mb-0">Audit-ready running balance statement for every account in the chart of accounts.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-bank me-1"></i> Chart of Accounts
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Statement (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="ledger.php" class="row g-2 align-items-end">
                    <div class="col-md-5 col-sm-12">
                        <label class="form-label small text-muted mb-1">Select Account</label>
                        <select name="account_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($allAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= $accountId === (int)$acc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> (<?= strtoupper($acc['account_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-3 col-sm-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel"></i> Load Statement
                        </button>
                        <a href="ledger.php?account_id=<?= $accountId ?>" class="btn btn-outline-secondary btn-sm" title="Reset Dates">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger shadow-sm">
                <i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($ledgerData): ?>
            
            <?php 
                $acc = $ledgerData['account'];
                $isDebitNormal = $ledgerData['is_debit_normal'];
            ?>

            <!-- Printable Statement Container -->
            <div class="card border shadow-sm print-container bg-white">
                <div class="card-body p-4">
                    
                    <!-- Statement Header -->
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                        <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                        <div class="mt-2">
                            <span class="badge bg-dark text-white px-3 py-1 fs-6 text-uppercase">GENERAL LEDGER STATEMENT</span>
                        </div>
                    </div>

                    <!-- Account Info & Date Range -->
                    <div class="row align-items-center mb-4 pb-2 border-bottom">
                        <div class="col-md-8 col-sm-7">
                            <div class="h5 fw-bold text-navy mb-1">
                                <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?>
                            </div>
                            <div class="small text-muted">
                                Classification: <strong class="text-dark text-uppercase"><?= htmlspecialchars($acc['account_type']) ?></strong>
                                (<?= htmlspecialchars(str_replace('_', ' ', $acc['sub_type'])) ?>)
                                <span class="mx-2">•</span>
                                Normal Balance Rule: 
                                <span class="badge <?= $isDebitNormal ? 'bg-primary' : 'bg-danger' ?>-subtle text-<?= $isDebitNormal ? 'primary' : 'danger' ?> border">
                                    <?= $isDebitNormal ? 'DEBIT (+)' : 'CREDIT (+)' ?> NORMAL
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-5 text-md-end text-start mt-md-0 mt-2">
                            <div class="small text-muted">Statement Period:</div>
                            <div class="fw-bold text-dark">
                                <?= format_date($fromDate) ?> <span class="text-muted">to</span> <?= format_date($toDate) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Ledger Table -->
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light text-nowrap">
                                <tr>
                                    <th style="width: 40px;" class="text-center">#</th>
                                    <th style="width: 100px;">Date</th>
                                    <th style="width: 110px;">Voucher #</th>
                                    <th style="width: 120px;">Reference</th>
                                    <th>Narration / Particulars</th>
                                    <th>Party / Link</th>
                                    <th style="width: 130px;" class="text-end">Debit (Rs.)</th>
                                    <th style="width: 130px;" class="text-end">Credit (Rs.)</th>
                                    <th style="width: 140px;" class="text-end">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Opening Balance Row -->
                                <tr class="table-light fw-bold">
                                    <td class="text-center">—</td>
                                    <td><?= format_date($fromDate) ?></td>
                                    <td class="text-muted text-center">—</td>
                                    <td class="text-muted">OPENING</td>
                                    <td colspan="2">Opening Balance brought forward</td>
                                    <td class="text-end">—</td>
                                    <td class="text-end">—</td>
                                    <td class="text-end text-navy fs-6">
                                        <?= format_currency($ledgerData['opening_balance']) ?>
                                    </td>
                                </tr>

                                <?php if (empty($ledgerData['lines'])): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            No transaction activity recorded for this account during the selected date range.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $sn = 1; foreach ($ledgerData['lines'] as $line): ?>
                                        <tr>
                                            <td class="text-center text-muted small"><?= $sn++ ?></td>
                                            <td class="text-nowrap"><?= format_date($line['entry_date']) ?></td>
                                            <td class="fw-bold text-nowrap">
                                                <a href="journal_view.php?id=<?= $line['journal_id'] ?>" class="text-decoration-none text-navy" title="View Journal Voucher">
                                                    <?= htmlspecialchars($line['entry_number']) ?>
                                                </a>
                                            </td>
                                            <td class="small text-nowrap">
                                                <span class="badge bg-light text-dark border text-uppercase">
                                                    <?= htmlspecialchars(str_replace('_', ' ', $line['reference_type'])) ?>
                                                </span>
                                            </td>
                                            <td class="small">
                                                <?= htmlspecialchars($line['line_desc'] ?: $line['narration']) ?>
                                            </td>
                                            <td class="small text-muted text-nowrap">
                                                <?= htmlspecialchars($line['party_name'] ?: '—') ?>
                                            </td>
                                            <td class="text-end fw-semibold <?= $line['debit'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                                <?= $line['debit'] > 0 ? format_currency($line['debit']) : '—' ?>
                                            </td>
                                            <td class="text-end fw-semibold <?= $line['credit'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                                <?= $line['credit'] > 0 ? format_currency($line['credit']) : '—' ?>
                                            </td>
                                            <td class="text-end fw-bold text-navy">
                                                <?= format_currency($line['running_balance']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="6" class="text-end">Total Period Activity:</td>
                                    <td class="text-end text-primary"><?= format_currency($ledgerData['total_debit']) ?></td>
                                    <td class="text-end text-danger"><?= format_currency($ledgerData['total_credit']) ?></td>
                                    <td class="text-end text-navy fs-6">
                                        <?= format_currency($ledgerData['closing_balance']) ?>
                                    </td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="6" class="text-end">Net Period Movement:</td>
                                    <td colspan="2" class="text-center text-muted">
                                        <?php 
                                            $net = $ledgerData['total_debit'] - $ledgerData['total_credit'];
                                            if (!$isDebitNormal) $net = -$net;
                                        ?>
                                        <?= $net >= 0 ? '+' : '' ?><?= format_currency($net) ?>
                                    </td>
                                    <td class="text-end fw-bold fs-6 <?= $ledgerData['closing_balance'] >= 0 ? 'text-navy' : 'text-danger' ?>">
                                        Closing: <?= format_currency($ledgerData['closing_balance']) ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Signatures -->
                    <div class="row pt-5 mt-4 text-center">
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Prepared By (Accountant)
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Verified By (Internal Auditor)
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Approved By (Proprietor / Partner)
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        <?php endif; ?>

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
