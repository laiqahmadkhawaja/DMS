<?php
/**
 * DMS - Distribution Management System
 * Record New Daily Operating Expense
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

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

    // Partner Khata & Expense Sharing Parameters
    $rawPaidByType = clean_input($_POST['paid_by_type'] ?? 'central_cash');
    $paidByType    = 'central_cash';
    $paidByPid     = null;

    if ($rawPaidByType === 'partner_moazzam') {
        $paidByType = 'partner';
        $paidByPid  = (int)db_fetch_val("SELECT id FROM partners WHERE partner_code = 'PTR-MOAZZAM' LIMIT 1") ?: 1;
    } elseif ($rawPaidByType === 'partner_rehman') {
        $paidByType = 'partner';
        $paidByPid  = (int)db_fetch_val("SELECT id FROM partners WHERE partner_code = 'PTR-REHMAN' LIMIT 1") ?: 2;
    } elseif ($rawPaidByType === 'partner') {
        $paidByType = 'partner';
        $paidByPid  = !empty($_POST['paid_by_partner_id']) ? (int)$_POST['paid_by_partner_id'] : null;
    }

    $splitType       = clean_input($_POST['split_type'] ?? 'equal_50_50');
    $customSplitJson = null;
    if ($splitType === 'custom') {
        $moazzamId = (int)db_fetch_val("SELECT id FROM partners WHERE partner_code = 'PTR-MOAZZAM' LIMIT 1") ?: 1;
        $rehmanId  = (int)db_fetch_val("SELECT id FROM partners WHERE partner_code = 'PTR-REHMAN' LIMIT 1") ?: 2;
        $mSplit    = (float)($_POST['custom_split_moazzam'] ?? 0);
        $rSplit    = (float)($_POST['custom_split_rehman'] ?? 0);
        $customSplitJson = json_encode([$moazzamId => $mSplit, $rehmanId => $rSplit]);
    }

    // Validation
    if (empty($expenseDate) || !strtotime($expenseDate)) {
        $errors[] = "Valid expense date is required.";
    }
    if ($categoryId <= 0) {
        $errors[] = "Please select an expense category.";
    }
    if ($paidByType === 'central_cash' && $paidFromAcc <= 0) {
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
                    reference_voucher, paid_by_type, paid_by_partner_id, split_type, split_details,
                    created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, NOW()
                )
            ");
            $stmt->execute([
                $expenseNumber, $expenseDate, $categoryId, $amount,
                $paidFromAcc, $paymentMode, $payeeName, $description,
                $referenceNo, $paidByType, $paidByPid, $splitType, $customSplitJson,
                current_user_id()
            ]);
            $expenseId = (int)db()->lastInsertId();

            // 2. Synchronize with Partner Khata Ledger
            sync_expense_partner_transactions($expenseId, [
                'amount'             => $amount,
                'expense_date'       => $expenseDate,
                'paid_by_type'       => $paidByType,
                'paid_by_partner_id' => $paidByPid,
                'split_type'         => $splitType,
                'split_details'      => $customSplitJson,
                'description'        => $description,
                'category_name'      => $category['category_name']
            ]);

            // 3. Post Balanced General Ledger Journal Entry
            // Debit: Expense Account (Increases Expense)
            // Credit: Cash/Bank Account if central pool, OR Partner Equity Account (3010) if partner paid!
            $journalHeader = [
                'entry_date'     => $expenseDate,
                'reference_type' => 'expense',
                'reference_id'   => $expenseId,
                'narration'      => "Expense: " . $category['category_name'] . ($payeeName ? " | Payee: {$payeeName}" : "") . ($description ? " | {$description}" : "")
            ];

            $creditAccountId = ($paidByType === 'partner') ? 3010 : $paidFromAcc;
            $creditDesc = ($paidByType === 'partner') 
                ? "Funded by Partner out of personal pocket" 
                : "Paid via {$paymentMode} from Central Pool for " . $category['category_name'];

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
                    'account_id'  => $creditAccountId,
                    'debit'       => 0.00,
                    'credit'      => $amount,
                    'description' => $creditDesc,
                    'party_type'  => ($paidByType === 'partner' && $paidByPid > 0) ? 'partner' : null,
                    'party_id'    => ($paidByType === 'partner' && $paidByPid > 0) ? $paidByPid : null
                ]
            ];

            post_journal_entry($journalHeader, $journalLines);

            db()->commit();

            set_flash('success', "Expense voucher {$expenseNumber} for " . format_currency($amount) . " recorded and synchronized with Partner Khatas successfully.");
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
    SELECT id, account_code, account_name, sub_type, bank_name, account_number, current_balance 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank', 'wallet') AND is_active = 1 
    ORDER BY 
        CASE sub_type 
            WHEN 'cash' THEN 1 
            WHEN 'bank' THEN 2 
            WHEN 'wallet' THEN 3 
            ELSE 4 
        END,
        account_code ASC
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

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
                                    <?php
                                        $groupedAccs = ['cash' => [], 'bank' => [], 'wallet' => []];
                                        foreach ($paymentAccounts as $pa) {
                                            $groupedAccs[$pa['sub_type']][] = $pa;
                                        }
                                    ?>
                                    <select name="paid_from_account_id" class="form-select" required>
                                        <?php if (!empty($groupedAccs['cash'])): ?>
                                            <optgroup label="💵 Cash in Hand / Drawers">
                                                <?php foreach ($groupedAccs['cash'] as $acc): ?>
                                                    <option value="<?= $acc['id'] ?>" <?= (isset($_POST['paid_from_account_id']) && (int)$_POST['paid_from_account_id'] === (int)$acc['id']) ? 'selected' : ($acc['id'] == ACC_CASH_MAIN ? 'selected' : '') ?>>
                                                        <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                        <?php if (!empty($groupedAccs['bank'])): ?>
                                            <optgroup label="🏦 Commercial Bank Accounts">
                                                <?php foreach ($groupedAccs['bank'] as $acc): ?>
                                                    <option value="<?= $acc['id'] ?>" <?= (isset($_POST['paid_from_account_id']) && (int)$_POST['paid_from_account_id'] === (int)$acc['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> <?= $acc['bank_name'] ? "({$acc['bank_name']})" : "" ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                        <?php if (!empty($groupedAccs['wallet'])): ?>
                                            <optgroup label="📱 Digital Wallets (JazzCash / EasyPaisa / Raast)">
                                                <?php foreach ($groupedAccs['wallet'] as $acc): ?>
                                                    <option value="<?= $acc['id'] ?>" <?= (isset($_POST['paid_from_account_id']) && (int)$_POST['paid_from_account_id'] === (int)$acc['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> <?= $acc['account_number'] ? "({$acc['account_number']})" : "" ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                                    <select name="payment_mode" class="form-select" required>
                                        <option value="cash" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'cash') ? 'selected' : '' ?>>Cash (Drawer / Handover)</option>
                                        <option value="bank" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'bank') ? 'selected' : '' ?>>Bank (Transfer / Cheque / Online)</option>
                                        <option value="wallet" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'wallet') ? 'selected' : '' ?>>Digital Wallet (JazzCash / EasyPaisa / Raast)</option>
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

                            <!-- Partner Khata & Expense Sharing System -->
                            <div class="card bg-light border-primary border-opacity-25 mb-4 shadow-sm">
                                <div class="card-header bg-primary bg-opacity-10 py-2 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-navy small">
                                        <i class="bi bi-people-fill me-1 text-primary"></i> Partner Khata & Expense Sharing (پارٹنرز کھاتہ و اخراجات کی تقسیم)
                                    </span>
                                    <span class="badge bg-primary">Automated Khata Ledger</span>
                                </div>
                                <div class="card-body p-3">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">
                                                Paid By (کس نے رقم ادا کی؟) <span class="text-danger">*</span>
                                            </label>
                                            <select name="paid_by_type" id="paidByType" class="form-select form-select-sm" onchange="onPayerChange()">
                                                <option value="central_cash" <?= (isset($_POST['paid_by_type']) && $_POST['paid_by_type'] === 'central_cash') ? 'selected' : '' ?>>
                                                    🏢 Central Sales Pool / گودام کیش (Partner 3 / کیش کسٹوڈین)
                                                </option>
                                                <option value="partner_moazzam" <?= (isset($_POST['paid_by_type']) && $_POST['paid_by_type'] === 'partner_moazzam') ? 'selected' : '' ?>>
                                                    👤 Moazzam (معظم نے اپنی ذاتی جیب سے دیا)
                                                </option>
                                                <option value="partner_rehman" <?= (isset($_POST['paid_by_type']) && $_POST['paid_by_type'] === 'partner_rehman') ? 'selected' : '' ?>>
                                                    👤 Abdur Rehman (عبدالرحمن نے اپنی ذاتی جیب سے دیا)
                                                </option>
                                            </select>
                                            <div class="form-text small" id="payerNote">
                                                اگر گودام کے سیلز کیش سے خرچہ ہوا ہے تو سنٹرل پول رکھیں، اگر پارٹنر نے ذاتی جیب سے ادا کیا ہے تو اس کا نام منتخب کریں۔
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">
                                                Khata Division / Split (خرچہ کس کے کھاتے میں جائے گا؟) <span class="text-danger">*</span>
                                            </label>
                                            <select name="split_type" id="splitType" class="form-select form-select-sm" onchange="onSplitChange()">
                                                <option value="equal_50_50" <?= (!isset($_POST['split_type']) || $_POST['split_type'] === 'equal_50_50') ? 'selected' : '' ?>>
                                                    ⚖️ Shared 50 / 50 (50% معظم + 50% عبدالرحمن) — برابر تقسیم
                                                </option>
                                                <option value="only_moazzam" <?= (isset($_POST['split_type']) && $_POST['split_type'] === 'only_moazzam') ? 'selected' : '' ?>>
                                                    🔵 100% Moazzam (صرف معظم کے کھاتے میں - SMS)
                                                </option>
                                                <option value="only_rehman" <?= (isset($_POST['split_type']) && $_POST['split_type'] === 'only_rehman') ? 'selected' : '' ?>>
                                                    🟢 100% Abdur Rehman (صرف عبدالرحمن کے کھاتے میں - Ujab)
                                                </option>
                                                <option value="custom" <?= (isset($_POST['split_type']) && $_POST['split_type'] === 'custom') ? 'selected' : '' ?>>
                                                    ✏️ Custom Split (اپنی مرضی سے رقم تقسیم کریں)
                                                </option>
                                                <option value="none" <?= (isset($_POST['split_type']) && $_POST['split_type'] === 'none') ? 'selected' : '' ?>>
                                                    🏢 General Company Expense (مشترکہ پول سے بغیر ذاتی کھاتے کے)
                                                </option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Custom Split Inputs -->
                                    <div id="customSplitRow" class="row g-2 d-none mt-2 p-2 bg-white rounded border">
                                        <div class="col-6">
                                            <label class="form-label small text-muted">Moazzam Share (Rs.):</label>
                                            <input type="number" step="0.01" name="custom_split_moazzam" id="customSplitMoazzam" class="form-control form-control-sm" placeholder="0.00" oninput="updateSplitPreview()">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small text-muted">Abdur Rehman Share (Rs.):</label>
                                            <input type="number" step="0.01" name="custom_split_rehman" id="customSplitRehman" class="form-control form-control-sm" placeholder="0.00" oninput="updateSplitPreview()">
                                        </div>
                                    </div>

                                    <!-- Live Khata Split Preview Banner -->
                                    <div class="alert alert-info py-2 px-3 mt-3 mb-0 small" id="splitPreviewBanner">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-info-circle-fill me-2 fs-6"></i>
                                            <div id="splitPreviewText">
                                                <strong>50 / 50 تقسیم:</strong> ہر پارٹنر (معظم اور عبدالرحمن) کے کھاتے میں آدھا آدھا خرچہ چارج ہوگا۔
                                            </div>
                                        </div>
                                    </div>

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

<script>
function onPayerChange() {
    const payer = document.getElementById('paidByType').value;
    const paidFromGroup = document.querySelector('select[name="paid_from_account_id"]').closest('.col-md-6');
    const payerNote = document.getElementById('payerNote');

    if (payer === 'central_cash') {
        paidFromGroup.style.opacity = '1';
        paidFromGroup.style.pointerEvents = 'auto';
        payerNote.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i> خرچہ سنٹرل کیش پول (گودام دراز / تھرڈ پارٹنر) سے ادا ہوگا۔</span>';
    } else if (payer === 'partner_moazzam') {
        paidFromGroup.style.opacity = '0.5';
        payerNote.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-wallet2 me-1"></i> معظم نے ذاتی جیب سے رقم دی ہے۔ معظم کے کھاتے میں کریڈٹ (اضافہ) ہوگا۔</span>';
    } else if (payer === 'partner_rehman') {
        paidFromGroup.style.opacity = '0.5';
        payerNote.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-wallet2 me-1"></i> عبدالرحمن نے ذاتی جیب سے رقم دی ہے۔ عبدالرحمن کے کھاتے میں کریڈٹ (اضافہ) ہوگا۔</span>';
    }
    updateSplitPreview();
}

function onSplitChange() {
    const splitType = document.getElementById('splitType').value;
    const customRow = document.getElementById('customSplitRow');
    if (splitType === 'custom') {
        customRow.classList.remove('d-none');
    } else {
        customRow.classList.add('d-none');
    }
    updateSplitPreview();
}

function updateSplitPreview() {
    const amount = parseFloat(document.querySelector('input[name="amount"]').value) || 0;
    const payer = document.getElementById('paidByType').value;
    const splitType = document.getElementById('splitType').value;
    const textEl = document.getElementById('splitPreviewText');

    let payerStr = (payer === 'partner_moazzam') ? 'معظم' : ((payer === 'partner_rehman') ? 'عبدالرحمن' : 'سنٹرل کیش پول (تھرڈ پارٹنر)');
    let half = (amount / 2).toFixed(2);

    if (splitType === 'equal_50_50') {
        textEl.innerHTML = `<strong>تقسیم کا نتیجہ:</strong> ادا کنندہ: <strong>${payerStr}</strong> | معظم کا حصہ: <strong>Rs. ${half}</strong> | عبدالرحمن کا حصہ: <strong>Rs. ${half}</strong>`;
    } else if (splitType === 'only_moazzam') {
        textEl.innerHTML = `<strong>تقسیم کا نتیجہ:</strong> پورا خرچہ (Rs. ${amount.toFixed(2)}) صرف <strong>معظم (SMS)</strong> کے کھاتے میں ڈیبٹ ہوگا۔`;
    } else if (splitType === 'only_rehman') {
        textEl.innerHTML = `<strong>تقسیم کا نتیجہ:</strong> پورا خرچہ (Rs. ${amount.toFixed(2)}) صرف <strong>عبدالرحمن (Ujab)</strong> کے کھاتے میں ڈیبٹ ہوگا۔`;
    } else if (splitType === 'custom') {
        const mVal = parseFloat(document.getElementById('customSplitMoazzam').value) || 0;
        const rVal = parseFloat(document.getElementById('customSplitRehman').value) || 0;
        textEl.innerHTML = `<strong>اپنی مرضی کی تقسیم:</strong> معظم: Rs. ${mVal.toFixed(2)} | عبدالرحمن: Rs. ${rVal.toFixed(2)}`;
    } else {
        textEl.innerHTML = `<strong>مشترکہ کمپنی پول:</strong> یہ خرچہ پارٹنرز کے ذاتی کھاتوں پر اثر انداز نہیں ہوگا، سنٹرل فنڈ سے کٹے گا۔`;
    }
}

document.querySelector('input[name="amount"]').addEventListener('input', updateSplitPreview);
document.addEventListener('DOMContentLoaded', function() {
    onPayerChange();
    onSplitChange();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.location.href = 'index.php';
    }
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
