<?php
/**
 * DMS - Distribution Management System
 * View & Printable Customer Payment Receipt
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('payments_manage');

$paymentId = (int)($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    set_flash('danger', "Invalid payment receipt ID.");
    header('Location: customer_payments.php');
    exit;
}

$payment = db_fetch_one("
    SELECT 
        cp.*,
        c.shop_name,
        c.owner_name,
        c.customer_code,
        c.phone as customer_phone,
        c.address as customer_address,
        c.current_balance as customer_current_balance,
        a.area_name,
        acc.account_name as deposited_account_name,
        acc.account_code as deposited_account_code,
        sm.salesman_name,
        u.full_name as recorded_by_name
    FROM customer_payments cp
    JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN areas a ON c.area_id = a.id
    JOIN accounts acc ON cp.account_id = acc.id
    LEFT JOIN salesmen sm ON cp.salesman_id = sm.id
    LEFT JOIN users u ON cp.created_by = u.id
    WHERE cp.id = ?
", [$paymentId]);

if (!$payment) {
    set_flash('danger', "Payment receipt not found.");
    header('Location: customer_payments.php');
    exit;
}

// Linked JV
$jv = db_fetch_one("
    SELECT id, entry_number, entry_date 
    FROM journal_entries 
    WHERE reference_type = 'cash_receipt' AND reference_id = ?
", [$paymentId]);

$pageTitle = "Receipt - " . $payment['payment_number'];
$activeMenu = "customer_payments";

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Action Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div class="d-flex align-items-center gap-2">
                <a href="customer_payments.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Receipts
                </a>
                <span class="text-muted">|</span>
                <span class="fw-bold text-navy fs-5">Receipt <?= htmlspecialchars($payment['payment_number']) ?></span>
                <span class="badge bg-success-subtle text-success border border-success-subtle">CLEARED</span>
            </div>
            <div class="d-flex gap-2">
                <a href="create_customer_payment.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Receipt
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Receipt (Ctrl+P)
                </button>
            </div>
        </div>

        <!-- Printable Receipt Slip -->
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="card border shadow-sm p-4 print-container bg-white">
                    
                    <!-- Header -->
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold mb-0 text-uppercase tracking-wider"><?= CLIENT_NAME ?></h4>
                        <div class="text-muted small">Wholesale & FMCG Distribution • Gujranwala</div>
                        <div class="text-muted small">Phone: 0300-0673276 | Location: Jandiala Bhagwala G.T. Road</div>
                        <div class="mt-2">
                            <span class="badge bg-success text-white px-3 py-1 fs-6 text-uppercase">CUSTOMER PAYMENT RECEIPT</span>
                        </div>
                    </div>

                    <!-- Receipt Metadata -->
                    <div class="row mb-3 pb-2 border-bottom">
                        <div class="col-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td class="text-muted py-1" style="width: 120px;">Receipt No:</td>
                                    <td class="fw-bold py-1 text-navy"><?= htmlspecialchars($payment['payment_number']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1">Receipt Date:</td>
                                    <td class="fw-bold py-1"><?= format_date($payment['payment_date']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1">Payment Mode:</td>
                                    <td class="fw-semibold py-1 text-uppercase"><?= htmlspecialchars(str_replace('_', ' ', $payment['payment_mode'])) ?></td>
                                </tr>
                                <?php if (!empty($payment['reference_no'])): ?>
                                <tr>
                                    <td class="text-muted py-1">Cheque / Ref #:</td>
                                    <td class="fw-bold py-1"><?= htmlspecialchars($payment['reference_no']) ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <div class="col-6 text-end">
                            <table class="table table-sm table-borderless mb-0 float-end" style="width: auto;">
                                <tr>
                                    <td class="text-muted py-1 text-start">Customer Code:</td>
                                    <td class="fw-bold py-1 text-end"><?= htmlspecialchars($payment['customer_code']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted py-1 text-start">Deposited To:</td>
                                    <td class="fw-semibold py-1 text-end"><?= htmlspecialchars($payment['deposited_account_name']) ?></td>
                                </tr>
                                <?php if (!empty($payment['salesman_name'])): ?>
                                <tr>
                                    <td class="text-muted py-1 text-start">Collector:</td>
                                    <td class="fw-semibold py-1 text-end"><?= htmlspecialchars($payment['salesman_name']) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($jv): ?>
                                <tr>
                                    <td class="text-muted py-1 text-start">GL Journal JV:</td>
                                    <td class="fw-bold py-1 text-end text-primary"><?= htmlspecialchars($jv['entry_number']) ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <!-- Customer Info Card -->
                    <div class="p-3 bg-light border rounded mb-3">
                        <div class="row">
                            <div class="col-7">
                                <div class="small text-muted text-uppercase fw-semibold">Received With Thanks From:</div>
                                <div class="h5 fw-bold text-navy mb-1"><?= htmlspecialchars($payment['shop_name']) ?></div>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($payment['owner_name'] ?: 'Retail Customer') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($payment['customer_address'] ?: $payment['area_name']) ?></div>
                            </div>
                            <div class="col-5 text-end border-start">
                                <div class="small text-muted text-uppercase fw-semibold">Amount Received:</div>
                                <div class="h3 fw-bold text-success mb-1"><?= format_currency($payment['amount']) ?></div>
                                <div class="small text-muted">
                                    Current Remaining Balance: <strong><?= format_currency($payment['customer_current_balance']) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Amount in words & Remarks -->
                    <div class="mb-4">
                        <div class="p-2 border rounded bg-white mb-2">
                            <span class="small text-muted">Amount in Words:</span>
                            <span class="fw-bold text-dark text-capitalize ms-1">
                                Rupees <?= number_format($payment['amount'], 2) ?> Only
                            </span>
                        </div>
                        <?php if (!empty($payment['remarks'])): ?>
                            <div class="small text-muted">
                                <strong>Remarks / Narration:</strong> <?= htmlspecialchars($payment['remarks']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Signatures -->
                    <div class="row pt-5 mt-4 text-center">
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Prepared By: <?= htmlspecialchars($payment['recorded_by_name'] ?? 'Cashier') ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Cashier / Officer Signature
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-top border-dark pt-1 small fw-semibold">
                                Customer / Stamp
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
