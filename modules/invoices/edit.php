<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - Edit Bill / Invoice
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('invoices_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid invoice ID.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$invoice = db_fetch_one("
    SELECT inv.*, c.shop_name, c.current_balance as cust_balance, a.area_name, b.booker_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    WHERE inv.id = ?
", [$id]);

if (!$invoice) {
    set_flash('danger', 'Invoice not found.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$items = db_fetch_all("
    SELECT sii.*, p.product_name, p.product_code, p.carton_qty as ctn_ratio, p.pack_qty as pack_ratio,
           p.carton_rate, p.piece_rate, u.symbol as unit_symbol
    FROM sales_invoice_items sii
    JOIN products p ON sii.product_id = p.id
    LEFT JOIN units u ON u.id = sii.unit_id
    WHERE sii.invoice_id = ?
    ORDER BY sii.id ASC
", [$id]);

$products = db_fetch_all("
    SELECT id, product_name, product_code, wholesale_rate, carton_rate, piece_rate, carton_qty, pack_qty, base_unit_id 
    FROM products 
    WHERE is_deleted = 0 AND is_active = 1 
    ORDER BY product_name ASC
");

$errors = [];

// Handle POST Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoiceDate = sanitize_input($_POST['invoice_date'] ?? $invoice['invoice_date']);
    $remarks     = sanitize_input($_POST['remarks'] ?? '');
    
    $itemIds     = $_POST['item_product_id'] ?? [];
    $ctnQtys     = $_POST['item_carton_qty'] ?? [];
    $packQtys    = $_POST['item_pack_qty'] ?? [];
    $pcsQtys     = $_POST['item_pcs_qty'] ?? [];
    $itemUnits   = $_POST['item_unit'] ?? [];
    $rates       = $_POST['item_rate'] ?? [];
    $discounts   = $_POST['item_discount_percent'] ?? [];

    $discountMode = sanitize_input($_POST['discount_mode'] ?? ($invoice['discount_mode'] ?? 'item_rate'));
    if ($discountMode !== 'subtotal') {
        $discountMode = 'item_rate';
    }
    $subtotalDiscountPercent = (float)($_POST['subtotal_discount_percent'] ?? ($invoice['subtotal_discount_percent'] ?? 0));
    $subtotalDiscountAmount  = (float)($_POST['subtotal_discount_amount'] ?? ($invoice['extra_discount_amount'] ?? 0));

    $grossTotal = 0.0;
    $discTotal  = 0.0;
    $validItems = [];

    foreach ($itemIds as $idx => $pid) {
        $prodId = (int)$pid;
        $ctn  = (float)($ctnQtys[$idx] ?? 0);
        $pack = (float)($packQtys[$idx] ?? 0);
        $pcs  = (float)($pcsQtys[$idx] ?? 0);
        $unitCode = strtolower(trim((string)($itemUnits[$idx] ?? 'ctn')));
        $rate = (float)($rates[$idx] ?? 0);
        $disc = (float)($discounts[$idx] ?? 0);

        if ($prodId <= 0 || ($ctn <= 0 && $pack <= 0 && $pcs <= 0)) continue;

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $baseQty = calculate_base_pieces($ctn, $pack, $pcs, $packsPerCtn, $pcsPerPack);
        $baseCartons = calculate_carton_equivalent($ctn, $pack, $pcs, $packsPerCtn, $pcsPerPack);

        if ($unitCode === 'pcs') {
            $lineGross = $baseQty * $rate;
            $unitId = 4; // Piece unit
        } else {
            $lineGross = $baseCartons * $rate;
            $unitId = (int)$prod['base_unit_id']; // Carton unit
        }

        if ($discountMode === 'subtotal') {
            $itemDiscPct = 0.0;
            $lineDisc = 0.0;
            $lineNet = $lineGross;
        } else {
            $itemDiscPct = $disc;
            $lineDisc = ($lineGross * $itemDiscPct) / 100.0;
            $lineNet = $lineGross - $lineDisc;
            $discTotal += $lineDisc;
        }

        $grossTotal += $lineGross;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => $unitId,
            'carton_qty'       => $ctn,
            'pack_qty'         => $pack,
            'pcs_qty'          => $pcs,
            'quantity'         => $baseQty,
            'rate'             => $rate,
            'discount_percent' => $itemDiscPct,
            'discount_amount'  => $lineDisc,
            'line_total'       => $lineNet
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line.";
    }

    if (empty($errors)) {
        if ($discountMode === 'subtotal') {
            if ($subtotalDiscountAmount > 0) {
                $extraDiscount = $subtotalDiscountAmount;
                if ($grossTotal > 0 && $subtotalDiscountPercent <= 0) {
                    $subtotalDiscountPercent = round(($extraDiscount / $grossTotal) * 100, 2);
                }
            } elseif ($subtotalDiscountPercent > 0) {
                $extraDiscount = round(($grossTotal * $subtotalDiscountPercent) / 100.0, 2);
                $subtotalDiscountAmount = $extraDiscount;
            } else {
                $extraDiscount = 0.0;
                $subtotalDiscountAmount = 0.0;
                $subtotalDiscountPercent = 0.0;
            }
            $itemDiscount = 0.0;
        } else {
            $itemDiscount = $discTotal;
            $extraDiscount = 0.0;
            $subtotalDiscountPercent = 0.0;
            $subtotalDiscountAmount = 0.0;
        }

        $netTotal = max(0, round($grossTotal - ($itemDiscount + $extraDiscount), 2));

        try {
            db()->beginTransaction();

            $oldNet = (float)$invoice['net_amount'];
            $oldCredit = (float)$invoice['credit_amount'];
            $netDiff = $netTotal - $oldNet;

            // Update invoice items
            db()->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?")->execute([$id]);

            $stmtItem = db()->prepare("
                INSERT INTO sales_invoice_items (
                    invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
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

            // Adjust Customer Balance if credit was involved or if settled
            if ($oldCredit > 0) {
                $newCredit = max(0, $netTotal - (float)$invoice['paid_amount'] - (float)$invoice['return_amount']);
                $creditDelta = $newCredit - $oldCredit;
                if (abs($creditDelta) > 0.001) {
                    if ($creditDelta > 0) {
                        update_customer_balance((int)$invoice['customer_id'], $creditDelta, 'increase');
                    } else {
                        update_customer_balance((int)$invoice['customer_id'], abs($creditDelta), 'decrease');
                    }
                    db()->prepare("UPDATE sales_invoices SET credit_amount = ? WHERE id = ?")->execute([$newCredit, $id]);
                }
            }

            // Update invoice header
            $stmtUpd = db()->prepare("
                UPDATE sales_invoices 
                SET invoice_date = ?, discount_mode = ?, gross_amount = ?, item_discount_amount = ?, 
                    extra_discount_amount = ?, subtotal_discount_percent = ?,
                    net_amount = ?, remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $invoiceDate, $discountMode, $grossTotal, $itemDiscount, $extraDiscount,
                $subtotalDiscountPercent, $netTotal, $remarks, $id
            ]);

            db()->commit();

            log_audit('update_invoice', 'sales_invoices', $id, "Updated invoice #{$invoice['invoice_number']} net: {$netTotal}");
            set_flash('success', "Invoice <strong>{$invoice['invoice_number']}</strong> updated successfully! Relative balances have been recalculated.");
            redirect(APP_URL . '/modules/invoices/view.php?id=' . $id);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error editing invoice: " . $e->getMessage());
            $errors[] = "Error saving changes: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Bill ' . $invoice['invoice_number'];
$activeMenu = 'invoices';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Edit Sales Bill: <?= htmlspecialchars($invoice['invoice_number']) ?>
                </h4>
                <p class="text-muted small mb-0">Modify products, quantities, rates, or discounts. Customer ledger balances adjust automatically.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="view.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Bill View
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Unable to proceed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit.php?id=<?= $invoice['id'] ?>">
            <!-- Bill Metadata -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Customer / Retail Shop</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($invoice['shop_name']) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" class="form-control" value="<?= htmlspecialchars($invoice['invoice_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Route / Area</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($invoice['area_name']) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Order Booker</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($invoice['booker_name']) ?>" disabled>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Discount Mode Radio Selection Bar -->
            <div class="pos-discount-mode-bar bg-light p-2 border mb-3 rounded d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <span class="small fw-bold text-secondary"><i class="bi bi-tag-fill me-1 text-primary"></i> Discount Type:</span>
                    <div class="form-check form-check-inline m-0">
                        <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeItem" value="item_rate" <?= ($invoice['discount_mode'] ?? 'item_rate') === 'item_rate' ? 'checked' : '' ?> onchange="onDiscountModeChange()">
                        <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeItem">Discount from per item rate</label>
                    </div>
                    <div class="form-check form-check-inline m-0">
                        <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeSubtotal" value="subtotal" <?= ($invoice['discount_mode'] ?? 'item_rate') === 'subtotal' ? 'checked' : '' ?> onchange="onDiscountModeChange()">
                        <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeSubtotal">Discount from subtotal</label>
                    </div>
                </div>
                <div id="subtotalDiscountInputs" class="<?= ($invoice['discount_mode'] ?? 'item_rate') === 'subtotal' ? 'd-flex' : 'd-none' ?> align-items-center gap-2">
                    <span class="small fw-bold text-navy">Subtotal Disc:</span>
                    <div class="input-group input-group-sm" style="width: 110px;">
                        <input type="number" step="0.01" min="0" max="100" name="subtotal_discount_percent" id="subtotalDiscountPercent" class="form-control form-control-sm text-end" placeholder="0" value="<?= (float)($invoice['subtotal_discount_percent'] ?? 0) > 0 ? (float)$invoice['subtotal_discount_percent'] : '' ?>" oninput="onSubtotalPercentChange()">
                        <span class="input-group-text small">%</span>
                    </div>
                    <div class="input-group input-group-sm" style="width: 140px;">
                        <span class="input-group-text small">Rs</span>
                        <input type="number" step="0.01" min="0" name="subtotal_discount_amount" id="subtotalDiscountAmount" class="form-control form-control-sm text-end" placeholder="0.00" value="<?= (float)($invoice['extra_discount_amount'] ?? 0) > 0 ? (float)$invoice['extra_discount_amount'] : '' ?>" oninput="onSubtotalAmountChange()">
                    </div>
                </div>
            </div>

            <!-- Bill Items -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-cart3 me-1"></i> Bill Items & Wholesale Pricing</h6>
                    <button type="button" class="btn btn-xs btn-outline-primary" onclick="addInvRow()">
                        <i class="bi bi-plus-lg me-1"></i> Add Product Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0" id="invItemsTable">
                            <thead class="bg-light small text-uppercase">
                                <tr>
                                    <th>Product Description</th>
                                    <th style="width: 85px;" class="text-center">Unit</th>
                                    <th style="width: 95px;" class="text-center">Cartons</th>
                                    <th style="width: 85px;" class="text-center">Packs</th>
                                    <th style="width: 95px;" class="text-center">Pieces</th>
                                    <th style="width: 140px;" class="text-end">Rate</th>
                                    <th style="width: 90px;" class="text-center">Disc %</th>
                                    <th style="width: 140px;" class="text-end pe-3">Line Total (Rs.)</th>
                                    <th style="width: 45px;"></th>
                                </tr>
                            </thead>
                            <tbody id="invItemsTbody">
                                <?php foreach ($items as $it): 
                                    $packsPerCtn = (float)$it['ctn_ratio'] > 0 ? (float)$it['ctn_ratio'] : 1;
                                    $pcsPerPack  = (float)$it['pack_ratio'] > 0 ? (float)$it['pack_ratio'] : 1;
                                    $isPieceUnit = ((int)($it['unit_id'] ?? 0) === 4);
                                ?>
                                    <tr class="inv-row" data-ratio="<?= $packsPerCtn ?>" data-pack-ratio="<?= $pcsPerPack ?>">
                                        <td>
                                            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onInvProdChange(this)">
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
                                                            <?= $p['id'] == $it['product_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <select name="item_unit[]" class="form-select form-select-sm unit-inp text-center py-0 px-1" onchange="onInvUnitChange(this)">
                                                <option value="ctn" <?= !$isPieceUnit ? 'selected' : '' ?>>Ctn</option>
                                                <option value="pcs" <?= $isPieceUnit ? 'selected' : '' ?>>Pcs</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="<?= (float)$it['carton_qty'] ?>" oninput="recalcInvLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_pack_qty[]" class="form-control form-control-sm text-center pack-inp" value="<?= (float)($it['pack_qty'] ?? 0) ?>" oninput="recalcInvLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="<?= (float)$it['pcs_qty'] ?>" oninput="recalcInvLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="<?= (float)$it['rate'] ?>" oninput="recalcInvLine(this)">
                                            <span class="small text-muted rate-unit-lbl" style="font-size: 10px;">/ <?= $isPieceUnit ? 'Piece' : 'Carton' ?></span>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="item_discount_percent[]" class="form-control form-control-sm text-center disc-inp" value="<?= (float)$it['discount_percent'] ?>" oninput="recalcInvLine(this)">
                                        </td>
                                        <td class="text-end pe-3 fw-bold line-net text-navy">
                                            <?= number_format((float)$it['line_total'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-xs text-danger" onclick="removeInvRow(this)"><i class="bi bi-x-circle-fill"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold fs-6">
                                <tr>
                                    <td class="text-end text-uppercase" colspan="2">Totals:</td>
                                    <td class="text-center" id="footCtn">0</td>
                                    <td class="text-center" id="footPack">0</td>
                                    <td class="text-center" id="footPcs">0</td>
                                    <td class="text-end" id="footGross">Rs. 0.00</td>
                                    <td class="text-center text-danger" id="footDisc">Rs. 0.00</td>
                                    <td class="text-end pe-3 text-navy" id="footInvNet">Rs. 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white py-3 px-3 d-flex justify-content-between align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Invoice notes or delivery remarks..." value="<?= htmlspecialchars($invoice['remarks'] ?? '') ?>">
                    </div>
                    <div>
                        <a href="view.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-outline-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold">
                            <i class="bi bi-check2-circle me-1"></i> Save Bill & Rebalance Ledger
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<template id="invRowTpl">
    <tr class="inv-row" data-ratio="1" data-pack-ratio="1">
        <td>
            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onInvProdChange(this)">
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
                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="text-center">
            <select name="item_unit[]" class="form-select form-select-sm unit-inp text-center py-0 px-1" onchange="onInvUnitChange(this)">
                <option value="ctn" selected>Ctn</option>
                <option value="pcs">Pcs</option>
            </select>
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="1" oninput="recalcInvLine(this)">
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_pack_qty[]" class="form-control form-control-sm text-center pack-inp" value="0" oninput="recalcInvLine(this)">
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="0" oninput="recalcInvLine(this)">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="0.00" oninput="recalcInvLine(this)">
            <span class="small text-muted rate-unit-lbl" style="font-size: 10px;">/ Carton</span>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_discount_percent[]" class="form-control form-control-sm text-center disc-inp" value="0" oninput="recalcInvLine(this)">
        </td>
        <td class="text-end pe-3 fw-bold line-net text-navy">
            0.00
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs text-danger" onclick="removeInvRow(this)"><i class="bi bi-x-circle-fill"></i></button>
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

    recalcAllInvLines();
    recalcInvTotals();
}

function calculateInvRowGross(row) {
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
    document.querySelectorAll('.inv-row').forEach(row => {
        grossTotal += calculateInvRowGross(row);
    });

    const amt = Math.round(((grossTotal * pctVal) / 100.0) * 100) / 100;
    const amtEl = document.getElementById('subtotalDiscountAmount');
    if (amtEl && document.activeElement !== amtEl) {
        amtEl.value = amt > 0 ? amt.toFixed(2) : '';
    }

    recalcInvTotals();
}

function onSubtotalAmountChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const amtVal = parseFloat(document.getElementById('subtotalDiscountAmount')?.value) || 0;
    let grossTotal = 0;
    document.querySelectorAll('.inv-row').forEach(row => {
        grossTotal += calculateInvRowGross(row);
    });

    const pct = grossTotal > 0 ? Math.round(((amtVal / grossTotal) * 100.0) * 100) / 100 : 0;
    const pctEl = document.getElementById('subtotalDiscountPercent');
    if (pctEl && document.activeElement !== pctEl) {
        pctEl.value = pct > 0 ? pct.toFixed(2) : '';
    }

    recalcInvTotals();
}

function recalcAllInvLines() {
    const mode = getDiscountMode();
    document.querySelectorAll('.inv-row').forEach(row => {
        const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;
        const lineGross = calculateInvRowGross(row);
        const lineDisc = (mode === 'subtotal') ? 0 : ((lineGross * disc) / 100.0);
        const lineNet = lineGross - lineDisc;

        row.querySelector('.line-net').innerText = lineNet.toLocaleString('en-US', {minimumFractionDigits: 2});
    });
}

function addInvRow() {
    const tpl = document.getElementById('invRowTpl').content.cloneNode(true);
    document.getElementById('invItemsTbody').appendChild(tpl);
    if (getDiscountMode() === 'subtotal') {
        const lastRow = document.querySelector('#invItemsTbody tr:last-child');
        const discInp = lastRow?.querySelector('.disc-inp');
        if (discInp) {
            discInp.disabled = true;
            discInp.classList.add('bg-light', 'text-muted');
            discInp.title = 'Disabled in subtotal discount mode';
        }
    }
    recalcInvTotals();
}

function removeInvRow(btn) {
    const row = btn.closest('tr');
    if (document.querySelectorAll('.inv-row').length > 1) {
        row.remove();
        recalcInvTotals();
    } else {
        alert('Invoice must contain at least one item.');
    }
}

function onInvUnitChange(sel) {
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

    recalcInvLine(sel);
}

function onInvProdChange(sel) {
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

        recalcInvLine(sel);
    }
}

function recalcInvLine(el) {
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
    const lineGross = calculateInvRowGross(row);
    const lineDisc = (mode === 'subtotal') ? 0 : ((lineGross * discVal) / 100.0);
    const lineNet = lineGross - lineDisc;

    row.querySelector('.line-net').innerText = lineNet.toLocaleString('en-US', {minimumFractionDigits: 2});

    if (mode === 'subtotal') {
        const pctEl = document.getElementById('subtotalDiscountPercent');
        if (pctEl && parseFloat(pctEl.value) > 0) {
            onSubtotalPercentChange();
            return;
        }
    }

    recalcInvTotals();
}

function recalcInvTotals() {
    const mode = getDiscountMode();
    let totCtn = 0;
    let totPack = 0;
    let totPcs = 0;
    let totGross = 0;
    let totItemDisc = 0;

    document.querySelectorAll('.inv-row').forEach(row => {
        const ctn  = parseFloat(row.querySelector('.ctn-inp').value) || 0;
        const pack = parseFloat(row.querySelector('.pack-inp') ? row.querySelector('.pack-inp').value : 0) || 0;
        const pcs  = parseFloat(row.querySelector('.pcs-inp').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;

        const lineGross = calculateInvRowGross(row);
        const lineDisc = (mode === 'subtotal') ? 0 : ((lineGross * disc) / 100.0);

        totCtn += ctn;
        totPack += pack;
        totPcs += pcs;
        totGross += lineGross;
        totItemDisc += lineDisc;
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

    const footCtn = document.getElementById('footCtn');
    if (footCtn) footCtn.innerText = totCtn;
    const footPack = document.getElementById('footPack');
    if (footPack) footPack.innerText = totPack;
    const footPcs = document.getElementById('footPcs');
    if (footPcs) footPcs.innerText = totPcs;
    const footGross = document.getElementById('footGross');
    if (footGross) footGross.innerText = 'Rs. ' + totGross.toLocaleString('en-US', {minimumFractionDigits: 2});
    const footDisc = document.getElementById('footDisc');
    if (footDisc) footDisc.innerText = '-Rs. ' + finalDisc.toLocaleString('en-US', {minimumFractionDigits: 2});
    const footInvNet = document.getElementById('footInvNet');
    if (footInvNet) footInvNet.innerText = 'Rs. ' + finalNet.toLocaleString('en-US', {minimumFractionDigits: 2});
}

document.addEventListener('DOMContentLoaded', function() {
    if (getDiscountMode() === 'subtotal') {
        document.querySelectorAll('.disc-inp').forEach(inp => {
            inp.disabled = true;
            inp.classList.add('bg-light', 'text-muted');
            inp.title = 'Disabled in subtotal discount mode';
        });
    }
    recalcInvTotals();
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
