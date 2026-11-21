<?php
/**
 * DMS - Distribution Management System
 * Supplier 360° Profile & Factory Account Management Hub
 * Complete ledger statement, purchase orders, goods receiving (GRNs), payments, and supplied catalog
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('suppliers_view');

$supplierId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($supplierId <= 0) {
    set_flash_message('danger', 'Valid supplier ID is required.');
    redirect(APP_URL . '/modules/suppliers/index.php');
}

$supplier = db_fetch_one("
    SELECT * 
    FROM suppliers 
    WHERE id = ? AND is_deleted = 0
", [$supplierId]);

if (!$supplier) {
    set_flash_message('danger', 'Supplier record not found.');
    redirect(APP_URL . '/modules/suppliers/index.php');
}

// Synchronize balance with double-entry accounting audit
$auditedBalance = recalculate_supplier_balance($supplierId);
$supplier['current_balance'] = $auditedBalance;

// Active Tab
$activeTab = sanitize_input($_GET['tab'] ?? 'purchases');

// Date Filters for Ledger
$fromDate = sanitize_input($_GET['from_date'] ?? date('Y-m-01'));
$toDate   = sanitize_input($_GET['to_date'] ?? date('Y-m-d'));

// 1. KPI Summary Aggregates
$poStats = db_fetch_one("
    SELECT 
        COUNT(id) as total_pos,
        COALESCE(SUM(net_amount), 0) as total_po_val,
        COALESCE(SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END), 0) as pending_pos
    FROM purchase_orders 
    WHERE supplier_id = ? AND status != 'cancelled'
", [$supplierId]) ?: ['total_pos' => 0, 'total_po_val' => 0.0, 'pending_pos' => 0];

$grnStats = db_fetch_one("
    SELECT 
        COUNT(id) as total_grns,
        COALESCE(SUM(total_amount), 0) as total_received_val
    FROM goods_receipts 
    WHERE supplier_id = ?
", [$supplierId]) ?: ['total_grns' => 0, 'total_received_val' => 0.0];

$paymentStats = db_fetch_one("
    SELECT 
        COUNT(id) as total_payments,
        COALESCE(SUM(amount), 0) as total_paid
    FROM supplier_payments 
    WHERE supplier_id = ?
", [$supplierId]) ?: ['total_payments' => 0, 'total_paid' => 0.0];

// 2. Tab: Purchase Orders
$purchaseOrders = db_fetch_all("
    SELECT po.*, u.full_name as creator_name
    FROM purchase_orders po
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.supplier_id = ?
    ORDER BY po.order_date DESC, po.id DESC
    LIMIT 100
", [$supplierId]);

// 3. Tab: Goods Receiving Notes (GRNs)
$goodsReceipts = db_fetch_all("
    SELECT gr.*, po.purchase_number, w.warehouse_name, u.full_name as receiver_name
    FROM goods_receipts gr
    LEFT JOIN purchase_orders po ON gr.purchase_order_id = po.id
    LEFT JOIN warehouses w ON gr.warehouse_id = w.id
    LEFT JOIN users u ON gr.received_by = u.id
    WHERE gr.supplier_id = ?
    ORDER BY gr.grn_date DESC, gr.id DESC
    LIMIT 100
", [$supplierId]);

// 4. Tab: Supplier Payments
$payments = db_fetch_all("
    SELECT sp.*, a.account_name, u.full_name as creator_name
    FROM supplier_payments sp
    LEFT JOIN accounts a ON sp.account_id = a.id
    LEFT JOIN users u ON sp.created_by = u.id
    WHERE sp.supplier_id = ?
    ORDER BY sp.payment_date DESC, sp.id DESC
    LIMIT 100
", [$supplierId]);

// 5. Tab: Supplied Products & Catalog
$catalog = db_fetch_all("
    SELECT DISTINCT 
        p.id, p.product_code, p.product_name, p.carton_qty, p.pack_qty,
        p.purchase_rate, p.wholesale_rate, cat.category_name,
        COALESCE(sb.saleable_qty, 0) as stock_qty
    FROM products p
    LEFT JOIN product_categories cat ON cat.id = p.category_id
    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = 1
    WHERE p.id IN (
        SELECT DISTINCT product_id FROM purchase_order_items WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE supplier_id = ?)
        UNION
        SELECT DISTINCT product_id FROM goods_receipt_items WHERE goods_receipt_id IN (SELECT id FROM goods_receipts WHERE supplier_id = ?)
    ) AND p.is_deleted = 0
    ORDER BY p.product_name ASC
    LIMIT 100
", [$supplierId, $supplierId]);

// 6. Tab: Ledger Statement Calculations (Double-Entry General Ledger)
$hasOpeningJE = (bool)db_fetch_val("
    SELECT jel.id FROM journal_entry_lines jel
    JOIN journal_entries je ON je.id = jel.journal_entry_id
    WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.reference_type = 'opening'
", [$supplierId]);
$baseOpening = (!$hasOpeningJE) ? (float)($supplier['opening_balance'] ?? 0) : 0.0;

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

$openingBal = $baseOpening + ($preCredits - $preDebits);

$ledgerEntries = db_fetch_all("
    SELECT 
        je.entry_date, je.entry_number, je.reference_type, je.reference_id,
        jel.description, jel.debit, jel.credit
    FROM journal_entry_lines jel
    JOIN journal_entries je ON je.id = jel.journal_entry_id
    WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.entry_date BETWEEN ? AND ?
    ORDER BY je.entry_date ASC, je.id ASC
", [$supplierId, $fromDate, $toDate]);

$pageTitle = 'Supplier Profile: ' . $supplier['supplier_name'] . ' — ' . APP_NAME;
$activeMenu = 'suppliers';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Suppliers', 'url' => 'index.php'],
    ['label' => $supplier['supplier_name'], 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Supplier Profile Hero Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-7">
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar-lg rounded bg-navy text-white d-flex align-items-center justify-content-center fs-2 fw-bold shadow-sm" style="width: 64px; height: 64px; min-width: 64px;">
                                <?= strtoupper(substr($supplier['supplier_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <h4 class="mb-0 fw-bold text-navy"><?= htmlspecialchars($supplier['supplier_name']) ?></h4>
                                    <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($supplier['supplier_code']) ?></span>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <?= $supplier['is_active'] ? 'Active Vendor' : 'Inactive' ?>
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-geo-alt me-1 text-danger"></i>
                                    <?= htmlspecialchars($supplier['address'] ?? 'No physical address recorded') ?>
                                    <?= !empty($supplier['city']) ? '— ' . htmlspecialchars($supplier['city']) : '' ?>
                                </div>
                                <div class="text-muted small mt-1 d-flex flex-wrap gap-3">
                                    <?php if (!empty($supplier['phone'])): ?>
                                        <span><i class="bi bi-telephone me-1 text-primary"></i><?= htmlspecialchars($supplier['phone']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['company_name'])): ?>
                                        <span><i class="bi bi-building me-1 text-secondary"></i>Company: <strong><?= htmlspecialchars($supplier['company_name']) ?></strong></span>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['contact_person'])): ?>
                                        <span><i class="bi bi-person me-1 text-info"></i>Contact: <strong><?= htmlspecialchars($supplier['contact_person']) ?></strong></span>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['email'])): ?>
                                        <span><i class="bi bi-envelope me-1 text-muted"></i><?= htmlspecialchars($supplier['email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: Outstanding Payable Balance Widget -->
                    <div class="col-md-5 text-md-end">
                        <div class="p-3 rounded bg-light border d-inline-block text-md-end text-start w-100" style="max-width: 380px;">
                            <div class="text-muted small text-uppercase fw-bold mb-1">
                                <i class="bi bi-wallet2 me-1"></i> Current Payable Balance (Khata)
                            </div>
                            <div class="fs-3 fw-bold <?= $supplier['current_balance'] > 0 ? 'text-danger' : ($supplier['current_balance'] < 0 ? 'text-primary' : 'text-success') ?>">
                                <?= format_currency($supplier['current_balance']) ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small">
                                <span class="text-muted">Account Status:</span>
                                <?php if ($supplier['current_balance'] > 0): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Payment Owed to Factory</span>
                                <?php elseif ($supplier['current_balance'] < 0): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Advance Paid to Supplier</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Settled / Zero Balance</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Action Hub Buttons -->
                <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                    <a href="<?= APP_URL ?>/modules/purchases/create.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-sm btn-primary fw-bold shadow-sm">
                        <i class="bi bi-cart-plus me-1"></i> New Purchase Order
                    </a>
                    <a href="<?= APP_URL ?>/modules/goods_receiving/create.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-sm btn-navy fw-bold shadow-sm">
                        <i class="bi bi-box-seam me-1"></i> Receive Goods (GRN)
                    </a>
                    <a href="<?= APP_URL ?>/modules/accounts/create_supplier_payment.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-sm btn-success fw-bold shadow-sm">
                        <i class="bi bi-cash-stack me-1"></i> Pay Supplier
                    </a>
                    <a href="<?= APP_URL ?>/modules/suppliers/ledger.php?id=<?= $supplier['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-printer me-1"></i> Print Ledger (F10)
                    </a>
                    <a href="<?= APP_URL ?>/modules/suppliers/edit.php?id=<?= $supplier['id'] ?>" class="btn btn-sm btn-outline-secondary">
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
                            <i class="bi bi-file-earmark-text fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Purchase Orders Issued</div>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($poStats['total_pos']) ?> Orders</div>
                            <div class="small text-muted"><?= format_currency((float)$poStats['total_po_val']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-info-subtle text-info me-3">
                            <i class="bi bi-boxes fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Goods Received (GRN)</div>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($grnStats['total_grns']) ?> Shipments</div>
                            <div class="small text-muted"><?= format_currency((float)$grnStats['total_received_val']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-success-subtle text-success me-3">
                            <i class="bi bi-check2-circle fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Payments Disbursed</div>
                            <div class="fw-bold fs-5 text-success"><?= format_currency((float)$paymentStats['total_paid']) ?></div>
                            <div class="small text-muted"><?= number_format($paymentStats['total_payments']) ?> Payment Vouchers</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-3 px-3 d-flex align-items-center">
                        <div class="p-3 rounded bg-warning-subtle text-warning me-3">
                            <i class="bi bi-tags fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Supplied Products</div>
                            <div class="fw-bold fs-5 text-dark"><?= count($catalog) ?> Products</div>
                            <div class="small text-muted"><?= number_format($poStats['pending_pos']) ?> Active / Pending POs</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 360° Management Tabs Card -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom p-0">
                <ul class="nav nav-tabs card-header-tabs m-0 px-3 border-bottom-0" id="supplierTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'purchases' ? 'active text-primary' : 'text-muted' ?>" id="purchases-tab" data-bs-toggle="tab" data-bs-target="#purchases-pane" type="button" role="tab">
                            <i class="bi bi-cart-check me-1"></i> Purchase Orders (<?= count($purchaseOrders) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'grns' ? 'active text-primary' : 'text-muted' ?>" id="grns-tab" data-bs-toggle="tab" data-bs-target="#grns-pane" type="button" role="tab">
                            <i class="bi bi-box-arrow-in-down me-1"></i> Goods Receiving / GRNs (<?= count($goodsReceipts) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'ledger' ? 'active text-primary' : 'text-muted' ?>" id="ledger-tab" data-bs-toggle="tab" data-bs-target="#ledger-pane" type="button" role="tab">
                            <i class="bi bi-journal-text me-1"></i> Running Ledger & Statement
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'payments' ? 'active text-primary' : 'text-muted' ?>" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments-pane" type="button" role="tab">
                            <i class="bi bi-cash-stack me-1"></i> Payment Vouchers (<?= count($payments) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold py-3 <?= $activeTab === 'catalog' ? 'active text-primary' : 'text-muted' ?>" id="catalog-tab" data-bs-toggle="tab" data-bs-target="#catalog-pane" type="button" role="tab">
                            <i class="bi bi-boxes me-1"></i> Supplied Products Catalog (<?= count($catalog) ?>)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body p-0">
                <div class="tab-content" id="supplierTabsContent">
                    <!-- TAB 1: PURCHASE ORDERS -->
                    <div class="tab-pane fade <?= $activeTab === 'purchases' ? 'show active' : '' ?>" id="purchases-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">History of Factory Purchase Orders</span>
                            <a href="<?= APP_URL ?>/modules/purchases/create.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-lg me-1"></i> Create Purchase Order
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-uppercase">
                                        <th>PO Number</th>
                                        <th>Order Date</th>
                                        <th>Expected Delivery</th>
                                        <th>Supplier Inv / Bilty</th>
                                        <th class="text-end">Cartons</th>
                                        <th class="text-end">Net Amount</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($purchaseOrders)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary"></i>
                                                No purchase orders found for this supplier.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($purchaseOrders as $po): ?>
                                            <tr>
                                                <td class="fw-bold font-monospace">
                                                    <a href="<?= APP_URL ?>/modules/purchases/view.php?id=<?= $po['id'] ?>" class="text-decoration-none text-navy">
                                                        <?= htmlspecialchars($po['purchase_number']) ?>
                                                    </a>
                                                </td>
                                                <td><?= date('d-M-Y', strtotime($po['order_date'])) ?></td>
                                                <td><?= !empty($po['expected_delivery_date']) ? date('d-M-Y', strtotime($po['expected_delivery_date'])) : '<span class="text-muted">-</span>' ?></td>
                                                <td><?= !empty($po['supplier_invoice_no']) ? htmlspecialchars($po['supplier_invoice_no']) : '<span class="text-muted">-</span>' ?></td>
                                                <td class="text-end"><?= number_format((float)($po['total_cartons'] ?? 0), 1) ?></td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency((float)$po['net_amount']) ?></td>
                                                <td class="text-center">
                                                    <?php
                                                    $stBadge = 'bg-secondary';
                                                    if ($po['status'] === 'received') $stBadge = 'bg-success';
                                                    elseif ($po['status'] === 'ordered') $stBadge = 'bg-primary';
                                                    elseif ($po['status'] === 'partially_received') $stBadge = 'bg-info text-dark';
                                                    elseif ($po['status'] === 'cancelled') $stBadge = 'bg-danger';
                                                    ?>
                                                    <span class="badge <?= $stBadge ?>"><?= ucfirst(str_replace('_', ' ', $po['status'])) ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="<?= APP_URL ?>/modules/purchases/view.php?id=<?= $po['id'] ?>" class="btn btn-outline-primary" title="View PO Details">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="<?= APP_URL ?>/modules/purchases/print.php?id=<?= $po['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print PO">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                        <?php if ($po['status'] !== 'received' && $po['status'] !== 'cancelled'): ?>
                                                            <a href="<?= APP_URL ?>/modules/goods_receiving/create.php?po_id=<?= $po['id'] ?>" class="btn btn-outline-success" title="Receive Goods against this PO">
                                                                <i class="bi bi-box-arrow-in-down"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 2: GOODS RECEIVING NOTES (GRNs) -->
                    <div class="tab-pane fade <?= $activeTab === 'grns' ? 'show active' : '' ?>" id="grns-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">Stock Shipments & Factory Invoices Received</span>
                            <a href="<?= APP_URL ?>/modules/goods_receiving/create.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-sm btn-navy">
                                <i class="bi bi-box-arrow-in-down me-1"></i> Receive Goods (GRN)
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-uppercase">
                                        <th>GRN #</th>
                                        <th>Receiving Date</th>
                                        <th>Linked PO #</th>
                                        <th>Supplier Bill / Invoice #</th>
                                        <th>Warehouse</th>
                                        <th class="text-end">Total Amount</th>
                                        <th>Received By</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($goodsReceipts)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="bi bi-box fs-1 d-block mb-2 text-secondary"></i>
                                                No goods receiving notes recorded yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($goodsReceipts as $gr): ?>
                                            <tr>
                                                <td class="fw-bold font-monospace">
                                                    <a href="<?= APP_URL ?>/modules/goods_receiving/view.php?id=<?= $gr['id'] ?>" class="text-decoration-none text-navy">
                                                        <?= htmlspecialchars($gr['grn_number']) ?>
                                                    </a>
                                                </td>
                                                <td><?= date('d-M-Y', strtotime($gr['grn_date'])) ?></td>
                                                <td>
                                                    <?php if (!empty($gr['purchase_number'])): ?>
                                                        <a href="<?= APP_URL ?>/modules/purchases/view.php?id=<?= $gr['purchase_order_id'] ?>" class="badge bg-light text-dark border font-monospace text-decoration-none">
                                                            <?= htmlspecialchars($gr['purchase_number']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">Direct GRN</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= !empty($gr['supplier_bill_no']) ? htmlspecialchars($gr['supplier_bill_no']) : '<span class="text-muted">-</span>' ?></td>
                                                <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($gr['warehouse_name'] ?? 'Main') ?></span></td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency((float)$gr['total_amount']) ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars($gr['receiver_name'] ?? '-') ?></td>
                                                <td class="text-end">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="<?= APP_URL ?>/modules/goods_receiving/view.php?id=<?= $gr['id'] ?>" class="btn btn-outline-primary" title="View GRN">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="<?= APP_URL ?>/modules/goods_receiving/print.php?id=<?= $gr['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print GRN">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 3: RUNNING LEDGER & KHATA STATEMENT -->
                    <div class="tab-pane fade <?= $activeTab === 'ledger' ? 'show active' : '' ?>" id="ledger-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom">
                            <form method="GET" action="<?= APP_URL ?>/modules/suppliers/view.php" class="row g-2 align-items-center">
                                <input type="hidden" name="id" value="<?= $supplier['id'] ?>">
                                <input type="hidden" name="tab" value="ledger">
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold mb-1">From Date</label>
                                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold mb-1">To Date</label>
                                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-4">
                                        <i class="bi bi-filter me-1"></i> Apply Filter
                                    </button>
                                </div>
                                <div class="col-md-4 text-end d-flex align-items-end justify-content-end">
                                    <a href="<?= APP_URL ?>/modules/suppliers/ledger.php?id=<?= $supplier['id'] ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-4">
                                        <i class="bi bi-printer me-1"></i> Print Formal Ledger (F10)
                                    </a>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 font-monospace small">
                                <thead class="table-light text-uppercase">
                                    <tr>
                                        <th style="width: 100px;">Date</th>
                                        <th style="width: 140px;">Reference</th>
                                        <th>Description</th>
                                        <th class="text-end" style="width: 130px;">Debit (Paid Out)</th>
                                        <th class="text-end" style="width: 130px;">Credit (Payable Added)</th>
                                        <th class="text-end" style="width: 140px;">Running Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="bg-light fw-bold">
                                        <td><?= date('d-M-Y', strtotime($fromDate)) ?></td>
                                        <td colspan="2"><i class="bi bi-hourglass-split me-1 text-secondary"></i> OPENING BALANCE BROUGHT FORWARD</td>
                                        <td class="text-end">-</td>
                                        <td class="text-end">-</td>
                                        <td class="text-end <?= $openingBal > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= format_currency($openingBal) ?>
                                        </td>
                                    </tr>
                                    <?php 
                                    $runningBal = $openingBal;
                                    $totDebits = 0;
                                    $totCredits = 0;
                                    ?>
                                    <?php if (empty($ledgerEntries)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted fst-italic">
                                                No ledger transactions recorded between <?= date('d-M-Y', strtotime($fromDate)) ?> and <?= date('d-M-Y', strtotime($toDate)) ?>.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($ledgerEntries as $entry): ?>
                                            <?php 
                                            $dr = (float)$entry['debit'];
                                            $cr = (float)$entry['credit'];
                                            // Supplier Liability: Cr increases payable, Dr decreases payable
                                            $runningBal += ($cr - $dr);
                                            $totDebits += $dr;
                                            $totCredits += $cr;
                                            ?>
                                            <tr>
                                                <td><?= date('d-M-Y', strtotime($entry['entry_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-secondary-subtle text-secondary border font-monospace">
                                                        <?= htmlspecialchars($entry['entry_number']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-wrap"><?= htmlspecialchars($entry['description']) ?></td>
                                                <td class="text-end text-success"><?= $dr > 0 ? format_currency($dr) : '-' ?></td>
                                                <td class="text-end text-danger"><?= $cr > 0 ? format_currency($cr) : '-' ?></td>
                                                <td class="text-end fw-bold <?= $runningBal > 0 ? 'text-danger' : 'text-success' ?>">
                                                    <?= format_currency($runningBal) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="3" class="text-end text-uppercase">Period Totals:</td>
                                        <td class="text-end text-success"><?= format_currency($totDebits) ?></td>
                                        <td class="text-end text-danger"><?= format_currency($totCredits) ?></td>
                                        <td class="text-end <?= $runningBal > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= format_currency($runningBal) ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 4: PAYMENT VOUCHERS -->
                    <div class="tab-pane fade <?= $activeTab === 'payments' ? 'show active' : '' ?>" id="payments-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">History of Payments Made to Supplier / Factory</span>
                            <a href="<?= APP_URL ?>/modules/accounts/create_supplier_payment.php?supplier_id=<?= $supplier['id'] ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-cash-stack me-1"></i> Disburse New Payment
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-uppercase">
                                        <th>Voucher #</th>
                                        <th>Payment Date</th>
                                        <th>Bank / Cash Account</th>
                                        <th>Mode</th>
                                        <th>Reference / Cheque #</th>
                                        <th class="text-end">Amount Paid</th>
                                        <th>Created By</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($payments)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                                                No payment vouchers recorded for this supplier.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($payments as $pay): ?>
                                            <tr>
                                                <td class="fw-bold font-monospace">
                                                    <a href="<?= APP_URL ?>/modules/accounts/view_supplier_payment.php?id=<?= $pay['id'] ?>" class="text-decoration-none text-navy">
                                                        <?= htmlspecialchars($pay['payment_number'] ?? ('PV-' . $pay['id'])) ?>
                                                    </a>
                                                </td>
                                                <td><?= date('d-M-Y', strtotime($pay['payment_date'])) ?></td>
                                                <td><?= htmlspecialchars($pay['account_name'] ?? 'Main Cash Account') ?></td>
                                                <td>
                                                    <span class="badge bg-secondary text-uppercase">
                                                        <?= htmlspecialchars($pay['payment_mode']) ?>
                                                    </span>
                                                </td>
                                                <td><?= !empty($pay['reference_no']) ? htmlspecialchars($pay['reference_no']) : '<span class="text-muted">-</span>' ?></td>
                                                <td class="text-end fw-bold text-success"><?= format_currency((float)$pay['amount']) ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars($pay['creator_name'] ?? '-') ?></td>
                                                <td class="text-end">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="<?= APP_URL ?>/modules/accounts/view_supplier_payment.php?id=<?= $pay['id'] ?>" class="btn btn-outline-primary" title="View Voucher">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="<?= APP_URL ?>/modules/accounts/print_supplier_payment.php?id=<?= $pay['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print Voucher">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 5: SUPPLIED PRODUCTS CATALOG -->
                    <div class="tab-pane fade <?= $activeTab === 'catalog' ? 'show active' : '' ?>" id="catalog-pane" role="tabpanel">
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="small text-muted">Stock Items Supplied by this Vendor & Current Inventory Levels</span>
                            <span class="badge bg-secondary"><?= count($catalog) ?> Products</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-uppercase">
                                        <th style="width: 100px;">Code</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th class="text-center">Packaging (Ctn/Pack)</th>
                                        <th class="text-end">Purchase Cost Rate</th>
                                        <th class="text-end">Wholesale Sale Rate</th>
                                        <th class="text-end">Current Stock (Pcs)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($catalog)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                <i class="bi bi-box-seam fs-1 d-block mb-2 text-secondary"></i>
                                                No products have been purchased or received from this supplier yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($catalog as $prod): ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($prod['product_code']) ?></span></td>
                                                <td class="fw-semibold text-dark"><?= htmlspecialchars($prod['product_name']) ?></td>
                                                <td><?= htmlspecialchars($prod['category_name'] ?? 'General') ?></td>
                                                <td class="text-center small">
                                                    1 CTN = <?= (int)$prod['carton_qty'] ?> Pcs
                                                    <?php if ((int)$prod['pack_qty'] > 1): ?>
                                                        (Pack: <?= (int)$prod['pack_qty'] ?>)
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency((float)$prod['purchase_rate']) ?></td>
                                                <td class="text-end text-muted"><?= format_currency((float)$prod['wholesale_rate']) ?></td>
                                                <td class="text-end fw-bold <?= (float)$prod['stock_qty'] <= 0 ? 'text-danger' : 'text-success' ?>">
                                                    <?= number_format((float)$prod['stock_qty']) ?>
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
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
