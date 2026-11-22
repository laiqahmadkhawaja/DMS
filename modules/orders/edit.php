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
        p.product_name, p.product_code, p.carton_qty as ctn_ratio, p.pack_qty as pack_ratio,
        p.carton_rate, p.piece_rate, u.symbol as unit_symbol
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN units u ON u.id = oi.unit_id
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
    SELECT p.id, p.product_name, p.product_code, p.wholesale_rate, p.carton_rate, p.piece_rate, p.carton_qty, p.pack_qty, p.base_unit_id
    FROM products p
    WHERE p.is_deleted = 0 AND p.is_active = 1
    ORDER BY p.product_name ASC
");

$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

// Handle POST Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderDate    = clean_input($_POST['order_date'] ?? $order['order_date']);
    $deliveryDate = !empty($_POST['delivery_date']) ? clean_input($_POST['delivery_date']) : null;
    $customerId   = (int)($_POST['customer_id'] ?? $order['customer_id']);
    $bookerId     = (int)($_POST['booker_id'] ?? $order['booker_id']);
    $areaId       = (int)($_POST['area_id'] ?? $order['area_id']);
    $remarks      = clean_input($_POST['remarks'] ?? '');
    $orderStatus  = clean_input($_POST['order_status'] ?? $order['status']);

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPackQtys   = $_POST['item_pack_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemUnits      = $_POST['item_unit'] ?? [];
    $itemRates      = $_POST['item_rate'] ?? [];
    $itemDiscounts  = $_POST['item_discount_percent'] ?? [];

    $discountMode = clean_input($_POST['discount_mode'] ?? ($order['discount_mode'] ?? 'item_rate'));
    if ($discountMode !== 'subtotal') {
        $discountMode = 'item_rate';
    }
    $subtotalDiscountPercent = (float)($_POST['subtotal_discount_percent'] ?? ($order['subtotal_discount_percent'] ?? 0));
    $subtotalDiscountAmount  = (float)($_POST['subtotal_discount_amount'] ?? ($order['subtotal_discount_amount'] ?? 0));

    $grossAmount = 0.0;
    $discountAmount = 0.0;
    $totalCartons = 0.0;
    $totalPieces = 0.0;
    $totalUnits = 0.0;
    $validItems = [];

    foreach ($itemProductIds as $idx => $pid) {
        $prodId = (int)$pid;
        $cartonQty = (float)($itemCartonQtys[$idx] ?? 0);
        $packQty   = (float)($itemPackQtys[$idx] ?? 0);
        $pcsQty    = (float)($itemPcsQtys[$idx] ?? 0);
        $unitCode  = strtolower(trim((string)($itemUnits[$idx] ?? 'ctn')));
        $rate      = (float)($itemRates[$idx] ?? 0);
        $discPercent = (float)($itemDiscounts[$idx] ?? 0);

        if ($prodId <= 0 || ($cartonQty <= 0 && $packQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $totalQuantityInBaseUnits = calculate_base_pieces($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
        $baseCartons = calculate_carton_equivalent($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);

        if ($unitCode === 'pcs') {
            $lineGross = $totalQuantityInBaseUnits * $rate;
            $unitId = 4; // Piece unit
        } else {
            $lineGross = $baseCartons * $rate;
            $unitId = (int)$prod['base_unit_id']; // Carton unit
        }

        if ($discountMode === 'subtotal') {
            $itemDiscPct = 0.0;
            $lineDiscount = 0.0;
            $lineTotal = $lineGross;
        } else {
            $itemDiscPct = $discPercent;
            $lineDiscount = ($lineGross * $itemDiscPct) / 100.0;
            $lineTotal = $lineGross - $lineDiscount;
            $discountAmount += $lineDiscount;
        }

        $grossAmount += $lineGross;
        $totalCartons += $cartonQty;
        $totalPieces += $pcsQty;
        $totalUnits += $totalQuantityInBaseUnits;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => $unitId,
            'carton_qty'       => $cartonQty,
            'pack_qty'         => $packQty,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $totalQuantityInBaseUnits,
            'rate'             => $rate,
            'discount_percent' => $itemDiscPct,
            'discount_amount'  => $lineDiscount,
            'line_total'       => $lineTotal
        ];
    }

    if (empty($validItems)) {
        set_flash_message('danger', 'Please include at least one valid product line in the order.');
    } else {
        if ($discountMode === 'subtotal') {
            if ($subtotalDiscountAmount > 0) {
                $discountAmount = $subtotalDiscountAmount;
                if ($grossAmount > 0 && $subtotalDiscountPercent <= 0) {
                    $subtotalDiscountPercent = round(($discountAmount / $grossAmount) * 100, 2);
                }
            } elseif ($subtotalDiscountPercent > 0) {
                $discountAmount = round(($grossAmount * $subtotalDiscountPercent) / 100.0, 2);
                $subtotalDiscountAmount = $discountAmount;
            } else {
                $discountAmount = 0.0;
                $subtotalDiscountAmount = 0.0;
                $subtotalDiscountPercent = 0.0;
            }
        } else {
            $subtotalDiscountPercent = 0.0;
            $subtotalDiscountAmount = 0.0;
        }

        $netAmount = max(0, round($grossAmount - $discountAmount, 2));

        try {
            db()->beginTransaction();

            // 1. Update order header
            $stmtUpd = db()->prepare("
                UPDATE orders 
                SET order_date = ?, delivery_date = ?, customer_id = ?, booker_id = ?, area_id = ?,
                    discount_mode = ?, subtotal_discount_percent = ?, subtotal_discount_amount = ?,
                    gross_amount = ?, discount_amount = ?, net_amount = ?, total_cartons = ?, 
                    total_units = ?, status = ?, remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $orderDate, $deliveryDate, $customerId, $bookerId, $areaId,
                $discountMode, $subtotalDiscountPercent, $subtotalDiscountAmount,
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
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $id, $vi['product_id'], $vi['unit_id'], $vi['carton_qty'],
                    $vi['pack_qty'], $vi['pcs_qty'], $vi['quantity'], $vi['rate'], $vi['discount_percent'],
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
                            <label class="form-label small fw-bold mb-1">Delivery Date (Optional)</label>
                            <input type="date" name="delivery_date" class="form-control form-control-sm" value="<?= e($order['delivery_date'] ?? '') ?>">
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

            <!-- Discount Mode Radio Selection Bar -->
            <div class="pos-discount-mode-bar bg-light p-2 border mb-3 rounded d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <span class="small fw-bold text-secondary"><i class="bi bi-tag-fill me-1 text-primary"></i> Discount Type:</span>
                    <div class="form-check form-check-inline m-0">
                        <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeItem" value="item_rate" <?= ($order['discount_mode'] ?? 'item_rate') === 'item_rate' ? 'checked' : '' ?> onchange="onDiscountModeChange()">
                        <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeItem">Discount from per item rate</label>
                    </div>
                    <div class="form-check form-check-inline m-0">
                        <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeSubtotal" value="subtotal" <?= ($order['discount_mode'] ?? 'item_rate') === 'subtotal' ? 'checked' : '' ?> onchange="onDiscountModeChange()">
                        <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeSubtotal">Discount from subtotal</label>
                    </div>
                </div>
                <div id="subtotalDiscountInputs" class="<?= ($order['discount_mode'] ?? 'item_rate') === 'subtotal' ? 'd-flex' : 'd-none' ?> align-items-center gap-2">
                    <span class="small fw-bold text-navy">Subtotal Disc:</span>
                    <div class="input-group input-group-sm" style="width: 110px;">
                        <input type="number" step="0.01" min="0" max="100" name="subtotal_discount_percent" id="subtotalDiscountPercent" class="form-control form-control-sm text-end" placeholder="0" value="<?= (float)($order['subtotal_discount_percent'] ?? 0) > 0 ? (float)$order['subtotal_discount_percent'] : '' ?>" oninput="onSubtotalPercentChange()">
                        <span class="input-group-text small">%</span>
                    </div>
                    <div class="input-group input-group-sm" style="width: 140px;">
                        <span class="input-group-text small">Rs</span>
                        <input type="number" step="0.01" min="0" name="subtotal_discount_amount" id="subtotalDiscountAmount" class="form-control form-control-sm text-end" placeholder="0.00" value="<?= (float)($order['subtotal_discount_amount'] ?? 0) > 0 ? (float)$order['subtotal_discount_amount'] : '' ?>" oninput="onSubtotalAmountChange()">
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
                                    <th style="width: 85px;" class="text-center">Unit</th>
                                    <th style="width: 95px;" class="text-center">Cartons</th>
                                    <th style="width: 85px;" class="text-center">Packs</th>
                                    <th style="width: 95px;" class="text-center">Loose Pcs</th>
                                    <th style="width: 140px;" class="text-end">Rate (Rs.)</th>
                                    <th style="width: 90px;" class="text-center">Disc %</th>
                                    <th style="width: 140px;" class="text-end pe-3">Line Total (Rs.)</th>
                                    <th style="width: 45px;"></th>
                                </tr>
                            </thead>
                            <tbody id="orderItemsTbody">
                                <?php foreach ($orderItems as $idx => $item): 
                                    $packsPerCtn = (float)$item['ctn_ratio'] > 0 ? (float)$item['ctn_ratio'] : 1;
                                    $pcsPerPack  = (float)$item['pack_ratio'] > 0 ? (float)$item['pack_ratio'] : 1;
                                    $isPieceUnit = ((int)($item['unit_id'] ?? 0) === 4);
                                ?>
                                    <tr class="item-row" data-ratio="<?= $packsPerCtn ?>" data-pack-ratio="<?= $pcsPerPack ?>">
                                        <td>
                                            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onProductChange(this)">
                                                <?php foreach ($products as $p): 
                                                    $pPacksPerCtn = (float)$p['carton_qty'] > 0 ? (float)$p['carton_qty'] : 1;
                                                    $pPcsPerPack  = (float)$p['pack_qty'] > 0 ? (float)$p['pack_qty'] : 1;
                                                ?>
                                                    <option value="<?= $p['id'] ?>" 
                                                            data-rate="<?= (float)$p['carton_rate'] ?>" 
                                                            data-carton-rate="<?= (float)$p['carton_rate'] ?>" 
                                                            data-piece-rate="<?= (float)$p['piece_rate'] ?>" 
                                                            data-ratio="<?= $pPacksPerCtn ?>" 
                                                            data-pack-ratio="<?= $pPcsPerPack ?>" 
                                                            <?= $p['id'] == $item['product_id'] ? 'selected' : '' ?>>
                                                        <?= e($p['product_name']) ?> (<?= e($p['product_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <select name="item_unit[]" class="form-select form-select-sm unit-inp text-center py-0 px-1" onchange="onOrderUnitChange(this)">
                                                <option value="ctn" <?= !$isPieceUnit ? 'selected' : '' ?>>Ctn</option>
                                                <option value="pcs" <?= $isPieceUnit ? 'selected' : '' ?>>Pcs</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="<?= (float)$item['carton_qty'] ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_pack_qty[]" class="form-control form-control-sm text-center pack-inp" value="<?= (float)($item['pack_qty'] ?? 0) ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="<?= (float)$item['pcs_qty'] ?>" oninput="recalcOrderLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="<?= (float)$item['rate'] ?>" oninput="recalcOrderLine(this)">
                                            <span class="small text-muted rate-unit-lbl" style="font-size: 10px;">/ <?= $isPieceUnit ? 'Piece' : 'Carton' ?></span>
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
                                    <td class="text-end text-uppercase" colspan="2">Totals:</td>
                                    <td class="text-center" id="footCtn">0</td>
                                    <td class="text-center" id="footPack">0</td>
                                    <td class="text-center" id="footPcs">0</td>
                                    <td class="text-end" id="footGross">Rs. 0.00</td>
                                    <td class="text-center text-danger" id="footDisc">Rs. 0.00</td>
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
    <tr class="item-row" data-ratio="1" data-pack-ratio="1">
        <td>
            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onProductChange(this)">
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): 
                    $pPacksPerCtn = (float)$p['carton_qty'] > 0 ? (float)$p['carton_qty'] : 1;
                    $pPcsPerPack  = (float)$p['pack_qty'] > 0 ? (float)$p['pack_qty'] : 1;
                ?>
                    <option value="<?= $p['id'] ?>" 
                            data-rate="<?= (float)$p['carton_rate'] ?>" 
                            data-carton-rate="<?= (float)$p['carton_rate'] ?>" 
                            data-piece-rate="<?= (float)$p['piece_rate'] ?>" 
                            data-ratio="<?= $pPacksPerCtn ?>" 
                            data-pack-ratio="<?= $pPcsPerPack ?>">
                        <?= e($p['product_name']) ?> (<?= e($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="text-center">
            <select name="item_unit[]" class="form-select form-select-sm unit-inp text-center py-0 px-1" onchange="onOrderUnitChange(this)">
                <option value="ctn" selected>Ctn</option>
                <option value="pcs">Pcs</option>
            </select>
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="1" oninput="recalcOrderLine(this)">
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_pack_qty[]" class="form-control form-control-sm text-center pack-inp" value="0" oninput="recalcOrderLine(this)">
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="0" oninput="recalcOrderLine(this)">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="0.00" oninput="recalcOrderLine(this)">
            <span class="small text-muted rate-unit-lbl" style="font-size: 10px;">/ Carton</span>
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
function getDiscountMode() {
    const radio = document.querySelector('input[name="discount_mode"]:checked');
    return radio ? radio.value : 'item_rate';
}

function onDiscountModeChange() {
    const mode = getDiscountMode();
    const subtotalBox = document.getElementById('subtotalDiscountInputs');
    const discInps = document.querySelectorAll('.disc-inp');

    if (mode === 'subtotal') {
        if (subtotalBox) {
            subtotalBox.classList.remove('d-none');
            subtotalBox.classList.add('d-flex');
        }
        discInps.forEach(inp => {
            inp.disabled = true;
            inp.classList.add('bg-light', 'text-muted');
            inp.title = 'Disabled in subtotal discount mode';
        });
        const pctEl = document.getElementById('subtotalDiscountPercent');
        const amtEl = document.getElementById('subtotalDiscountAmount');
        if (parseFloat(pctEl?.value) > 0) {
            onSubtotalPercentChange();
        } else if (parseFloat(amtEl?.value) > 0) {
            onSubtotalAmountChange();
        }
    } else {
        if (subtotalBox) {
            subtotalBox.classList.remove('d-flex');
            subtotalBox.classList.add('d-none');
        }
        discInps.forEach(inp => {
            inp.disabled = false;
            inp.classList.remove('bg-light', 'text-muted');
            inp.title = '';
        });
    }

    recalcAllOrderLines();
    recalcOrderTotals();
}

function calculateOrderRowGross(row) {
    const packsPerCtn = parseFloat(row.getAttribute('data-ratio')) || 1;
    const pcsPerPack  = parseFloat(row.getAttribute('data-pack-ratio')) || 1;
    const pcsPerCtn   = packsPerCtn * pcsPerPack;

    const ctn  = parseFloat(row.querySelector('.ctn-inp')?.value) || 0;
    const pack = parseFloat(row.querySelector('.pack-inp')?.value) || 0;
    const pcs  = parseFloat(row.querySelector('.pcs-inp')?.value) || 0;
    const rate = parseFloat(row.querySelector('.rate-inp')?.value) || 0;
    const unit = row.querySelector('.unit-inp')?.value || 'ctn';

    if (unit === 'pcs') {
        const totalPieces = (ctn * pcsPerCtn) + (pack * pcsPerPack) + pcs;
        return totalPieces * rate;
    } else {
        const baseCartons = ctn + (pack / packsPerCtn) + (pcs / pcsPerCtn);
        return baseCartons * rate;
    }
}

function onSubtotalPercentChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const pctVal = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
    let grossTotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        grossTotal += calculateOrderRowGross(row);
    });

    const amt = Math.round(((grossTotal * pctVal) / 100.0) * 100) / 100;
    const amtEl = document.getElementById('subtotalDiscountAmount');
    if (amtEl && document.activeElement !== amtEl) {
        amtEl.value = amt > 0 ? amt.toFixed(2) : '';
    }

    recalcOrderTotals();
}

function onSubtotalAmountChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const amtVal = parseFloat(document.getElementById('subtotalDiscountAmount')?.value) || 0;
    let grossTotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        grossTotal += calculateOrderRowGross(row);
    });

    const pct = grossTotal > 0 ? Math.round(((amtVal / grossTotal) * 100.0) * 100) / 100 : 0;
    const pctEl = document.getElementById('subtotalDiscountPercent');
    if (pctEl && document.activeElement !== pctEl) {
        pctEl.value = pct > 0 ? pct.toFixed(2) : '';
    }

    recalcOrderTotals();
}

function recalcAllOrderLines() {
    const mode = getDiscountMode();
    document.querySelectorAll('.item-row').forEach(row => {
        const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;
        const gross = calculateOrderRowGross(row);
        const discAmt = (mode === 'subtotal') ? 0 : ((gross * disc) / 100);
        const net = gross - discAmt;

        row.querySelector('.line-total').innerText = net.toLocaleString('en-US', {minimumFractionDigits: 2});
    });
}

function addOrderRow() {
    const tpl = document.getElementById('orderRowTpl').content.cloneNode(true);
    document.getElementById('orderItemsTbody').appendChild(tpl);
    if (getDiscountMode() === 'subtotal') {
        const lastRow = document.querySelector('#orderItemsTbody tr:last-child');
        const discInp = lastRow?.querySelector('.disc-inp');
        if (discInp) {
            discInp.disabled = true;
            discInp.classList.add('bg-light', 'text-muted');
            discInp.title = 'Disabled in subtotal discount mode';
        }
    }
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

function onOrderUnitChange(sel) {
    const row = sel.closest('tr');
    const unit = sel.value;
    const prodSel = row.querySelector('.prod-select');
    const opt = prodSel ? prodSel.selectedOptions[0] : null;

    const packsPerCtn = parseFloat(row.getAttribute('data-ratio')) || 1;
    const pcsPerPack  = parseFloat(row.getAttribute('data-pack-ratio')) || 1;
    const pcsPerCtn   = packsPerCtn * pcsPerPack;

    const cartonRate = opt ? (parseFloat(opt.getAttribute('data-carton-rate')) || parseFloat(opt.getAttribute('data-rate')) || 0) : 0;
    const pieceRate  = opt ? (parseFloat(opt.getAttribute('data-piece-rate')) || (pcsPerCtn > 0 ? cartonRate / pcsPerCtn : cartonRate)) : 0;

    const ctnInp  = row.querySelector('.ctn-inp');
    const pcsInp  = row.querySelector('.pcs-inp');
    const rateInp = row.querySelector('.rate-inp');
    const lblUnit = row.querySelector('.rate-unit-lbl');

    if (unit === 'pcs') {
        if (rateInp) rateInp.value = pieceRate.toFixed(2);
        if (lblUnit) lblUnit.innerText = '/ Piece';
        const ctnVal = parseFloat(ctnInp?.value) || 0;
        const pcsVal = parseFloat(pcsInp?.value) || 0;
        if (ctnVal > 0 && pcsVal === 0) {
            if (pcsInp) pcsInp.value = ctnVal * pcsPerCtn;
            if (ctnInp) ctnInp.value = 0;
        }
    } else {
        if (rateInp) rateInp.value = cartonRate.toFixed(2);
        if (lblUnit) lblUnit.innerText = '/ Carton';
        const ctnVal = parseFloat(ctnInp?.value) || 0;
        const pcsVal = parseFloat(pcsInp?.value) || 0;
        if (ctnVal === 0 && pcsVal > 0 && pcsPerCtn > 0) {
            if (ctnInp) ctnInp.value = Math.floor(pcsVal / pcsPerCtn);
            if (pcsInp) pcsInp.value = pcsVal % pcsPerCtn;
        }
    }

    recalcOrderLine(sel);
}

function onProductChange(sel) {
    const row = sel.closest('tr');
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        const ratio = parseFloat(opt.getAttribute('data-ratio')) || 1;
        const packRatio = parseFloat(opt.getAttribute('data-pack-ratio')) || 1;
        row.setAttribute('data-ratio', ratio);
        row.setAttribute('data-pack-ratio', packRatio);

        const pcsPerCtn = ratio * packRatio;
        const cartonRate = parseFloat(opt.getAttribute('data-carton-rate')) || parseFloat(opt.getAttribute('data-rate')) || 0;
        const pieceRate  = parseFloat(opt.getAttribute('data-piece-rate')) || (pcsPerCtn > 0 ? cartonRate / pcsPerCtn : cartonRate);

        const unit = row.querySelector('.unit-inp')?.value || 'ctn';
        const rateInp = row.querySelector('.rate-inp');
        const lblUnit = row.querySelector('.rate-unit-lbl');

        if (unit === 'pcs') {
            if (rateInp) rateInp.value = pieceRate.toFixed(2);
            if (lblUnit) lblUnit.innerText = '/ Piece';
        } else {
            if (rateInp) rateInp.value = cartonRate.toFixed(2);
            if (lblUnit) lblUnit.innerText = '/ Carton';
        }

        recalcOrderLine(sel);
    }
}

function recalcOrderLine(el) {
    const row = el.closest('tr');
    const packsPerCtn = parseFloat(row.getAttribute('data-ratio')) || 1;
    const pcsPerPack  = parseFloat(row.getAttribute('data-pack-ratio')) || 1;
    const pcsPerCtn   = packsPerCtn * pcsPerPack;

    const ctnInp  = row.querySelector('.ctn-inp');
    const pcsInp  = row.querySelector('.pcs-inp');
    const unitInp = row.querySelector('.unit-inp');
    const rateInp = row.querySelector('.rate-inp');
    const lblUnit = row.querySelector('.rate-unit-lbl');

    const ctnVal  = parseFloat(ctnInp?.value) || 0;
    const pcsVal  = parseFloat(pcsInp?.value) || 0;
    const rateVal = parseFloat(rateInp?.value) || 0;
    const discVal = parseFloat(row.querySelector('.disc-inp')?.value) || 0;
    let unitVal   = unitInp ? unitInp.value : 'ctn';

    // Auto-switch unit if user specifically entered 0 Cartons and >0 Pieces
    if (unitVal === 'ctn' && ctnVal === 0 && pcsVal > 0) {
        const prodSel = row.querySelector('.prod-select');
        const opt = prodSel ? prodSel.selectedOptions[0] : null;
        const pieceRate = opt ? (parseFloat(opt.getAttribute('data-piece-rate')) || (pcsPerCtn > 0 ? rateVal / pcsPerCtn : rateVal)) : (pcsPerCtn > 0 ? rateVal / pcsPerCtn : rateVal);
        unitVal = 'pcs';
        if (unitInp) unitInp.value = 'pcs';
        if (rateInp) rateInp.value = pieceRate.toFixed(2);
        if (lblUnit) lblUnit.innerText = '/ Piece';
    } else if (unitVal === 'pcs' && ctnVal > 0 && pcsVal === 0) {
        const prodSel = row.querySelector('.prod-select');
        const opt = prodSel ? prodSel.selectedOptions[0] : null;
        const cartonRate = opt ? (parseFloat(opt.getAttribute('data-carton-rate')) || rateVal * pcsPerCtn) : rateVal * pcsPerCtn;
        unitVal = 'ctn';
        if (unitInp) unitInp.value = 'ctn';
        if (rateInp) rateInp.value = cartonRate.toFixed(2);
        if (lblUnit) lblUnit.innerText = '/ Carton';
    }

    const mode = getDiscountMode();
    const gross = calculateOrderRowGross(row);
    const discAmt = (mode === 'subtotal') ? 0 : ((gross * discVal) / 100);
    const net = gross - discAmt;

    row.querySelector('.line-total').innerText = net.toLocaleString('en-US', {minimumFractionDigits: 2});
    
    if (mode === 'subtotal') {
        const pctEl = document.getElementById('subtotalDiscountPercent');
        if (pctEl && parseFloat(pctEl.value) > 0) {
            onSubtotalPercentChange();
            return;
        }
    }

    recalcOrderTotals();
}

function recalcOrderTotals() {
    const mode = getDiscountMode();
    let totCtn = 0;
    let totPack = 0;
    let totPcs = 0;
    let totGross = 0;
    let totItemDisc = 0;

    document.querySelectorAll('.item-row').forEach(row => {
        const ctn  = parseFloat(row.querySelector('.ctn-inp').value) || 0;
        const pack = parseFloat(row.querySelector('.pack-inp') ? row.querySelector('.pack-inp').value : 0) || 0;
        const pcs  = parseFloat(row.querySelector('.pcs-inp').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;

        const gross = calculateOrderRowGross(row);
        const discAmt = (mode === 'subtotal') ? 0 : ((gross * disc) / 100);

        totCtn += ctn;
        totPack += pack;
        totPcs += pcs;
        totGross += gross;
        totItemDisc += discAmt;
    });

    let finalDisc = 0;
    if (mode === 'subtotal') {
        const subtotalAmt = parseFloat(document.getElementById('subtotalDiscountAmount')?.value) || 0;
        const subtotalPct = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
        if (subtotalAmt > 0) {
            finalDisc = subtotalAmt;
        } else if (subtotalPct > 0) {
            finalDisc = (totGross * subtotalPct) / 100.0;
        }
    } else {
        finalDisc = totItemDisc;
    }

    const finalNet = Math.max(0, totGross - finalDisc);

    document.getElementById('footCtn').innerText = totCtn;
    if (document.getElementById('footPack')) document.getElementById('footPack').innerText = totPack;
    document.getElementById('footPcs').innerText = totPcs;
    document.getElementById('footGross').innerText = 'Rs. ' + totGross.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('footDisc').innerText = '-Rs. ' + finalDisc.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('footNet').innerText = 'Rs. ' + finalNet.toLocaleString('en-US', {minimumFractionDigits: 2});
}

document.addEventListener('DOMContentLoaded', function() {
    if (getDiscountMode() === 'subtotal') {
        document.querySelectorAll('.disc-inp').forEach(inp => {
            inp.disabled = true;
            inp.classList.add('bg-light', 'text-muted');
            inp.title = 'Disabled in subtotal discount mode';
        });
    }
    recalcOrderTotals();
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
