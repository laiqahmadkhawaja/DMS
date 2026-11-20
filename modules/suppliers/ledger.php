<?php
/**
 * DMS - Supplier Ledger Statement
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('suppliers_ledger');

$supplierId = (int)($_GET['id'] ?? 0);
$allSuppliers = db_fetch_all("SELECT id, supplier_name, supplier_code, current_balance FROM suppliers WHERE is_deleted = 0 ORDER BY supplier_name ASC");

if ($supplierId === 0 && !empty($allSuppliers)) {
    $supplierId = (int)$allSuppliers[0]['id'];
}

$selectedSupp = db_fetch_one("SELECT * FROM suppliers WHERE id = ?", [$supplierId]);

$fromDate = clean_input($_GET['from_date'] ?? date('Y-m-01'));
$toDate   = clean_input($_GET['to_date'] ?? date('Y-m-d'));

$openingBal = 0.0;
$ledgerEntries = [];

if ($selectedSupp) {
    // Supplier is Liability: Credit increases payable, Debit decreases payable (payments)
    $preCredits = (float)db_fetch_val("
        SELECT COALESCE(SUM(credit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.entry_date < ?
    ", [$supplierId, $fromDate]);

    $preDebits = (float)db_fetch_val("
        SELECT COALESCE(SUM(debit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.entry_date < ?
    ", [$supplierId, $fromDate]);

    $openingBal = $preCredits - $preDebits;

    $sqlTransactions = "
        SELECT 
            je.entry_date, je.entry_number, je.reference_type, je.reference_id,
            jel.description, jel.debit, jel.credit
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.entry_date BETWEEN ? AND ?
        ORDER BY je.entry_date ASC, je.id ASC
    ";
    $ledgerEntries = db_fetch_all($sqlTransactions, [$supplierId, $fromDate, $toDate]);
}

$pageTitle = 'Supplier Ledger: ' . ($selectedSupp['supplier_name'] ?? '') . ' — ' . APP_NAME;
$activeMenu = 'suppliers';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-journal-text me-2 text-primary"></i>Supplier Account Ledger</h5>
        <small class="text-muted">Payable history, factory purchases, and payment vouchers</small>
    </div>
    <div class="no-print">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Ledger (F10)
        </button>
    </div>
</div>

<div class="card-dms p-2 mb-3 no-print">
    <form method="GET" action="<?= APP_URL ?>/modules/suppliers/ledger.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <label class="form-label small fw-semibold mb-1">Select Supplier / Manufacturer</label>
            <select name="id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($allSuppliers as $as): ?>
                    <option value="<?= $as['id'] ?>" <?= $as['id'] == $supplierId ? 'selected' : '' ?>>
                        <?= e($as['supplier_name']) ?> — Payable: <?= format_currency($as['current_balance']) ?>
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
            <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<?php if ($selectedSupp): ?>
    <div class="card-dms p-3 mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="fw-bold mb-1 text-dark"><?= e($selectedSupp['supplier_name']) ?></h4>
                <div class="text-secondary small">
                    <i class="bi bi-building me-1"></i><?= e($selectedSupp['company_name'] ?? 'Vendor') ?> | <?= e($selectedSupp['city'] ?? '') ?>
                </div>
                <div class="text-secondary small">
                    <i class="bi bi-telephone me-1"></i><?= e($selectedSupp['phone']) ?> | Code: <span class="font-monospace fw-semibold"><?= e($selectedSupp['supplier_code']) ?></span>
                </div>
            </div>
            <div class="col-md-6 text-md-end mt-2 mt-md-0">
                <div class="small text-muted">Current Trade Payable Balance:</div>
                <h3 class="fw-bold mb-0 <?= (float)$selectedSupp['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= format_currency($selectedSupp['current_balance']) ?>
                </h3>
            </div>
        </div>
    </div>

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
                        <th>Narration / Details</th>
                        <th class="text-end" style="width: 130px;">Debit (Paid)</th>
                        <th class="text-end" style="width: 130px;">Credit (Purchased)</th>
                        <th class="text-end" style="width: 140px;">Payable Balance</th>
                    </tr>
                </thead>
                <tbody>
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
                                No purchase or payment transactions found in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ledgerEntries as $entry): 
                            $debit = (float)$entry['debit'];
                            $credit = (float)$entry['credit'];
                            $runningBal += ($credit - $debit);
                            $periodDebit += $debit;
                            $periodCredit += $credit;
                        ?>
                            <tr>
                                <td><?= format_date($entry['entry_date']) ?></td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?= e($entry['entry_number']) ?></span></td>
                                <td><?= e($entry['description']) ?></td>
                                <td class="text-end text-success fw-semibold"><?= $debit > 0 ? format_currency($debit) : '-' ?></td>
                                <td class="text-end text-danger fw-semibold"><?= $credit > 0 ? format_currency($credit) : '-' ?></td>
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
<?php endif; ?>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
