<?php
/**
 * DMS - Distribution Management System
 * Partners Management & Khatas Dashboard (پارٹنرز کھاتے اور حساب کتاب)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('partners_view');

$pageTitle = "Partners & Khatas Dashboard (پارٹنرز کھاتہ)";
$activeMenu = "partners";

$partners = get_partners(false);

// Calculate monthly partner statistics
$firstDayOfMonth = date('Y-m-01');
$today = date('Y-m-d');

$monthlyDrawings = (float)db_fetch_val("
    SELECT COALESCE(SUM(debit), 0) 
    FROM partner_transactions 
    WHERE transaction_type IN ('cash_drawn', 'sales_cash_handover') 
      AND transaction_date >= ? AND transaction_date <= ?
", [$firstDayOfMonth, $today]);

$monthlyExpensesPaidByPartners = (float)db_fetch_val("
    SELECT COALESCE(SUM(credit), 0) 
    FROM partner_transactions 
    WHERE transaction_type = 'expense_paid' 
      AND transaction_date >= ? AND transaction_date <= ?
", [$firstDayOfMonth, $today]);

$monthlySettlements = (float)db_fetch_val("
    SELECT COALESCE(SUM(credit), 0) 
    FROM partner_transactions 
    WHERE transaction_type = 'inter_settlement' 
      AND transaction_date >= ? AND transaction_date <= ?
", [$firstDayOfMonth, $today]);

// Recent 30 partner transactions
$recentTxns = db_fetch_all("
    SELECT pt.*, p.partner_name, p.urdu_name, p.partner_code, u.full_name as created_by_name
    FROM partner_transactions pt
    JOIN partners p ON pt.partner_id = p.id
    LEFT JOIN users u ON pt.created_by = u.id
    ORDER BY pt.transaction_date DESC, pt.id DESC
    LIMIT 30
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-people-fill text-warning me-2"></i>Partners & Khatas Dashboard (پارٹنرز کھاتے اور حساب کتاب)
                </h4>
                <p class="text-muted small mb-0">Multi-Partner accounts, expense sharing, cash advances, and inter-settlement ledgers.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="drawings.php" class="btn btn-danger btn-sm">
                    <i class="bi bi-cash-stack me-1"></i> Cash Advance / Drawing (کیش ایڈوانس)
                </a>
                <a href="settle.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-left-right me-1"></i> Inter-Partner Settlement (آپس کا لین دین)
                </a>
                <a href="ledger.php" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-journal-text me-1"></i> Khata Statements (کھاتہ اسٹیٹمنٹ)
                </a>
            </div>
        </div>

        <!-- Quick Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm border-start border-danger border-4 h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">Monthly Cash Advances / Drawings</div>
                        <div class="fs-4 fw-bold text-danger mt-1"><?= format_currency($monthlyDrawings) ?></div>
                        <div class="small text-muted mt-1"><i class="bi bi-calendar3 me-1"></i> Month of <?= date('F Y') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm border-start border-success border-4 h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">Expenses Funded Out-of-Pocket</div>
                        <div class="fs-4 fw-bold text-success mt-1"><?= format_currency($monthlyExpensesPaidByPartners) ?></div>
                        <div class="small text-muted mt-1"><i class="bi bi-wallet2 me-1"></i> Partner personal contributions</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm border-start border-primary border-4 h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">Inter-Partner Adjustments</div>
                        <div class="fs-4 fw-bold text-primary mt-1"><?= format_currency($monthlySettlements) ?></div>
                        <div class="small text-muted mt-1"><i class="bi bi-arrow-repeat me-1"></i> Direct mutual settlements</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Partner Cards Section -->
        <div class="row g-3 mb-4">
            <?php foreach ($partners as $partner): 
                $bal = (float)$partner['current_balance'];
                $isSupplierPartner = !empty($partner['supplier_id']);
            ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-navy-subtle text-navy border px-2 py-1 mb-1 font-monospace"><?= e($partner['partner_code']) ?></span>
                            <h5 class="fw-bold mb-0 text-navy">
                                <?= e($partner['partner_name']) ?>
                                <?php if (!empty($partner['urdu_name'])): ?>
                                    <span class="text-muted fs-6 fw-normal ms-1">(<?= e($partner['urdu_name']) ?>)</span>
                                <?php endif; ?>
                            </h5>
                        </div>
                        <div class="text-end">
                            <?php if ($partner['role'] === 'managing_partner'): ?>
                                <span class="badge bg-info-subtle text-info border border-info-subtle">Admin / Cash Custodian</span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Agency Partner</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($isSupplierPartner): ?>
                            <div class="p-2 bg-light rounded mb-3 small">
                                <span class="text-muted">Associated Agency / Company:</span><br>
                                <strong class="text-dark"><i class="bi bi-building me-1"></i> <?= e($partner['supplier_name']) ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="p-2 bg-light rounded mb-3 small">
                                <span class="text-muted">Central Operations:</span><br>
                                <strong class="text-dark"><i class="bi bi-safe me-1"></i> Central Cash Pool & Field Sales</strong>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3 text-center py-2 bg-light rounded border">
                            <div class="text-muted small text-uppercase">Current Khata Balance (موجودہ کھاتہ بیلنس)</div>
                            <div class="fs-3 fw-bold mt-1 <?= $bal > 0 ? 'text-success' : ($bal < 0 ? 'text-danger' : 'text-muted') ?>">
                                <?= format_currency(abs($bal)) ?>
                            </div>
                            <div class="small fw-semibold mt-1">
                                <?php if ($bal > 0): ?>
                                    <span class="text-success"><i class="bi bi-arrow-up-circle-fill me-1"></i> Payable to Partner (پارٹنر کو دینے ہیں)</span>
                                <?php elseif ($bal < 0): ?>
                                    <span class="text-danger"><i class="bi bi-arrow-down-circle-fill me-1"></i> Receivable / Drawn (پارٹنر نے دینے ہیں)</span>
                                <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-check2-circle me-1"></i> Balanced / Nil (کھاتہ بیباق)</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($partner['notes'])): ?>
                            <div class="text-muted small mb-3 fst-italic">
                                <i class="bi bi-info-circle me-1"></i> <?= e($partner['notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-column gap-2 mt-auto">
                            <a href="ledger.php?partner_id=<?= $partner['id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-journal-text me-1"></i> View Ledger Statement (کھاتہ دیکھیں)
                            </a>
                            <div class="d-flex gap-2">
                                <a href="drawings.php?partner_id=<?= $partner['id'] ?>" class="btn btn-outline-danger btn-sm flex-fill" title="Record Cash Drawing">
                                    <i class="bi bi-cash me-1"></i> Cash Advance
                                </a>
                                <?php if ($partner['role'] !== 'managing_partner'): ?>
                                <a href="settle.php?from_partner_id=<?= $partner['id'] ?>" class="btn btn-outline-secondary btn-sm flex-fill" title="Inter-Partner Settlement">
                                    <i class="bi bi-arrow-left-right me-1"></i> Settle
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent Transactions Log -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-navy">
                    <i class="bi bi-clock-history me-2 text-primary"></i>Recent Khata Activity (حالیہ لین دین)
                </h5>
                <a href="ledger.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-filter me-1"></i> Detailed Khata Filters
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Txn #</th>
                                <th>Partner</th>
                                <th>Type</th>
                                <th>Description / Narration</th>
                                <th class="text-end">Debit (نام)</th>
                                <th class="text-end">Credit (جمع)</th>
                                <th class="text-end">Balance</th>
                                <th class="text-center">Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTxns)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No partner transactions recorded yet. When expenses are divided or cash advances are taken, they will appear here.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recentTxns as $txn): 
                                $badgeClass = 'bg-secondary';
                                $badgeLabel = ucfirst(str_replace('_', ' ', $txn['transaction_type']));
                                if ($txn['transaction_type'] === 'expense_paid') {
                                    $badgeClass = 'bg-success';
                                    $badgeLabel = 'Expense Paid (خود خرچ کیا)';
                                } elseif ($txn['transaction_type'] === 'expense_share') {
                                    $badgeClass = 'bg-warning text-dark';
                                    $badgeLabel = 'Expense Share (حصہ خرچہ)';
                                } elseif ($txn['transaction_type'] === 'cash_drawn') {
                                    $badgeClass = 'bg-danger';
                                    $badgeLabel = 'Cash Drawing (ایڈوانس لیا)';
                                } elseif ($txn['transaction_type'] === 'sales_cash_handover') {
                                    $badgeClass = 'bg-danger text-white';
                                    $badgeLabel = 'Sales Handover (سیلز کیش)';
                                } elseif ($txn['transaction_type'] === 'cash_returned') {
                                    $badgeClass = 'bg-success text-white';
                                    $badgeLabel = 'Cash Received (کیش واپسی)';
                                } elseif ($txn['transaction_type'] === 'inter_settlement') {
                                    $badgeClass = 'bg-primary';
                                    $badgeLabel = 'Settlement (آپس کا لین دین)';
                                }
                            ?>
                            <tr>
                                <td><?= format_date($txn['transaction_date']) ?></td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?= e($txn['transaction_number']) ?></span></td>
                                <td>
                                    <strong><?= e($txn['partner_name']) ?></strong>
                                    <?php if (!empty($txn['urdu_name'])): ?>
                                        <small class="text-muted ms-1">(<?= e($txn['urdu_name']) ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                                <td class="small text-muted"><?= e($txn['description'] ?: '—') ?></td>
                                <td class="text-end fw-semibold text-danger">
                                    <?= (float)$txn['debit'] > 0 ? format_currency($txn['debit']) : '—' ?>
                                </td>
                                <td class="text-end fw-semibold text-success">
                                    <?= (float)$txn['credit'] > 0 ? format_currency($txn['credit']) : '—' ?>
                                </td>
                                <td class="text-end fw-bold <?= (float)$txn['running_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= format_currency($txn['running_balance']) ?>
                                </td>
                                <td class="text-center small text-muted"><?= e($txn['created_by_name'] ?? 'Admin') ?></td>
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

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
