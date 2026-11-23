<?php
/**
 * DMS - Distribution Management System
 * Inter-Partner Settlement & Mutual Adjustment (آپس کا لین دین اور کھاتہ ایڈجسٹمنٹ)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('partners_manage');

$pageTitle = "Inter-Partner Settlement (آپس کا لین دین)";
$activeMenu = "partner_settle";

$partners = get_partners(true);
$preselectedFrom = (int)($_GET['from_partner_id'] ?? 1);
$preselectedTo   = (int)($_GET['to_partner_id'] ?? 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromPartnerId   = (int)($_POST['from_partner_id'] ?? 0);
    $toPartnerId     = (int)($_POST['to_partner_id'] ?? 0);
    $settlementDate  = clean_input($_POST['settlement_date'] ?? date('Y-m-d'));
    $amount          = (float)($_POST['amount'] ?? 0);
    $settlementType  = clean_input($_POST['settlement_type'] ?? 'cash');
    $remarks         = clean_input($_POST['remarks'] ?? '');

    $validator = new Validator();
    $validator->positiveInt('from_partner_id', $fromPartnerId, 'Please select the Payer / Giving Partner.')
              ->exists('from_partner_id', $fromPartnerId, 'partners', 'id', 'Selected payer partner is invalid.')
              ->positiveInt('to_partner_id', $toPartnerId, 'Please select the Receiving Partner.')
              ->exists('to_partner_id', $toPartnerId, 'partners', 'id', 'Selected receiving partner is invalid.')
              ->numeric('amount', $amount, 0.01, null, 'Settlement amount must be greater than zero.')
              ->date('settlement_date', $settlementDate);

    if ($fromPartnerId === $toPartnerId) {
        $validator->addError('to_partner_id', 'Payer and Receiving partner cannot be the same person.');
    }

    if ($validator->passes()) {
        try {
            $typeLabel = match($settlementType) {
                'stock_offset' => 'Stock / Cross Sales Offset',
                'return_offset' => 'Sales Return / Claim Adjustment',
                'balance_offset' => 'Khata Balance Reconciliation',
                default => 'Direct Cash / Bank Settlement'
            };

            $fullDesc = "Type: {$typeLabel}" . ($remarks ? " | Notes: {$remarks}" : "");

            record_partner_settlement(
                $fromPartnerId,
                $toPartnerId,
                $amount,
                $settlementDate,
                $fullDesc
            );

            $fromPartner = get_partner_by_id($fromPartnerId);
            $toPartner   = get_partner_by_id($toPartnerId);

            set_flash('success', "Settlement of <strong>" . format_currency($amount) . "</strong> between <strong>" . e($fromPartner['partner_name']) . "</strong> and <strong>" . e($toPartner['partner_name']) . "</strong> recorded successfully.");
            redirect(APP_URL . '/modules/partners/settle.php?from_partner_id=' . $fromPartnerId . '&to_partner_id=' . $toPartnerId);
        } catch (Exception $e) {
            set_flash('danger', "Error processing settlement: " . $e->getMessage());
        }
    } else {
        set_flash('danger', $validator->getFirstError());
    }
}

// Fetch recent settlements
$recentSettlements = db_fetch_all("
    SELECT pt.*, p.partner_name, p.urdu_name, u.full_name as created_by_name
    FROM partner_transactions pt
    JOIN partners p ON pt.partner_id = p.id
    LEFT JOIN users u ON pt.created_by = u.id
    WHERE pt.transaction_type = 'inter_settlement' AND pt.credit > 0
    ORDER BY pt.transaction_date DESC, pt.id DESC
    LIMIT 20
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-arrow-left-right text-primary me-2"></i>Inter-Partner Settlement (آپس کا لین دین اور کھاتہ ایڈجسٹمنٹ)
                </h4>
                <p class="text-muted small mb-0">Record direct payments, stock transfers, return offsets, and mutual ledger reconciliations between partners.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Partners Dashboard
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Settlement Form -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="fw-bold mb-0 text-navy"><i class="bi bi-sliders me-2 text-primary"></i>Record Settlement (لین دین درج کریں)</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="settle.php" id="settleForm">
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Paid By / Settler (رقم دینے والا) <span class="text-danger">*</span></label>
                                    <select name="from_partner_id" id="fromPartner" class="form-select" required>
                                        <option value="">-- Settling Partner --</option>
                                        <?php foreach ($partners as $p): ?>
                                            <option value="<?= $p['id'] ?>" <?= ($preselectedFrom === (int)$p['id']) ? 'selected' : '' ?>>
                                                <?= e($p['partner_name']) ?> <?= !empty($p['urdu_name']) ? '(' . e($p['urdu_name']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Received By (رقم لینے والا) <span class="text-danger">*</span></label>
                                    <select name="to_partner_id" id="toPartner" class="form-select" required>
                                        <option value="">-- Receiving Partner --</option>
                                        <?php foreach ($partners as $p): ?>
                                            <option value="<?= $p['id'] ?>" <?= ($preselectedTo === (int)$p['id']) ? 'selected' : '' ?>>
                                                <?= e($p['partner_name']) ?> <?= !empty($p['urdu_name']) ? '(' . e($p['urdu_name']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Date (تاریخ) <span class="text-danger">*</span></label>
                                    <input type="date" name="settlement_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Amount (رقم - PKR) <span class="text-danger">*</span></label>
                                    <input type="number" name="amount" step="0.01" min="1" class="form-control fw-bold text-primary fs-5" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Adjustment / Settlement Reason (نوعیت)</label>
                                <select name="settlement_type" class="form-select">
                                    <option value="cash" selected>Direct Cash / Bank Settlement (نقد یا بینک ادائیگی)</option>
                                    <option value="stock_offset">Stock Exchange / Mutual Sales Offset (اسٹاک یا سیل ایڈجسٹمنٹ)</option>
                                    <option value="return_offset">Sales Returns / Defective Claim Mutual Offset (سیلز ریٹرن کلیم ایڈجسٹمنٹ)</option>
                                    <option value="balance_offset">General Khata Offset / Reconciliation (کھاتہ بیباقی ایڈجسٹمنٹ)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Remarks / Details (تفصیل / رسید نمبر)</label>
                                <textarea name="remarks" class="form-control" rows="2" placeholder="e.g. Cleared 5 cartons stock difference, paid Rs. 10,000 via jazzcash..."></textarea>
                            </div>

                            <div class="p-3 bg-light rounded border mb-3 small text-muted">
                                <div class="fw-bold text-dark mb-1"><i class="bi bi-info-circle text-primary me-1"></i> Khata Impact Preview:</div>
                                <div>• <strong>Payer Partner</strong> will be <span class="badge bg-success">CREDITED</span> (balance improves).</div>
                                <div>• <strong>Receiver Partner</strong> will be <span class="badge bg-danger">DEBITED</span> (balance charged).</div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary fw-bold">
                                    <i class="bi bi-check2-circle me-1"></i> Record Mutual Settlement (لین دین محفوظ کریں)
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Settlements Table -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-navy"><i class="bi bi-clock-history me-2 text-primary"></i>Settlement History (حالیہ ایڈجسٹمنٹ تاریخ)</h6>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($recentSettlements) ?> Records</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Txn #</th>
                                        <th>Payer Partner</th>
                                        <th>Details / Reason</th>
                                        <th class="text-end">Amount (PKR)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentSettlements)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            No inter-partner settlements recorded yet.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($recentSettlements as $s): ?>
                                    <tr>
                                        <td><?= format_date($s['transaction_date']) ?></td>
                                        <td><span class="badge bg-light text-dark border font-monospace"><?= e($s['transaction_number']) ?></span></td>
                                        <td>
                                            <strong class="text-navy"><?= e($s['partner_name']) ?></strong>
                                            <?php if (!empty($s['urdu_name'])): ?>
                                                <small class="text-muted ms-1">(<?= e($s['urdu_name']) ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= e($s['description'] ?: '—') ?></td>
                                        <td class="text-end fw-bold text-success"><?= format_currency($s['credit']) ?></td>
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

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
