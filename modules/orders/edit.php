<?php
/**
 * DMS - Distribution Management System
 * Order Booking - Edit Order
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('orders_view');

$id = (int)($_GET['id'] ?? 0);
$order = db_fetch_one("SELECT * FROM orders WHERE id = ?", [$id]);

if (!$order) {
    set_flash_message('danger', 'Order not found.');
    redirect(APP_URL . '/modules/orders/index.php');
}

if ($order['status'] === 'delivered') {
    set_flash_message('danger', 'Delivered orders cannot be modified.');
    redirect(APP_URL . '/modules/orders/view.php?id=' . $id);
}

// Fetch order items
$orderItems = db_fetch_all("
    SELECT 
        oi.*,
        p.product_name, p.product_code, p.carton_qty as ctn_ratio
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
", [$id]);

$customers = db_fetch_all("
    SELECT c.id, c.shop_name, c.area_id, c.booker_id, c.current_balance, a.area_name
    FROM customers c
    JOIN areas a ON a.id = c.area_id
    WHERE c.is_deleted = 0
    ORDER BY c.shop_name ASC
");

$products = db_fetch_all("
    SELECT p.id, p.product_name, p.product_code, p.wholesale_rate, p.carton_qty, p.base_unit_id
    FROM products p
    WHERE p.is_deleted = 0 AND p.is_active = 1
    ORDER BY p.product_name ASC
");

$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

// Handle POST Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderDate    = clean_input($_POST['order_date'] ?? $order['order_date']);
    $deliveryDate = clean_input($_POST['delivery_date'] ?? $order['delivery_date']);
    $customerId   = (int)($_POST['customer_id'] ?? $order['customer_id']);
    $bookerId     = (int)($_POST['booker_id'] ?? $order['booker_id']);
    $areaId       = (int)($_POST['area_id'] ?? $order['area_id']);
    $remarks      = clean_input($_POST['remarks'] ?? '');
    $orderStatus  = clean_input($_POST['order_status'] ?? $order['status']);

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemRates      = $_POST['item_rate'] ?? [];
    $itemDiscounts  = $_POST['item_discount_percent'] ?? [];

    $grossAmount = 0.0;
    $discountAmount = 0.0;
    $totalCartons = 0.0;
    $totalPieces = 0.0;
    $totalUnits = 0.0;
    $validItems = [];

    foreach ($itemProductIds as $idx => $pid) {
        $prodId = (int)$pid;
        $cartonQty = (float)($itemCartonQtys[$idx] ?? 0);
        $pcsQty = (float)($itemPcsQtys[$idx] ?? 0);
        $rate = (float)($itemRates[$idx] ?? 0);
        $discPercent = (float)($itemDiscounts[$idx] ?? 0);

        if ($prodId <= 0 || ($cartonQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $cartonRatio = (float)$prod['carton_qty'] > 0 ? (float)$prod['carton_qty'] : 1;
        $totalQuantityInBaseUnits = ($cartonQty * $cartonRatio) + $pcsQty;
        $baseCartons = $cartonQty + ($pcsQty / $cartonRatio);
        $lineGross = $baseCartons * $rate;
        $lineDiscount = ($lineGross * $discPercent) / 100.0;
        $lineTotal = $lineGross - $lineDiscount;

        $grossAmount += $lineGross;
        $discountAmount += $lineDiscount;
        $totalCartons += $cartonQty;
        $totalPieces += $pcsQty;
        $totalUnits += $totalQuantityInBaseUnits;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => $prod['base_unit_id'],
            'carton_qty'       => $cartonQty,
            'pack_qty'         => 0,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $totalQuantityInBaseUnits,
            'rate'             => $rate,
            'discount_percent' => $discPercent,
            'discount_amount'  => $lineDiscount,
            'line_total'       => $lineTotal
        ];
    }

    if (empty($validItems)) {
        set_flash_message('danger', 'Please include at least one valid product line in the order.');
    } else {
        $netAmount = round($grossAmount - $discountAmount, 2);

        try {
            db()->beginTransaction();

            // 1. Update order header
            $stmtUpd = db()->prepare("
                UPDATE orders 
                SET order_date = ?, delivery_date = ?, customer_id = ?, booker_id = ?, area_id = ?,
                    gross_amount = ?, discount_amount = ?, net_amount = ?, total_cartons = ?, 
                    total_units = ?, status = ?, remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $orderDate, $deliveryDate, $customerId, $bookerId, $areaId,
                $grossAmount, $discountAmount, $netAmount, $totalCartons,
                $totalUnits, $orderStatus, $remarks, $id
            ]);

            // 2. Delete and re-insert order items
            db()->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);

            $stmtItem = db()->prepare("
                INSERT INTO order_items (
                    order_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, 
                    quantity, rate, discount_percent, discount_amount, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, 0.00, ?, 
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $id, $vi['product_id'], $vi['unit_id'], $vi['carton_qty'],
                    $vi['pcs_qty'], $vi['quantity'], $vi['rate'], $vi['discount_percent'],
                    $vi['discount_amount'], $vi['line_total']
                ]);
            }

            db()->commit();

            log_audit('update_order', 'orders', $id, "Updated order {$order['order_number']}, Net: {$netAmount}");
            set_flash_message('success', "Order <strong>{$order['order_number']}</strong> updated successfully!");
            redirect(APP_URL . '/modules/orders/view.php?id=' . $id);

        } catch (Exception $e) {
            db()->rollBack();
            set_flash_message('danger', 'Error updating order: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Edit Order ' . $order['order_number'] . ' — ' . APP_NAME;
$activeMenu = 'orders';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Market Order: <?= e($order['order_number']) ?></h5>
                <small class="text-muted">Modify retailer, scheduled delivery date, items, or wholesale rates</small>
            </div>
            <div class="d-flex gap-2">
                <a href="view.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Order View (ESC)
                </a>
            </div>
        </div>

        <form method="POST" action="edit.php?id=<?= $order['id'] ?>" id="orderEditForm">
            <!-- Header Setup -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Customer / Retail Shop <span class="text-danger">*</span></label>
                            <select name="customer_id" class="form-select form-select-sm" required>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $order['customer_id'] ? 'selected' : '' ?>>
                                        <?= e($c['shop_name']) ?> (<?= e($c['area_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Order Booker <span class="text-danger">*</span></label>
                            <select name="booker_id" class="form-select form-select-sm" required>
                                <?php foreach ($bookers as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $order['booker_id'] ? 'selected' : '' ?>><?= e($b['booker_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Route / Area <span class="text-danger">*</span></label>
                            <select name="area_id" class="form-select form-select-sm" required>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id'] ?>" <?= $a['id'] == $order['area_id'] ? 'selected' : '' ?>><?= e($a['area_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Booking Date</label>
                            <input type="date" name="order_date" class="form-control form-control-sm" value="<?= e($order['order_date']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Delivery Date</label>
                            <input type="date" name="delivery_date" class="form-control form-control-sm" value="<?= e($order['delivery_date']) ?>" required>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-bold mb-1">Status</label>
                            <select name="order_status" class="form-select form-select-sm">
                                <option value="draft" <?= $order['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="confirmed" <?= $order['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                <?php if (in_array($order['status'], ['loaded', 'partially_loaded'])): ?>
                                    <option value="<?= $order['status'] ?>" selected><?= ucfirst($order['status']) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Grid -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-cart3 me-1"></i> Order Items & Quantities</h6>
                    <button type="button" class="btn btn-xs btn-outline-primary" onclick="addOrderRow()">
                        <i class="bi bi-plus-lg me-1"></i> Add Product Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0" id="orderItemsTable">
                            <thead class="bg-light small text-uppercase">
                                <tr>
                                    <th>Product Description</th>
                                    <th style="width: 120px;" class="text-center">Cartons</th>
                                    <th style="width: 120px;" class="text-center">Loose Pcs</th>
                                    <th style="width: 140px;" class="text-end">Rate (Rs.)</th>
                                    <th style="width: 110px;" class="text-center">Disc %</th>
                                    <th style="width: 160px;" class="text-end pe-3">Line Total (Rs.)</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="orderItemsTbody">
                                <?php foreach ($orderItems as $idx => $item): ?>
                                    <tr class="item-row" data-ratio="<?= (float)$item['ctn_ratio'] ?>">
                                        <td>
                                            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onProductChange(this)">
                                                <?php foreach ($products as $p): ?>
                                                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['wholesale_rate'] ?>" data-ratio="<?= $p['carton_qty'] ?>" <?= $p['id'] == $item['product_id'] ? 'selected' : '' ?>>
                                                        <?= e($p['product_name']) ?> (<?= e($p['product_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="<?= (float)$item['carton_qty'] ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="<?= (float)$item['pcs_qty'] ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="<?= (float)$item['rate'] ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="item_discount_percent[]" class="form-control form-control-sm text-center disc-inp" value="<?= (float)$item['discount_percent'] ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td class="text-end pe-3 fw-bold line-total text-navy">
                                            <?= number_format((float)$item['line_total'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-xs text-danger" onclick="removeOrderRow(this)"><i class="bi bi-x-circle-fill"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td class="text-end text-uppercase">Totals:</td>
                                    <td class="text-center" id="footCtn">0</td>
                                    <td class="text-center" id="footPcs">0</td>
                                    <td colspan="2" class="text-end">Order Net:</td>
                                    <td class="text-end pe-3 text-navy fs-6" id="footNet">Rs. 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white py-3 px-3 d-flex justify-content-between align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Order notes or delivery remarks..." value="<?= e($order['remarks']) ?>">
                    </div>
                    <div>
                        <a href="view.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold">
                            <i class="bi bi-check2-circle me-1"></i> Save Changes (F9)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<template id="orderRowTpl">
    <tr class="item-row" data-ratio="1">
        <td>
            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onProductChange(this)">
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['wholesale_rate'] ?>" data-ratio="<?= $p['carton_qty'] ?>">
                        <?= e($p['product_name']) ?> (<?= e($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="1" oninput="recalcOrderLine(this)">
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="0" oninput="recalcOrderLine(this)">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="0.00" oninput="recalcOrderLine(this)">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_discount_percent[]" class="form-control form-control-sm text-center disc-inp" value="0" oninput="recalcOrderLine(this)">
        </td>
        <td class="text-end pe-3 fw-bold line-total text-navy">
            0.00
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs text-danger" onclick="removeOrderRow(this)"><i class="bi bi-x-circle-fill"></i></button>
        </td>
    </tr>
</template>

<script>
function addOrderRow() {
    const tpl = document.getElementById('orderRowTpl').content.cloneNode(true);
    document.getElementById('orderItemsTbody').appendChild(tpl);
    recalcOrderTotals();
}

function removeOrderRow(btn) {
    const row = btn.closest('tr');
    if (document.querySelectorAll('.item-row').length > 1) {
        row.remove();
        recalcOrderTotals();
    } else {
        alert('Order must contain at least one item.');
    }
}

function onProductChange(sel) {
    const row = sel.closest('tr');
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        const rate = parseFloat(opt.getAttribute('data-rate')) || 0;
        const ratio = parseFloat(opt.getAttribute('data-ratio')) || 1;
        row.querySelector('.rate-inp').value = rate.toFixed(2);
        row.setAttribute('data-ratio', ratio);
        recalcOrderLine(sel);
    }
}

function recalcOrderLine(el) {
    const row = el.closest('tr');
    const ratio = parseFloat(row.getAttribute('data-ratio')) || 1;
    const ctn = parseFloat(row.querySelector('.ctn-inp').value) || 0;
    const pcs = parseFloat(row.querySelector('.pcs-inp').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-inp').value) || 0;
    const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;

    const baseCartons = ctn + (pcs / ratio);
    const gross = baseCartons * rate;
    const discAmt = (gross * disc) / 100;
    const net = gross - discAmt;

    row.querySelector('.line-total').innerText = net.toLocaleString('en-US', {minimumFractionDigits: 2});
    recalcOrderTotals();
}

function recalcOrderTotals() {
    let totCtn = 0;
    let totPcs = 0;
    let totNet = 0;

    document.querySelectorAll('.item-row').forEach(row => {
        const ratio = parseFloat(row.getAttribute('data-ratio')) || 1;
        const ctn = parseFloat(row.querySelector('.ctn-inp').value) || 0;
        const pcs = parseFloat(row.querySelector('.pcs-inp').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-inp').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;

        const baseCartons = ctn + (pcs / ratio);
        const gross = baseCartons * rate;
        const discAmt = (gross * disc) / 100;
        const net = gross - discAmt;

        totCtn += ctn;
        totPcs += pcs;
        totNet += net;
    });

    document.getElementById('footCtn').innerText = totCtn;
    document.getElementById('footPcs').innerText = totPcs;
    document.getElementById('footNet').innerText = 'Rs. ' + totNet.toLocaleString('en-US', {minimumFractionDigits: 2});
}

document.addEventListener('DOMContentLoaded', function() {
    recalcOrderTotals();
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
