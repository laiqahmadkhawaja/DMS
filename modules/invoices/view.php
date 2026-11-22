<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - View Invoice Details
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('invoices_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid invoice ID.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$invoice = db_fetch_one("
    SELECT 
        inv.*,
        c.shop_name as customer_name,
        c.customer_code,
        c.phone as customer_phone,
        c.address as customer_address,
        c.current_balance as customer_current_balance,
        a.area_name,
        b.booker_name,
        s.salesman_name,
        ls.load_number,
        o.order_number,
        u.full_name as creator_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    LEFT JOIN salesmen s ON inv.salesman_id = s.id
    LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
    LEFT JOIN orders o ON inv.order_id = o.id
    LEFT JOIN users u ON inv.created_by = u.id
    WHERE inv.id = ?
", [$id]);

if (!$invoice) {
    set_flash('danger', 'Invoice not found.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$items = db_fetch_all("
    SELECT 
        sii.*,
        p.product_code,
        p.product_name,
        p.carton_qty as packs_per_ctn,
        p.pack_qty as pcs_per_pack,
        u.unit_code,
        u.unit_name
    FROM sales_invoice_items sii
    JOIN products p ON sii.product_id = p.id
    JOIN units u ON sii.unit_id = u.id
    WHERE sii.invoice_id = ?
    ORDER BY sii.id ASC
", [$id]);

$pageTitle = 'Invoice ' . $invoice['invoice_number'];
$activeMenu = 'invoices';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Invoices', 'url' => 'index.php'],
    ['label' => $invoice['invoice_number'], 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Top Actions Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-receipt me-2"></i>Invoice: <?= htmlspecialchars($invoice['invoice_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($invoice['status'] === 'settled'): ?>
                        <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i>Settled</span>
                    <?php elseif ($invoice['status'] === 'finalized'): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-truck me-1"></i>In Route</span>
                    <?php elseif ($invoice['status'] === 'cancelled'): ?>
                        <span class="badge bg-danger">Cancelled</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($invoice['status']) ?></span>
                    <?php endif; ?>

                    <span class="text-muted small">Date: <?= date('d-M-Y', strtotime($invoice['invoice_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Bills
                </a>
                <a href="edit.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-warning btn-sm shadow-sm">
                    <i class="bi bi-pencil me-1"></i> Edit Bill
                </a>
                <div class="btn-group btn-group-sm">
                    <a href="print.php?id=<?= $invoice['id'] ?>" target="_blank" class="btn btn-primary shadow-sm">
                        <i class="bi bi-printer me-1"></i> Print Bill (Photo 2/7)
                    </a>
                    <a href="print.php?id=<?= $invoice['id'] ?>&mode=duplicate" target="_blank" class="btn btn-outline-primary shadow-sm" title="Print Customer + Office copy on single page">
                        <i class="bi bi-files me-1"></i> 2-up Duplicate
                    </a>
                </div>
                <?php if ($invoice['status'] !== 'cancelled' && $invoice['status'] !== 'settled' && has_permission('invoices_cancel')): ?>
                    <a href="action.php?action=cancel&id=<?= $invoice['id'] ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Cancel this invoice?');">
                        <i class="bi bi-x-circle me-1"></i> Cancel Bill
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left: Bill Details & Line Items -->
            <div class="col-lg-8">
                <!-- Customer & Delivery Info Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-shop me-1"></i> Customer & Routing Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="text-muted small text-uppercase fw-bold">Customer / Retail Shop</div>
                                <div class="fs-6 fw-bold text-dark mt-1">
                                    <a href="../customers/statement.php?id=<?= $invoice['customer_id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($invoice['customer_name']) ?> (<?= htmlspecialchars($invoice['customer_code']) ?>)
                                    </a>
                                </div>
                                <div class="small text-muted mt-1"><?= htmlspecialchars($invoice['customer_address'] ?: 'No address specified') ?></div>
                                <div class="small text-muted">Phone: <?= htmlspecialchars($invoice['customer_phone'] ?: 'N/A') ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small text-uppercase fw-bold">Route / Booker</div>
                                <div class="fw-semibold text-dark mt-1"><?= htmlspecialchars($invoice['area_name']) ?></div>
                                <div class="small text-muted mt-1">Booker: <?= htmlspecialchars($invoice['booker_name']) ?></div>
                                <div class="small text-muted">Salesman: <?= htmlspecialchars($invoice['salesman_name'] ?: 'Route Delivery') ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small text-uppercase fw-bold">Related Documents</div>
                                <div class="small mt-1">
                                    <?php if ($invoice['order_number']): ?>
                                        Order: <a href="../orders/view.php?id=<?= $invoice['order_id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($invoice['order_number']) ?></a><br>
                                    <?php endif; ?>
                                    <?php if ($invoice['load_number']): ?>
                                        Load Sheet: <a href="../loading/view.php?id=<?= $invoice['load_sheet_id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($invoice['load_number']) ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Line Items Table -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-box-seam me-1"></i> Billed Items</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase text-center">
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th class="text-start ps-3">Product Description</th>
                                        <th style="width: 90px;">Carton</th>
                                        <th style="width: 100px;">Con. Pkng</th>
                                        <th style="width: 80px;">Packs</th>
                                        <th style="width: 80px;">Pcs</th>
                                        <th style="width: 100px;">Total Base</th>
                                        <th style="width: 110px;">Unit Rate</th>
                                        <th style="width: 120px;" class="text-end pe-3">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sr = 1;
                                    foreach ($items as $item): 
                                        $packsPerCtn = (float)$item['packs_per_ctn'] > 0 ? (float)$item['packs_per_ctn'] : 1;
                                        $pcsPerPack  = (float)$item['pcs_per_pack'] > 0 ? (float)$item['pcs_per_pack'] : 1;
                                        $conPkg = $pcsPerPack > 1 ? "1*{$packsPerCtn}*{$pcsPerPack}" : "1*{$packsPerCtn}";
                                    ?>
                                        <tr>
                                            <td class="text-center text-muted small"><?= $sr++ ?></td>
                                            <td class="ps-3 fw-bold text-dark">
                                                <?= htmlspecialchars($item['product_name']) ?>
                                            </td>
                                            <td class="text-center fw-semibold"><?= (float)$item['carton_qty'] > 0 ? (float)$item['carton_qty'] : '-' ?></td>
                                            <td class="text-center small text-muted"><?= htmlspecialchars($conPkg) ?></td>
                                            <td class="text-center"><?= (float)$item['pack_qty'] > 0 ? (float)$item['pack_qty'] : '-' ?></td>
                                            <td class="text-center"><?= (float)$item['pcs_qty'] > 0 ? (float)$item['pcs_qty'] : '-' ?></td>
                                            <td class="text-center fw-semibold text-navy"><?= (float)$item['quantity'] ?></td>
                                            <td class="text-center">
                                                <?= format_currency((float)$item['rate']) ?>
                                                <small class="text-muted d-block" style="font-size: 0.72rem;"><?= ($item['unit_code'] === 'PCS' || (int)$item['unit_id'] === 4) ? '/ Piece' : '/ Carton' ?></small>
                                            </td>
                                            <td class="text-end pe-3 fw-bold text-dark"><?= format_currency((float)$item['line_total']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Financial Summary & Route Settlement Status -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-navy text-white py-3">
                        <h6 class="mb-0 fw-bold text-white"><i class="bi bi-cash-stack me-1"></i> Financial Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Gross Amount:</span>
                            <span class="fw-semibold text-dark"><?= format_currency((float)$invoice['gross_amount']) ?></span>
                        </div>
                        <?php 
                        $totalDisc = (float)($invoice['item_discount_amount'] ?? 0) + (float)($invoice['extra_discount_amount'] ?? 0);
                        $discMode = $invoice['discount_mode'] ?? ((float)($invoice['extra_discount_amount'] ?? 0) > 0 ? 'subtotal' : 'item_rate');
                        ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Discount Mode:</span>
                            <span class="badge <?= $discMode === 'subtotal' ? 'bg-info text-dark' : 'bg-secondary' ?>">
                                <?= $discMode === 'subtotal' ? 'Subtotal Discount' : 'Per Item Rate' ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Discount Allowed:</span>
                            <span class="fw-semibold text-danger">-<?= format_currency($totalDisc) ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="fw-bold fs-6 text-navy">Net Bill Amount:</span>
                            <span class="fw-bold fs-5 text-navy"><?= format_currency((float)$invoice['net_amount']) ?></span>
                        </div>

                        <!-- Settlement Status Breakdown -->
                        <div class="p-3 bg-light rounded border">
                            <div class="small fw-bold text-uppercase text-muted mb-2">Route Recovery Breakdown</div>
                            <div class="d-flex justify-content-between mb-1 small">
                                <span><i class="bi bi-cash text-success me-1"></i> Cash Collected:</span>
                                <span class="fw-bold text-success"><?= format_currency((float)$invoice['paid_amount']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 small">
                                <span><i class="bi bi-clock-history text-danger me-1"></i> Credit Given:</span>
                                <span class="fw-bold text-danger"><?= format_currency((float)$invoice['credit_amount']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span><i class="bi bi-arrow-return-left text-warning me-1"></i> Goods Returned:</span>
                                <span class="fw-bold text-warning"><?= format_currency((float)$invoice['return_amount']) ?></span>
                            </div>
                        </div>

                        <!-- Customer Ledger Info -->
                        <div class="mt-3 text-center">
                            <a href="../customers/statement.php?id=<?= $invoice['customer_id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-journal-text me-1"></i> View Customer Running Ledger
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Audit Log Card -->
                <div class="card border-0 shadow-sm">
                    <div class="card-body small text-muted">
                        <div><strong>Created By:</strong> <?= htmlspecialchars($invoice['creator_name'] ?: 'System') ?></div>
                        <div class="mt-1"><strong>Created Date:</strong> <?= date('d-M-Y h:i A', strtotime($invoice['created_at'])) ?></div>
                        <?php if ($invoice['finalized_at']): ?>
                            <div class="mt-1"><strong>Finalized Date:</strong> <?= date('d-M-Y h:i A', strtotime($invoice['finalized_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
