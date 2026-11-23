<?php
/**
 * DMS - Distribution Management System
 * View & Printable Expense Voucher
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('expenses_manage');

$expenseId = (int)($_GET['id'] ?? 0);
if ($expenseId <= 0) {
    set_flash('danger', "Invalid expense voucher ID.");
    header('Location: index.php');
    exit;
}

$expense = db_fetch_one("
    SELECT 
        e.*,
        ec.category_name,
        ec.category_code,
        a.account_name as paid_from_name,
        a.account_code as paid_from_code,
        u.full_name as created_by_name
    FROM expenses e
    JOIN expense_categories ec ON e.expense_category_id = ec.id
    JOIN accounts a ON e.paid_from_account_id = a.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.id = ?
", [$expenseId]);

if (!$expense) {
    set_flash('danger', "Expense voucher not found.");
    header('Location: index.php');
    exit;
}

// Fetch linked journal voucher
$jv = db_fetch_one("
    SELECT id, entry_number, entry_date 
    FROM journal_entries 
    WHERE reference_type = 'expense' AND reference_id = ?
", [$expenseId]);

// Fetch linked partner transactions if any
$partnerTxns = db_fetch_all("
    SELECT pt.*, p.partner_name, p.urdu_name 
    FROM partner_transactions pt
    JOIN partners p ON pt.partner_id = p.id
    WHERE pt.reference_type = 'expense' AND pt.reference_id = ?
    ORDER BY pt.id ASC
", [$expenseId]);

$pageTitle = "Expense Voucher - " . $expense['expense_number'];
$activeMenu = "expenses";

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

        <!-- Action Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div class="d-flex align-items-center gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Expenses
                </a>
                <span class="text-muted">|</span>
                <span class="fw-bold text-navy fs-5">Voucher <?= htmlspecialchars($expense['expense_number']) ?></span>
                <span class="badge bg-success-subtle text-success border border-success-subtle">POSTED</span>
            </div>
            <div class="d-flex gap-2">
                <a href="create.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Expense
                </a>
                <a href="print.php?id=<?= $expense['id'] ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-printer me-1"></i> Print Voucher
                </a>
            </div>
        </div>

        <!-- Printable Voucher Card -->
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="card border shadow-sm p-4 print-container bg-white">
                    
                    <!-- Voucher Header -->
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                        <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                        <div class="text-muted small">Phone: 0300-0673276 | Location: Jandiala Bhagwala G.T. Road</div>
                        <div class="mt-2">
                            <span class="badge bg-dark text-white px-3 py-1 fs-6 text-uppercase">PETTY CASH / EXPENSE VOUCHER</span>
                        </div>
                    </div>

                    <!-- Voucher Meta -->
                    <div class="row mb-3 pb-2 border-bottom">
                        <div class="col-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td class="text-muted py-1" style="width: 120px;">Voucher No:</td>
                                    <td class="fw-bold py-1 text-navy"><?= htmlspecialchars($expense['expense_number']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1">Voucher Date:</td>
                                    <td class="fw-bold py-1"><?= format_date($expense['expense_date']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1">Category:</td>
                                    <td class="fw-semibold py-1">
                                        <?= htmlspecialchars($expense['category_name']) ?> (<?= htmlspecialchars($expense['category_code']) ?>)
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-6 text-end">
                            <table class="table table-sm table-borderless mb-0 float-end" style="width: auto;">
                                <tr>
                                    <td class="text-muted py-1 text-start">Paid From:</td>
                                    <td class="fw-bold py-1 text-end"><?= htmlspecialchars($expense['paid_from_name']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1 text-start">Payment Mode:</td>
                                    <td class="fw-semibold py-1 text-end text-uppercase"><?= htmlspecialchars($expense['payment_mode']) ?></td>
                                </tr>
                                <?php if ($jv): ?>
                                <tr>
                                    <td class="text-muted py-1 text-start">General Ledger JV:</td>
                                    <td class="fw-bold py-1 text-end text-primary"><?= htmlspecialchars($jv['entry_number']) ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <!-- Particulars Table -->
                    <table class="table table-bordered mb-4">
                        <thead class="table-light">
                            <tr>
                                <th>Particulars / Description</th>
                                <th style="width: 200px;">Payee / Reference</th>
                                <th style="width: 160px;" class="text-end">Amount (PKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="height: 120px;">
                                <td>
                                    <div class="fw-bold mb-1"><?= htmlspecialchars($expense['category_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($expense['description'] ?: 'Daily operating expense') ?></div>
                                </td>
                                <td>
                                    <div><strong>Payee:</strong> <?= htmlspecialchars($expense['payee_name'] ?: '—') ?></div>
                                    <?php if (!empty($expense['reference_voucher'])): ?>
                                        <div class="small text-muted mt-1"><strong>Ref/Bill:</strong> <?= htmlspecialchars($expense['reference_voucher']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold fs-5 text-navy align-middle">
                                    <?= format_currency($expense['amount']) ?>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="2" class="fw-bold text-end">TOTAL VOUCHER AMOUNT:</td>
                                <td class="text-end fw-bold text-navy fs-5"><?= format_currency($expense['amount']) ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <?php if (!empty($partnerTxns) || (!empty($expense['split_type']) && $expense['split_type'] !== 'none')): ?>
                    <div class="card border border-primary-subtle bg-light mb-4">
                        <div class="card-header bg-white py-2 border-bottom border-primary-subtle d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small">
                                <i class="bi bi-people-fill text-primary me-1"></i> Partner Khata Division (پارٹنرز کھاتہ تقسیم)
                            </span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <?= ucfirst(str_replace('_', ' ', $expense['split_type'] ?? 'Equal')) ?>
                            </span>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2 mb-2 small">
                                <div class="col-sm-6">
                                    <span class="text-muted">Payment Source:</span> 
                                    <?php if (($expense['paid_by_type'] ?? '') === 'partner' && !empty($expense['paid_by_partner_id'])): 
                                        $payer = get_partner_by_id((int)$expense['paid_by_partner_id']);
                                    ?>
                                        <strong class="text-success"><i class="bi bi-person-check me-1"></i> Paid by <?= e($payer['partner_name'] ?? 'Partner') ?> (Personal Pocket)</strong>
                                    <?php else: ?>
                                        <strong class="text-dark"><i class="bi bi-safe me-1"></i> Paid from Central Sales Pool (Managing Partner)</strong>
                                    <?php endif; ?>
                                </div>
                                <div class="col-sm-6 text-sm-end">
                                    <a href="<?= APP_URL ?>/modules/partners/index.php" class="text-decoration-none">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Open Partners Khata
                                    </a>
                                </div>
                            </div>
                            <?php if (!empty($partnerTxns)): ?>
                            <table class="table table-sm table-bordered bg-white mb-1 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Partner</th>
                                        <th>Ledger Entry Type</th>
                                        <th class="text-end">Charged to Khata (Debit)</th>
                                        <th class="text-end">Funded / Reimbursable (Credit)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($partnerTxns as $pt): ?>
                                    <tr>
                                        <td><strong><?= e($pt['partner_name']) ?></strong> <?= !empty($pt['urdu_name']) ? '(' . e($pt['urdu_name']) . ')' : '' ?></td>
                                        <td>
                                            <?php if ($pt['transaction_type'] === 'expense_paid'): ?>
                                                <span class="badge bg-success">Expense Funded (ذاتی جیب سے دیا)</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Khata Share (حصہ خرچہ)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-danger fw-semibold"><?= (float)$pt['debit'] > 0 ? format_currency($pt['debit']) : '—' ?></td>
                                        <td class="text-end text-success fw-semibold"><?= (float)$pt['credit'] > 0 ? format_currency($pt['credit']) : '—' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Amount in words & Recorded by -->
                    <div class="p-2 bg-light border rounded mb-4">
                        <div class="small text-muted">Amount in Words:</div>
                        <div class="fw-bold text-dark text-capitalize">
                            Rupees <?= number_format($expense['amount'], 2) ?> Only
                        </div>
                    </div>

                    <!-- Signatures -->
                    <div class="row pt-5 mt-4 text-center">
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Prepared By: <?= htmlspecialchars($expense['created_by_name'] ?? 'Admin') ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Checked / Verified By
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Receiver's Signature
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
    .dms-content, .dms-main { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    .print-container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
}
</style>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
