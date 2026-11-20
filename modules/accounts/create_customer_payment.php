<?php
/**
 * DMS - Distribution Management System
 * Record Customer Payment Receipt
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('payments_manage');

$pageTitle = "Record Customer Payment";
$activeMenu = "customer_payments";
$errors = [];

$preselectedCustomerId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentDate = clean_input($_POST['payment_date'] ?? date('Y-m-d'));
    $customerId  = (int)($_POST['customer_id'] ?? 0);
    $salesmanId  = !empty($_POST['salesman_id']) ? (int)$_POST['salesman_id'] : null;
    $accountId   = (int)($_POST['account_id'] ?? ACC_CASH_MAIN);
    $paymentMode = clean_input($_POST['payment_mode'] ?? 'cash');
    $amount      = round((float)($_POST['amount'] ?? 0), 2);
    $referenceNo = clean_input($_POST['reference_no'] ?? '');
    $remarks     = clean_input($_POST['remarks'] ?? '');

    // Validation
    if (empty($paymentDate) || !strtotime($paymentDate)) {
        $errors[] = "Valid payment date is required.";
    }
    if ($customerId <= 0) {
        $errors[] = "Please select a customer.";
    }
    if ($accountId <= 0) {
        $errors[] = "Please select an account where funds are deposited.";
    }
    if ($amount <= 0) {
        $errors[] = "Payment amount must be greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $customer = db_fetch_one("SELECT * FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                throw new Exception("Selected customer does not exist.");
            }

            // Generate Payment Receipt Number
            $paymentNumber = generate_document_number(PREFIX_PAYMENT, 'customer_payments', 'payment_number');

            // 1. Insert into customer_payments
            $stmt = db()->prepare("
                INSERT INTO customer_payments (
                    payment_number, payment_date, customer_id, salesman_id,
                    amount, payment_mode, account_id, reference_no,
                    remarks, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            $stmt->execute([
                $paymentNumber, $paymentDate, $customerId, $salesmanId,
                $amount, $paymentMode, $accountId, $referenceNo,
                $remarks, current_user_id()
            ]);
            $paymentId = (int)db()->lastInsertId();

            // 2. Atomically decrease customer accounts receivable balance
            update_customer_balance($customerId, $amount, 'decrease');

            // 3. Post Balanced General Ledger Journal Entry
            // Debit: Cash / Bank Account (Increases Asset)
            // Credit: Accounts Receivable 1020 (Decreases Receivables Asset)
            $customerLabel = $customer['shop_name'] . ($customer['owner_name'] ? " - {$customer['owner_name']}" : "");
            $journalHeader = [
                'entry_date'     => $paymentDate,
                'reference_type' => 'cash_receipt',
                'reference_id'   => $paymentId,
                'narration'      => "Customer Payment: {$customerLabel} ({$customer['customer_code']})" . ($remarks ? " | {$remarks}" : "")
            ];

            $journalLines = [
                [
                    'account_id'  => $accountId,
                    'debit'       => $amount,
                    'credit'      => 0.00,
                    'description' => "Received from {$customerLabel} via {$paymentMode}",
                    'party_type'  => null,
                    'party_id'    => null
                ],
                [
                    'account_id'  => ACC_RECEIVABLES,
                    'debit'       => 0.00,
                    'credit'      => $amount,
                    'description' => "Credit recovery for {$customerLabel}",
                    'party_type'  => 'customer',
                    'party_id'    => $customerId
                ]
            ];

            post_journal_entry($journalHeader, $journalLines);

            db()->commit();

            set_flash('success', "Payment receipt {$paymentNumber} for " . format_currency($amount) . " successfully recorded.");
            header('Location: view_customer_payment.php?id=' . $paymentId);
            exit;

        } catch (Exception $e) {
            db()->rollBack();
            $errors[] = "Failed to record payment: " . $e->getMessage();
        }
    }
}

// Fetch Customers with outstanding balances
$customers = db_fetch_all("
    SELECT c.id, c.customer_code, c.shop_name, c.owner_name, c.current_balance, a.area_name
    FROM customers c
    LEFT JOIN areas a ON c.area_id = a.id
    WHERE c.is_deleted = 0
    ORDER BY c.shop_name ASC
");

// Fetch Salesmen
$salesmen = db_fetch_all("SELECT id, salesman_name, role FROM salesmen WHERE is_active = 1 AND is_deleted = 0 ORDER BY salesman_name ASC");

// Fetch Deposit Accounts (Cash & Bank)
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
                    <i class="bi bi-cash-coin me-2"></i>Record Customer Payment / Recovery
                </h4>
                <p class="text-muted small mb-0">Record credit recovery, office cash receipt, or bank transfer with instant customer ledger credit.</p>
            </div>
            <div>
                <a href="customer_payments.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Receipts (ESC)
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
                        <div class="fw-bold text-navy"><i class="bi bi-receipt-cutoff me-1"></i> Receipt Details</div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="create_customer_payment.php">
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Customer / Retail Shop <span class="text-danger">*</span></label>
                                    <select name="customer_id" id="customerSelect" class="form-select" required autofocus>
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach ($customers as $c): ?>
                                            <?php 
                                                $selected = false;
                                                if (!empty($_POST['customer_id']) && (int)$_POST['customer_id'] === (int)$c['id']) {
                                                    $selected = true;
                                                } elseif ($preselectedCustomerId === (int)$c['id']) {
                                                    $selected = true;
                                                }
                                            ?>
                                            <option value="<?= $c['id'] ?>" data-balance="<?= $c['current_balance'] ?>" <?= $selected ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['owner_name'] ?: $c['customer_code']) ?>) [Balance: <?= format_currency($c['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Amount Received (Rs.) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light fw-bold text-navy">Rs.</span>
                                        <input type="number" step="0.01" min="0.01" name="amount" id="amountInput" class="form-control form-control-lg fw-bold text-success" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="0.00" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                                    <select name="payment_mode" class="form-select" required>
                                        <option value="cash" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'cash') ? 'selected' : '' ?>>Cash (Drawer Collection)</option>
                                        <option value="bank_transfer" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer (Direct Deposit)</option>
                                        <option value="cheque" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'cheque') ? 'selected' : '' ?>>Party Cheque</option>
                                        <option value="online" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'online') ? 'selected' : '' ?>>Online / Mobile Payment</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Deposit Into Account <span class="text-danger">*</span></label>
                                    <select name="account_id" class="form-select" required>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && (int)$_POST['account_id'] === (int)$acc['id']) ? 'selected' : ($acc['id'] == ACC_CASH_MAIN ? 'selected' : '') ?>>
                                                <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Collector / Recovery Officer</label>
                                    <select name="salesman_id" class="form-select">
                                        <option value="">-- Office Direct Receipt --</option>
                                        <?php foreach ($salesmen as $sm): ?>
                                            <option value="<?= $sm['id'] ?>" <?= (isset($_POST['salesman_id']) && (int)$_POST['salesman_id'] === (int)$sm['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sm['salesman_name']) ?> (<?= htmlspecialchars($sm['role']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Cheque # / Bank Reference</label>
                                    <input type="text" name="reference_no" class="form-control" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>" placeholder="e.g. Cheque 048291, IBFT-83920">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Remarks / Narration</label>
                                    <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($_POST['remarks'] ?? '') ?>" placeholder="e.g. Partial recovery against Oct bill">
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                                <a href="customer_payments.php" class="btn btn-light">Cancel</a>
                                <button type="submit" class="btn btn-primary px-4 fw-bold">
                                    <i class="bi bi-check-circle me-1"></i> Save & Issue Receipt
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
        window.location.href = 'customer_payments.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
