<?php
/**
 * DMS - Distribution Management System
 * Financial Accounts Management (Cash Drawers, Commercial Banks, Digital Wallets)
 * Comprehensive management of liquid financial accounts, live balances, and inter-account transfers.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('accounts_view');

$pageTitle = "Cash, Bank & Digital Wallet Accounts";
$activeMenu = "manage_accounts";

$errors = [];
$successMessage = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_input($_POST['action'] ?? '');

    // 1. CREATE NEW ACCOUNT
    if ($action === 'create') {
        $accountCode = strtoupper(trim(clean_input($_POST['account_code'] ?? '')));
        $accountName = trim(clean_input($_POST['account_name'] ?? ''));
        $subType     = clean_input($_POST['sub_type'] ?? 'cash');
        $bankName    = clean_input($_POST['bank_name'] ?? '');
        $accountNo   = clean_input($_POST['account_number'] ?? '');
        $branchCode  = clean_input($_POST['branch_code'] ?? '');
        $notes       = clean_input($_POST['notes'] ?? '');
        $openingBal  = round((float)($_POST['opening_balance'] ?? 0), 2);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($accountCode)) {
            $errors[] = "Account code is required.";
        }
        if (empty($accountName)) {
            $errors[] = "Account name / title is required.";
        }
        if (!in_array($subType, ['cash', 'bank', 'wallet'], true)) {
            $errors[] = "Invalid account category selected.";
        }

        // Check uniqueness of account code
        if (empty($errors)) {
            $exists = db_fetch_val("SELECT COUNT(*) FROM accounts WHERE account_code = ?", [$accountCode]);
            if ($exists > 0) {
                $errors[] = "Account code '{$accountCode}' already exists. Please choose a unique code.";
            }
        }

        if (empty($errors)) {
            try {
                db()->beginTransaction();

                // Insert into accounts
                $stmt = db()->prepare("
                    INSERT INTO accounts (
                        account_code, account_name, account_type, sub_type,
                        bank_name, account_number, branch_code,
                        opening_balance, current_balance, is_system, is_active, notes, created_at, updated_at
                    ) VALUES (
                        ?, ?, 'asset', ?,
                        ?, ?, ?,
                        ?, 0.00, 0, ?, ?, NOW(), NOW()
                    )
                ");
                $stmt->execute([
                    $accountCode, $accountName, $subType,
                    $bankName, $accountNo, $branchCode,
                    $openingBal, $isActive, $notes
                ]);
                $newAccountId = (int)db()->lastInsertId();

                // If opening balance > 0, post balanced journal voucher against Owner Equity (3010)
                if ($openingBal > 0) {
                    $journalHeader = [
                        'entry_date'     => date('Y-m-d'),
                        'reference_type' => 'opening',
                        'reference_id'   => $newAccountId,
                        'narration'      => "Opening liquid balance for {$accountName} [{$accountCode}]"
                    ];
                    $journalLines = [
                        [
                            'account_id'  => $newAccountId,
                            'debit'       => $openingBal,
                            'credit'      => 0.00,
                            'description' => "Initial opening liquid balance for {$accountName}",
                            'party_type'  => null,
                            'party_id'    => null
                        ],
                        [
                            'account_id'  => 3010, // Owner Capital / Equity
                            'debit'       => 0.00,
                            'credit'      => $openingBal,
                            'description' => "Capital equity contribution for account {$accountCode}",
                            'party_type'  => null,
                            'party_id'    => null
                        ]
                    ];
                    post_journal_entry($journalHeader, $journalLines);
                }

                // If sub_type is cash, also ensure an entry in cash_accounts
                if ($subType === 'cash') {
                    $stmtCa = db()->prepare("
                        INSERT INTO cash_accounts (account_id, account_name, custodian_name, is_active, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmtCa->execute([$newAccountId, $accountName, $branchCode ?: 'Cashier', $isActive]);
                }

                db()->commit();
                set_flash('success', "Financial account '{$accountName}' ({$accountCode}) created successfully.");
                redirect('manage_accounts.php');
            } catch (Exception $e) {
                db()->rollBack();
                $errors[] = "Failed to create account: " . $e->getMessage();
            }
        }
    }

    // 2. EDIT ACCOUNT
    elseif ($action === 'edit') {
        $accountId   = (int)($_POST['account_id'] ?? 0);
        $accountName = trim(clean_input($_POST['account_name'] ?? ''));
        $bankName    = clean_input($_POST['bank_name'] ?? '');
        $accountNo   = clean_input($_POST['account_number'] ?? '');
        $branchCode  = clean_input($_POST['branch_code'] ?? '');
        $notes       = clean_input($_POST['notes'] ?? '');
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($accountId <= 0) {
            $errors[] = "Invalid account selected for update.";
        }
        if (empty($accountName)) {
            $errors[] = "Account title / name is required.";
        }

        if (empty($errors)) {
            try {
                $acc = db_fetch_one("SELECT * FROM accounts WHERE id = ?", [$accountId]);
                if (!$acc) {
                    throw new Exception("Account not found.");
                }

                // Prevent deactivating system accounts
                if ($acc['is_system'] && $isActive == 0) {
                    $isActive = 1; // Preserve system accounts active
                }

                $stmt = db()->prepare("
                    UPDATE accounts 
                    SET account_name = ?, bank_name = ?, account_number = ?, branch_code = ?, notes = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$accountName, $bankName, $accountNo, $branchCode, $notes, $isActive, $accountId]);

                // Update cash_accounts if linked
                db()->prepare("UPDATE cash_accounts SET account_name = ?, is_active = ? WHERE account_id = ?")
                    ->execute([$accountName, $isActive, $accountId]);

                set_flash('success', "Account '{$accountName}' updated successfully.");
                redirect('manage_accounts.php');
            } catch (Exception $e) {
                $errors[] = "Update failed: " . $e->getMessage();
            }
        }
    }

    // 3. INTERNAL FUND TRANSFER
    elseif ($action === 'transfer') {
        $transferDate = clean_input($_POST['transfer_date'] ?? date('Y-m-d'));
        $fromAccId    = (int)($_POST['from_account_id'] ?? 0);
        $toAccId      = (int)($_POST['to_account_id'] ?? 0);
        $amount       = round((float)($_POST['amount'] ?? 0), 2);
        $transferNote = clean_input($_POST['transfer_notes'] ?? '');

        if ($fromAccId <= 0 || $toAccId <= 0) {
            $errors[] = "Please select both source and destination accounts.";
        }
        if ($fromAccId === $toAccId) {
            $errors[] = "Source and destination accounts must be different.";
        }
        if ($amount <= 0) {
            $errors[] = "Transfer amount must be greater than zero.";
        }
        if (empty($transferDate) || !strtotime($transferDate)) {
            $errors[] = "Valid transfer date is required.";
        }

        if (empty($errors)) {
            try {
                db()->beginTransaction();

                $fromAcc = db_fetch_one("SELECT * FROM accounts WHERE id = ?", [$fromAccId]);
                $toAcc   = db_fetch_one("SELECT * FROM accounts WHERE id = ?", [$toAccId]);

                if (!$fromAcc || !$toAcc) {
                    throw new Exception("One or both selected accounts do not exist.");
                }

                // Post balanced internal journal transfer
                // Debit: Destination Account (Increases Asset)
                // Credit: Source Account (Decreases Asset)
                $narration = "Internal Fund Transfer: " . $fromAcc['account_name'] . " -> " . $toAcc['account_name'];
                if ($transferNote) {
                    $narration .= " | " . $transferNote;
                }

                $journalHeader = [
                    'entry_date'     => $transferDate,
                    'reference_type' => 'manual_journal',
                    'reference_id'   => null,
                    'narration'      => $narration
                ];

                $journalLines = [
                    [
                        'account_id'  => $toAccId,
                        'debit'       => $amount,
                        'credit'      => 0.00,
                        'description' => "Transfer received from {$fromAcc['account_name']}",
                        'party_type'  => null,
                        'party_id'    => null
                    ],
                    [
                        'account_id'  => $fromAccId,
                        'debit'       => 0.00,
                        'credit'      => $amount,
                        'description' => "Transfer disbursed to {$toAcc['account_name']}",
                        'party_type'  => null,
                        'party_id'    => null
                    ]
                ];

                $jvId = post_journal_entry($journalHeader, $journalLines);

                db()->commit();
                set_flash('success', "Transfer of " . format_currency($amount) . " from {$fromAcc['account_name']} to {$toAcc['account_name']} completed successfully (JV #{$jvId}).");
                redirect('manage_accounts.php');
            } catch (Exception $e) {
                db()->rollBack();
                $errors[] = "Transfer failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch all financial accounts (Cash, Bank, Wallets)
$filterType = clean_input($_GET['type'] ?? 'all');
$whereClause = "sub_type IN ('cash', 'bank', 'wallet')";
$params = [];

if (in_array($filterType, ['cash', 'bank', 'wallet'], true)) {
    $whereClause .= " AND sub_type = ?";
    $params[] = $filterType;
}

$accounts = db_fetch_all("
    SELECT a.*,
        (SELECT COUNT(jel.id) FROM journal_entry_lines jel WHERE jel.account_id = a.id) as total_txns
    FROM accounts a
    WHERE {$whereClause}
    ORDER BY 
        CASE a.sub_type 
            WHEN 'cash' THEN 1 
            WHEN 'bank' THEN 2 
            WHEN 'wallet' THEN 3 
            ELSE 4 
        END,
        a.account_code ASC
", $params);

// Calculate totals
$allLiquidAccounts = db_fetch_all("SELECT sub_type, current_balance FROM accounts WHERE sub_type IN ('cash', 'bank', 'wallet') AND is_active = 1");
$totalCash = 0.0;
$totalBank = 0.0;
$totalWallet = 0.0;

foreach ($allLiquidAccounts as $la) {
    $b = (float)$la['current_balance'];
    if ($la['sub_type'] === 'cash') $totalCash += $b;
    elseif ($la['sub_type'] === 'bank') $totalBank += $b;
    elseif ($la['sub_type'] === 'wallet') $totalWallet += $b;
}
$totalLiquid = $totalCash + $totalBank + $totalWallet;

// Suggest next account codes
$maxCashCode   = db_fetch_val("SELECT MAX(CAST(account_code AS UNSIGNED)) FROM accounts WHERE sub_type = 'cash' AND account_code REGEXP '^[0-9]+$'");
$nextCashCode  = $maxCashCode ? ($maxCashCode + 1) : 1003;

$maxBankCode   = db_fetch_val("SELECT MAX(CAST(account_code AS UNSIGNED)) FROM accounts WHERE sub_type = 'bank' AND account_code REGEXP '^[0-9]+$'");
$nextBankCode  = $maxBankCode ? ($maxBankCode + 1) : 1011;

$maxWalletCode = db_fetch_val("SELECT MAX(CAST(account_code AS UNSIGNED)) FROM accounts WHERE sub_type = 'wallet' AND account_code REGEXP '^[0-9]+$'");
$nextWalletCode = $maxWalletCode ? ($maxWalletCode + 1) : 1017;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header & Action Buttons -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-wallet2 me-2"></i>Cash, Bank & Digital Wallet Accounts
                </h4>
                <p class="text-muted small mb-0">Manage cash registers, commercial banks, and digital wallets (JazzCash, EasyPaisa) with live balances and transfer tools.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#transferModal">
                    <i class="bi bi-arrow-left-right me-1"></i> Internal Transfer
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Account / Wallet
                </button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-diagram-3 me-1"></i> Chart of Accounts
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

        <!-- Balance Metrics Overview -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Total Liquid Funds</div>
                            <div class="h4 fw-bold text-success mt-1 mb-0"><?= format_currency($totalLiquid) ?></div>
                            <div class="small text-muted mt-1">Cash + Bank + Wallets Combined</div>
                        </div>
                        <div class="bg-success-subtle text-success p-3 rounded-circle fs-4">
                            <i class="bi bi-safe2"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Cash in Hand / Tills</div>
                            <div class="h4 fw-bold text-primary mt-1 mb-0"><?= format_currency($totalCash) ?></div>
                            <div class="small text-muted mt-1">Office drawers & route buffers</div>
                        </div>
                        <div class="bg-primary-subtle text-primary p-3 rounded-circle fs-4">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Commercial Banks</div>
                            <div class="h4 fw-bold text-info mt-1 mb-0"><?= format_currency($totalBank) ?></div>
                            <div class="small text-muted mt-1">Current & operational accounts</div>
                        </div>
                        <div class="bg-info-subtle text-info p-3 rounded-circle fs-4">
                            <i class="bi bi-bank"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Digital Wallets</div>
                            <div class="h4 fw-bold text-warning-emphasis mt-1 mb-0"><?= format_currency($totalWallet) ?></div>
                            <div class="small text-muted mt-1">JazzCash, EasyPaisa, SadaPay</div>
                        </div>
                        <div class="bg-warning-subtle text-warning-emphasis p-3 rounded-circle fs-4">
                            <i class="bi bi-phone"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Tabs / Pills -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <ul class="nav nav-pills gap-2">
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterType === 'all' ? 'active' : '' ?>" href="manage_accounts.php">
                            <i class="bi bi-grid me-1"></i> All Accounts (<?= count($allLiquidAccounts) ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterType === 'cash' ? 'active' : '' ?>" href="manage_accounts.php?type=cash">
                            <i class="bi bi-cash-stack me-1"></i> Cash Drawers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterType === 'bank' ? 'active' : '' ?>" href="manage_accounts.php?type=bank">
                            <i class="bi bi-bank me-1"></i> Bank Accounts
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterType === 'wallet' ? 'active' : '' ?>" href="manage_accounts.php?type=wallet">
                            <i class="bi bi-phone me-1"></i> Digital Wallets
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Accounts Master Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div class="fw-bold text-navy">
                    <i class="bi bi-list-check me-1"></i> Active Financial Instruments & Ledgers
                </div>
                <div class="text-muted small">
                    Showing <?= count($accounts) ?> active account(s)
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-3" style="width: 100px;">Code</th>
                            <th>Account Title & Category</th>
                            <th>Bank / Provider</th>
                            <th>Account / Mobile #</th>
                            <th>Branch / Location</th>
                            <th class="text-end">Opening</th>
                            <th class="text-end">Current Balance</th>
                            <th class="text-center">Status</th>
                            <th class="text-center" style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-1"></i>
                                    No financial accounts found matching the current criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($accounts as $a): ?>
                                <?php 
                                    $bal = (float)$a['current_balance'];
                                    $subType = $a['sub_type'];
                                    $typeBadge = '';
                                    if ($subType === 'cash') {
                                        $typeBadge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-cash-stack me-1"></i>Cash Drawer</span>';
                                    } elseif ($subType === 'bank') {
                                        $typeBadge = '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="bi bi-bank me-1"></i>Commercial Bank</span>';
                                    } elseif ($subType === 'wallet') {
                                        $typeBadge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-phone me-1"></i>Digital Wallet</span>';
                                    }
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <span class="badge bg-light text-dark font-monospace border px-2 py-1">
                                            <?= htmlspecialchars($a['account_code']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-navy"><?= htmlspecialchars($a['account_name']) ?></div>
                                        <div class="mt-1"><?= $typeBadge ?></div>
                                        <?php if (!empty($a['notes'])): ?>
                                            <div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($a['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= !empty($a['bank_name']) ? htmlspecialchars($a['bank_name']) : '<span class="text-muted small">N/A</span>' ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($a['account_number'])): ?>
                                            <span class="font-monospace text-dark fw-semibold"><?= htmlspecialchars($a['account_number']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= !empty($a['branch_code']) ? htmlspecialchars($a['branch_code']) : '<span class="text-muted small">-</span>' ?>
                                    </td>
                                    <td class="text-end text-muted small">
                                        <?= format_currency($a['opening_balance']) ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold fs-6 <?= $bal >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= format_currency($bal) ?>
                                        </span>
                                        <div class="text-muted" style="font-size: 0.72rem;">
                                            <?= (int)$a['total_txns'] ?> GL transactions
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($a['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="ledger.php?account_id=<?= $a['id'] ?>" class="btn btn-outline-primary" title="View General Ledger History">
                                                <i class="bi bi-journal-text"></i> Ledger
                                            </a>
                                            <button type="button" class="btn btn-outline-secondary edit-account-btn" 
                                                    data-bs-toggle="modal" data-bs-target="#editAccountModal"
                                                    data-id="<?= $a['id'] ?>"
                                                    data-code="<?= htmlspecialchars($a['account_code']) ?>"
                                                    data-name="<?= htmlspecialchars($a['account_name']) ?>"
                                                    data-subtype="<?= htmlspecialchars($a['sub_type']) ?>"
                                                    data-bank="<?= htmlspecialchars($a['bank_name'] ?? '') ?>"
                                                    data-accno="<?= htmlspecialchars($a['account_number'] ?? '') ?>"
                                                    data-branch="<?= htmlspecialchars($a['branch_code'] ?? '') ?>"
                                                    data-notes="<?= htmlspecialchars($a['notes'] ?? '') ?>"
                                                    data-active="<?= (int)$a['is_active'] ?>"
                                                    title="Edit Account Details">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
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

<!-- MODAL: ADD ACCOUNT / WALLET -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="manage_accounts.php">
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-1"></i> Add Financial Account / Wallet</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Account Category <span class="text-danger">*</span></label>
                        <select name="sub_type" id="newSubType" class="form-select" required>
                            <option value="cash">💵 Cash in Hand / Drawer / Till</option>
                            <option value="bank">🏦 Commercial Bank Account</option>
                            <option value="wallet">📱 Digital Mobile Wallet (JazzCash / EasyPaisa / etc.)</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Account Code <span class="text-danger">*</span></label>
                            <input type="text" name="account_code" id="newAccountCode" class="form-control font-monospace" value="<?= $nextCashCode ?>" required>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Account Title / Name <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control" placeholder="e.g. Meezan Bank / JazzCash Till" required>
                        </div>
                    </div>

                    <div id="bankWalletFields">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" id="lblBankName">Bank / Provider Name</label>
                                <input type="text" name="bank_name" id="newBankName" class="form-control" placeholder="e.g. JazzCash / Meezan">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" id="lblAccountNo">Account / Mobile Number</label>
                                <input type="text" name="account_number" id="newAccountNo" class="form-control font-monospace" placeholder="e.g. 0300-1234567">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" id="lblBranch">Branch Code / Location / City</label>
                            <input type="text" name="branch_code" id="newBranch" class="form-control" placeholder="e.g. Main Branch / Counter 1">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Initial Opening Balance (PKR)</label>
                            <input type="number" name="opening_balance" class="form-control" step="0.01" min="0" value="0.00">
                            <div class="form-text small">If > 0, an automatic balanced entry against Owner Capital (3010) is created.</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-center mt-3">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="newIsActive" value="1" checked>
                                <label class="form-check-label fw-semibold" for="newIsActive">Is Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Description / Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Van driver cash buffer, shop counter wallet..."></textarea>
                    </div>

                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-check2-circle me-1"></i> Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EDIT ACCOUNT -->
<div class="modal fade" id="editAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="manage_accounts.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="account_id" id="editAccountId">
                
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-1"></i> Edit Account Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Account Code</label>
                            <input type="text" id="editAccountCode" class="form-control font-monospace bg-light" readonly>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Account Title / Name <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" id="editAccountName" class="form-control" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Bank / Provider Name</label>
                            <input type="text" name="bank_name" id="editBankName" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Account / Mobile Number</label>
                            <input type="text" name="account_number" id="editAccountNo" class="form-control font-monospace">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch Code / Location / Custodian</label>
                        <input type="text" name="branch_code" id="editBranch" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description / Notes</label>
                        <textarea name="notes" id="editNotes" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive" value="1">
                        <label class="form-check-label fw-semibold" for="editIsActive">Active Account (Allowed for new transactions)</label>
                    </div>

                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark fw-bold"><i class="bi bi-save me-1"></i> Update Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: INTERNAL FUND TRANSFER -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="manage_accounts.php">
                <input type="hidden" name="action" value="transfer">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right me-1"></i> Internal Fund Transfer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">Move funds between Cash Drawers, Commercial Banks, or Digital Wallets (e.g. Bank Deposit, Cash Withdrawal, JazzCash Top-up).</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Transfer Date <span class="text-danger">*</span></label>
                        <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">From Account (Source / Credit) <span class="text-danger">*</span></label>
                        <select name="from_account_id" id="fromAccSelect" class="form-select" required>
                            <option value="">-- Select Source Account --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">To Account (Destination / Debit) <span class="text-danger">*</span></label>
                        <select name="to_account_id" id="toAccSelect" class="form-select" required>
                            <option value="">-- Select Destination Account --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> [Bal: <?= format_currency($acc['current_balance']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Transfer Amount (PKR) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control fs-5 fw-bold text-success" step="0.01" min="1" placeholder="0.00" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Transfer Memo / Slip Ref</label>
                        <input type="text" name="transfer_notes" class="form-control" placeholder="e.g. Cash deposit slip #1234 or ATM withdrawal">
                    </div>

                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-check2-circle me-1"></i> Complete Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const subTypeSelect = document.getElementById('newSubType');
    const codeInput = document.getElementById('newAccountCode');
    const bankNameInput = document.getElementById('newBankName');
    const accNoInput = document.getElementById('newAccountNo');
    const branchInput = document.getElementById('newBranch');
    
    const nextCodes = {
        cash: '<?= $nextCashCode ?>',
        bank: '<?= $nextBankCode ?>',
        wallet: '<?= $nextWalletCode ?>'
    };

    if (subTypeSelect) {
        subTypeSelect.addEventListener('change', function() {
            const val = this.value;
            if (nextCodes[val]) {
                codeInput.value = nextCodes[val];
            }
            if (val === 'cash') {
                bankNameInput.placeholder = 'N/A (Drawer / Till)';
                accNoInput.placeholder = 'Optional Till #';
                branchInput.placeholder = 'Drawer / Counter Location';
            } else if (val === 'bank') {
                bankNameInput.placeholder = 'e.g. Meezan Bank, HBL, MCB';
                accNoInput.placeholder = 'e.g. Account Number / IBAN';
                branchInput.placeholder = 'e.g. Main Branch / Branch Code';
            } else if (val === 'wallet') {
                bankNameInput.placeholder = 'e.g. JazzCash, EasyPaisa, SadaPay';
                accNoInput.placeholder = 'e.g. 0300-1234567';
                branchInput.placeholder = 'e.g. Online Mobile Till';
            }
        });
    }

    // Populate Edit Modal
    document.querySelectorAll('.edit-account-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('editAccountId').value = this.dataset.id;
            document.getElementById('editAccountCode').value = this.dataset.code;
            document.getElementById('editAccountName').value = this.dataset.name;
            document.getElementById('editBankName').value = this.dataset.bank;
            document.getElementById('editAccountNo').value = this.dataset.accno;
            document.getElementById('editBranch').value = this.dataset.branch;
            document.getElementById('editNotes').value = this.dataset.notes;
            document.getElementById('editIsActive').checked = (parseInt(this.dataset.active) === 1);
        });
    });
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
