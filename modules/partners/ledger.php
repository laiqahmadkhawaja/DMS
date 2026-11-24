<?php
/**
 * DMS - Distribution Management System
 * Partner Khata Ledger Statement (پارٹنر تفصیلی کھاتہ اسٹیٹمنٹ)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('partners_view');

$pageTitle = "Partner Khata Ledger (پارٹنر کھاتہ اسٹیٹمنٹ)";
$activeMenu = "partner_ledger";

$partners = get_partners(false);
$selectedPartnerId = (int)($_GET['partner_id'] ?? ($partners[0]['id'] ?? 1));

$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

$period = clean_input($_GET['period'] ?? '');
if ($period === 'today') {
    $fromDate = $today;
    $toDate   = $today;
} elseif ($period === 'month') {
    $fromDate = $firstDayOfMonth;
    $toDate   = $today;
} elseif ($period === 'all') {
    $fromDate = '2020-01-01';
    $toDate   = $today;
} else {
    $fromDate = clean_input($_GET['from_date'] ?? $firstDayOfMonth);
    $toDate   = clean_input($_GET['to_date'] ?? $today);
}

// Fetch Statement
$statement = get_partner_statement($selectedPartnerId, $fromDate, $toDate);
$partner = $statement['partner'] ?? null;
$openingBal = (float)($statement['opening_balance'] ?? 0);
$transactions = $statement['transactions'] ?? [];
$closingBal = (float)($statement['closing_balance'] ?? 0);

// Calculate totals in the period
$periodDebits = 0.0;
$periodCredits = 0.0;
foreach ($transactions as $t) {
    $periodDebits += (float)$t['debit'];
    $periodCredits += (float)$t['credit'];
}

// Handle CSV / Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Partner_Khata_' . ($partner['partner_code'] ?? 'PTR') . '_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Partner Khata Statement', $partner['partner_name'] ?? 'Partner', 'Period: ' . $fromDate . ' to ' . $toDate]);
    fputcsv($output, ['Date', 'Txn Number', 'Type', 'Description', 'Debit (PKR)', 'Credit (PKR)', 'Running Balance (PKR)']);
    fputcsv($output, [$fromDate, 'OPENING', 'Opening Balance', 'Opening balance before ' . $fromDate, '', '', $openingBal]);
    
    foreach ($transactions as $t) {
        fputcsv($output, [
            $t['transaction_date'],
            $t['transaction_number'],
            $t['transaction_type'],
            $t['description'],
            $t['debit'] > 0 ? $t['debit'] : '',
            $t['credit'] > 0 ? $t['credit'] : '',
            $t['calculated_balance']
        ]);
    }
    fputcsv($output, ['CLOSING', '', 'Closing Balance as of ' . $toDate, '', $periodDebits, $periodCredits, $closingBal]);
    fclose($output);
    exit;
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Top Toolbar (Hidden when printing) -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom no-print">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-journal-text text-primary me-2"></i>Partner Khata Ledger (پارٹنر کھاتہ اسٹیٹمنٹ)
                </h4>
                <p class="text-muted small mb-0">Detailed transaction statement with opening balance, debits, credits, and running balance.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
                <a href="drawings.php?partner_id=<?= $selectedPartnerId ?>" class="btn btn-danger btn-sm">
                    <i class="bi bi-cash me-1"></i> Cash Advance / Return
                </a>
                <a href="<?= APP_URL ?>/modules/reports/daily_closing.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-journal-bookmark-fill me-1"></i> Day Closing (روزنامچہ)
                </a>
                <a href="settle.php?from_partner_id=<?= $selectedPartnerId ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-left-right me-1"></i> Settle
                </a>
                <a href="ledger.php?partner_id=<?= $selectedPartnerId ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&export=csv" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                </a>
                <button type="button" class="btn btn-navy btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Khata (پرنٹ)
                </button>
            </div>
        </div>

        <!-- Filter Bar (Hidden when printing) -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-3">
                <form method="GET" action="ledger.php" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-navy mb-1">Select Partner (پارٹنر منتخب کریں)</label>
                        <select name="partner_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($selectedPartnerId === (int)$p['id']) ? 'selected' : '' ?>>
                                    <?= e($p['partner_name']) ?> <?= !empty($p['urdu_name']) ? '(' . e($p['urdu_name']) . ')' : '' ?> [<?= e($p['partner_code']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-navy mb-1">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-navy mb-1">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= e($toDate) ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <a href="ledger.php?partner_id=<?= $selectedPartnerId ?>&period=month" class="btn btn-outline-secondary btn-sm" title="This Month">
                            This Month
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Printable Statement Card -->
        <div class="card border-0 shadow-sm print-container bg-white">
            <div class="card-body p-4">
                
                <!-- Statement Header -->
                <div class="text-center border-bottom pb-3 mb-3">
                    <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                    <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                    <div class="mt-2">
                        <span class="badge bg-navy text-white px-3 py-1 fs-6 text-uppercase">PARTNER KHATA LEDGER STATEMENT</span>
                    </div>
                </div>

                <!-- Partner Info & Period Details -->
                <div class="row align-items-center mb-3 pb-3 border-bottom">
                    <div class="col-md-7">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td class="text-muted py-1" style="width: 140px;">Partner Name:</td>
                                <td class="fw-bold py-1 fs-5 text-navy">
                                    <?= e($partner['partner_name'] ?? '—') ?>
                                    <?php if (!empty($partner['urdu_name'])): ?>
                                        <span class="text-muted fs-6 fw-normal ms-1">(<?= e($partner['urdu_name']) ?>)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted py-1">Partner Code / Role:</td>
                                <td class="fw-semibold py-1">
                                    <span class="badge bg-light text-dark border font-monospace"><?= e($partner['partner_code'] ?? '—') ?></span>
                                    <span class="badge bg-secondary-subtle text-secondary border ms-1"><?= ucfirst(str_replace('_', ' ', $partner['role'] ?? 'Partner')) ?></span>
                                </td>
                            </tr>
                            <?php if (!empty($partner['supplier_name'])): ?>
                            <tr>
                                <td class="text-muted py-1">Representing Agency:</td>
                                <td class="fw-bold py-1 text-primary">
                                    <i class="bi bi-building me-1"></i> <?= e($partner['supplier_name']) ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                    <div class="col-md-5 text-md-end mt-2 mt-md-0">
                        <div class="text-muted small">Statement Period:</div>
                        <div class="fw-bold text-dark fs-6"><?= format_date($fromDate) ?> &nbsp;to&nbsp; <?= format_date($toDate) ?></div>
                        <div class="text-muted small mt-1">Generated: <?= date('d M Y, h:i A') ?></div>
                    </div>
                </div>

                <!-- Balance Summary KPI Banner -->
                <div class="row g-2 mb-4">
                    <div class="col-3">
                        <div class="p-2 border rounded bg-light text-center">
                            <div class="text-muted small">Opening Balance</div>
                            <div class="fw-bold fs-6 <?= $openingBal >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= format_currency($openingBal) ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 border rounded bg-light text-center">
                            <div class="text-muted small">Period Debits (وصول کیا)</div>
                            <div class="fw-bold fs-6 text-danger"><?= format_currency($periodDebits) ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 border rounded bg-light text-center">
                            <div class="text-muted small">Period Credits (ادا کیا)</div>
                            <div class="fw-bold fs-6 text-success"><?= format_currency($periodCredits) ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 border rounded bg-light text-center">
                            <div class="text-muted small">Net Closing Balance</div>
                            <div class="fw-bold fs-5 <?= $closingBal >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= format_currency($closingBal) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ledger Statement Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-hover align-middle mb-0 font-monospace-amount">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 110px;">Date</th>
                                <th style="width: 140px;">Doc / Txn #</th>
                                <th style="width: 150px;">Type</th>
                                <th>Description / Narration</th>
                                <th style="width: 130px;" class="text-end">Debit (نام)</th>
                                <th style="width: 130px;" class="text-end">Credit (جمع)</th>
                                <th style="width: 140px;" class="text-end">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Opening Balance Row -->
                            <tr class="table-light fw-bold">
                                <td><?= format_date($fromDate) ?></td>
                                <td><span class="badge bg-secondary font-monospace">OPENING</span></td>
                                <td>Opening Balance</td>
                                <td class="fst-italic text-muted">Brought forward balance prior to <?= format_date($fromDate) ?></td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end <?= $openingBal >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= format_currency($openingBal) ?>
                                </td>
                            </tr>

                            <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted fst-italic">
                                    No transactions recorded for this partner in the selected date range.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($transactions as $t): 
                                $badgeClass = 'bg-secondary';
                                $badgeLabel = ucfirst(str_replace('_', ' ', $t['transaction_type']));
                                if ($t['transaction_type'] === 'expense_paid') {
                                    $badgeClass = 'bg-success';
                                    $badgeLabel = 'Expense Paid';
                                } elseif ($t['transaction_type'] === 'expense_share') {
                                    $badgeClass = 'bg-warning text-dark';
                                    $badgeLabel = 'Expense Share';
                                } elseif ($t['transaction_type'] === 'cash_drawn') {
                                    $badgeClass = 'bg-danger';
                                    $badgeLabel = 'Cash Advance';
                                } elseif ($t['transaction_type'] === 'sales_cash_handover') {
                                    $badgeClass = 'bg-danger text-white';
                                    $badgeLabel = 'Sales Handover (سیلز کیش)';
                                } elseif ($t['transaction_type'] === 'cash_returned') {
                                    $badgeClass = 'bg-success text-white';
                                    $badgeLabel = 'Cash Returned (واپسی)';
                                } elseif ($t['transaction_type'] === 'inter_settlement') {
                                    $badgeClass = 'bg-primary';
                                    $badgeLabel = 'Settlement';
                                }
                            ?>
                            <tr>
                                <td><?= format_date($t['transaction_date']) ?></td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?= e($t['transaction_number']) ?></span></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                                <td class="small"><?= e($t['description'] ?: '—') ?></td>
                                <td class="text-end text-danger fw-semibold">
                                    <?= (float)$t['debit'] > 0 ? format_currency($t['debit']) : '—' ?>
                                </td>
                                <td class="text-end text-success fw-semibold">
                                    <?= (float)$t['credit'] > 0 ? format_currency($t['credit']) : '—' ?>
                                </td>
                                <td class="text-end fw-bold <?= (float)$t['calculated_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= format_currency($t['calculated_balance']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="4" class="text-end text-uppercase">Period Totals & Net Balance:</td>
                                <td class="text-end text-danger fs-6"><?= format_currency($periodDebits) ?></td>
                                <td class="text-end text-success fs-6"><?= format_currency($periodCredits) ?></td>
                                <td class="text-end fs-6 <?= $closingBal >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= format_currency($closingBal) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Final Position Note -->
                <div class="p-3 rounded border mb-4 <?= $closingBal > 0 ? 'bg-success-subtle border-success' : ($closingBal < 0 ? 'bg-danger-subtle border-danger' : 'bg-light border-secondary') ?>">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <strong>Final Khata Status (کھاتہ پوزیشن):</strong>
                            <?php if ($closingBal > 0): ?>
                                <span class="text-success ms-2">
                                    <i class="bi bi-arrow-up-circle-fill me-1"></i> Business owes <strong><?= format_currency($closingBal) ?></strong> to <?= e($partner['partner_name']) ?> (کاروبار نے پارٹنر کو دینے ہیں).
                                </span>
                            <?php elseif ($closingBal < 0): ?>
                                <span class="text-danger ms-2">
                                    <i class="bi bi-arrow-down-circle-fill me-1"></i> <?= e($partner['partner_name']) ?> has drawn excess / owes <strong><?= format_currency(abs($closingBal)) ?></strong> to Business (پارٹنر کے ذمے بقایا ہیں).
                                </span>
                            <?php else: ?>
                                <span class="text-muted ms-2">
                                    <i class="bi bi-check2-circle me-1"></i> Khata is Nil / Fully Settled (کھاتہ بیباق ہے).
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="fw-bold fs-5 <?= $closingBal >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= format_currency($closingBal) ?>
                        </div>
                    </div>
                </div>

                <!-- Signatures -->
                <div class="row pt-5 mt-4 text-center">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Partner Signature (دستخط پارٹنر)
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Central Managing Partner / Admin
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1 small fw-semibold">
                            Accountant / Audit Check
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
    .dms-content, .dms-main, .content-wrapper { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    .print-container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
}
</style>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
