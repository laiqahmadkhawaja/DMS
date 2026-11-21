<?php
/**
 * DMS - Distribution Management System
 * Customer 360° Profile & Account Management Hub
 * Complete ledger statement, invoices, orders history, payment receipts, and returns
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('customers_view');

$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($customerId <= 0) {
    set_flash('danger', 'Valid customer ID is required.');
    redirect(APP_URL . '/modules/customers/index.php');
}

$customer = db_fetch_one("
    SELECT c.*, a.area_name, b.booker_name 
    FROM customers c 
    LEFT JOIN areas a ON a.id = c.area_id 
    LEFT JOIN bookers b ON b.id = c.booker_id 
    WHERE c.id = ? AND c.is_deleted = 0
", [$customerId]);

if (!$customer) {
    set_flash('danger', 'Customer record not found.');
    redirect(APP_URL . '/modules/customers/index.php');
}

// Ensure balance is strictly synchronized
$auditedBalance = recalculate_customer_balance($customerId);
$customer['current_balance'] = $auditedBalance;

// Active Tab
$activeTab = sanitize_input($_GET['tab'] ?? 'invoices');

// Date Filters for Ledger
$fromDate = sanitize_input($_GET['from_date'] ?? date('Y-m-01'));
$toDate   = sanitize_input($_GET['to_date'] ?? date('Y-m-d'));

// Summary Aggregates
$invStats = db_fetch_one("
    SELECT 
        COUNT(id) as total_invoices,
        COALESCE(SUM(net_amount), 0) as total_billed,
        COALESCE(SUM(paid_amount), 0) as total_paid
    FROM sales_invoices 
    WHERE customer_id = ? AND status != 'cancelled'
", [$customerId]);

$orderCount = (int)db_fetch_val("
    SELECT COUNT(id) FROM orders WHERE customer_id = ? AND status != 'cancelled'
", [$customerId]);

$paymentStats = db_fetch_one("
    SELECT 
        COUNT(id) as total_receipts,
        COALESCE(SUM(amount), 0) as total_recovered
    FROM customer_payments 
    WHERE customer_id = ?
", [$customerId]);

$returnStats = db_fetch_one("
    SELECT 
        COUNT(id) as total_returns,
        COALESCE(SUM(total_amount), 0) as total_returned
    FROM sales_returns 
    WHERE customer_id = ? AND status != 'draft'
", [$customerId]);

// 1. Invoices
$invoices = db_fetch_all("
    SELECT si.*, b.booker_name, u.full_name as creator_name
    FROM sales_invoices si
    LEFT JOIN bookers b ON si.booker_id = b.id
    LEFT JOIN users u ON si.created_by = u.id
    WHERE si.customer_id = ?
    ORDER BY si.invoice_date DESC, si.id DESC
    LIMIT 100
", [$customerId]);

// 2. Orders History
$orders = db_fetch_all("
    SELECT o.*, b.booker_name, u.full_name as creator_name
    FROM orders o
    LEFT JOIN bookers b ON o.booker_id = b.id
    LEFT JOIN users u ON o.created_by = u.id
    WHERE o.customer_id = ?
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT 100
", [$customerId]);

// 3. Payment Receipts
$payments = db_fetch_all("
    SELECT cp.*, a.account_name, u.full_name as creator_name
    FROM customer_payments cp
    LEFT JOIN accounts a ON cp.account_id = a.id
    LEFT JOIN users u ON cp.created_by = u.id
    WHERE cp.customer_id = ?
    ORDER BY cp.payment_date DESC, cp.id DESC
    LIMIT 100
", [$customerId]);

// 4. Sales Returns
$returns = db_fetch_all("
    SELECT sr.*, inv.invoice_number, st.settlement_number, u.full_name as creator_name
    FROM sales_returns sr
    LEFT JOIN sales_invoices inv ON sr.invoice_id = inv.id
    LEFT JOIN settlements st ON sr.settlement_id = st.id
    LEFT JOIN users u ON sr.created_by = u.id
    WHERE sr.customer_id = ?
    ORDER BY sr.return_date DESC, sr.id DESC
    LIMIT 100
", [$customerId]);

// 5. Ledger Statement Calculations
$hasOpeningJE = (bool)db_fetch_val("
    SELECT jel.id FROM journal_entry_lines jel
    JOIN journal_entries je ON je.id = jel.journal_entry_id
    WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.reference_type = 'opening'
", [$customerId]);
$baseOpening = (!$hasOpeningJE) ? (float)($customer['opening_balance'] ?? 0) : 0.0;

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

$ledgerEntries = db_fetch_all("
    SELECT 
        je.entry_date, je.entry_number, je.reference_type, je.reference_id,
        jel.description, jel.debit, jel.credit
    FROM journal_entry_lines jel
    JOIN journal_entries je ON je.id = jel.journal_entry_id
    WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.entry_date BETWEEN ? AND ?
    ORDER BY je.entry_date ASC, je.id ASC
", [$customerId, $fromDate, $toDate]);

$pageTitle = 'Customer Profile: ' . $customer['shop_name'];
$activeMenu = 'customers';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Customers', 'url' => 'index.php'],
    ['label' => $customer['shop_name'], 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Customer Profile Hero Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-7">
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar-lg rounded bg-navy text-white d-flex align-items-center justify-content-center fs-2 fw-bold shadow-sm" style="width: 64px; height: 64px; min-width: 64px;">
                                <?= strtoupper(substr($customer['shop_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <h4 class="mb-0 fw-bold text-navy"><?= htmlspecialchars($customer['shop_name']) ?></h4>
                                    <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($customer['customer_code']) ?></span>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-geo-alt me-1 text-danger"></i><?= htmlspecialchars($customer['address']) ?> 
                                    <?= !empty($customer['area_name']) ? '(' . htmlspecialchars($customer['area_name']) . ')' : '' ?>
                                </div>
                                <div class="text-muted small mt-1 d-flex flex-wrap gap-3">
                                    <?php if (!empty($customer['phone'])): ?>
                                        <span><i class="bi bi-telephone me-1 text-primary"></i><?= htmlspecialchars($customer['phone']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($customer['owner_name'])): ?>
                                        <span><i class="bi bi-person me-1 text-secondary"></i>Owner: <strong><?= htmlspecialchars($customer['owner_name']) ?></strong></span>
                                    <?php endif; ?>
                                    <?php if (!empty($customer['booker_name'])): ?>
                                        <span><i class="bi bi-person-badge me-1 text-info"></i>Booker: <strong><?= htmlspecialchars($customer['booker_name']) ?></strong></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: Outstanding Balance Widget -->
                    <div class="col-md-5 text-md-end">
                        <div class="p-3 rounded bg-light border d-inline-block text-md-end text-start w-100" style="max-width: 380px;">
                            <div class="text-muted small text-uppercase fw-bold mb-1">
                                <i class="bi bi-wallet2 me-1"></i> Current Account Balance
                            </div>
                            <div class="fs-3 fw-bold <?= $customer['current_balance'] > 0 ? 'text-danger' : ($customer['current_balance'] < 0 ? 'text-primary' : 'text-dark') ?>">
                                <?= format_currency($customer['current_balance']) ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small">
                                <span class="text-muted">Credit Limit: <?= format_currency((float)$customer['credit_limit']) ?></span>
                                <?php if ($customer['current_balance'] > (float)$customer['credit_limit'] && (float)$customer['credit_limit'] > 0): ?>
                                    <span class="badge bg-danger">Credit Limit Exceeded</span>
                                <?php elseif ($customer['current_balance'] < 0): ?>
                                    <span class="badge bg-primary">Advance Balance</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success">Healthy Standing</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Action Hub Buttons -->
                <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                    <a href="<?= APP_URL ?>/modules/orders/create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-primary fw-bold shadow-sm">
                        <i class="bi bi-cart-plus me-1"></i> New Market Order (F6)
                    </a>
                    <a href="<?= APP_URL ?>/modules/invoices/create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-navy fw-bold shadow-sm">
                        <i class="bi bi-receipt me-1"></i> New Direct Bill (F7)
                    </a>
                    <a href="<?= APP_URL ?>/modules/accounts/create_customer_payment.php?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-success fw-bold shadow-sm">
                        <i class="bi bi-cash-stack me-1"></i> Receive Payment
                    </a>
                    <a href="<?= APP_URL ?>/modules/returns/create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-danger fw-bold shadow-sm">
                        <i class="bi bi-arrow-return-left me-1"></i> Sales Return
                    </a>
                    <a href="<?= APP_URL ?>/modules/customers/statement.php?view=single&id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-printer me-1"></i> Print Statement (F10)
                    </a>
                    <a href="<?= APP_URL ?>/modules/customers/edit.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil me-1"></i> Edit Info
                    </a>
                </div>
            </div>
        </div>

        <!-- 4 KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-primary-subtle text-primary me-3">
                            <i class="bi bi-receipt-cutoff fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Invoices Billed</div>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($invStats['total_invoices']) ?> Bills</div>
                            <div class="small text-muted"><?= format_currency((float)$invStats['total_billed']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-info-subtle text-info me-3">
                            <i class="bi bi-cart-check fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Orders Placed</div>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($orderCount) ?> Orders</div>
                            <div class="small text-muted">Field Market Bookings</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-success-subtle text-success me-3">
                            <i class="bi bi-cash-coin fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Cash Recovered</div>
                            <div class="fw-bold fs-5 text-success"><?= format_currency((float)$paymentStats['total_recovered']) ?></div>
                            <div class="small text-muted"><?= number_format($paymentStats['total_receipts']) ?> Payment Receipts</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-danger-subtle text-danger me-3">
                            <i class="bi bi-arrow-counterclockwise fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Sales Returns</div>
                            <div class="fw-bold fs-5 text-danger"><?= format_currency((float)$returnStats['total_returned']) ?></div>
                            <div class="small text-muted"><?= number_format($returnStats['total_returns']) ?> Credit Vouchers</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2">
                <ul class="nav nav-tabs card-header-tabs" id="customerTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?= $activeTab === 'invoices' ? 'active text-primary' : 'text-secondary' ?>" 
                           href="?id=<?= $customer['id'] ?>&tab=invoices">
                            <i class="bi bi-receipt me-1"></i> Invoices & Bills (<?= count($invoices) ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?= $activeTab === 'orders' ? 'active text-primary' : 'text-secondary' ?>" 
                           href="?id=<?= $customer['id'] ?>&tab=orders">
                            <i class="bi bi-cart3 me-1"></i> Market Orders (<?= count($orders) ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?= $activeTab === 'ledger' ? 'active text-primary' : 'text-secondary' ?>" 
                           href="?id=<?= $customer['id'] ?>&tab=ledger">
                            <i class="bi bi-journal-text me-1"></i> Running Ledger & Khata
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?= $activeTab === 'payments' ? 'active text-primary' : 'text-secondary' ?>" 
                           href="?id=<?= $customer['id'] ?>&tab=payments">
                            <i class="bi bi-cash-stack me-1"></i> Payment Receipts (<?= count($payments) ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?= $activeTab === 'returns' ? 'active text-primary' : 'text-secondary' ?>" 
                           href="?id=<?= $customer['id'] ?>&tab=returns">
                            <i class="bi bi-arrow-return-left me-1"></i> Sales Returns (<?= count($returns) ?>)
                        </a>
                    </li>
                </ul>
            </div>

            <div class="card-body p-0">
                <!-- TAB 1: INVOICES -->
                <?php if ($activeTab === 'invoices'): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3">Invoice #</th>
                                    <th>Date</th>
                                    <th>Booker</th>
                                    <th class="text-end">Gross (Rs.)</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Net Bill</th>
                                    <th class="text-end">Paid Amount</th>
                                    <th class="text-end">Credit Balance</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($invoices)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-5 text-muted">
                                            <i class="bi bi-receipt fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                            No sales invoices found for this customer.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $inv): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-navy">
                                                <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($inv['invoice_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></td>
                                            <td><?= htmlspecialchars($inv['booker_name'] ?: 'N/A') ?></td>
                                            <td class="text-end"><?= number_format((float)$inv['gross_amount'], 2) ?></td>
                                            <td class="text-end text-danger"><?= (float)$inv['item_discount_amount'] + (float)$inv['extra_discount_amount'] > 0 ? number_format((float)$inv['item_discount_amount'] + (float)$inv['extra_discount_amount'], 2) : '-' ?></td>
                                            <td class="text-end fw-bold text-dark"><?= format_currency((float)$inv['net_amount']) ?></td>
                                            <td class="text-end text-success"><?= number_format((float)$inv['paid_amount'], 2) ?></td>
                                            <td class="text-end text-danger"><?= number_format((float)$inv['credit_amount'], 2) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?= htmlspecialchars($inv['status']) ?></span>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary" title="View Bill">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="<?= APP_URL ?>/modules/invoices/print.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print Bill">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- TAB 2: ORDERS -->
                <?php elseif ($activeTab === 'orders'): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3">Order #</th>
                                    <th>Booking Date</th>
                                    <th>Delivery Date</th>
                                    <th>Booker</th>
                                    <th class="text-center">Cartons</th>
                                    <th class="text-end">Gross Amount</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Net Amount</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-5 text-muted">
                                            <i class="bi bi-cart3 fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                            No market orders booked for this customer.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $ord): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-navy">
                                                <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $ord['id'] ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($ord['order_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= date('d-M-Y', strtotime($ord['order_date'])) ?></td>
                                            <td><?= !empty($ord['delivery_date']) ? date('d-M-Y', strtotime($ord['delivery_date'])) : '<span class="text-muted">-</span>' ?></td>
                                            <td><?= htmlspecialchars($ord['booker_name'] ?: 'N/A') ?></td>
                                            <td class="text-center fw-semibold"><?= (float)$ord['total_cartons'] ?> Ctns</td>
                                            <td class="text-end"><?= number_format((float)$ord['gross_amount'], 2) ?></td>
                                            <td class="text-end text-danger"><?= (float)$ord['discount_amount'] > 0 ? number_format((float)$ord['discount_amount'], 2) : '-' ?></td>
                                            <td class="text-end fw-bold text-navy"><?= format_currency((float)$ord['net_amount']) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?= htmlspecialchars($ord['status']) ?></span>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $ord['id'] ?>" class="btn btn-xs btn-outline-primary" title="View Order">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="<?= APP_URL ?>/modules/orders/print.php?id=<?= $ord['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print Order">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- TAB 3: RUNNING LEDGER STATEMENT -->
                <?php elseif ($activeTab === 'ledger'): ?>
                    <div class="p-3 bg-light border-bottom">
                        <form method="GET" action="view.php" class="row g-2 align-items-center">
                            <input type="hidden" name="id" value="<?= $customer['id'] ?>">
                            <input type="hidden" name="tab" value="ledger">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold mb-1">From Date</label>
                                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold mb-1">To Date</label>
                                <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-filter me-1"></i> Filter Ledger
                                </button>
                                <a href="<?= APP_URL ?>/modules/customers/statement.php?view=single&id=<?= $customer['id'] ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-printer me-1"></i> Print Khata
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0" style="font-size: 13px;">
                            <thead class="table-light small text-center text-uppercase">
                                <tr>
                                    <th style="width: 12%;">Date</th>
                                    <th style="width: 14%;">Voucher / Ref #</th>
                                    <th class="text-start ps-3">Narration / Description</th>
                                    <th style="width: 14%;" class="text-end">Debit (Dr)</th>
                                    <th style="width: 14%;" class="text-end">Credit (Cr)</th>
                                    <th style="width: 16%;" class="text-end pe-3">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="table-light fw-bold">
                                    <td><?= date('d-M-Y', strtotime($fromDate)) ?></td>
                                    <td class="text-center">OPENING</td>
                                    <td class="ps-3 text-muted">Opening balance brought forward</td>
                                    <td class="text-end"><?= $openingBal > 0 ? number_format($openingBal, 2) : '-' ?></td>
                                    <td class="text-end"><?= $openingBal < 0 ? number_format(abs($openingBal), 2) : '-' ?></td>
                                    <td class="text-end pe-3 fw-bold <?= $openingBal > 0 ? 'text-danger' : ($openingBal < 0 ? 'text-primary' : 'text-dark') ?>">
                                        <?= format_currency($openingBal) ?>
                                    </td>
                                </tr>
                                <?php 
                                $runBal = $openingBal;
                                $totDr = 0; $totCr = 0;
                                foreach ($ledgerEntries as $entry): 
                                    $dr = (float)$entry['debit'];
                                    $cr = (float)$entry['credit'];
                                    $totDr += $dr;
                                    $totCr += $cr;
                                    $runBal += ($dr - $cr);
                                ?>
                                    <tr>
                                        <td><?= date('d-M-Y', strtotime($entry['entry_date'])) ?></td>
                                        <td class="font-monospace small text-center"><?= htmlspecialchars($entry['entry_number'] ?: 'JV') ?></td>
                                        <td class="ps-3 text-dark"><?= htmlspecialchars($entry['description']) ?></td>
                                        <td class="text-end fw-semibold text-danger"><?= $dr > 0 ? number_format($dr, 2) : '-' ?></td>
                                        <td class="text-end fw-semibold text-success"><?= $cr > 0 ? number_format($cr, 2) : '-' ?></td>
                                        <td class="text-end pe-3 fw-bold <?= $runBal > 0 ? 'text-danger' : ($runBal < 0 ? 'text-primary' : 'text-dark') ?>">
                                            <?= format_currency($runBal) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold fs-6">
                                <tr>
                                    <td colspan="3" class="text-end text-uppercase">Totals in Date Range:</td>
                                    <td class="text-end text-danger"><?= number_format($totDr, 2) ?></td>
                                    <td class="text-end text-success"><?= number_format($totCr, 2) ?></td>
                                    <td class="text-end pe-3 text-navy"><?= format_currency($runBal) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                <!-- TAB 4: PAYMENT RECEIPTS -->
                <?php elseif ($activeTab === 'payments'): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3">Receipt #</th>
                                    <th>Date</th>
                                    <th>Payment Mode</th>
                                    <th>Account Credited</th>
                                    <th>Reference / Cheque #</th>
                                    <th class="text-end">Amount Recovered</th>
                                    <th>Recorded By</th>
                                    <th class="text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="bi bi-cash-stack fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                            No payment receipts recorded for this customer.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-navy">
                                                <?= htmlspecialchars($p['payment_number']) ?>
                                            </td>
                                            <td><?= date('d-M-Y', strtotime($p['payment_date'])) ?></td>
                                            <td><span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($p['payment_mode']) ?></span></td>
                                            <td><?= htmlspecialchars($p['account_name'] ?: 'Cash Drawer') ?></td>
                                            <td class="text-muted small"><?= htmlspecialchars($p['reference_no'] ?: '-') ?></td>
                                            <td class="text-end fw-bold text-success fs-6"><?= format_currency((float)$p['amount']) ?></td>
                                            <td class="small text-muted"><?= htmlspecialchars($p['creator_name'] ?: 'System') ?></td>
                                            <td class="text-end pe-3">
                                                <a href="<?= APP_URL ?>/modules/accounts/print_receipt.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print Receipt">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- TAB 5: SALES RETURNS -->
                <?php elseif ($activeTab === 'returns'): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3">Return Voucher #</th>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Condition</th>
                                    <th class="text-end">Return Value</th>
                                    <th class="text-center">Stock Status</th>
                                    <th class="text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($returns)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="bi bi-arrow-return-left fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                            No sales returns recorded from this customer.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($returns as $ret): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-navy">
                                                <a href="<?= APP_URL ?>/modules/returns/view.php?id=<?= $ret['id'] ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($ret['return_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= date('d-M-Y', strtotime($ret['return_date'])) ?></td>
                                            <td class="small text-muted">
                                                <?= $ret['settlement_number'] ? 'Settlement: ' . htmlspecialchars($ret['settlement_number']) : ($ret['invoice_number'] ? 'Bill: ' . htmlspecialchars($ret['invoice_number']) : 'Direct Return') ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= ucfirst(htmlspecialchars($ret['condition_type'])) ?></span>
                                            </td>
                                            <td class="text-end fw-bold text-danger"><?= format_currency((float)$ret['total_amount']) ?></td>
                                            <td class="text-center">
                                                <?php if ((int)($ret['stock_settled'] ?? 1) === 1): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check2"></i> Restocked</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock-history me-1"></i>Pending Stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?= APP_URL ?>/modules/returns/view.php?id=<?= $ret['id'] ?>" class="btn btn-xs btn-outline-primary" title="View Return">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="<?= APP_URL ?>/modules/returns/print.php?id=<?= $ret['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print Return">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
