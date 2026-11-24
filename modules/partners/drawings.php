<?php
/**
 * DMS - Distribution Management System
 * Record Partner Cash Drawing / Advance (پارٹنر کیش ایڈوانس / واپسی)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('partners_manage');

$pageTitle = "Partner Cash Drawings (پارٹنر کیش ایڈوانس)";
$activeMenu = "partner_drawings";

$partners = get_partners(true);
$preselectedPartnerId = (int)($_GET['partner_id'] ?? 0);

// Cash and Bank payment accounts
$paymentAccounts = db_fetch_all("
    SELECT id, account_code, account_name, sub_type 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank') AND is_active = 1 
    ORDER BY account_code ASC
");

// Form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $direction          = clean_input($_POST['direction'] ?? 'give'); // 'give' or 'receive'
    $drawingType        = clean_input($_POST['drawing_type'] ?? 'cash_drawn'); // 'cash_drawn' or 'sales_cash_handover'
    $partnerId          = (int)($_POST['partner_id'] ?? 0);
    $transactionDate    = clean_input($_POST['transaction_date'] ?? date('Y-m-d'));
    $amount             = (float)($_POST['amount'] ?? 0);
    $paidFromAccountId  = (int)($_POST['paid_from_account_id'] ?? ($_POST['account_id'] ?? 0));
    $paymentMode        = clean_input($_POST['payment_mode'] ?? 'cash');
    $description        = clean_input($_POST['description'] ?? '');

    $validator = new Validator();
    $validator->positiveInt('partner_id', $partnerId, 'Please choose a Partner.')
              ->exists('partner_id', $partnerId, 'partners', 'id', 'The selected partner does not exist.')
              ->numeric('amount', $amount, 0.01, null, 'Amount must be greater than zero.')
              ->positiveInt('paid_from_account_id', $paidFromAccountId, 'Please select the Cash or Bank account.')
              ->exists('paid_from_account_id', $paidFromAccountId, 'accounts', 'id', 'Selected payment account does not exist.')
              ->date('transaction_date', $transactionDate);

    if ($validator->passes()) {
        try {
            $partner = get_partner_by_id($partnerId);
            if ($direction === 'receive') {
                $txnId = record_partner_cash_received(
                    $partnerId,
                    $amount,
                    $transactionDate,
                    $paidFromAccountId,
                    $paymentMode,
                    $description
                );
                set_flash('success', "Cash returned / received of <strong>" . format_currency($amount) . "</strong> from partner <strong>" . e($partner['partner_name']) . "</strong> recorded successfully.");
            } else {
                if ($drawingType === 'sales_cash_handover') {
                    $txnId = record_partner_cash_handover(
                        $partnerId,
                        $amount,
                        $transactionDate,
                        $paidFromAccountId,
                        $description
                    );
                    set_flash('success', "Daily sales cash handover of <strong>" . format_currency($amount) . "</strong> to partner <strong>" . e($partner['partner_name']) . "</strong> recorded successfully.");
                } else {
                    $txnId = record_partner_drawing(
                        $partnerId,
                        $amount,
                        $transactionDate,
                        $paymentMode,
                        $paidFromAccountId,
                        $description
                    );
                    set_flash('success', "Cash advance of <strong>" . format_currency($amount) . "</strong> recorded for partner <strong>" . e($partner['partner_name']) . "</strong> successfully.");
                }
            }
            redirect(APP_URL . '/modules/partners/drawings.php?partner_id=' . $partnerId);
        } catch (Exception $e) {
            set_flash('danger', "Error recording transaction: " . $e->getMessage());
        }
    } else {
        set_flash('danger', $validator->getFirstError());
    }
}

// Fetch recent cash drawings, handovers, and returns
$recentDrawings = db_fetch_all("
    SELECT pt.*, p.partner_name, p.urdu_name, u.full_name as created_by_name
    FROM partner_transactions pt
    JOIN partners p ON pt.partner_id = p.id
    LEFT JOIN users u ON pt.created_by = u.id
    WHERE pt.transaction_type IN ('cash_drawn', 'sales_cash_handover', 'cash_returned')
    ORDER BY pt.transaction_date DESC, pt.id DESC
    LIMIT 30
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Breadcrumb / Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-cash-stack text-danger me-2"></i>Record Cash Advance / Drawing (کیش ایڈوانس / واپسی)
                </h4>
                <p class="text-muted small mb-0">Record cash withdrawals or operational advances taken by partners from the Central Sales Pool.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Partners Dashboard
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Form Card -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="fw-bold mb-0 text-navy"><i class="bi bi-pencil-square me-2 text-primary"></i>Transaction Details (لین دین تفصیلات)</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="drawings.php" id="drawingForm">
                            <!-- Direction Toggle -->
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-navy">Transaction Direction (لین دین کی نوعیت) <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <input type="radio" class="btn-check" name="direction" id="dir_give" value="give" checked onchange="toggleDirection('give')">
                                    <label class="btn btn-outline-danger flex-fill py-2 fw-semibold" for="dir_give">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Give Cash to Partner (کیش ادائیگی / ایڈوانس)
                                    </label>

                                    <input type="radio" class="btn-check" name="direction" id="dir_receive" value="receive" onchange="toggleDirection('receive')">
                                    <label class="btn btn-outline-success flex-fill py-2 fw-semibold" for="dir_receive">
                                        <i class="bi bi-box-arrow-in-left me-1"></i> Receive Cash from Partner (کیش وصولی / واپسی)
                                    </label>
                                </div>
                            </div>

                            <div class="mb-3" id="giveTypeWrapper">
                                <label class="form-label fw-semibold small">Cash Outflow Head (ادائیگی کی قسم)</label>
                                <select name="drawing_type" id="drawingTypeSelect" class="form-select">
                                    <option value="cash_drawn" selected>Personal Advance / Cash Drawing (ذاتی کیش ایڈوانس)</option>
                                    <option value="sales_cash_handover">Daily Sales Cash Handover (سیلز کیش سپردگی - مینجنگ پارٹنر)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small" id="partnerLabel">Partner (پارٹنر) <span class="text-danger">*</span></label>
                                <select name="partner_id" id="partnerSelect" class="form-select" required>
                                    <option value="">-- Choose Partner --</option>
                                    <?php foreach ($partners as $p): ?>
                                        <option value="<?= $p['id'] ?>" 
                                            data-balance="<?= $p['current_balance'] ?>"
                                            <?= ($preselectedPartnerId === (int)$p['id']) ? 'selected' : '' ?>>
                                            <?= e($p['partner_name']) ?> <?= !empty($p['urdu_name']) ? '(' . e($p['urdu_name']) . ')' : '' ?> - Bal: <?= format_currency($p['current_balance']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Date (تاریخ) <span class="text-danger">*</span></label>
                                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Amount (رقم - PKR) <span class="text-danger">*</span></label>
                                    <input type="number" name="amount" id="amountInput" step="0.01" min="1" class="form-control fw-bold text-danger fs-5" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-7">
                                    <label class="form-label fw-semibold small" id="accountLabel">Paid From Account (کھاتہ) <span class="text-danger">*</span></label>
                                    <select name="paid_from_account_id" class="form-select" required>
                                        <option value="">-- Select Cash/Bank --</option>
                                        <?php foreach ($paymentAccounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= ($acc['account_code'] === '1001' || $acc['account_code'] === '1010' || $acc['sub_type'] === 'cash') ? 'selected' : '' ?>>
                                                <?= e($acc['account_name']) ?> (<?= e($acc['account_code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold small">Payment Mode</label>
                                    <select name="payment_mode" class="form-select">
                                        <option value="cash" selected>Cash (نقد)</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Remarks / Purpose (تفصیل / وجہ)</label>
                                <textarea name="description" id="remarksInput" class="form-control" rows="2" placeholder="e.g. Personal advance, emergency cash, sales handover, reimbursement..."></textarea>
                            </div>

                            <div class="alert alert-info py-2 px-3 small border-0 mb-3" id="directionInfoAlert">
                                <i class="bi bi-info-circle me-1"></i> This transaction will debit the partner's personal ledger and automatically post a journal entry reducing the selected cash/bank balance.
                            </div>

                            <div class="d-grid">
                                <button type="submit" id="submitBtn" class="btn btn-danger fw-bold">
                                    <i class="bi bi-check-circle me-1"></i> Save Cash Advance (ایڈوانس درج کریں)
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Drawings & Handovers Table -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-navy"><i class="bi bi-clock-history me-2 text-danger"></i>Recent Partner Cash Movements (حالیہ لین دین)</h6>
                        <span class="badge bg-secondary-subtle text-secondary border"><?= count($recentDrawings) ?> Records</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 font-monospace-amount small">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 100px;">Date</th>
                                        <th>Txn #</th>
                                        <th>Partner</th>
                                        <th>Type</th>
                                        <th>Purpose / Description</th>
                                        <th class="text-end">Amount (PKR)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentDrawings)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            No partner cash transactions recorded yet.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($recentDrawings as $d): 
                                        $type = $d['transaction_type'];
                                        $isDebit = (float)$d['debit'] > 0;
                                        $badgeClass = 'bg-danger';
                                        $badgeLabel = 'Advance';
                                        if ($type === 'sales_cash_handover') {
                                            $badgeClass = 'bg-danger text-white';
                                            $badgeLabel = 'Sales Handover';
                                        } elseif ($type === 'cash_returned') {
                                            $badgeClass = 'bg-success text-white';
                                            $badgeLabel = 'Cash Received';
                                        }
                                    ?>
                                    <tr>
                                        <td><?= format_date($d['transaction_date']) ?></td>
                                        <td><span class="badge bg-light text-dark border font-monospace"><?= e($d['transaction_number']) ?></span></td>
                                        <td>
                                            <strong><?= e($d['partner_name']) ?></strong>
                                            <?php if (!empty($d['urdu_name'])): ?>
                                                <small class="text-muted ms-1">(<?= e($d['urdu_name']) ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                                        <td class="small text-muted"><?= e($d['description'] ?: '—') ?></td>
                                        <td class="text-end fw-bold <?= $isDebit ? 'text-danger' : 'text-success' ?>">
                                            <?= $isDebit ? '-' : '+' ?><?= format_currency($isDebit ? (float)$d['debit'] : (float)$d['credit']) ?>
                                        </td>
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

    </div>
</div>

<script>
function toggleDirection(dir) {
    const giveTypeWrapper = document.getElementById('giveTypeWrapper');
    const accountLabel = document.getElementById('accountLabel');
    const amountInput = document.getElementById('amountInput');
    const submitBtn = document.getElementById('submitBtn');
    const infoAlert = document.getElementById('directionInfoAlert');

    if (dir === 'receive') {
        giveTypeWrapper.style.display = 'none';
        accountLabel.innerHTML = 'Deposit To Account (کھاتہ) <span class="text-danger">*</span>';
        amountInput.classList.remove('text-danger');
        amountInput.classList.add('text-success');
        submitBtn.className = 'btn btn-success fw-bold';
        submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Cash Received (وصولی درج کریں)';
        infoAlert.innerHTML = '<i class="bi bi-info-circle me-1"></i> This transaction will credit the partner\'s personal ledger (settling drawn funds) and increase selected cash/bank balance.';
    } else {
        giveTypeWrapper.style.display = 'block';
        accountLabel.innerHTML = 'Paid From Account (کھاتہ) <span class="text-danger">*</span>';
        amountInput.classList.remove('text-success');
        amountInput.classList.add('text-danger');
        submitBtn.className = 'btn btn-danger fw-bold';
        submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Cash Advance / Handover (ادائیگی درج کریں)';
        infoAlert.innerHTML = '<i class="bi bi-info-circle me-1"></i> This transaction will debit the partner\'s personal ledger and automatically post a journal entry reducing the selected cash/bank balance.';
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
