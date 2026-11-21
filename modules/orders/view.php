<?php
/**
 * DMS - View Order Details & Print Order
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('orders_view');

$id = (int)($_GET['id'] ?? 0);
$order = db_fetch_one("
    SELECT 
        o.*,
        c.shop_name, c.owner_name, c.phone as customer_phone, c.address as customer_address,
        a.area_name, a.city,
        b.booker_name, b.phone as booker_phone,
        u.full_name as creator_name
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    JOIN areas a ON a.id = o.area_id
    JOIN bookers b ON b.id = o.booker_id
    LEFT JOIN users u ON u.id = o.created_by
    WHERE o.id = ?
", [$id]);

if (!$order) {
    set_flash_message('danger', 'Order not found.');
    redirect(APP_URL . '/modules/orders/index.php');
}

$items = db_fetch_all("
    SELECT 
        oi.*,
        p.product_name, p.product_code, p.carton_qty as product_ratio,
        u.symbol as unit_symbol
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN units u ON u.id = oi.unit_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
", [$id]);

$calcCartons = array_sum(array_column($items, 'carton_qty'));
$calcUnits   = array_sum(array_column($items, 'quantity'));
$totalCartons = (float)($order['total_cartons'] ?? 0) > 0 ? (float)$order['total_cartons'] : $calcCartons;
$totalUnits   = (float)($order['total_units'] ?? 0) > 0 ? (float)$order['total_units'] : $calcUnits;

$pageTitle = 'Order ' . $order['order_number'] . ' — ' . APP_NAME;
$activeMenu = 'orders';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark">
            <i class="bi bi-receipt me-2 text-primary"></i>Order #<span class="font-monospace"><?= e($order['order_number']) ?></span>
        </h5>
        <small class="text-muted">Booked by <?= e($order['booker_name']) ?> for <?= e($order['shop_name']) ?></small>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Order List
        </a>

        <?php if ($order['status'] === 'draft' && has_permission('orders_confirm')): ?>
            <a href="<?= APP_URL ?>/modules/orders/action.php?id=<?= $order['id'] ?>&action=confirm" class="btn btn-sm btn-success">
                <i class="bi bi-check-circle me-1"></i> Confirm Order
            </a>
        <?php endif; ?>

        <?php if ($order['status'] === 'confirmed' && has_permission('loading_create')): ?>
            <a href="<?= APP_URL ?>/modules/loading/create.php?order_id=<?= $order['id'] ?>" class="btn btn-sm btn-warning text-dark">
                <i class="bi bi-truck-flatbed me-1"></i> Create Load Sheet
            </a>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['draft', 'confirmed'])): ?>
            <a href="<?= APP_URL ?>/modules/orders/edit.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i> Edit Order
            </a>
        <?php endif; ?>

        <a href="<?= APP_URL ?>/modules/orders/print.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-printer me-1"></i> Print Order (F10)
        </a>

        <?php if (in_array($order['status'], ['draft', 'confirmed']) && has_permission('orders_cancel')): ?>
            <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to cancel this order?', function(){ window.location.href='<?= APP_URL ?>/modules/orders/action.php?id=<?= $order['id'] ?>&action=cancel'; })" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-x-circle me-1"></i> Cancel
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Printable Order Sheet Container -->
<div class="card-dms p-4">
    <!-- Distributor & Order Header -->
    <div class="row align-items-start border-bottom pb-3 mb-3">
        <div class="col-6">
            <h4 class="fw-bold mb-0 text-dark"><?= e(CLIENT_NAME) ?></h4>
            <div class="small text-muted"><?= e(CLIENT_LOCATION) ?></div>
            <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= e(get_setting('distributor_phone', '0300-0673276')) ?></div>
        </div>
        <div class="col-6 text-end">
            <h5 class="fw-bold text-primary font-monospace mb-1"><?= e($order['order_number']) ?></h5>
            <div class="small">Order Date: <strong><?= format_date($order['order_date']) ?></strong></div>
            <div class="small">Delivery Date: <strong><?= format_date($order['delivery_date']) ?></strong></div>
            <div class="mt-1">
                <span class="badge bg-<?= match($order['status']){ 'confirmed'=>'success', 'loaded'=>'warning', 'delivered'=>'info', 'cancelled'=>'danger', default=>'secondary' } ?>-subtle text-<?= match($order['status']){ 'confirmed'=>'success', 'loaded'=>'warning', 'delivered'=>'info', 'cancelled'=>'danger', default=>'secondary' } ?> border">
                    Status: <?= strtoupper(str_replace('_', ' ', $order['status'])) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Customer & Route Metadata -->
    <div class="row mb-3 bg-light p-3 rounded">
        <div class="col-md-6 border-end">
            <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Retail Customer / Shop</span>
            <h6 class="fw-bold mb-1 text-dark"><?= e($order['shop_name']) ?></h6>
            <div class="small text-secondary"><i class="bi bi-geo-alt me-1"></i><?= e($order['customer_address']) ?></div>
            <div class="small text-secondary"><i class="bi bi-telephone me-1"></i><?= e($order['customer_phone']) ?></div>
        </div>
        <div class="col-md-6 ps-md-4 mt-2 mt-md-0">
            <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Route & Booking Staff</span>
            <div class="small mb-1">Market Route: <strong><?= e($order['area_name']) ?> (<?= e($order['city']) ?>)</strong></div>
            <div class="small mb-1">Order Booker: <strong><?= e($order['booker_name']) ?></strong> (<?= e($order['booker_phone']) ?>)</div>
            <div class="small text-muted">Created By: <?= e($order['creator_name'] ?? 'System') ?> at <?= format_datetime($order['created_at']) ?></div>
        </div>
    </div>

    <!-- Order Items Table -->
    <div class="table-responsive mb-3">
        <table class="table table-bordered table-sm mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th style="width: 40px;" class="text-center">Sr.</th>
                    <th>Product Description</th>
                    <th class="text-center" style="width: 120px;">Carton Qty</th>
                    <th class="text-center" style="width: 120px;">Pcs Qty</th>
                    <th class="text-end" style="width: 140px;">Wholesale Rate</th>
                    <th class="text-end" style="width: 100px;">Disc %</th>
                    <th class="text-end" style="width: 150px;">Line Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sr = 1;
                foreach ($items as $item): 
                ?>
                    <tr>
                        <td class="text-center"><?= $sr++ ?></td>
                        <td>
                            <span class="fw-semibold text-dark"><?= e($item['product_name']) ?></span>
                            <small class="text-muted font-monospace d-block"><?= e($item['product_code']) ?></small>
                        </td>
                        <td class="text-center fw-semibold"><?= format_qty($item['carton_qty']) ?> Ctn</td>
                        <td class="text-center"><?= (float)$item['pcs_qty'] > 0 ? format_qty($item['pcs_qty']) . ' Pcs' : '-' ?></td>
                        <td class="text-end"><?= format_currency($item['rate']) ?></td>
                        <td class="text-end"><?= (float)$item['discount_percent'] > 0 ? number_format((float)$item['discount_percent'], 1) . '%' : '-' ?></td>
                        <td class="text-end fw-semibold"><?= format_currency($item['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-fmcg-total">
                <tr>
                    <th colspan="2" class="text-end">Order Totals:</th>
                    <th class="text-center"><?= format_qty($totalCartons) ?> Ctns</th>
                    <th class="text-center"><?= format_qty($totalUnits) ?> Units</th>
                    <th class="text-end" colspan="2">Gross: <?= format_currency($order['gross_amount']) ?></th>
                    <th class="text-end fs-6"><?= format_currency($order['net_amount']) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if (!empty($order['remarks'])): ?>
        <div class="p-2 border rounded bg-light mb-3 small">
            <strong>Remarks:</strong> <?= e($order['remarks']) ?>
        </div>
    <?php endif; ?>

    <!-- Signature Boxes -->
    <div class="row pt-5 mt-4 text-center">
        <div class="col-4">
            <div class="border-top pt-2 small text-muted">Order Booker Signature</div>
        </div>
        <div class="col-4">
            <div class="border-top pt-2 small text-muted">Warehouse Supervisor</div>
        </div>
        <div class="col-4">
            <div class="border-top pt-2 small text-muted">Authorized Signatory</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'F10') {
        e.preventDefault();
        window.location.href = '<?= APP_URL ?>/modules/orders/print.php?id=<?= $order['id'] ?>';
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
