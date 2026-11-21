<?php
/**
 * DMS - Distribution Management System
 * Goods Receiving Note (GRN) - View & Print GRN Slip
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_receive');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid GRN ID.');
    redirect(APP_URL . '/modules/goods_receiving/index.php');
}

$grn = db_fetch_one("
    SELECT 
        gr.*,
        s.supplier_name,
        s.phone as supplier_phone,
        s.address as supplier_address,
        w.warehouse_name,
        po.purchase_number,
        u.full_name as receiver_name
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN warehouses w ON gr.warehouse_id = w.id
    LEFT JOIN purchase_orders po ON gr.purchase_order_id = po.id
    LEFT JOIN users u ON gr.received_by = u.id
    WHERE gr.id = ?
", [$id]);

if (!$grn) {
    set_flash('danger', 'Goods receipt not found.');
    redirect(APP_URL . '/modules/goods_receiving/index.php');
}

$items = db_fetch_all("
    SELECT 
        gri.*,
        p.product_code,
        p.product_name,
        p.carton_qty as packs_per_ctn,
        p.pack_qty as pcs_per_pack,
        u.unit_code,
        u.unit_name
    FROM goods_receipt_items gri
    JOIN products p ON gri.product_id = p.id
    JOIN units u ON gri.unit_id = u.id
    WHERE gri.goods_receipt_id = ?
    ORDER BY gri.id ASC
", [$id]);

$journalEntry = db_fetch_one("
    SELECT * FROM journal_entries 
    WHERE reference_type = 'grn' AND reference_id = ?
", [$id]);

$pageTitle = 'GRN ' . $grn['grn_number'];
$activeMenu = 'purchases';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Goods Receiving', 'url' => 'index.php'],
    ['label' => $grn['grn_number'], 'url' => '']
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
                    <i class="bi bi-box-arrow-in-down me-2"></i>Goods Receipt Note: <?= htmlspecialchars($grn['grn_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($grn['status']) ?></span>
                    <span class="text-muted small">Date: <?= date('d-M-Y', strtotime($grn['grn_date'])) ?> | Warehouse: <?= htmlspecialchars($grn['warehouse_name']) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to GRNs
                </a>
                <a href="print.php?id=<?= $grn['id'] ?>" class="btn btn-primary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print GRN Slip
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 mb-4 pb-3 border-bottom">
                    <div class="col-md-6">
                        <div class="text-muted small text-uppercase fw-bold">Supplier / Manufacturer</div>
                        <div class="fs-5 fw-bold text-dark mt-1">
                            <a href="../suppliers/ledger.php?id=<?= $grn['supplier_id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($grn['supplier_name']) ?>
                            </a>
                        </div>
                        <div class="small text-muted"><?= htmlspecialchars($grn['supplier_address'] ?: 'N/A') ?></div>
                        <div class="small text-muted">Phone: <?= htmlspecialchars($grn['supplier_phone'] ?: 'N/A') ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small text-uppercase fw-bold">Receiving Information</div>
                        <div class="small mt-1">Warehouse: <strong><?= htmlspecialchars($grn['warehouse_name']) ?></strong></div>
                        <div class="small text-muted">Supplier Bilty/Inv: <strong><?= htmlspecialchars($grn['supplier_bill_no'] ?: 'N/A') ?></strong></div>
                        <?php if ($grn['purchase_number']): ?>
                            <div class="small text-muted">PO: <a href="../purchases/view.php?id=<?= $grn['purchase_order_id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($grn['purchase_number']) ?></a></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <div class="text-muted small text-uppercase fw-bold">Stock Received Total</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$grn['total_amount']) ?></div>
                        <div class="small text-muted">Receiver: <?= htmlspecialchars($grn['receiver_name'] ?: 'Warehouse Gate') ?></div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase text-center">
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th class="text-start ps-3">Product Description</th>
                                <th style="width: 10%;">Ordered (Ctn)</th>
                                <th style="width: 8%;">Recv Ctn</th>
                                <th style="width: 8%;">Recv Pack</th>
                                <th style="width: 8%;">Recv Pcs</th>
                                <th style="width: 10%;">Total Cartons</th>
                                <th style="width: 9%;">Damaged</th>
                                <th style="width: 12%;">Cost / Ctn</th>
                                <th style="width: 15%;" class="text-end pe-3">Received Valuation</th>
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
                                    <td class="text-center text-muted"><?= (float)$item['ordered_qty'] > 0 ? (float)$item['ordered_qty'] : '-' ?></td>
                                    <td class="text-center fw-bold text-dark"><?= $cQty > 0 ? number_format($cQty) : '-' ?></td>
                                    <td class="text-center fw-bold text-dark"><?= $pkQty > 0 ? number_format($pkQty) : '-' ?></td>
                                    <td class="text-center fw-bold text-dark"><?= $pQty > 0 ? number_format($pQty) : '-' ?></td>
                                    <td class="text-center fw-bold text-success fs-6"><?= number_format((float)$item['received_qty'], 2) ?> Ctn</td>
                                    <td class="text-center fw-semibold text-danger"><?= (float)$item['damaged_qty'] > 0 ? (float)$item['damaged_qty'] : '-' ?></td>
                                    <td class="text-center"><?= format_currency((float)$item['unit_rate']) ?></td>
                                    <td class="text-end pe-3 fw-bold text-dark"><?= format_currency((float)$item['line_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="3" class="text-end text-uppercase">Totals:</td>
                                <td class="text-center"><?= number_format($totCtn) ?></td>
                                <td class="text-center"><?= number_format($totPack) ?></td>
                                <td class="text-center"><?= number_format($totPcs) ?></td>
                                <td colspan="3" class="text-end text-uppercase">Total Added to Stock:</td>
                                <td class="text-end pe-3 text-navy"><?= format_currency((float)$grn['total_amount']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures -->
                <div class="row pt-4 text-center small fw-bold">
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Driver / Unloader</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Warehouse Gate Inspector</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top border-dark pt-1">Inventory Manager Sign-off</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
