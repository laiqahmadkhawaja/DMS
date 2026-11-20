<?php
/**
 * DMS - Distribution Management System
 * Create Manual Double-Entry Journal Voucher (JV)
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';
require_once __DIR__ . '/../../includes/accounting_functions.php';

require_login();
require_permission('journal_entries');

$pageTitle = "New Journal Voucher";
$activeMenu = "journal_entries";
$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entryDate = clean_input($_POST['entry_date'] ?? date('Y-m-d'));
    $narration = clean_input($_POST['narration'] ?? '');
    $accounts  = $_POST['account_id'] ?? [];
    $debits    = $_POST['debit'] ?? [];
    $credits   = $_POST['credit'] ?? [];
    $descs     = $_POST['description'] ?? [];
    $parties   = $_POST['party'] ?? [];

    if (empty($entryDate) || !strtotime($entryDate)) {
        $errors[] = "Valid voucher date is required.";
    }
    if (empty($narration)) {
        $errors[] = "Voucher narration / explanation is required.";
    }

    $validLines = [];
    $sumDebit = 0.0;
    $sumCredit = 0.0;

    foreach ($accounts as $idx => $accId) {
        $accId = (int)$accId;
        $d = round((float)($debits[$idx] ?? 0), 2);
        $c = round((float)($credits[$idx] ?? 0), 2);
        $desc = clean_input($descs[$idx] ?? '');
        $partyRaw = clean_input($parties[$idx] ?? '');

        if ($accId > 0 && ($d > 0 || $c > 0)) {
            $partyType = null;
            $partyId = null;

            if ($partyRaw) {
                $parts = explode(':', $partyRaw);
                if (count($parts) === 2) {
                    $partyType = $parts[0];
                    $partyId = (int)$parts[1];
                }
            }

            $validLines[] = [
                'account_id'  => $accId,
                'debit'       => $d,
                'credit'      => $c,
                'description' => $desc ?: $narration,
                'party_type'  => $partyType,
                'party_id'    => $partyId
            ];

            $sumDebit += $d;
            $sumCredit += $c;
        }
    }

    if (count($validLines) < 2) {
        $errors[] = "A journal entry must contain at least two valid lines with debit/credit entries.";
    } elseif (abs($sumDebit - $sumCredit) > 0.01) {
        $errors[] = "Unbalanced voucher: Total Debits (" . format_currency($sumDebit) . ") does not equal Total Credits (" . format_currency($sumCredit) . "). Difference: " . format_currency(abs($sumDebit - $sumCredit));
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $header = [
                'entry_date'     => $entryDate,
                'reference_type' => 'manual_journal',
                'reference_id'   => null,
                'narration'      => $narration
            ];

            $journalId = post_journal_entry($header, $validLines);

            db()->commit();

            set_flash('success', "Journal Voucher posted successfully.");
            header('Location: journal_view.php?id=' . $journalId);
            exit;

        } catch (Exception $e) {
            db()->rollBack();
            $errors[] = "Failed to post journal entry: " . $e->getMessage();
        }
    }
}

// Fetch Accounts
$accountsList = db_fetch_all("
    SELECT id, account_code, account_name, account_type, current_balance 
    FROM accounts 
    WHERE is_active = 1 
    ORDER BY account_code ASC
");

// Fetch Customers & Suppliers for party dropdown
$customers = db_fetch_all("SELECT id, shop_name, owner_name FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");
$suppliers = db_fetch_all("SELECT id, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 ORDER BY supplier_name ASC");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>New Double-Entry Journal Voucher (JV)
                </h4>
                <p class="text-muted small mb-0">Record manual adjustments, capital investments, bank charges, or asset transfers with zero-imbalance enforcement.</p>
            </div>
            <div>
                <a href="journal_list.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Vouchers (ESC)
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

        <form method="POST" action="journal_create.php" id="jvForm">
            
            <!-- Header Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-3">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Voucher Date <span class="text-danger">*</span></label>
                            <input type="date" name="entry_date" class="form-control" value="<?= htmlspecialchars($_POST['entry_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label fw-semibold">Narration / Memo <span class="text-danger">*</span></label>
                            <input type="text" name="narration" class="form-control" value="<?= htmlspecialchars($_POST['narration'] ?? '') ?>" placeholder="e.g. Owner capital injection via Bank Al-Habib, or Year-end depreciation" required autofocus>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lines Table Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 border-bottom d-flex justify-content-between align-items-center">
                    <div class="fw-bold text-navy"><i class="bi bi-list-columns-reverse me-1"></i> Voucher Debit & Credit Lines</div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-circle me-1"></i> Add Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="linesTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 32%;">Account Title <span class="text-danger">*</span></th>
                                    <th style="width: 15%;" class="text-end">Debit (Rs.)</th>
                                    <th style="width: 15%;" class="text-end">Credit (Rs.)</th>
                                    <th style="width: 20%;">Description / Sub-narration</th>
                                    <th style="width: 14%;">Party Link (Optional)</th>
                                    <th style="width: 4%;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="linesBody">
                                <!-- Initial Row 1 -->
                                <tr>
                                    <td>
                                        <select name="account_id[]" class="form-select form-select-sm acc-select" required>
                                            <option value="">-- Select Account --</option>
                                            <?php foreach ($accountsList as $acc): ?>
                                                <option value="<?= $acc['id'] ?>">
                                                    <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> (<?= strtoupper($acc['account_type']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="debit[]" class="form-control form-control-sm text-end fw-bold text-primary debit-input" placeholder="0.00" value="">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="credit[]" class="form-control form-control-sm text-end fw-bold text-danger credit-input" placeholder="0.00" value="">
                                    </td>
                                    <td>
                                        <input type="text" name="description[]" class="form-control form-control-sm" placeholder="Line details">
                                    </td>
                                    <td>
                                        <select name="party[]" class="form-select form-select-sm">
                                            <option value="">-- None --</option>
                                            <optgroup label="Customers">
                                                <?php foreach ($customers as $c): ?>
                                                    <option value="customer:<?= $c['id'] ?>"><?= htmlspecialchars($c['shop_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Suppliers">
                                                <?php foreach ($suppliers as $s): ?>
                                                    <option value="supplier:<?= $s['id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove" title="Remove line"><i class="bi bi-x-lg"></i></button>
                                    </td>
                                </tr>

                                <!-- Initial Row 2 -->
                                <tr>
                                    <td>
                                        <select name="account_id[]" class="form-select form-select-sm acc-select" required>
                                            <option value="">-- Select Account --</option>
                                            <?php foreach ($accountsList as $acc): ?>
                                                <option value="<?= $acc['id'] ?>">
                                                    <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?> (<?= strtoupper($acc['account_type']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="debit[]" class="form-control form-control-sm text-end fw-bold text-primary debit-input" placeholder="0.00" value="">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="credit[]" class="form-control form-control-sm text-end fw-bold text-danger credit-input" placeholder="0.00" value="">
                                    </td>
                                    <td>
                                        <input type="text" name="description[]" class="form-control form-control-sm" placeholder="Line details">
                                    </td>
                                    <td>
                                        <select name="party[]" class="form-select form-select-sm">
                                            <option value="">-- None --</option>
                                            <optgroup label="Customers">
                                                <?php foreach ($customers as $c): ?>
                                                    <option value="customer:<?= $c['id'] ?>"><?= htmlspecialchars($c['shop_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Suppliers">
                                                <?php foreach ($suppliers as $s): ?>
                                                    <option value="supplier:<?= $s['id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove" title="Remove line"><i class="bi bi-x-lg"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td class="text-end">Total:</td>
                                    <td class="text-end text-primary fs-6" id="totalDebitDisplay">Rs. 0.00</td>
                                    <td class="text-end text-danger fs-6" id="totalCreditDisplay">Rs. 0.00</td>
                                    <td colspan="3" class="align-middle">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span id="balanceStatusBadge" class="badge bg-secondary">Awaiting input</span>
                                            <span class="small text-muted" id="diffDisplay">Difference: Rs. 0.00</span>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Submit Toolbar -->
            <div class="d-flex justify-content-between align-items-center">
                <a href="journal_list.php" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary px-4 fw-bold" id="btnSubmitJV">
                    <i class="bi bi-check-circle me-1"></i> Post Journal Voucher
                </button>
            </div>

        </form>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const linesBody = document.getElementById('linesBody');
    const btnAddRow = document.getElementById('btnAddRow');
    const totalDebitDisplay = document.getElementById('totalDebitDisplay');
    const totalCreditDisplay = document.getElementById('totalCreditDisplay');
    const balanceStatusBadge = document.getElementById('balanceStatusBadge');
    const diffDisplay = document.getElementById('diffDisplay');
    const btnSubmit = document.getElementById('btnSubmitJV');

    function calculateTotals() {
        let totalD = 0;
        let totalC = 0;

        document.querySelectorAll('.debit-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            totalD += val;
        });

        document.querySelectorAll('.credit-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            totalC += val;
        });

        totalDebitDisplay.textContent = 'Rs. ' + totalD.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        totalCreditDisplay.textContent = 'Rs. ' + totalC.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const diff = Math.abs(totalD - totalC);
        diffDisplay.textContent = 'Difference: Rs. ' + diff.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        if (totalD > 0 && diff < 0.01) {
            balanceStatusBadge.className = 'badge bg-success';
            balanceStatusBadge.textContent = 'BALANCED (Rs. 0.00)';
            btnSubmit.disabled = false;
        } else if (totalD === 0 && totalC === 0) {
            balanceStatusBadge.className = 'badge bg-secondary';
            balanceStatusBadge.textContent = 'Awaiting input';
            btnSubmit.disabled = true;
        } else {
            balanceStatusBadge.className = 'badge bg-danger';
            balanceStatusBadge.textContent = 'UNBALANCED';
            btnSubmit.disabled = true;
        }
    }

    linesBody.addEventListener('input', function(e) {
        if (e.target.classList.contains('debit-input') || e.target.classList.contains('credit-input')) {
            calculateTotals();
        }
    });

    linesBody.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-remove');
        if (btn) {
            const rowCount = linesBody.querySelectorAll('tr').length;
            if (rowCount > 2) {
                btn.closest('tr').remove();
                calculateTotals();
            } else {
                alert('A journal voucher must have at least 2 lines.');
            }
        }
    });

    btnAddRow.addEventListener('click', function() {
        const firstRow = linesBody.querySelector('tr');
        const clone = firstRow.cloneNode(true);
        clone.querySelectorAll('input').forEach(i => i.value = '');
        clone.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        linesBody.appendChild(clone);
        calculateTotals();
    });

    calculateTotals();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.location.href = 'journal_list.php';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
