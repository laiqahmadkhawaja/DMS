<?php
/**
 * DMS - Distribution Management System
 * Inventory - Physical Stock Audit & Adjustment (Stock Take)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('inventory_adjust');

$pageTitle = 'Physical Stock Adjustment';
$activeMenu = 'inventory';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Inventory', 'url' => 'index.php'],
    ['label' => 'Stock Adjustment', 'url' => '']
];

$preProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adjDate     = sanitize_input($_POST['adjustment_date'] ?? date('Y-m-d'));
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $reason      = sanitize_input($_POST['reason'] ?? '');
    $itemsData   = $_POST['items'] ?? [];

    if (empty($reason)) {
        $errors[] = "Please provide an audit reason for the adjustment.";
    }
    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "Please enter at least one product adjustment.";
    }

    $validItems = [];
    $totalAdjValue = 0.0;

    foreach ($itemsData as $idx => $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $type      = sanitize_input($row['adjustment_type'] ?? 'subtract'); // 'add' or 'subtract'
        $condition = sanitize_input($row['condition_type'] ?? 'saleable'); // 'saleable', 'damaged', 'expired'
        $ctnQty    = (float)($row['carton_qty'] ?? 0);
        $packQty   = (float)($row['pack_qty'] ?? 0);
        $pcsQty    = (float)($row['pcs_qty'] ?? 0);
        $rawQty    = (float)($row['quantity'] ?? 0);
        $remarksRow = sanitize_input($row['remarks'] ?? $reason);

        if ($productId <= 0 || ($ctnQty <= 0 && $packQty <= 0 && $pcsQty <= 0 && $rawQty <= 0)) continue;

        $prod = db_fetch_one("
            SELECT p.*, COALESCE(u.symbol, 'Ctn') as major_unit_symbol 
            FROM products p 
            LEFT JOIN units u ON p.base_unit_id = u.id 
            WHERE p.id = ?
        ", [$productId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        if ($ctnQty > 0 || $packQty > 0 || $pcsQty > 0) {
            $basePieces = calculate_base_pieces($ctnQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
        } else {
            $basePieces = $rawQty;
            $bk = breakdown_base_pieces($basePieces, $ratios['packs_per_ctn'], $ratios['pcs_per_pack'], $prod['major_unit_symbol']);
            $ctnQty  = $bk['cartons'];
            $packQty = $bk['packs'];
            $pcsQty  = $bk['pieces'];
        }

        if ($basePieces <= 0) continue;

        $breakdown = breakdown_base_pieces($basePieces, $ratios['packs_per_ctn'], $ratios['pcs_per_pack'], $prod['major_unit_symbol']);

        $pcsPerCtn = (float)($ratios['pcs_per_ctn'] ?? 1);
        if ($pcsPerCtn <= 0) $pcsPerCtn = 1.0;
        $pieceCost = (float)($prod['purchase_piece_rate'] ?? 0) > 0 
            ? (float)$prod['purchase_piece_rate'] 
            : round((float)($prod['purchase_rate'] ?? 0) / $pcsPerCtn, 6);
        $lineValue = round($basePieces * $pieceCost, 2);
        $totalAdjValue += $lineValue;

        $validItems[] = [
            'product_id'      => $productId,
            'carton_qty'      => $ctnQty,
            'pack_qty'        => $packQty,
            'pcs_qty'         => $pcsQty,
            'adjustment_type' => $type,
            'condition_type'  => $condition,
            'quantity'        => $basePieces,
            'unit_cost'       => $pieceCost,
            'total_amount'    => $lineValue,
            'remarks'         => $remarksRow,
            'breakdown_text'  => $breakdown['formatted']
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please enter valid quantities greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $adjNumber = generate_document_number(PREFIX_ADJUSTMENT, 'stock_adjustments', 'adjustment_number');

            // 1. Insert stock_adjustments header
            $stmtAdj = db()->prepare("
                INSERT INTO stock_adjustments (
                    adjustment_number, warehouse_id, adjustment_date, reason, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, 'approved', ?, NOW()
                )
            ");
            $stmtAdj->execute([$adjNumber, $warehouseId, $adjDate, $reason, $userId]);
            $adjId = (int)db()->lastInsertId();

            // 2. Insert items & record stock movements
            $stmtItem = db()->prepare("
                INSERT INTO stock_adjustment_items (
                    stock_adjustment_id, product_id, carton_qty, pack_qty, pcs_qty, 
                    adjustment_type, condition_type, quantity, unit_cost, total_amount, remarks, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, NOW()
                )
            ");

            $netAdditionValue = 0.0;
            $netSubtractionValue = 0.0;

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $adjId, $vi['product_id'], $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['adjustment_type'], $vi['condition_type'],
                    $vi['quantity'], $vi['unit_cost'], $vi['total_amount'], $vi['remarks']
                ]);

                if ($vi['adjustment_type'] === 'add') {
                    $netAdditionValue += $vi['total_amount'];
                    record_stock_movement([
                        'product_id'     => $vi['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => 'stock_adjustment_add',
                        'reference_type' => 'adjustment',
                        'reference_id'   => $adjId,
                        'qty_in'         => $vi['quantity'],
                        'qty_out'        => 0,
                        'unit_cost'      => $vi['unit_cost'],
                        'total_cost'     => $vi['total_amount'],
                        'remarks'        => "Stock surplus #{$adjNumber} [{$vi['breakdown_text']}] ({$vi['remarks']})",
                        'condition_type' => $vi['condition_type']
                    ]);
                } else {
                    $netSubtractionValue += $vi['total_amount'];
                    record_stock_movement([
                        'product_id'     => $vi['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => 'stock_adjustment_sub',
                        'reference_type' => 'adjustment',
                        'reference_id'   => $adjId,
                        'qty_in'         => 0,
                        'qty_out'        => $vi['quantity'],
                        'unit_cost'      => $vi['unit_cost'],
                        'total_cost'     => $vi['total_amount'],
                        'remarks'        => "Stock write-off #{$adjNumber} [{$vi['breakdown_text']}] ({$vi['remarks']})",
                        'condition_type' => $vi['condition_type']
                    ]);
                }
            }

            // 3. Post to General Ledger
            if ($netSubtractionValue > 0) {
                // Shortage/Loss: Debit COGS (5010), Credit Inventory Asset (1030)
                post_journal_entry([
                    'entry_date'     => $adjDate,
                    'reference_type' => 'manual_journal',
                    'reference_id'   => $adjId,
                    'narration'      => "Stock adjustment shortage write-off #{$adjNumber}"
                ], [
                    ['account_id' => ACC_COGS, 'debit' => $netSubtractionValue, 'credit' => 0.00, 'description' => "Stock loss write-off #{$adjNumber}"],
                    ['account_id' => ACC_INVENTORY, 'debit' => 0.00, 'credit' => $netSubtractionValue, 'description' => "Inventory reduced - #{$adjNumber}"]
                ]);
            }

            if ($netAdditionValue > 0) {
                // Surplus/Gain: Debit Inventory Asset (1030), Credit COGS (5010)
                post_journal_entry([
                    'entry_date'     => $adjDate,
                    'reference_type' => 'manual_journal',
                    'reference_id'   => $adjId,
                    'narration'      => "Stock adjustment surplus gain #{$adjNumber}"
                ], [
                    ['account_id' => ACC_INVENTORY, 'debit' => $netAdditionValue, 'credit' => 0.00, 'description' => "Inventory added - #{$adjNumber}"],
                    ['account_id' => ACC_COGS, 'debit' => 0.00, 'credit' => $netAdditionValue, 'description' => "Stock gain adjustment #{$adjNumber}"]
                ]);
            }

            db()->commit();
            set_flash('success', "Stock adjustment <strong>{$adjNumber}</strong> successfully applied and ledger updated!");
            redirect(APP_URL . '/modules/inventory/index.php');

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error saving stock adjustment: " . $e->getMessage());
            $errors[] = "Error processing adjustment: " . $e->getMessage();
        }
    }
}

$warehouses = db_fetch_all("SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC");
$products   = db_fetch_all("
    SELECT 
        p.id, p.product_code, p.product_name, p.purchase_rate as cost_price, 
        p.carton_qty, p.pack_qty, p.base_unit_id,
        COALESCE(u.symbol, 'Ctn') as major_unit_symbol
    FROM products p 
    LEFT JOIN units u ON p.base_unit_id = u.id
    WHERE p.is_active = 1 AND p.is_deleted = 0 
    ORDER BY p.product_name ASC
");

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
                    <i class="bi bi-sliders me-2"></i>Physical Stock Audit & Adjustment
                </h4>
                <p class="text-muted small mb-0">Record physical stock count audits, breakage write-offs, and warehouse reconciliations</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Inventory
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

        <form method="POST" action="adjust.php" id="adjustForm">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-info-circle me-1"></i> Audit Details</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Adjustment Date <span class="text-danger">*</span></label>
                            <input type="date" name="adjustment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Warehouse <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Audit Reason / Justification <span class="text-danger">*</span></label>
                            <input type="text" name="reason" class="form-control" placeholder="e.g. Monthly physical stock take variance / Warehouse packaging damage" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-box-seam me-1"></i> Products to Adjust</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-lg me-1"></i> Add Product Line
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="adjItemsTable" style="font-size: 13px;">
                            <thead class="table-light text-muted small text-uppercase text-center">
                                <tr>
                                    <th style="width: 35px;">#</th>
                                    <th style="width: 28%;" class="text-start ps-3">Product Description</th>
                                    <th style="width: 12%;">Type</th>
                                    <th style="width: 10%;">Condition</th>
                                    <th style="width: 9%;">Carton (Ctn)</th>
                                    <th style="width: 8%;">Pack (Pck)</th>
                                    <th style="width: 8%;">Pcs</th>
                                    <th style="width: 10%;">Total Units</th>
                                    <th style="width: 12%;">Notes / Reason</th>
                                    <th style="width: 35px;"></th>
                                </tr>
                            </thead>
                            <tbody id="adjItemsTbody">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-2 text-end">
                    <button type="submit" class="btn btn-warning text-dark px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Apply Physical Adjustment
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
                            data-ctn="<?= (float)$p['carton_qty'] > 0 ? (float)$p['carton_qty'] : 1 ?>" 
                            data-pack="<?= (float)$p['pack_qty'] > 0 ? (float)$p['pack_qty'] : 1 ?>"
                            data-unit="<?= htmlspecialchars($p['major_unit_symbol'] ?: 'Ctn') ?>"
                            <?= ($preProductId === (int)$p['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="packaging-hint text-muted mt-1" style="font-size: 0.72rem;"></div>
        </td>
        <td>
            <select name="items[INDEX][adjustment_type]" class="form-select form-select-sm fw-bold">
                <option value="subtract" class="text-danger">Subtract (Loss / Breakage)</option>
                <option value="add" class="text-success">Add (Surplus / Found)</option>
            </select>
        </td>
        <td>
            <select name="items[INDEX][condition_type]" class="form-select form-select-sm">
                <option value="saleable">Saleable</option>
                <option value="damaged">Damaged</option>
                <option value="expired">Expired</option>
            </select>
        </td>
        <td>
            <input type="number" name="items[INDEX][carton_qty]" class="form-control form-control-sm text-center fw-bold ctn-input" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][pack_qty]" class="form-control form-control-sm text-center fw-bold pack-input" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][pcs_qty]" class="form-control form-control-sm text-center fw-bold pcs-input" step="1" min="0" placeholder="0">
        </td>
        <td>
            <input type="number" name="items[INDEX][quantity]" class="form-control form-control-sm text-center fw-bold bg-light total-units-input" step="1" min="1" placeholder="0" required>
        </td>
        <td>
            <input type="text" name="items[INDEX][remarks]" class="form-control form-control-sm" placeholder="e.g. Broken in transit">
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
    const tbody = document.getElementById('adjItemsTbody');
    
    tbody.insertAdjacentHTML('beforeend', html);
    const newRow = tbody.lastElementChild;
    rowIndex++;

    attachRowEvents(newRow);
    renumberRows();
}

function attachRowEvents(row) {
    const btnRemove = row.querySelector('.btn-remove-row');
    btnRemove.addEventListener('click', function() {
        if (document.querySelectorAll('.item-row').length > 1) {
            row.remove();
            renumberRows();
        }
    });

    const prodSelect = row.querySelector('.product-select');
    const ctnInput   = row.querySelector('.ctn-input');
    const packInput  = row.querySelector('.pack-input');
    const pcsInput   = row.querySelector('.pcs-input');
    const unitsInput = row.querySelector('.total-units-input');
    const hintDiv    = row.querySelector('.packaging-hint');

    function updateRatios() {
        const opt = prodSelect.options[prodSelect.selectedIndex];
        if (!opt || !opt.value) {
            hintDiv.innerHTML = '';
            return;
        }
        const ctnRatio  = parseFloat(opt.dataset.ctn) || 1;
        const packRatio = parseFloat(opt.dataset.pack) || 1;
        const unitName  = opt.dataset.unit || 'Ctn';
        const pcsPerCtn = ctnRatio * packRatio;

        if (packRatio > 1) {
            hintDiv.innerHTML = `<span class="badge bg-light text-secondary border">1 ${unitName} = ${ctnRatio} Pck x ${packRatio} Pcs (${pcsPerCtn} units)</span>`;
        } else {
            hintDiv.innerHTML = `<span class="badge bg-light text-secondary border">1 ${unitName} = ${ctnRatio} units</span>`;
        }
        calcFromBreakdown();
    }

    function calcFromBreakdown() {
        const opt = prodSelect.options[prodSelect.selectedIndex];
        if (!opt || !opt.value) return;

        const ctnRatio  = parseFloat(opt.dataset.ctn) || 1;
        const packRatio = parseFloat(opt.dataset.pack) || 1;

        const ctn  = parseFloat(ctnInput.value) || 0;
        const pack = parseFloat(packInput.value) || 0;
        const pcs  = parseFloat(pcsInput.value) || 0;

        const totalUnits = Math.round((ctn * ctnRatio * packRatio) + (pack * packRatio) + pcs);
        unitsInput.value = totalUnits > 0 ? totalUnits : '';
    }

    function calcFromUnits() {
        const opt = prodSelect.options[prodSelect.selectedIndex];
        if (!opt || !opt.value) return;

        const ctnRatio  = parseFloat(opt.dataset.ctn) || 1;
        const packRatio = parseFloat(opt.dataset.pack) || 1;
        const pcsPerCtn = ctnRatio * packRatio;

        const totalUnits = parseFloat(unitsInput.value) || 0;
        if (totalUnits <= 0) {
            ctnInput.value = '';
            packInput.value = '';
            pcsInput.value = '';
            return;
        }

        const ctns = Math.floor(totalUnits / pcsPerCtn);
        const rem = totalUnits % pcsPerCtn;
        const packs = Math.floor(rem / packRatio);
        const pcs = rem % packRatio;

        ctnInput.value = ctns > 0 ? ctns : '';
        packInput.value = packs > 0 ? packs : '';
        pcsInput.value = pcs > 0 ? pcs : '';
    }

    prodSelect.addEventListener('change', updateRatios);
    ctnInput.addEventListener('input', calcFromBreakdown);
    packInput.addEventListener('input', calcFromBreakdown);
    pcsInput.addEventListener('input', calcFromBreakdown);
    unitsInput.addEventListener('input', calcFromUnits);

    if (prodSelect.value) {
        updateRatios();
    }
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
