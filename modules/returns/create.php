<?php
/**
 * DMS - Distribution Management System
 * Sales Returns - Create Sales Return Voucher
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('returns_create');

$pageTitle = 'Create Sales Return Voucher';
$activeMenu = 'returns';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Returns', 'url' => 'index.php'],
    ['label' => 'New Return', 'url' => '']
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnDate    = sanitize_input($_POST['return_date'] ?? date('Y-m-d'));
    $customerId    = (int)($_POST['customer_id'] ?? 0);
    $conditionType = sanitize_input($_POST['condition_type'] ?? 'saleable');
    $reason        = sanitize_input($_POST['reason'] ?? '');
    $itemsData     = $_POST['items'] ?? [];

    if ($customerId <= 0) {
        $errors[] = "Please select a customer / retail shop.";
    }
    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "Please enter at least one return product item.";
    }

    $validItems = [];
    $totalAmount = 0.0;

    foreach ($itemsData as $idx => $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $ctnQty    = (float)($row['carton_qty'] ?? 0);
        $packQty   = (float)($row['pack_qty'] ?? 0);
        $pcsQty    = (float)($row['pcs_qty'] ?? 0);
        $qty       = (float)($row['quantity'] ?? 0);
        $rate      = (float)($row['rate'] ?? 0);
        $itemReason = sanitize_input($row['reason'] ?? $reason);

        if ($productId <= 0 || ($ctnQty <= 0 && $packQty <= 0 && $pcsQty <= 0 && $qty <= 0)) continue;

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);

        if ($ctnQty > 0 || $packQty > 0 || $pcsQty > 0) {
            $equivCartons = calculate_carton_equivalent($ctnQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
            $basePieces   = calculate_base_pieces($ctnQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
        } else {
            $equivCartons = $qty;
            $basePieces   = $qty * $ratios['pcs_per_ctn'];
            $bk = breakdown_base_pieces($basePieces, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
            $ctnQty  = $bk['cartons'];
            $packQty = $bk['packs'];
            $pcsQty  = $bk['pieces'];
        }

        $lineTotal = round($equivCartons * $rate, 2);
        $totalAmount += $lineTotal;

        $validItems[] = [
            'product_id'     => $productId,
            'unit_id'        => (int)$prod['base_unit_id'],
            'carton_qty'     => $ctnQty,
            'pack_qty'       => $packQty,
            'pcs_qty'        => $pcsQty,
            'quantity'       => $equivCartons,
            'base_pieces'    => $basePieces,
            'rate'           => $rate,
            'amount'         => $lineTotal,
            'reason'         => $itemReason,
            'condition_type' => $conditionType
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line with quantity greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $returnNumber = generate_document_number(PREFIX_RETURN, 'sales_returns', 'return_number');

            // 1. Insert sales_returns header
            $stmt = db()->prepare("
                INSERT INTO sales_returns (
                    return_number, invoice_id, customer_id, settlement_id, return_date, 
                    total_amount, reason, condition_type, status, created_by, created_at
                ) VALUES (
                    ?, NULL, ?, NULL, ?, 
                    ?, ?, ?, 'processed', ?, NOW()
                )
            ");
            $stmt->execute([
                $returnNumber, $customerId, $returnDate,
                $totalAmount, $reason, $conditionType, $userId
            ]);
            $returnId = (int)db()->lastInsertId();

            // 2. Insert items and update stock movements
            $stmtItem = db()->prepare("
                INSERT INTO sales_return_items (
                    sales_return_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, quantity, rate, amount, reason, condition_type, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");

            $movementType = 'sales_return_saleable';
            if ($conditionType === 'damaged') {
                $movementType = 'sales_return_damaged';
            }

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $returnId, $vi['product_id'], $vi['unit_id'],
                    $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['quantity'], $vi['rate'], $vi['amount'],
                    $vi['reason'], $vi['condition_type']
                ]);

                // Atomically update inventory with base atomic pieces
                record_stock_movement([
                    'product_id'     => $vi['product_id'],
                    'warehouse_id'   => 1,
                    'movement_type'  => $movementType,
                    'reference_type' => 'sales_return',
                    'reference_id'   => $returnId,
                    'qty_in'         => $vi['base_pieces'],
                    'qty_out'        => 0,
                    'unit_cost'      => $vi['rate'],
                    'remarks'        => "Sales return #{$returnNumber} ({$conditionType})",
                    'condition_type' => $conditionType
                ]);
            }

            // 3. Update customer balance (credit note)
            update_customer_balance($customerId, $totalAmount, 'decrease');

            // 4. Post to General Ledger: Debit Sales Returns (4030), Credit Accounts Receivable (1020)
            post_journal_entry([
                'entry_date'     => $returnDate,
                'reference_type' => 'sales_return',
                'reference_id'   => $returnId,
                'narration'      => "Sales Return #{$returnNumber} ({$conditionType})"
            ], [
                ['account_id' => ACC_RETURNS, 'debit' => $totalAmount, 'credit' => 0.00, 'description' => "Sales Return Voucher #{$returnNumber}"],
                ['account_id' => ACC_RECEIVABLES, 'debit' => 0.00, 'credit' => $totalAmount, 'party_type' => 'customer', 'party_id' => $customerId, 'description' => "Credit note for goods returned #{$returnNumber}"]
            ]);

            db()->commit();
            set_flash('success', "Sales return <strong>{$returnNumber}</strong> successfully recorded, stock updated, and credited to customer ledger!");
            redirect(APP_URL . '/modules/returns/view.php?id=' . $returnId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error saving return: " . $e->getMessage());
            $errors[] = "Error saving return: " . $e->getMessage();
        }
    }
}

$customers = db_fetch_all("SELECT id, customer_code, shop_name, address FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");
$products  = db_fetch_all("SELECT id, product_code, product_name, carton_qty, pack_qty, wholesale_rate FROM products WHERE is_active = 1 AND is_deleted = 0 ORDER BY product_name ASC");

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
                    <i class="bi bi-arrow-return-left me-2"></i>Create Sales Return Voucher
                </h4>
                <p class="text-muted small mb-0">Record goods returned by customers with condition tracking and credit note</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Returns
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

        <form method="POST" action="create.php" id="returnForm">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-info-circle me-1"></i> Customer & Return Details</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Customer / Retail Shop <span class="text-danger">*</span></label>
                            <select name="customer_id" class="form-select" required autofocus>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['customer_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Return Date <span class="text-danger">*</span></label>
                            <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Condition Type <span class="text-danger">*</span></label>
                            <select name="condition_type" class="form-select" required>
                                <option value="saleable">Saleable (Good stock to restock)</option>
                                <option value="damaged">Damaged / Leaked (Defective quarantine)</option>
                                <option value="expired">Expired (Write-off claim)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Reason / Category</label>
                            <input type="text" name="reason" class="form-control" placeholder="e.g. Broken packaging">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-box-seam me-1"></i> Returned Products</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-lg me-1"></i> Add Product Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="returnItemsTable">
                            <thead class="table-light text-muted small text-uppercase text-center">
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th style="width: 34%;" class="text-start ps-3">Product Description</th>
                                    <th style="width: 10%;">Ctn</th>
                                    <th style="width: 10%;">Pack</th>
                                    <th style="width: 10%;">Pcs</th>
                                    <th style="width: 14%;">Rate / Ctn (Rs.)</th>
                                    <th style="width: 18%;" class="text-end pe-3">Line Amount (Rs.)</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="returnItemsTbody">
                                <!-- Dynamic rows -->
                            </tbody>
                            <tfoot class="table-light fw-bold fs-6">
                                <tr>
                                    <td colspan="6" class="text-end text-uppercase">Total Return Value:</td>
                                    <td class="text-end pe-3 text-navy" id="totalReturnLabel">Rs. 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-2 text-end">
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Process Return & Issue Credit
                    </button>
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
                            data-rate="<?= $p['wholesale_rate'] ?>"
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
    const tbody = document.getElementById('returnItemsTbody');
    
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
    const btnRemove  = row.querySelector('.btn-remove-row');
    const ratioLabel = row.querySelector('.ratio-label');

    prodSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            rateInput.value = parseFloat(opt.getAttribute('data-rate') || 0).toFixed(2);
            const packsPerCtn = parseFloat(opt.getAttribute('data-ctn')) || 1;
            const pcsPerPack  = parseFloat(opt.getAttribute('data-pack')) || 1;
            if (pcsPerPack > 1) {
                ratioLabel.innerText = `1 Ctn = ${packsPerCtn} Pck × ${pcsPerPack} Pcs (${(packsPerCtn * pcsPerPack).toLocaleString()} Pcs)`;
            } else {
                ratioLabel.innerText = `1 Ctn = ${packsPerCtn} Pcs`;
            }
            if (!ctnInput.value && !packInput.value && !pcsInput.value) {
                ctnInput.value = 1;
            }
        } else {
            ratioLabel.innerText = '';
        }
        calculateRowTotal(row);
    });

    [ctnInput, packInput, pcsInput, rateInput].forEach(inp => {
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

    const totalCartons = ctn + (pack / packsPerCtn) + (pcs / (packsPerCtn * pcsPerPack));
    row.querySelector('.equiv-qty-input').value = totalCartons.toFixed(4);

    const total = totalCartons * rate;
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

        const totalCartons = ctn + (pack / packsPerCtn) + (pcs / (packsPerCtn * pcsPerPack));
        grand += (totalCartons * rate);
    });
    document.getElementById('totalReturnLabel').innerText = 'Rs. ' + grand.toLocaleString('en-US', {minimumFractionDigits: 2});
}
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        grand += (qty * rate);
    });
    document.getElementById('totalReturnLabel').innerText = 'Rs. ' + grand.toLocaleString('en-US', {minimumFractionDigits: 2});
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
