<?php
/**
 * DMS - Customer Ledger Statement & Parties Credit Summary
 * Matches Image 6 (Parties Credit) and PRD Section 20
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('customers_statement');

$customerId = (int)($_GET['id'] ?? 0);
$viewMode = clean_input($_GET['view'] ?? ($customerId > 0 ? 'single' : 'parties_credit'));

$fromDate = clean_input($_GET['from_date'] ?? date('Y-m-01'));
$toDate   = clean_input($_GET['to_date'] ?? date('Y-m-d'));

$allCustomers = db_fetch_all("
    SELECT c.id, c.shop_name, c.customer_code, a.area_name, c.current_balance 
    FROM customers c 
    JOIN areas a ON a.id = c.area_id 
    WHERE c.is_deleted = 0 
    ORDER BY c.shop_name ASC
");

// 1. PARTIES CREDIT VIEW (Matching Image 6)
if ($viewMode === 'parties_credit') {
    $areaFilter    = (int)($_GET['area_id'] ?? 0);
    $bookerFilter  = (int)($_GET['booker_id'] ?? 0);
    $balanceFilter = clean_input($_GET['balance_type'] ?? 'all_balances');

    $sqlParties = "
        SELECT 
            c.id, c.customer_code, c.shop_name, c.owner_name, c.address, c.current_balance,
            a.area_name, 
            b.booker_name
        FROM customers c
        JOIN areas a ON a.id = c.area_id
        LEFT JOIN bookers b ON b.id = c.booker_id
        WHERE c.is_deleted = 0
    ";
    $paramsParties = [];

    if ($balanceFilter === 'debit_only') {
        $sqlParties .= " AND c.current_balance > 0";
    } elseif ($balanceFilter === 'credit_only') {
        $sqlParties .= " AND c.current_balance < 0";
    } elseif ($balanceFilter === 'all_customers') {
        // No balance constraint
    } else {
        // Default: all non-zero balances (both positive receivables and negative advance credits)
        $sqlParties .= " AND c.current_balance != 0";
    }

    if ($areaFilter > 0) {
        $sqlParties .= " AND c.area_id = ?";
        $paramsParties[] = $areaFilter;
    }
    if ($bookerFilter > 0) {
        $sqlParties .= " AND c.booker_id = ?";
        $paramsParties[] = $bookerFilter;
    }

    $sqlParties .= " ORDER BY c.current_balance DESC, c.shop_name ASC";
    $creditParties = db_fetch_all($sqlParties, $paramsParties);

    $totalPartiesCredit = 0;
    $totalReceivables = 0;
    $totalAdvances = 0;
    foreach ($creditParties as $cp) {
        $bal = (float)$cp['current_balance'];
        $totalPartiesCredit += $bal;
        if ($bal > 0) {
            $totalReceivables += $bal;
        } else {
            $totalAdvances += abs($bal);
        }
    }

    $pageTitle = 'Parties Credit List — ' . APP_NAME;
    $activeMenu = 'cust_ledger';

    $areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");
    $bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");

    require_once INCLUDES_PATH . '/header.php';
    require_once INCLUDES_PATH . '/sidebar.php';
    require_once INCLUDES_PATH . '/topbar.php';
    ?>

    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
        <div>
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Parties Credit (Market Debtors)</h5>
            <small class="text-muted">Master list of customer outstanding balances, credit terms, and shop addresses</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print List (F10)
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card-dms p-2 mb-3 no-print">
        <form method="GET" action="<?= APP_URL ?>/modules/customers/statement.php" class="row g-2 align-items-center">
            <input type="hidden" name="view" value="parties_credit">
            <div class="col-md-3">
                <select name="area_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">All Areas / Routes</option>
                    <?php foreach ($areas as $ar): ?>
                        <option value="<?= $ar['id'] ?>" <?= $ar['id'] == $areaFilter ? 'selected' : '' ?>>
                            <?= e($ar['area_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="booker_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">All Bookers</option>
                    <?php foreach ($bookers as $bk): ?>
                        <option value="<?= $bk['id'] ?>" <?= $bk['id'] == $bookerFilter ? 'selected' : '' ?>>
                            <?= e($bk['booker_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="balance_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all_balances" <?= $balanceFilter === 'all_balances' ? 'selected' : '' ?>>All Outstanding (Non-Zero)</option>
                    <option value="debit_only" <?= $balanceFilter === 'debit_only' ? 'selected' : '' ?>>Market Debtors (Receivables &gt; 0)</option>
                    <option value="credit_only" <?= $balanceFilter === 'credit_only' ? 'selected' : '' ?>>Advances / In Credit (Balance &lt; 0)</option>
                    <option value="all_customers" <?= $balanceFilter === 'all_customers' ? 'selected' : '' ?>>All Customers (Incl. Zero)</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2 text-end">
                <a href="<?= APP_URL ?>/modules/customers/statement.php?view=single" class="btn btn-sm btn-outline-primary">
                    Single Ledger Statement &rarr;
                </a>
            </div>
        </form>
    </div>

    <!-- Parties Credit Table (Excel Layout matching Picture 6) -->
    <div class="card-dms">
        <div class="card-dms-header" style="background-color: #3b82f6; color: #fff;">
            <span class="fs-6 fw-bold">Parties Credit Summary</span>
            <span>Total Parties: <?= count($creditParties) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-compact mb-0">
                <thead style="background-color: #334155; color: #f8fafc;">
                    <tr>
                        <th style="color: #f8fafc;">Booker Name / Shop</th>
                        <th style="color: #f8fafc;">Address</th>
                        <th class="text-end" style="color: #f8fafc; width: 220px;">Amount (<?= DEFAULT_CURRENCY ?>)</th>
                        <th class="text-end no-print" style="color: #f8fafc; width: 100px;">Statement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($creditParties)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">
                                <i class="bi bi-check-circle fs-4 d-block text-success mb-1"></i>
                                No customer outstanding balances in this selection.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($creditParties as $party): 
                            $bal = (float)$party['current_balance'];
                        ?>
                            <tr>
                                <td class="fw-semibold text-dark">
                                    <?= e($party['shop_name']) ?>
                                    <?php if (!empty($party['booker_name'])): ?>
                                        <small class="text-muted d-block font-normal">Booker: <?= e($party['booker_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($party['address']) ?> <span class="text-muted">(<?= e($party['area_name']) ?>)</span></td>
                                <td class="text-end fw-bold fs-6">
                                    <?php if ($bal < 0): ?>
                                        <span class="badge bg-info-subtle text-primary border border-primary-subtle me-1" style="font-size: 0.72rem;">Advance / Cr</span>
                                        <span class="text-primary"><?= format_currency($bal, false) ?></span>
                                    <?php elseif ($bal > 0): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1" style="font-size: 0.72rem;">Receivable / Dr</span>
                                        <span class="text-danger"><?= format_currency($bal, false) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0.00</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end no-print">
                                    <a href="<?= APP_URL ?>/modules/customers/statement.php?view=single&id=<?= $party['id'] ?>" class="btn btn-xs btn-outline-primary py-0 px-2">
                                        View Ledger
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-fmcg-total">
                    <tr style="font-size: 1rem;">
                        <th colspan="2" class="text-end">
                            Total Outstanding Balance:
                            <?php if ($totalAdvances > 0 && $totalReceivables > 0): ?>
                                <small class="text-muted fw-normal ms-2">(Receivables: <?= format_currency($totalReceivables) ?> | Advances: <?= format_currency($totalAdvances) ?>)</small>
                            <?php endif; ?>
                        </th>
                        <th class="text-end <?= $totalPartiesCredit < 0 ? 'text-primary' : 'text-dark' ?>">
                            <?= format_currency($totalPartiesCredit) ?>
                        </th>
                        <th class="no-print"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

// 2. SINGLE CUSTOMER LEDGER STATEMENT VIEW
$selectedCust = null;
if ($customerId > 0) {
    $selectedCust = db_fetch_one("
        SELECT c.*, a.area_name, b.booker_name 
        FROM customers c 
        JOIN areas a ON a.id = c.area_id 
        LEFT JOIN bookers b ON b.id = c.booker_id 
        WHERE c.id = ?
    ", [$customerId]);
}

$ledgerEntries = [];
$openingBal = 0.0;

if ($selectedCust) {
    // Check if initial opening balance had a journal entry
    $hasOpeningJE = (bool)db_fetch_val("
        SELECT jel.id FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.reference_type = 'opening'
    ", [$customerId]);
    $baseOpening = (!$hasOpeningJE) ? (float)($selectedCust['opening_balance'] ?? 0) : 0.0;

    // Calculate opening balance before $fromDate
    $preDebits = (float)db_fetch_val("
        SELECT COALESCE(SUM(debit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.entry_date < ?
    ", [$customerId, $fromDate]);

    $preCredits = (float)db_fetch_val("
        SELECT COALESCE(SUM(credit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.entry_date < ?
    ", [$customerId, $fromDate]);

    $openingBal = $baseOpening + ($preDebits - $preCredits);

    // Fetch transactions in date range
    $sqlTransactions = "
        SELECT 
            je.entry_date, je.entry_number, je.reference_type, je.reference_id,
            jel.description, jel.debit, jel.credit
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.entry_date BETWEEN ? AND ?
        ORDER BY je.entry_date ASC, je.id ASC
    ";
    $ledgerEntries = db_fetch_all($sqlTransactions, [$customerId, $fromDate, $toDate]);

    // Ensure customer balance is synced with audit
    $currentAudited = recalculate_customer_balance($customerId);
    $selectedCust['current_balance'] = $currentAudited;
}

$pageTitle = 'Customer Statement: ' . ($selectedCust['shop_name'] ?? '') . ' — ' . APP_NAME;
$activeMenu = 'cust_ledger';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-journal-text me-2 text-primary"></i>Customer Account Statement</h5>
        <small class="text-muted">Itemized debit and credit ledger statement with running balance</small>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="<?= APP_URL ?>/modules/customers/statement.php?view=parties_credit" class="btn btn-sm btn-outline-primary">
            &larr; Parties Credit Summary
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Statement (F10)
        </button>
    </div>
</div>

<!-- Customer & Date Selector -->
<div class="card-dms p-2 mb-3 no-print">
    <form method="GET" action="<?= APP_URL ?>/modules/customers/statement.php" class="row g-2 align-items-center">
        <input type="hidden" name="view" value="single">
        <div class="col-md-5">
            <label class="form-label small fw-semibold mb-1">Select Customer / Shop</label>
            <select name="id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- Choose Retailer --</option>
                <?php foreach ($allCustomers as $ac): ?>
                    <option value="<?= $ac['id'] ?>" <?= $ac['id'] == $customerId ? 'selected' : '' ?>>
                        <?= e($ac['shop_name']) ?> (<?= e($ac['area_name']) ?>) — Bal: <?= format_currency($ac['current_balance']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">From Date</label>
            <input type="date" name="from_date" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">To Date</label>
            <input type="date" name="to_date" class="form-control form-control-sm" value="<?= e($toDate) ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
        </div>
    </form>
</div>

<?php if ($selectedCust): ?>
    <!-- Statement Header Details -->
    <div class="card-dms p-3 mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="fw-bold mb-1 text-dark"><?= e($selectedCust['shop_name']) ?></h4>
                <div class="text-secondary small">
                    <i class="bi bi-geo-alt me-1"></i><?= e($selectedCust['address']) ?> (<?= e($selectedCust['area_name']) ?>)
                </div>
                <div class="text-secondary small">
                    <i class="bi bi-telephone me-1"></i><?= e($selectedCust['phone']) ?> | Code: <span class="font-monospace fw-semibold"><?= e($selectedCust['customer_code']) ?></span>
                </div>
            </div>
            <div class="col-md-6 text-md-end mt-2 mt-md-0">
                <div class="small text-muted">Current Ledger Balance:</div>
                <h3 class="fw-bold mb-0 <?= (float)$selectedCust['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= format_currency($selectedCust['current_balance']) ?>
                </h3>
                <small class="text-muted">Credit Limit: <?= format_currency($selectedCust['credit_limit']) ?></small>
            </div>
        </div>
    </div>

    <!-- Statement Table -->
    <div class="card-dms">
        <div class="card-dms-header">
            <span>Statement Period: <strong><?= format_date($fromDate) ?></strong> to <strong><?= format_date($toDate) ?></strong></span>
        </div>
        <div class="table-responsive">
            <table class="table table-compact mb-0">
                <thead class="table-fmcg-header">
                    <tr>
                        <th style="width: 100px;">Date</th>
                        <th style="width: 130px;">Voucher #</th>
                        <th>Transaction Description</th>
                        <th class="text-end" style="width: 130px;">Debit (Invoice)</th>
                        <th class="text-end" style="width: 130px;">Credit (Recovery)</th>
                        <th class="text-end" style="width: 140px;">Running Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening Balance Row -->
                    <tr class="table-light">
                        <td><?= format_date($fromDate) ?></td>
                        <td><span class="badge bg-secondary-subtle text-dark border">OPENING</span></td>
                        <td><em>Opening balance brought forward</em></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end fw-bold"><?= format_currency($openingBal) ?></td>
                    </tr>

                    <?php
                    $runningBal = $openingBal;
                    $periodDebit = 0;
                    $periodCredit = 0;

                    if (empty($ledgerEntries)):
                    ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                No ledger transactions recorded in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ledgerEntries as $entry): 
                            $debit  = (float)$entry['debit'];
                            $credit = (float)$entry['credit'];
                            $runningBal += ($debit - $credit);
                            $periodDebit += $debit;
                            $periodCredit += $credit;
                        ?>
                            <tr>
                                <td><?= format_date($entry['entry_date']) ?></td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?= e($entry['entry_number']) ?></span></td>
                                <td><?= e($entry['description']) ?></td>
                                <td class="text-end text-danger fw-semibold"><?= $debit > 0 ? format_currency($debit) : '-' ?></td>
                                <td class="text-end text-success fw-semibold"><?= $credit > 0 ? format_currency($credit) : '-' ?></td>
                                <td class="text-end fw-bold <?= $runningBal > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= format_currency($runningBal) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-fmcg-total">
                    <tr>
                        <th colspan="3" class="text-end">Period Totals:</th>
                        <th class="text-end"><?= format_currency($periodDebit) ?></th>
                        <th class="text-end"><?= format_currency($periodCredit) ?></th>
                        <th class="text-end"><?= format_currency($runningBal) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info py-4 text-center">
        <i class="bi bi-person-bounding-box fs-3 d-block mb-2"></i>
        Please select a customer from the dropdown above to display their account ledger statement.
    </div>
<?php endif; ?>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
