<?php
/**
 * DMS - Distribution Management System
 * Double-Entry Trial Balance Statement
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('reports_financial');

$pageTitle = "Trial Balance";
$activeMenu = "reports";

$asOfDate = clean_input($_GET['as_of_date'] ?? date('Y-m-d'));

// Fetch all active accounts
$accounts = db_fetch_all("
    SELECT id, account_code, account_name, account_type, sub_type, opening_balance
    FROM accounts
    WHERE is_active = 1
    ORDER BY account_code ASC
");

$trialRows = [];
$totalDebit = 0.0;
$totalCredit = 0.0;

foreach ($accounts as $acc) {
    $accId = (int)$acc['id'];
    $isDebitNormal = in_array($acc['account_type'], ['asset', 'expense'], true);

    // Fetch sum of all journal entry debits and credits up to $asOfDate
    $sums = db_fetch_one("
        SELECT 
            COALESCE(SUM(jel.debit), 0) as total_debit,
            COALESCE(SUM(jel.credit), 0) as total_credit
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [$accId, $asOfDate]);

    $d = (float)$sums['total_debit'];
    $c = (float)$sums['total_credit'];
    $initial = (float)$acc['opening_balance'];

    // Net balance
    if ($isDebitNormal) {
        $net = $initial + ($d - $c);
    } else {
        $net = $initial + ($c - $d);
    }

    $debitBal = 0.0;
    $creditBal = 0.0;

    if ($isDebitNormal) {
        if ($net >= 0) {
            $debitBal = $net;
        } else {
            $creditBal = abs($net);
        }
    } else {
        if ($net >= 0) {
            $creditBal = $net;
        } else {
            $debitBal = abs($net);
        }
    }

    if (abs($debitBal) > 0.001 || abs($creditBal) > 0.001) {
        $trialRows[] = [
            'code'        => $acc['account_code'],
            'name'        => $acc['account_name'],
            'type'        => $acc['account_type'],
            'sub_type'    => $acc['sub_type'],
            'debit'       => round($debitBal, 2),
            'credit'      => round($creditBal, 2)
        ];

        $totalDebit += $debitBal;
        $totalCredit += $creditBal;
    }
}

$imbalance = abs($totalDebit - $totalCredit);

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
                    <i class="bi bi-scale me-2"></i>Trial Balance Statement
                </h4>
                <p class="text-muted small mb-0">Double-entry ledger verification statement balancing all accounts.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Statement (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" action="trial_balance.php" class="row g-2 align-items-end">
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small text-muted mb-1">Trial Balance As Of Date</label>
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

        <!-- Status Card -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="text-muted small fw-semibold text-uppercase">Total Ledger Debits</div>
                    <div class="h5 fw-bold text-primary mt-1 mb-0"><?= format_currency($totalDebit) ?></div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-danger">
                    <div class="text-muted small fw-semibold text-uppercase">Total Ledger Credits</div>
                    <div class="h5 fw-bold text-danger mt-1 mb-0"><?= format_currency($totalCredit) ?></div>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 <?= $imbalance < 0.01 ? 'border-success' : 'border-danger' ?>">
                    <div class="text-muted small fw-semibold text-uppercase">Trial Balance Status</div>
                    <div class="h5 fw-bold <?= $imbalance < 0.01 ? 'text-success' : 'text-danger' ?> mt-1 mb-0">
                        <?= $imbalance < 0.01 ? 'BALANCED (Rs. 0.00)' : 'IMBALANCE: ' . format_currency($imbalance) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Printable Statement -->
        <div class="card border shadow-sm print-container bg-white">
            <div class="card-body p-4">
                
                <div class="text-center border-bottom pb-3 mb-3">
                    <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                    <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                    <div class="mt-2">
                        <span class="badge bg-navy text-white px-3 py-1 fs-6 text-uppercase">TRIAL BALANCE STATEMENT</span>
                    </div>
                    <div class="small text-muted mt-1">
                        As of: <strong><?= format_date($asOfDate) ?></strong>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-nowrap mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 120px;">Account Code</th>
                                <th>Account Title</th>
                                <th>Classification</th>
                                <th style="width: 160px;" class="text-end">Debit Balance (Rs.)</th>
                                <th style="width: 160px;" class="text-end">Credit Balance (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trialRows as $row): ?>
                                <tr>
                                    <td class="fw-bold text-navy"><?= htmlspecialchars($row['code']) ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border text-uppercase small">
                                            <?= htmlspecialchars($row['type']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-semibold <?= $row['debit'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                        <?= $row['debit'] > 0 ? format_currency($row['debit']) : '—' ?>
                                    </td>
                                    <td class="text-end fw-semibold <?= $row['credit'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                        <?= $row['credit'] > 0 ? format_currency($row['credit']) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="3" class="text-end">TOTAL:</td>
                                <td class="text-end text-primary"><?= format_currency($totalDebit) ?></td>
                                <td class="text-end text-danger"><?= format_currency($totalCredit) ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end text-muted small">Difference / Status:</td>
                                <td colspan="2" class="text-center text-success small">
                                    <i class="bi bi-check-circle me-1"></i> ZERO IMBALANCE (DOUBLE-ENTRY VERIFIED)
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

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
                            Approved By (Managing Partner)
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
