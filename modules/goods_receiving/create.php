<?php
/**
 * DMS - Distribution Management System
 * Goods Receiving Note (GRN) - Create Goods Receipt
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('purchases_receive');

$pageTitle = 'Receive Goods (GRN)';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Goods Receiving', 'url' => 'index.php'],
    ['label' => 'New GRN', 'url' => '']
];

$prePoId = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
$errors = [];

// Pre-fill from PO if given
$prePo = null;
$preItems = [];
if ($prePoId > 0) {
    $prePo = db_fetch_one("SELECT * FROM purchase_orders WHERE id = ?", [$prePoId]);
    if ($prePo) {
        $preItems = db_fetch_all("
            SELECT 
                poi.*,
                p.product_code,
                p.product_name,
                (poi.quantity - poi.received_quantity) as remaining_qty
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = ?
        ", [$prePoId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grnDate     = sanitize_input($_POST['grn_date'] ?? date('Y-m-d'));
    $supplierId  = (int)($_POST['supplier_id'] ?? 0);
    $poId        = !empty($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : null;
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $supplierBill = sanitize_input($_POST['supplier_bill_no'] ?? '');
    $remarks     = sanitize_input($_POST['remarks'] ?? '');
    $itemsData   = $_POST['items'] ?? [];

    if ($supplierId <= 0) {
        $errors[] = "Please select a supplier.";
    }
    if ($warehouseId <= 0) {
        $errors[] = "Please select a receiving warehouse.";
    }
    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "Please enter at least one received product line.";
    }

    $validItems = [];
    $totalAmount = 0.0;

    foreach ($itemsData as $idx => $row) {
        $productId   = (int)($row['product_id'] ?? 0);
        $orderedQty  = (float)($row['ordered_qty'] ?? 0);
        $receivedQty = (float)($row['received_qty'] ?? 0);
        $shortQty    = (float)($row['short_qty'] ?? 0);
        $damagedQty  = (float)($row['damaged_qty'] ?? 0);
        $rate        = (float)($row['unit_rate'] ?? 0);

        if ($productId <= 0 || ($receivedQty <= 0 && $damagedQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
        if (!$prod) continue;

        $lineTotal = round($receivedQty * $rate, 2);
        $totalAmount += $lineTotal;

        $validItems[] = [
            'product_id'   => $productId,
            'unit_id'      => (int)$prod['base_unit_id'],
            'ordered_qty'  => $orderedQty,
            'received_qty' => $receivedQty,
            'short_qty'    => $shortQty,
            'damaged_qty'  => $damagedQty,
            'unit_rate'    => $rate,
            'line_total'   => $lineTotal
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please enter valid quantities for received items.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $grnNumber = generate_document_number(PREFIX_GRN, 'goods_receipts', 'grn_number');

            // 1. Insert goods_receipts header
            $stmtGRN = db()->prepare("
                INSERT INTO goods_receipts (
                    grn_number, purchase_order_id, supplier_id, supplier_bill_no, 
                    grn_date, warehouse_id, total_amount, status, remarks, received_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, 'posted', ?, ?, NOW()
                )
            ");
            $stmtGRN->execute([
                $grnNumber, $poId, $supplierId, $supplierBill,
                $grnDate, $warehouseId, $totalAmount, $remarks, $userId
            ]);
            $grnId = (int)db()->lastInsertId();

            // 2. Insert items, update inventory movements & balances
            $stmtItem = db()->prepare("
                INSERT INTO goods_receipt_items (
                    goods_receipt_id, product_id, unit_id, ordered_qty, received_qty, 
                    short_qty, damaged_qty, unit_rate, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, NOW()
                )
            ");

            $stmtUpdPoItem = db()->prepare("
                UPDATE purchase_order_items 
                SET received_quantity = received_quantity + ? 
                WHERE purchase_order_id = ? AND product_id = ?
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $grnId, $vi['product_id'], $vi['unit_id'],
                    $vi['ordered_qty'], $vi['received_qty'],
                    $vi['short_qty'], $vi['damaged_qty'],
                    $vi['unit_rate'], $vi['line_total']
                ]);

                // Record atomic stock addition into warehouse
                if ($vi['received_qty'] > 0) {
                    record_stock_movement([
                        'product_id'     => $vi['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => 'purchase_grn',
                        'reference_type' => 'grn',
                        'reference_id'   => $grnId,
                        'qty_in'         => $vi['received_qty'],
                        'qty_out'        => 0,
                        'unit_cost'      => $vi['unit_rate'],
                        'remarks'        => "Stock received via GRN #{$grnNumber}",
                        'condition_type' => 'saleable'
                    ]);
                }

                // If damaged stock received, quarantine it
                if ($vi['damaged_qty'] > 0) {
                    record_stock_movement([
                        'product_id'     => $vi['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => 'stock_adjustment_add',
                        'reference_type' => 'grn',
                        'reference_id'   => $grnId,
                        'qty_in'         => $vi['damaged_qty'],
                        'qty_out'        => 0,
                        'unit_cost'      => $vi['unit_rate'],
                        'remarks'        => "Damaged arrival quarantined via GRN #{$grnNumber}",
                        'condition_type' => 'damaged'
                    ]);
                }

                // If linked to PO, update PO received quantities
                if ($poId) {
                    $stmtUpdPoItem->execute([$vi['received_qty'], $poId, $vi['product_id']]);
                }
            }

            // Check if PO is completely received
            if ($poId) {
                $pendingPoItems = (int)db_fetch_val("
                    SELECT COUNT(*) 
                    FROM purchase_order_items 
                    WHERE purchase_order_id = ? AND received_quantity < quantity
                ", [$poId]);

                $newPoStatus = ($pendingPoItems === 0) ? 'received' : 'partially_received';
                db()->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$newPoStatus, $poId]);
            }

            // 3. Update Supplier Balance (Accounts Payable credit)
            update_supplier_balance($supplierId, $totalAmount, 'increase');

            // 4. Post to General Ledger:
            // Debit: Inventory Asset (1030)
            // Credit: Accounts Payable (2010)
            post_journal_entry([
                'entry_date'     => $grnDate,
                'reference_type' => 'grn',
                'reference_id'   => $grnId,
                'narration'      => "Goods Receipt Note #{$grnNumber}"
            ], [
                ['account_id' => ACC_INVENTORY, 'debit' => $totalAmount, 'credit' => 0.00, 'description' => "Inventory added - GRN #{$grnNumber}"],
                ['account_id' => ACC_PAYABLES, 'debit' => 0.00, 'credit' => $totalAmount, 'party_type' => 'supplier', 'party_id' => $supplierId, 'description' => "Supplier payable - GRN #{$grnNumber}"]
            ]);

            db()->commit();
            set_flash('success', "Goods Receipt <strong>{$grnNumber}</strong> posted successfully! Warehouse inventory added and supplier payable recorded.");
            redirect(APP_URL . '/modules/goods_receiving/view.php?id=' . $grnId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error saving GRN: " . $e->getMessage());
            $errors[] = "Error processing goods receipt: " . $e->getMessage();
        }
    }
}

$suppliers  = db_fetch_all("SELECT id, supplier_name FROM suppliers WHERE is_deleted = 0 ORDER BY supplier_name ASC");
$warehouses = db_fetch_all("SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC");
$pos        = db_fetch_all("SELECT id, purchase_number, supplier_id, order_date FROM purchase_orders WHERE status IN ('ordered', 'partially_received') ORDER BY id DESC");
$products   = db_fetch_all("SELECT id, product_code, product_name, purchase_rate as cost_price, carton_qty FROM products WHERE is_active = 1 AND is_deleted = 0 ORDER BY product_name ASC");

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
                    <i class="bi bi-box-arrow-in-down me-2"></i>Warehouse Goods Receiving (GRN)
                </h4>
                <p class="text-muted small mb-0">Inspect physical goods arrival, record carton counts, and update inventory balances</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to GRNs
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

        <form method="POST" action="create.php" id="grnForm">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-truck me-1"></i> Shipment & Receiving Info</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Supplier / Factory <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="supplierSelect" class="form-select" required autofocus>
                                <option value="">-- Select Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ($prePo && (int)$prePo['supplier_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Associated Purchase Order</label>
                            <select name="purchase_order_id" id="poSelect" class="form-select">
                                <option value="">-- Direct Shipment / None --</option>
                                <?php foreach ($pos as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($prePoId === (int)$p['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['purchase_number']) ?> (<?= date('d-M', strtotime($p['order_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">GRN Date <span class="text-danger">*</span></label>
                            <input type="date" name="grn_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Receiving Warehouse <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Supplier Bill / Bilty #</label>
                            <input type="text" name="supplier_bill_no" class="form-control" value="<?= htmlspecialchars($prePo['supplier_invoice_no'] ?? '') ?>" placeholder="e.g. INV-10492">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-box-seam me-1"></i> Inspected Goods (Carton Counts & Valuation)</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-lg me-1"></i> Add Product Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="grnItemsTable">
                            <thead class="table-light text-muted small text-uppercase text-center">
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th style="width: 34%;" class="text-start ps-3">Product Description</th>
                                    <th style="width: 12%;">Ordered Qty</th>
                                    <th style="width: 14%;">Received Qty</th>
                                    <th style="width: 12%;">Damaged Qty</th>
                                    <th style="width: 14%;">Unit Cost (Rs.)</th>
                                    <th style="width: 14%;" class="text-end pe-3">Line Total (Rs.)</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="grnItemsTbody">
                                <?php if (!empty($preItems)): ?>
                                    <?php foreach ($preItems as $idx => $pi): ?>
                                        <tr class="item-row">
                                            <td class="text-center row-number text-muted small"><?= $idx + 1 ?></td>
                                            <td class="ps-3">
                                                <select name="items[<?= $idx ?>][product_id]" class="form-select form-select-sm product-select" required>
                                                    <option value="<?= $pi['product_id'] ?>" selected>
                                                        <?= htmlspecialchars($pi['product_name']) ?> (<?= htmlspecialchars($pi['product_code']) ?>)
                                                    </option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="items[<?= $idx ?>][ordered_qty]" class="form-control form-control-sm text-center bg-light" value="<?= (float)$pi['remaining_qty'] ?>" readonly>
                                            </td>
                                            <td>
                                                <input type="number" name="items[<?= $idx ?>][received_qty]" class="form-control form-control-sm text-center recv-input fw-bold" step="1" min="0" value="<?= (float)$pi['remaining_qty'] ?>" required>
                                            </td>
                                            <td>
                                                <input type="number" name="items[<?= $idx ?>][damaged_qty]" class="form-control form-control-sm text-center dmg-input text-danger" step="1" min="0" value="0">
                                            </td>
                                            <td>
                                                <input type="number" name="items[<?= $idx ?>][unit_rate]" class="form-control form-control-sm text-center rate-input" step="0.01" min="0" value="<?= (float)$pi['rate'] ?>" required>
                                            </td>
                                            <td class="text-end pe-3 fw-bold text-dark line-total-cell">
                                                <?= number_format((float)$pi['remaining_qty'] * (float)$pi['rate'], 2) ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-xs btn-outline-danger btn-remove-row">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold fs-6">
                                <tr>
                                    <td colspan="6" class="text-end text-uppercase">Total Received Value:</td>
                                    <td class="text-end pe-3 text-navy" id="totalGrnLabel">Rs. 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Footer Remarks & Submit -->
            <div class="card border-0 shadow-sm mb-5">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <input type="text" name="remarks" class="form-control" placeholder="Inspection remarks / vehicle arrival notes...">
                        </div>
                        <div class="col-md-5 text-end d-flex gap-2 justify-content-end">
                            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> Confirm & Post Goods Receipt (GRN)
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
                    <option value="<?= $p['id'] ?>" data-cost="<?= $p['cost_price'] ?>">
                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="items[INDEX][ordered_qty]" class="form-control form-control-sm text-center bg-light" value="0" readonly>
        </td>
        <td>
            <input type="number" name="items[INDEX][received_qty]" class="form-control form-control-sm text-center recv-input fw-bold" step="1" min="0" placeholder="0" required>
        </td>
        <td>
            <input type="number" name="items[INDEX][damaged_qty]" class="form-control form-control-sm text-center dmg-input text-danger" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][unit_rate]" class="form-control form-control-sm text-center rate-input" step="0.01" min="0" placeholder="0.00" required>
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
let rowIndex = <?= count($preItems) ?>;

function addNewRow() {
    const template = document.getElementById('rowTemplate').innerHTML;
    const html = template.replace(/INDEX/g, rowIndex);
    const tbody = document.getElementById('grnItemsTbody');
    
    tbody.insertAdjacentHTML('beforeend', html);
    const newRow = tbody.lastElementChild;
    rowIndex++;

    attachRowEvents(newRow);
    renumberRows();
}

function attachRowEvents(row) {
    const prodSelect = row.querySelector('.product-select');
    const recvInput  = row.querySelector('.recv-input');
    const dmgInput   = row.querySelector('.dmg-input');
    const rateInput  = row.querySelector('.rate-input');
    const btnRemove  = row.querySelector('.btn-remove-row');

    if (prodSelect) {
        prodSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                rateInput.value = parseFloat(opt.getAttribute('data-cost') || 0).toFixed(2);
                if (!recvInput.value) recvInput.value = 10;
            }
            calculateRowTotal(row);
        });
    }

    [recvInput, dmgInput, rateInput].forEach(inp => {
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
    const recv = parseFloat(row.querySelector('.recv-input').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const total = recv * rate;
    row.querySelector('.line-total-cell').innerText = total.toFixed(2);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let grand = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const recv = parseFloat(row.querySelector('.recv-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        grand += (recv * rate);
    });
    document.getElementById('totalGrnLabel').innerText = 'Rs. ' + grand.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function renumberRows() {
    document.querySelectorAll('.item-row').forEach((row, idx) => {
        row.querySelector('.row-number').innerText = idx + 1;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelectorAll('.item-row').length === 0) {
        addNewRow();
    } else {
        document.querySelectorAll('.item-row').forEach(r => attachRowEvents(r));
        calculateGrandTotal();
    }

    document.getElementById('btnAddRow').addEventListener('click', addNewRow);
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
