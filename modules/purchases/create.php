<?php
/**
 * DMS - Distribution Management System
 * Purchases - Create Purchase Order
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_create');
require_once INCLUDES_PATH . '/inventory_functions.php';

$pageTitle = 'Create Purchase Order';
$activeMenu = 'purchases';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Purchases', 'url' => 'index.php'],
    ['label' => 'New Order', 'url' => '']
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderDate    = sanitize_input($_POST['order_date'] ?? date('Y-m-d'));
    $deliveryDate = !empty($_POST['expected_delivery_date']) ? sanitize_input($_POST['expected_delivery_date']) : null;
    $supplierId   = (int)($_POST['supplier_id'] ?? 0);
    $supplierInv  = sanitize_input($_POST['supplier_invoice_no'] ?? '');
    $status       = sanitize_input($_POST['status'] ?? 'ordered'); // 'draft' or 'ordered'
    $remarks      = sanitize_input($_POST['remarks'] ?? '');
    $itemsData    = $_POST['items'] ?? [];

    if ($supplierId <= 0) {
        $errors[] = "Please select a supplier / manufacturer.";
    }
    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "Please enter at least one product order line.";
    }

    $validItems = [];
    $totalAmount = 0.0;
    $totalDiscount = 0.0;

    foreach ($itemsData as $idx => $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $ctnQty    = (float)($row['carton_qty'] ?? 0);
        $packQty   = (float)($row['pack_qty'] ?? 0);
        $pcsQty    = (float)($row['pcs_qty'] ?? 0);
        $qty       = (float)($row['quantity'] ?? 0);
        $rate      = (float)($row['rate'] ?? 0);
        $disc      = (float)($row['discount_amount'] ?? 0);

        if ($productId <= 0 || ($ctnQty <= 0 && $packQty <= 0 && $pcsQty <= 0 && $qty <= 0)) continue;

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);

        if ($ctnQty > 0 || $packQty > 0 || $pcsQty > 0) {
            $equivCartons = calculate_carton_equivalent($ctnQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
        } else {
            $equivCartons = $qty;
            $bk = breakdown_base_pieces($qty * $ratios['pcs_per_ctn'], $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
            $ctnQty  = $bk['cartons'];
            $packQty = $bk['packs'];
            $pcsQty  = $bk['pieces'];
        }

        $lineGross = $equivCartons * $rate;
        $lineNet = max(0, $lineGross - $disc);

        $totalAmount += $lineGross;
        $totalDiscount += $disc;

        $validItems[] = [
            'product_id'      => $productId,
            'unit_id'         => (int)$prod['base_unit_id'],
            'carton_qty'      => $ctnQty,
            'pack_qty'        => $packQty,
            'pcs_qty'         => $pcsQty,
            'quantity'        => $equivCartons,
            'rate'            => $rate,
            'discount_amount' => $disc,
            'line_total'      => $lineNet
        ];
    }

    $netAmount = $totalAmount - $totalDiscount;

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line with quantity greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $poNumber = generate_document_number(PREFIX_PURCHASE, 'purchase_orders', 'purchase_number');

            $stmtPO = db()->prepare("
                INSERT INTO purchase_orders (
                    purchase_number, supplier_id, supplier_invoice_no, order_date, 
                    expected_delivery_date, total_amount, discount_amount, net_amount, 
                    status, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, NOW()
                )
            ");
            $stmtPO->execute([
                $poNumber, $supplierId, $supplierInv, $orderDate,
                $deliveryDate, $totalAmount, $totalDiscount, $netAmount,
                $status, $remarks, $userId
            ]);
            $poId = (int)db()->lastInsertId();

            $stmtItem = db()->prepare("
                INSERT INTO purchase_order_items (
                    purchase_order_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, 
                    quantity, rate, discount_amount, line_total, received_quantity, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, NOW())
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $poId, $vi['product_id'], $vi['unit_id'],
                    $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['quantity'], $vi['rate'], $vi['discount_amount'],
                    $vi['line_total']
                ]);
            }

            db()->commit();
            set_flash('success', "Purchase Order <strong>{$poNumber}</strong> created successfully!");
            redirect(APP_URL . '/modules/purchases/view.php?id=' . $poId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error creating purchase order: " . $e->getMessage());
            $errors[] = "Error saving purchase order: " . $e->getMessage();
        }
    }
}

$suppliers = db_fetch_all("SELECT id, supplier_name, phone, address FROM suppliers WHERE is_deleted = 0 ORDER BY supplier_name ASC");
$products  = db_fetch_all("SELECT id, product_code, product_name, carton_qty, pack_qty, purchase_rate as cost_price, wholesale_rate FROM products WHERE is_active = 1 AND is_deleted = 0 ORDER BY product_name ASC");

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
                    <i class="bi bi-cart-plus me-2"></i>Create Purchase Order
                </h4>
                <p class="text-muted small mb-0">Issue factory purchase order for FMCG stock replenishment</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Orders
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Form Validation Failed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="create.php" id="poForm">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-building me-1"></i> Supplier & Shipment Terms</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Supplier / Factory <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-select" required autofocus>
                                <option value="">-- Select Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Order Date <span class="text-danger">*</span></label>
                            <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Expected Delivery</label>
                            <input type="date" name="expected_delivery_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Supplier Invoice / Bilty #</label>
                            <input type="text" name="supplier_invoice_no" class="form-control" placeholder="e.g. BL-9842">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Order Status</label>
                            <select name="status" class="form-select">
                                <option value="ordered">Ordered (Confirmed)</option>
                                <option value="draft">Draft (Preparing)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-box-seam me-1"></i> Order Items</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-lg me-1"></i> Add Product Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="poItemsTable">
                            <thead class="table-light text-muted small text-uppercase text-center">
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th style="width: 32%;" class="text-start ps-3">Product Description</th>
                                    <th style="width: 10%;">Ctn</th>
                                    <th style="width: 10%;">Pack</th>
                                    <th style="width: 10%;">Pcs</th>
                                    <th style="width: 13%;">Rate / Ctn (Rs.)</th>
                                    <th style="width: 10%;">Discount (Rs.)</th>
                                    <th style="width: 15%;" class="text-end pe-3">Line Total (Rs.)</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="poItemsTbody">
                                <!-- Dynamic rows -->
                            </tbody>
                            <tfoot class="table-light fw-bold fs-6">
                                <tr>
                                    <td colspan="7" class="text-end text-uppercase">Net Order Amount:</td>
                                    <td class="text-end pe-3 text-navy" id="totalOrderLabel">Rs. 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-5">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <input type="text" name="remarks" class="form-control" placeholder="Purchase order instructions / terms...">
                        </div>
                        <div class="col-md-5 text-end d-flex gap-2 justify-content-end">
                            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> Submit Purchase Order
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<template id="rowTemplate">
    <tr class="item-row">
        <td class="text-center row-number text-muted small">1</td>
        <td class="ps-3">
            <select name="items[INDEX][product_id]" class="form-select form-select-sm product-select" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" 
                            data-cost="<?= $p['cost_price'] ?>"
                            data-ctn="<?= (float)$p['carton_qty'] > 0 ? (float)$p['carton_qty'] : 1 ?>"
                            data-pack="<?= (float)$p['pack_qty'] > 0 ? (float)$p['pack_qty'] : 1 ?>">
                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted ratio-label d-block mt-1" style="font-size: 0.72rem;"></small>
            <input type="hidden" name="items[INDEX][quantity]" class="equiv-qty-input" value="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][carton_qty]" class="form-control form-control-sm text-center ctn-input" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][pack_qty]" class="form-control form-control-sm text-center pack-input" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][pcs_qty]" class="form-control form-control-sm text-center pcs-input" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][rate]" class="form-control form-control-sm text-center rate-input" step="0.01" min="0" placeholder="0.00" required>
        </td>
        <td>
            <input type="number" name="items[INDEX][discount_amount]" class="form-control form-control-sm text-center disc-input" step="0.01" min="0" placeholder="0.00">
        </td>
        <td class="text-end pe-3 fw-bold text-dark line-total-cell">
            0.00
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger btn-remove-row">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</template>

<script>
let rowIndex = 0;

function addNewRow() {
    const template = document.getElementById('rowTemplate').innerHTML;
    const html = template.replace(/INDEX/g, rowIndex);
    const tbody = document.getElementById('poItemsTbody');
    
    tbody.insertAdjacentHTML('beforeend', html);
    const newRow = tbody.lastElementChild;
    rowIndex++;

    attachRowEvents(newRow);
    renumberRows();
}

function attachRowEvents(row) {
    const prodSelect = row.querySelector('.product-select');
    const ctnInput   = row.querySelector('.ctn-input');
    const packInput  = row.querySelector('.pack-input');
    const pcsInput   = row.querySelector('.pcs-input');
    const rateInput  = row.querySelector('.rate-input');
    const discInput  = row.querySelector('.disc-input');
    const btnRemove  = row.querySelector('.btn-remove-row');
    const ratioLabel = row.querySelector('.ratio-label');

    prodSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            rateInput.value = parseFloat(opt.getAttribute('data-cost') || 0).toFixed(2);
            const packsPerCtn = parseFloat(opt.getAttribute('data-ctn')) || 1;
            const pcsPerPack  = parseFloat(opt.getAttribute('data-pack')) || 1;
            
            if (pcsPerPack > 1) {
                ratioLabel.innerText = `1 Ctn = ${packsPerCtn} Pck × ${pcsPerPack} Pcs (${(packsPerCtn * pcsPerPack).toLocaleString()} Pcs)`;
            } else {
                ratioLabel.innerText = `1 Ctn = ${packsPerCtn} Pcs`;
            }

            if (!ctnInput.value && !packInput.value && !pcsInput.value) {
                ctnInput.value = 10;
            }
        } else {
            ratioLabel.innerText = '';
        }
        calculateRowTotal(row);
    });

    [ctnInput, packInput, pcsInput, rateInput, discInput].forEach(inp => {
        inp.addEventListener('input', () => calculateRowTotal(row));
    });

    btnRemove.addEventListener('click', function() {
        if (document.querySelectorAll('.item-row').length > 1) {
            row.remove();
            renumberRows();
            calculateGrandTotal();
        }
    });
}

function calculateRowTotal(row) {
    const prodSelect = row.querySelector('.product-select');
    const opt = prodSelect.options[prodSelect.selectedIndex];
    const packsPerCtn = opt && opt.value ? (parseFloat(opt.getAttribute('data-ctn')) || 1) : 1;
    const pcsPerPack  = opt && opt.value ? (parseFloat(opt.getAttribute('data-pack')) || 1) : 1;

    const ctn  = parseFloat(row.querySelector('.ctn-input').value) || 0;
    const pack = parseFloat(row.querySelector('.pack-input').value) || 0;
    const pcs  = parseFloat(row.querySelector('.pcs-input').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const disc = parseFloat(row.querySelector('.disc-input').value) || 0;

    const totalCartons = ctn + (pack / packsPerCtn) + (pcs / (packsPerCtn * pcsPerPack));
    row.querySelector('.equiv-qty-input').value = totalCartons.toFixed(4);

    const gross = totalCartons * rate;
    const total = Math.max(0, gross - disc);
    row.querySelector('.line-total-cell').innerText = total.toFixed(2);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let grand = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const prodSelect = row.querySelector('.product-select');
        const opt = prodSelect.options[prodSelect.selectedIndex];
        const packsPerCtn = opt && opt.value ? (parseFloat(opt.getAttribute('data-ctn')) || 1) : 1;
        const pcsPerPack  = opt && opt.value ? (parseFloat(opt.getAttribute('data-pack')) || 1) : 1;

        const ctn  = parseFloat(row.querySelector('.ctn-input').value) || 0;
        const pack = parseFloat(row.querySelector('.pack-input').value) || 0;
        const pcs  = parseFloat(row.querySelector('.pcs-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-input').value) || 0;

        const totalCartons = ctn + (pack / packsPerCtn) + (pcs / (packsPerCtn * pcsPerPack));
        grand += Math.max(0, (totalCartons * rate) - disc);
    });
    document.getElementById('totalOrderLabel').innerText = 'Rs. ' + grand.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function renumberRows() {
    document.querySelectorAll('.item-row').forEach((row, idx) => {
        row.querySelector('.row-number').innerText = idx + 1;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    addNewRow();
    document.getElementById('btnAddRow').addEventListener('click', addNewRow);
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
