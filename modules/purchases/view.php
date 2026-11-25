<?php
/**
 * DMS - Distribution Management System
 * Purchases - View Purchase Order
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid purchase order ID.');
    redirect(APP_URL . '/modules/purchases/index.php');
}

$po = db_fetch_one("
    SELECT 
        po.*,
        s.supplier_name,
        s.phone as supplier_phone,
        s.address as supplier_address,
        u.full_name as creator_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.id = ?
", [$id]);

if (!$po) {
    set_flash('danger', 'Purchase order not found.');
    redirect(APP_URL . '/modules/purchases/index.php');
}

$items = db_fetch_all("
    SELECT 
        poi.*,
        p.product_code,
        p.product_name,
        p.carton_qty as packs_per_ctn,
        p.pack_qty as pcs_per_pack,
        u.unit_code,
        u.unit_name
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    JOIN units u ON poi.unit_id = u.id
    WHERE poi.purchase_order_id = ?
    ORDER BY poi.id ASC
", [$id]);

// Related GRNs
$grns = db_fetch_all("
    SELECT * FROM goods_receipts 
    WHERE purchase_order_id = ?
    ORDER BY grn_date DESC, id DESC
", [$id]);

$pageTitle = 'PO ' . $po['purchase_number'];
$activeMenu = 'purchases';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Purchases', 'url' => 'index.php'],
    ['label' => $po['purchase_number'], 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Top Action Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-bag-check me-2"></i>Purchase Order: <?= htmlspecialchars($po['purchase_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($po['status'] === 'received'): ?>
                        <span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Received</span>
                    <?php elseif ($po['status'] === 'partially_received'): ?>
                        <span class="badge bg-info text-dark">Partially Received</span>
                    <?php elseif ($po['status'] === 'ordered'): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-truck me-1"></i>Ordered / Pending Delivery</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($po['status']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($po['free_loose_pieces'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-gift-fill me-1"></i> Bonus / Scheme (مفت پیس)</span>
                    <?php endif; ?>
                    <span class="text-muted small">Date: <?= date('d-M-Y', strtotime($po['order_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Orders
                </a>
                <a href="print.php?id=<?= $po['id'] ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-printer me-1"></i> Print PO
                </a>
                <?php if (in_array($po['status'], ['ordered', 'partially_received'], true) && has_permission('purchases_receive')): ?>
                    <a href="../goods_receiving/create.php?po_id=<?= $po['id'] ?>" class="btn btn-success btn-sm shadow-sm fw-bold">
                        <i class="bi bi-box-arrow-in-down me-1"></i> Receive Goods at Warehouse (GRN)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 mb-4 pb-3 border-bottom">
                    <div class="col-md-6">
                        <div class="text-muted small text-uppercase fw-bold">Supplier / Manufacturer</div>
                        <div class="fs-5 fw-bold text-dark mt-1"><?= htmlspecialchars($po['supplier_name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($po['supplier_address'] ?: 'N/A') ?></div>
                        <div class="small text-muted">Phone: <?= htmlspecialchars($po['supplier_phone'] ?: 'N/A') ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small text-uppercase fw-bold">Shipment Terms</div>
                        <div class="small mt-1">Expected: <strong><?= $po['expected_delivery_date'] ? date('d-M-Y', strtotime($po['expected_delivery_date'])) : 'Standard' ?></strong></div>
                        <div class="small text-muted">Supplier Ref: <?= htmlspecialchars($po['supplier_invoice_no'] ?: 'N/A') ?></div>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <div class="text-muted small text-uppercase fw-bold">Net Order Total</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$po['net_amount']) ?></div>
                        <div class="small text-muted">Total Items: <?= count($items) ?></div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase text-center">
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th class="text-start ps-3">Product Description</th>
                                <th style="width: 9%;">Ctn</th>
                                <th style="width: 9%;">Pack</th>
                                <th style="width: 9%;">Pcs</th>
                                <th style="width: 13%;">Total Cartons</th>
                                <th style="width: 12%;">Received</th>
                                <th style="width: 13%;">Purchase Rate</th>
                                <th style="width: 15%;" class="text-end pe-3">Line Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            $totCtn = 0; $totPack = 0; $totPcs = 0;
                            foreach ($items as $item): 
                                $cQty = (float)($item['carton_qty'] ?? 0);
                                $pkQty = (float)($item['pack_qty'] ?? 0);
                                $pQty = (float)($item['pcs_qty'] ?? 0);
                                $totCtn += $cQty; $totPack += $pkQty; $totPcs += $pQty;
                            ?>
                                <tr>
                                    <td class="text-center text-muted small"><?= $sr++ ?></td>
                                    <td class="ps-3 fw-bold text-dark">
                                        <?= htmlspecialchars($item['product_name']) ?> (<?= htmlspecialchars($item['product_code']) ?>)
                                        <?php if ((float)$item['pcs_per_pack'] > 1): ?>
                                            <span class="badge bg-light text-secondary border ms-1" style="font-size: 0.7rem;">
                                                1*<?= (int)$item['packs_per_ctn'] ?>*<?= (int)$item['pcs_per_pack'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold text-dark"><?= $cQty > 0 ? number_format($cQty) : '-' ?></td>
                                    <td class="text-center fw-bold text-dark"><?= $pkQty > 0 ? number_format($pkQty) : '-' ?></td>
                                    <td class="text-center fw-bold text-dark"><?= $pQty > 0 ? number_format($pQty) : '-' ?></td>
                                    <td class="text-center fw-semibold text-primary"><?= number_format((float)$item['quantity'], 2) ?> <?= ($item['unit_code'] === 'PCS' || (int)$item['unit_id'] === 4) ? 'Pcs' : (((int)$item['unit_id'] === 3 || (int)$item['unit_id'] === 2 || in_array(strtolower($item['unit_code'] ?? ''), ['pck', 'pack', 'box'])) ? 'Pack' : 'Ctn') ?></td>
                                    <td class="text-center">
                                        <?php if ((float)$item['received_quantity'] >= (float)$item['quantity']): ?>
                                            <span class="badge bg-success-subtle text-success"><?= (float)$item['received_quantity'] ?></span>
                                        <?php elseif ((float)$item['received_quantity'] > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning"><?= (float)$item['received_quantity'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?= format_currency((float)$item['rate']) ?>
                                        <small class="text-muted d-block" style="font-size: 0.72rem;"><?= ($item['unit_code'] === 'PCS' || (int)$item['unit_id'] === 4) ? '/ Piece' : (((int)$item['unit_id'] === 3 || (int)$item['unit_id'] === 2 || in_array(strtolower($item['unit_code'] ?? ''), ['pck', 'pack', 'box'])) ? '/ Pack' : '/ Carton') ?></small>
                                    </td>
                                    <td class="text-end pe-3 fw-bold text-dark"><?= format_currency((float)$item['line_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="2" class="text-end text-uppercase">Totals:</td>
                                <td class="text-center"><?= number_format($totCtn) ?></td>
                                <td class="text-center"><?= number_format($totPack) ?></td>
                                <td class="text-center"><?= number_format($totPcs) ?></td>
                                <td colspan="3" class="text-end text-uppercase">Net Order Amount:</td>
                                <td class="text-end pe-3 text-navy"><?= format_currency((float)$po['net_amount']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Related GRNs Section -->
                <?php if (!empty($grns)): ?>
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="fw-bold text-navy mb-3"><i class="bi bi-box-arrow-in-down me-1"></i> Warehouse Goods Receipts for this Order</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr>
                                        <th>GRN #</th>
                                        <th>Receipt Date</th>
                                        <th>Supplier Bill #</th>
                                        <th class="text-end">Received Value</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grns as $g): ?>
                                        <tr>
                                            <td class="fw-bold text-navy"><?= htmlspecialchars($g['grn_number']) ?></td>
                                            <td><?= date('d-M-Y', strtotime($g['grn_date'])) ?></td>
                                            <td><?= htmlspecialchars($g['supplier_bill_no'] ?: '-') ?></td>
                                            <td class="text-end fw-bold text-dark"><?= format_currency((float)$g['total_amount']) ?></td>
                                            <td><span class="badge bg-success"><?= htmlspecialchars($g['status']) ?></span></td>
                                            <td class="text-end">
                                                <a href="../goods_receiving/view.php?id=<?= $g['id'] ?>" class="btn btn-xs btn-outline-primary">
                                                    View GRN
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
