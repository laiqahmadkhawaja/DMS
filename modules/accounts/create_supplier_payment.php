<?php
/**
 * DMS - Distribution Management System
 * Record Supplier Payment Voucher
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('payments_manage');

$pageTitle = "Record Supplier Payment";
$activeMenu = "supplier_payments";
$errors = [];

$preselectedSupplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentDate = clean_input($_POST['payment_date'] ?? date('Y-m-d'));
    $supplierId  = (int)($_POST['supplier_id'] ?? 0);
    $accountId   = (int)($_POST['account_id'] ?? ACC_BANK_MAIN);
    $paymentMode = clean_input($_POST['payment_mode'] ?? 'bank_transfer');
    $amount      = round((float)($_POST['amount'] ?? 0), 2);
    $referenceNo = clean_input($_POST['reference_no'] ?? '');
    $remarks     = clean_input($_POST['remarks'] ?? '');

    // Validation
    if (empty($paymentDate) || !strtotime($paymentDate)) {
        $errors[] = "Valid payment date is required.";
    }
    if ($supplierId <= 0) {
        $errors[] = "Please select a supplier.";
    }
    if ($accountId <= 0) {
        $errors[] = "Please select the account paid from.";
    }
    if ($amount <= 0) {
        $errors[] = "Payment amount must be greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $supplier = db_fetch_one("SELECT * FROM suppliers WHERE id = ?", [$supplierId]);
            if (!$supplier) {
                throw new Exception("Selected supplier does not exist.");
            }

            // Generate Payment Voucher Number
            $paymentNumber = generate_document_number(PREFIX_PAYMENT, 'supplier_payments', 'payment_number');

            // 1. Insert into supplier_payments
            $stmt = db()->prepare("
                INSERT INTO supplier_payments (
                    payment_number, payment_date, supplier_id,
                    amount, payment_mode, account_id, reference_no,
                    remarks, created_by, created_at
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            $stmt->execute([
                $paymentNumber, $paymentDate, $supplierId,
                $amount, $paymentMode, $accountId, $referenceNo,
                $remarks, current_user_id()
            ]);
            $paymentId = (int)db()->lastInsertId();

            // 2. Atomically decrease supplier accounts payable balance
            update_supplier_balance($supplierId, $amount, 'decrease');

            // 3. Post Balanced General Ledger Journal Entry
            // Debit: Accounts Payable 2010 (Decreases Payables Liability)
            // Credit: Cash / Bank Account (Decreases Cash/Bank Asset)
            $journalHeader = [
                'entry_date'     => $paymentDate,
                'reference_type' => 'supplier_payment',
                'reference_id'   => $paymentId,
                'narration'      => "Supplier Payment: {$supplier['supplier_name']} ({$supplier['supplier_code']})" . ($remarks ? " | {$remarks}" : "")
            ];

            $journalLines = [
                [
                    'account_id'  => ACC_PAYABLES,
                    'debit'       => $amount,
                    'credit'      => 0.00,
                    'description' => "Disbursement to {$supplier['supplier_name']}",
                    'party_type'  => 'supplier',
                    'party_id'    => $supplierId
                ],
                [
                    'account_id'  => $accountId,
                    'debit'       => 0.00,
                    'credit'      => $amount,
                    'description' => "Paid via {$paymentMode} to {$supplier['supplier_name']}",
                    'party_type'  => null,
                    'party_id'    => null
                ]
            ];

            post_journal_entry($journalHeader, $journalLines);

            db()->commit();

            set_flash('success', "Supplier payment voucher {$paymentNumber} for " . format_currency($amount) . " recorded successfully.");
            header('Location: view_supplier_payment.php?id=' . $paymentId);
            exit;

        } catch (Exception $e) {
            db()->rollBack();
            $errors[] = "Failed to record payment: " . $e->getMessage();
        }
    }
}

// Fetch Suppliers
$suppliers = db_fetch_all("
    SELECT id, supplier_code, supplier_name, company_name, current_balance 
    FROM suppliers 
    WHERE is_deleted = 0 
    ORDER BY supplier_name ASC
");

// Fetch Accounts (Cash & Bank)
$accounts = db_fetch_all("
    SELECT id, account_code, account_name, current_balance 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank') AND is_active = 1 
    ORDER BY account_code ASC
");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-credit-card-2-front me-2"></i>Record Supplier Payment Voucher
                </h4>
                <p class="text-muted small mb-0">Disburse bank transfer or cash payments against factory payables with instant general ledger posting.</p>
            </div>
            <div>
                <a href="supplier_payments.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Payments (ESC)
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm py-2">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-bottom">
                        <div class="fw-bold text-navy"><i class="bi bi-receipt me-1"></i> Supplier Payment Details</div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="create_supplier_payment.php">
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Supplier / Manufacturing Factory <span class="text-danger">*</span></label>
                                    <select name="supplier_id" class="form-select" required autofocus>
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach ($suppliers as $s): ?>
                                            <?php 
                                                $selected = false;
                                                if (!empty($_POST['supplier_id']) && (int)$_POST['supplier_id'] === (int)$s['id']) {
                                                    $selected = true;
                                                } elseif ($preselectedSupplierId === (int)$s['id']) {
                                                    $selected = true;
                                                }
                                            ?>
                                            <option value="<?= $s['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['supplier_name']) ?> (<?= htmlspecialchars($s['company_name'] ?: $s['supplier_code']) ?>) [Payable: <?= format_currency($s['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Amount Paid (Rs.) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light fw-bold text-navy">Rs.</span>
                                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-lg fw-bold text-danger" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="0.00" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                                    <select name="payment_mode" class="form-select" required>
                                        <option value="bank_transfer" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer (Online / RTGS / IBFT)</option>
                                        <option value="cheque" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'cheque') ? 'selected' : '' ?>>Cheque (Bank Cheque)</option>
                                        <option value="cash" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'cash') ? 'selected' : '' ?>>Cash (Drawer Handover)</option>
                                        <option value="online" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'online') ? 'selected' : '' ?>>Online Transfer</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Paid From Account <span class="text-danger">*</span></label>
                                    <select name="account_id" class="form-select" required>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && (int)$_POST['account_id'] === (int)$acc['id']) ? 'selected' : ($acc['id'] == ACC_BANK_MAIN ? 'selected' : '') ?>>
                                                <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Cheque # / Transaction Reference</label>
                                    <input type="text" name="reference_no" class="form-control" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>" placeholder="e.g. Cheque # 938210, RTGS ref">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Remarks / Narration</label>
                                <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($_POST['remarks'] ?? '') ?>" placeholder="e.g. Factory payment for Biscuit order">
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                                <a href="supplier_payments.php" class="btn btn-light">Cancel</a>
                                <button type="submit" class="btn btn-primary px-4 fw-bold">
                                    <i class="bi bi-check-circle me-1"></i> Save & Post Payment
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.location.href = 'supplier_payments.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
