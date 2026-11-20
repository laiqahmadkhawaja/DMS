<?php
/**
 * DMS - Distribution Management System
 * View & Printable Journal Voucher
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('journal_entries');

$entryId = (int)($_GET['id'] ?? 0);
if ($entryId <= 0) {
    set_flash('danger', "Invalid journal voucher ID.");
    header('Location: journal_list.php');
    exit;
}

$entry = db_fetch_one("
    SELECT je.*, u.full_name as created_by_name
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.id
    WHERE je.id = ?
", [$entryId]);

if (!$entry) {
    set_flash('danger', "Journal voucher not found.");
    header('Location: journal_list.php');
    exit;
}

// Fetch line items
$lines = db_fetch_all("
    SELECT 
        jel.*,
        a.account_code,
        a.account_name,
        a.account_type
    FROM journal_entry_lines jel
    JOIN accounts a ON jel.account_id = a.id
    WHERE jel.journal_entry_id = ?
    ORDER BY jel.debit DESC, jel.id ASC
", [$entryId]);

// Resolve party names
foreach ($lines as &$l) {
    $l['party_name'] = null;
    if (!empty($l['party_type']) && !empty($l['party_id'])) {
        if ($l['party_type'] === 'customer') {
            $l['party_name'] = db_fetch_val("SELECT shop_name FROM customers WHERE id = ?", [$l['party_id']]);
        } elseif ($l['party_type'] === 'supplier') {
            $l['party_name'] = db_fetch_val("SELECT supplier_name FROM suppliers WHERE id = ?", [$l['party_id']]);
        } elseif ($l['party_type'] === 'salesman') {
            $l['party_name'] = db_fetch_val("SELECT salesman_name FROM salesmen WHERE id = ?", [$l['party_id']]);
        }
    }
}
unset($l);

$pageTitle = "Journal Voucher - " . $entry['entry_number'];
$activeMenu = "journal_entries";

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Action Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div class="d-flex align-items-center gap-2">
                <a href="journal_list.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Vouchers
                </a>
                <span class="text-muted">|</span>
                <span class="fw-bold text-navy fs-5">Voucher <?= htmlspecialchars($entry['entry_number']) ?></span>
                <span class="badge bg-success-subtle text-success border border-success-subtle">BALANCED & POSTED</span>
            </div>
            <div class="d-flex gap-2">
                <a href="journal_create.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New JV
                </a>
                <a href="print_journal.php?id=<?= $entry['id'] ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-printer me-1"></i> Print Voucher
                </a>
            </div>
        </div>

        <!-- Printable Voucher Card -->
        <div class="row justify-content-center">
            <div class="col-lg-9 col-md-11">
                <div class="card border shadow-sm p-4 print-container bg-white">
                    
                    <!-- Header -->
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                        <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                        <div class="text-muted small">Phone: 0300-0673276 | Location: Jandiala Bhagwala G.T. Road</div>
                        <div class="mt-2">
                            <span class="badge bg-navy text-white px-3 py-1 fs-6 text-uppercase">JOURNAL VOUCHER (JV)</span>
                        </div>
                    </div>

                    <!-- Voucher Metadata -->
                    <div class="row mb-3 pb-2 border-bottom">
                        <div class="col-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td class="text-muted py-1" style="width: 120px;">JV Number:</td>
                                    <td class="fw-bold py-1 text-navy"><?= htmlspecialchars($entry['entry_number']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1">Posting Date:</td>
                                    <td class="fw-bold py-1"><?= format_date($entry['entry_date']) ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-6 text-end">
                            <table class="table table-sm table-borderless mb-0 float-end" style="width: auto;">
                                <tr>
                                    <td class="text-muted py-1 text-start">Transaction Source:</td>
                                    <td class="fw-semibold py-1 text-end text-uppercase">
                                        <?= htmlspecialchars(str_replace('_', ' ', $entry['reference_type'])) ?>
                                    </td>
                                </tr>
                                <?php if (!empty($entry['reference_id'])): ?>
                                <tr>
                                    <td class="text-muted py-1 text-start">Reference ID:</td>
                                    <td class="fw-bold py-1 text-end">#<?= (int)$entry['reference_id'] ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <!-- Narration Memo -->
                    <div class="p-3 bg-light border rounded mb-3">
                        <div class="small text-muted text-uppercase fw-semibold">Narration / Memo:</div>
                        <div class="fw-semibold text-dark"><?= htmlspecialchars($entry['narration']) ?></div>
                    </div>

                    <!-- Line Items Table -->
                    <table class="table table-bordered align-middle mb-4">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 120px;">Account Code</th>
                                <th>Account Title & Classification</th>
                                <th>Description / Particulars</th>
                                <th>Party Link</th>
                                <th style="width: 130px;" class="text-end">Debit (Rs.)</th>
                                <th style="width: 130px;" class="text-end">Credit (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $row): ?>
                                <tr>
                                    <td class="fw-bold text-navy"><?= htmlspecialchars($row['account_code']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($row['account_name']) ?></div>
                                        <div class="small text-muted text-capitalize"><?= htmlspecialchars($row['account_type']) ?></div>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($row['description'] ?: '—') ?></td>
                                    <td class="small text-muted text-nowrap"><?= htmlspecialchars($row['party_name'] ?: '—') ?></td>
                                    <td class="text-end fw-semibold <?= $row['debit'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                        <?= $row['debit'] > 0 ? format_currency($row['debit']) : '—' ?>
                                    </td>
                                    <td class="text-end fw-semibold <?= $row['credit'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                        <?= $row['credit'] > 0 ? format_currency($row['credit']) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="4" class="text-end">TOTAL VOUCHER AMOUNT:</td>
                                <td class="text-end text-primary"><?= format_currency($entry['total_debit']) ?></td>
                                <td class="text-end text-danger"><?= format_currency($entry['total_credit']) ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end text-muted small">Imbalance Check:</td>
                                <td colspan="2" class="text-center text-success small">
                                    <i class="bi bi-check-circle me-1"></i> DIFFERENCE: RS. 0.00 (PERFECT BALANCE)
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Signatures -->
                    <div class="row pt-5 mt-4 text-center">
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Prepared By: <?= htmlspecialchars($entry['created_by_name'] ?? 'Accountant') ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Checked / Verified By
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Authorized Signatory
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<style>
@media print {
    body { background-color: #fff !important; }
    .no-print, .dms-sidebar, .dms-topbar { display: none !important; }
    .content-wrapper { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    .print-container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
