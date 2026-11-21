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
    SELECT sii.*, p.product_name, p.product_code, p.carton_qty as ctn_ratio
    FROM sales_invoice_items sii
    JOIN products p ON sii.product_id = p.id
    WHERE sii.invoice_id = ?
    ORDER BY sii.id ASC
", [$id]);

$products = db_fetch_all("
    SELECT id, product_name, product_code, wholesale_rate, carton_qty, base_unit_id 
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
    $pcsQtys     = $_POST['item_pcs_qty'] ?? [];
    $rates       = $_POST['item_rate'] ?? [];
    $discounts   = $_POST['item_discount_percent'] ?? [];

    $grossTotal = 0.0;
    $discTotal  = 0.0;
    $validItems = [];

    foreach ($itemIds as $idx => $pid) {
        $prodId = (int)$pid;
        $ctn = (float)($ctnQtys[$idx] ?? 0);
        $pcs = (float)($pcsQtys[$idx] ?? 0);
        $rate = (float)($rates[$idx] ?? 0);
        $disc = (float)($discounts[$idx] ?? 0);

        if ($prodId <= 0 || ($ctn <= 0 && $pcs <= 0)) continue;

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $ratio = (float)$prod['carton_qty'] > 0 ? (float)$prod['carton_qty'] : 1;
        $baseQty = ($ctn * $ratio) + $pcs;
        $lineGross = ($ctn > 0 ? ($ctn * $rate) : (($pcs / $ratio) * $rate));
        $lineDisc = ($lineGross * $disc) / 100.0;
        $lineNet = $lineGross - $lineDisc;

        $grossTotal += $lineGross;
        $discTotal  += $lineDisc;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => (int)$prod['base_unit_id'],
            'carton_qty'       => $ctn,
            'pcs_qty'          => $pcs,
            'quantity'         => $baseQty,
            'rate'             => $rate,
            'discount_percent' => $disc,
            'discount_amount'  => $lineDisc,
            'line_total'       => $lineNet
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line.";
    }

    if (empty($errors)) {
        $netTotal = round($grossTotal - $discTotal, 2);

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
                SET invoice_date = ?, gross_amount = ?, item_discount_amount = ?, 
                    net_amount = ?, remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $invoiceDate, $grossTotal, $discTotal, $netTotal, $remarks, $id
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
                                    <th style="width: 120px;" class="text-center">Cartons</th>
                                    <th style="width: 120px;" class="text-center">Pieces</th>
                                    <th style="width: 140px;" class="text-end">Wholesale Rate</th>
                                    <th style="width: 110px;" class="text-center">Disc %</th>
                                    <th style="width: 160px;" class="text-end pe-3">Line Total (Rs.)</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="invItemsTbody">
                                <?php foreach ($items as $it): ?>
                                    <tr class="inv-row" data-ratio="<?= (float)$it['ctn_ratio'] ?>">
                                        <td>
                                            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onInvProdChange(this)">
                                                <?php foreach ($products as $p): ?>
                                                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['wholesale_rate'] ?>" data-ratio="<?= $p['carton_qty'] ?>" <?= $p['id'] == $it['product_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="<?= (float)$it['carton_qty'] ?>" oninput="recalcInvLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="<?= (float)$it['pcs_qty'] ?>" oninput="recalcInvLine(this)">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="<?= (float)$it['rate'] ?>" oninput="recalcInvLine(this)">
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
                                    <td colspan="5" class="text-end text-uppercase">Net Invoice Total:</td>
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
    <tr class="inv-row" data-ratio="1">
        <td>
            <select name="item_product_id[]" class="form-select form-select-sm prod-select" onchange="onInvProdChange(this)">
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['wholesale_rate'] ?>" data-ratio="<?= $p['carton_qty'] ?>">
                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_carton_qty[]" class="form-control form-control-sm text-center ctn-inp" value="1" oninput="recalcInvLine(this)">
        </td>
        <td>
            <input type="number" step="1" min="0" name="item_pcs_qty[]" class="form-control form-control-sm text-center pcs-inp" value="0" oninput="recalcInvLine(this)">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_rate[]" class="form-control form-control-sm text-end rate-inp" value="0.00" oninput="recalcInvLine(this)">
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
function addInvRow() {
    const tpl = document.getElementById('invRowTpl').content.cloneNode(true);
    document.getElementById('invItemsTbody').appendChild(tpl);
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

function onInvProdChange(sel) {
    const row = sel.closest('tr');
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        const rate = parseFloat(opt.getAttribute('data-rate')) || 0;
        const ratio = parseFloat(opt.getAttribute('data-ratio')) || 1;
        row.querySelector('.rate-inp').value = rate.toFixed(2);
        row.setAttribute('data-ratio', ratio);
        recalcInvLine(sel);
    }
}

function recalcInvLine(el) {
    const row = el.closest('tr');
    const ratio = parseFloat(row.getAttribute('data-ratio')) || 1;
    const ctn = parseFloat(row.querySelector('.ctn-inp').value) || 0;
    const pcs = parseFloat(row.querySelector('.pcs-inp').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-inp').value) || 0;
    const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;

    const lineGross = (ctn > 0 ? (ctn * rate) : ((pcs / ratio) * rate));
    const lineDisc = (lineGross * disc) / 100.0;
    const lineNet = lineGross - lineDisc;

    row.querySelector('.line-net').innerText = lineNet.toLocaleString('en-US', {minimumFractionDigits: 2});
    recalcInvTotals();
}

function recalcInvTotals() {
    let totNet = 0;
    document.querySelectorAll('.inv-row').forEach(row => {
        const ratio = parseFloat(row.getAttribute('data-ratio')) || 1;
        const ctn = parseFloat(row.querySelector('.ctn-inp').value) || 0;
        const pcs = parseFloat(row.querySelector('.pcs-inp').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-inp').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-inp').value) || 0;

        const lineGross = (ctn > 0 ? (ctn * rate) : ((pcs / ratio) * rate));
        const lineDisc = (lineGross * disc) / 100.0;
        const lineNet = lineGross - lineDisc;

        totNet += lineNet;
    });

    document.getElementById('footInvNet').innerText = 'Rs. ' + totNet.toLocaleString('en-US', {minimumFractionDigits: 2});
}

document.addEventListener('DOMContentLoaded', function() {
    recalcInvTotals();
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
