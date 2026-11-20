<?php
/**
 * DMS - Distribution Management System
 * Record New Daily Operating Expense
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';
require_once __DIR__ . '/../../includes/accounting_functions.php';

require_login();
require_permission('expenses_manage');

$pageTitle = "Record Operating Expense";
$activeMenu = "expenses";
$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expenseDate  = clean_input($_POST['expense_date'] ?? date('Y-m-d'));
    $categoryId   = (int)($_POST['expense_category_id'] ?? 0);
    $paidFromAcc  = (int)($_POST['paid_from_account_id'] ?? ACC_CASH_MAIN);
    $paymentMode  = clean_input($_POST['payment_mode'] ?? 'cash');
    $amount       = round((float)($_POST['amount'] ?? 0), 2);
    $payeeName    = clean_input($_POST['payee_name'] ?? '');
    $referenceNo  = clean_input($_POST['reference_voucher'] ?? '');
    $description  = clean_input($_POST['description'] ?? '');

    // Validation
    if (empty($expenseDate) || !strtotime($expenseDate)) {
        $errors[] = "Valid expense date is required.";
    }
    if ($categoryId <= 0) {
        $errors[] = "Please select an expense category.";
    }
    if ($paidFromAcc <= 0) {
        $errors[] = "Please select a payment account.";
    }
    if ($amount <= 0) {
        $errors[] = "Expense amount must be greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            // Fetch Category Details
            $category = db_fetch_one("SELECT * FROM expense_categories WHERE id = ?", [$categoryId]);
            if (!$category) {
                throw new Exception("Invalid expense category selected.");
            }

            // Determine target expense GL account (default to 6050 Office/General Expense if unset)
            $expenseGlAccount = !empty($category['account_id']) ? (int)$category['account_id'] : 6050;

            // Generate Expense Voucher Number
            $expenseNumber = generate_document_number(PREFIX_EXPENSE, 'expenses', 'expense_number');

            // 1. Insert into expenses
            $stmt = db()->prepare("
                INSERT INTO expenses (
                    expense_number, expense_date, expense_category_id, amount,
                    paid_from_account_id, payment_mode, payee_name, description,
                    reference_voucher, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            $stmt->execute([
                $expenseNumber, $expenseDate, $categoryId, $amount,
                $paidFromAcc, $paymentMode, $payeeName, $description,
                $referenceNo, current_user_id()
            ]);
            $expenseId = (int)db()->lastInsertId();

            // 2. Post Balanced General Ledger Journal Entry
            // Debit: Expense Account (Increases Expense)
            // Credit: Cash / Bank Account (Decreases Asset)
            $journalHeader = [
                'entry_date'     => $expenseDate,
                'reference_type' => 'expense',
                'reference_id'   => $expenseId,
                'narration'      => "Expense: " . $category['category_name'] . ($payeeName ? " | Payee: {$payeeName}" : "") . ($description ? " | {$description}" : "")
            ];

            $journalLines = [
                [
                    'account_id'  => $expenseGlAccount,
                    'debit'       => $amount,
                    'credit'      => 0.00,
                    'description' => "Expense: " . $category['category_name'] . ($payeeName ? " - {$payeeName}" : ""),
                    'party_type'  => null,
                    'party_id'    => null
                ],
                [
                    'account_id'  => $paidFromAcc,
                    'debit'       => 0.00,
                    'credit'      => $amount,
                    'description' => "Paid via {$paymentMode} for " . $category['category_name'],
                    'party_type'  => null,
                    'party_id'    => null
                ]
            ];

            post_journal_entry($journalHeader, $journalLines);

            db()->commit();

            set_flash('success', "Expense voucher {$expenseNumber} for " . format_currency($amount) . " recorded and posted to ledger successfully.");
            header('Location: view.php?id=' . $expenseId);
            exit;

        } catch (Exception $e) {
            db()->rollBack();
            $errors[] = "Failed to record expense: " . $e->getMessage();
        }
    }
}

// Fetch categories and payment accounts
$categories = get_expense_categories();
$paymentAccounts = db_fetch_all("
    SELECT id, account_code, account_name, current_balance 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank') AND is_active = 1 
    ORDER BY account_code ASC
");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="dms-main-content">
    <div class="container-fluid py-3">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>Record Daily Operating Expense
                </h4>
                <p class="text-muted small mb-0">Record van fuel, daily labor, staff wages, repair, or tea expenses with immediate double-entry ledger posting.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Expenses (ESC)
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
                        <div class="fw-bold text-navy"><i class="bi bi-receipt me-1"></i> Expense Voucher Details</div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="create.php" id="expenseForm">
                            
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Expense Date <span class="text-danger">*</span></label>
                                    <input type="date" name="expense_date" class="form-control" value="<?= htmlspecialchars($_POST['expense_date'] ?? date('Y-m-d')) ?>" required autofocus>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Expense Category <span class="text-danger">*</span></label>
                                    <select name="expense_category_id" class="form-select" required>
                                        <option value="">-- Select Expense Category --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['id'] ?>" <?= (isset($_POST['expense_category_id']) && (int)$_POST['expense_category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['category_name']) ?> (<?= htmlspecialchars($cat['category_code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Paid From Account <span class="text-danger">*</span></label>
                                    <select name="paid_from_account_id" class="form-select" required>
                                        <?php foreach ($paymentAccounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" <?= (isset($_POST['paid_from_account_id']) && (int)$_POST['paid_from_account_id'] === (int)$acc['id']) ? 'selected' : ($acc['id'] == ACC_CASH_MAIN ? 'selected' : '') ?>>
                                                <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                                    <select name="payment_mode" class="form-select" required>
                                        <option value="cash" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'cash') ? 'selected' : '' ?>>Cash (Drawer / Handover)</option>
                                        <option value="bank" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'bank') ? 'selected' : '' ?>>Bank (Transfer / Cheque / Online)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Expense Amount (Rs.) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light fw-bold text-navy">Rs.</span>
                                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-lg fw-bold text-navy" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="0.00" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payee / Recipient Name</label>
                                    <input type="text" name="payee_name" class="form-control" value="<?= htmlspecialchars($_POST['payee_name'] ?? '') ?>" placeholder="e.g. PSO Petrol Pump, Driver Saghar, Tea Vendor">
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Bill / Slip / Reference #</label>
                                    <input type="text" name="reference_voucher" class="form-control" value="<?= htmlspecialchars($_POST['reference_voucher'] ?? '') ?>" placeholder="e.g. Fuel receipt #, Invoice ref">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Description / Purpose</label>
                                    <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($_POST['description'] ?? '') ?>" placeholder="e.g. 20 Litres Diesel for Route Delivery Van">
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                                <a href="index.php" class="btn btn-light">Cancel</a>
                                <button type="submit" class="btn btn-primary px-4 fw-bold">
                                    <i class="bi bi-check-circle me-1"></i> Save & Post Voucher
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
        window.location.href = 'index.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
