<?php
/**
 * DMS - Edit Product
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('products_edit');

$id = (int)($_GET['id'] ?? 0);
$product = db_fetch_one("SELECT * FROM products WHERE id = ? AND is_deleted = 0", [$id]);

if (!$product) {
    set_flash_message('danger', 'Product record not found.');
    redirect(APP_URL . '/modules/products/index.php');
}

$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 AND is_active = 1 ORDER BY category_name ASC");
$units = db_fetch_all("SELECT id, unit_name, symbol FROM units WHERE is_deleted = 0 AND is_active = 1 ORDER BY id ASC");
$brands = db_fetch_all("SELECT id, brand_name FROM brands WHERE is_deleted = 0 AND is_active = 1 ORDER BY brand_name ASC");
$suppliers = db_fetch_all("SELECT id, supplier_code, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productCode     = clean_input($_POST['product_code'] ?? '');
    $barcode         = clean_input($_POST['barcode'] ?? '');
    $productName     = clean_input($_POST['product_name'] ?? '');
    $categoryId      = (int)($_POST['category_id'] ?? 0);
    $supplierId      = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $brandId         = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
    $baseUnitId      = (int)($_POST['base_unit_id'] ?? 0);
    $purchaseRate       = (float)($_POST['purchase_rate'] ?? 0);
    $defaultSaleRate    = (float)($_POST['default_sale_rate'] ?? 0);
    $wholesaleRate      = (float)($_POST['wholesale_rate'] ?? 0);
    $retailRate         = (float)($_POST['retail_rate'] ?? 0);
    $cartonRate         = (float)($_POST['carton_rate'] ?? 0);
    $pieceRate          = (float)($_POST['piece_rate'] ?? 0);
    $purchaseCartonRate = (float)($_POST['purchase_carton_rate'] ?? 0);
    $purchasePieceRate  = (float)($_POST['purchase_piece_rate'] ?? 0);
    $retailCartonRate   = (float)($_POST['retail_carton_rate'] ?? 0);
    $retailPieceRate    = (float)($_POST['retail_piece_rate'] ?? 0);
    $cartonQty          = (float)($_POST['carton_qty'] ?? 1);
    $packQty            = (float)($_POST['pack_qty'] ?? 1);
    $minStockLevel      = (float)($_POST['min_stock_level'] ?? 0);
    $description        = clean_input($_POST['description'] ?? '');
    $isActive           = isset($_POST['is_active']) ? 1 : 0;

    $totalPcsPerCtn = max(1.0, ($cartonQty > 0 ? $cartonQty : 1.0) * ($packQty > 0 ? $packQty : 1.0));

    // Wholesale synchronization
    if ($cartonRate <= 0 && $wholesaleRate > 0) {
        $cartonRate = $wholesaleRate;
    }
    if ($cartonRate > 0 && $pieceRate <= 0) {
        $pieceRate = round($cartonRate / $totalPcsPerCtn, 2);
    } elseif ($pieceRate > 0 && $cartonRate <= 0) {
        $cartonRate = round($pieceRate * $totalPcsPerCtn, 2);
    }
    if ($wholesaleRate <= 0 && $cartonRate > 0) {
        $wholesaleRate = $cartonRate;
    }

    // Purchase cost synchronization
    if ($purchaseCartonRate <= 0 && $purchaseRate > 0) {
        $purchaseCartonRate = $purchaseRate;
    }
    if ($purchaseCartonRate > 0 && $purchasePieceRate <= 0) {
        $purchasePieceRate = round($purchaseCartonRate / $totalPcsPerCtn, 2);
    } elseif ($purchasePieceRate > 0 && $purchaseCartonRate <= 0) {
        $purchaseCartonRate = round($purchasePieceRate * $totalPcsPerCtn, 2);
    }
    if ($purchaseRate <= 0 && $purchaseCartonRate > 0) {
        $purchaseRate = $purchaseCartonRate;
    }

    // Retail MRP synchronization
    if ($retailCartonRate <= 0 && $retailRate > 0) {
        $retailCartonRate = $retailRate;
    }
    if ($retailCartonRate > 0 && $retailPieceRate <= 0) {
        $retailPieceRate = round($retailCartonRate / $totalPcsPerCtn, 2);
    } elseif ($retailPieceRate > 0 && $retailCartonRate <= 0) {
        $retailCartonRate = round($retailPieceRate * $totalPcsPerCtn, 2);
    }
    if ($retailRate <= 0 && $retailCartonRate > 0) {
        $retailRate = $retailCartonRate;
    }

    $validator = new Validator();
    $validator->required('product_code', $productCode)
              ->unique('product_code', $productCode, 'products', 'product_code', $id)
              ->required('product_name', $productName)
              ->required('category_id', $categoryId)
              ->exists('category_id', $categoryId, 'product_categories', 'id')
              ->required('base_unit_id', $baseUnitId)
              ->exists('base_unit_id', $baseUnitId, 'units', 'id')
              ->numeric('carton_qty', $cartonQty, 0.01);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE products 
                SET product_code = ?, barcode = ?, product_name = ?, category_id = ?, supplier_id = ?, brand_id = ?, base_unit_id = ?,
                    purchase_rate = ?, default_sale_rate = ?, wholesale_rate = ?, retail_rate = ?,
                    carton_rate = ?, piece_rate = ?, purchase_carton_rate = ?, purchase_piece_rate = ?, retail_carton_rate = ?, retail_piece_rate = ?,
                    carton_qty = ?, pack_qty = ?, min_stock_level = ?, description = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $productCode, $barcode, $productName, $categoryId, $supplierId, $brandId, $baseUnitId,
                $purchaseRate, $defaultSaleRate, $wholesaleRate, $retailRate,
                $cartonRate, $pieceRate, $purchaseCartonRate, $purchasePieceRate, $retailCartonRate, $retailPieceRate,
                $cartonQty, $packQty, $minStockLevel, $description, $isActive, $id
            ]);

            log_audit('update_product', 'products', $id, "Updated product: {$productName}");
            set_flash_message('success', "Product '{$productName}' updated successfully.");
            redirect(APP_URL . '/modules/products/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating product: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Product: ' . $product['product_name'] . ' — ' . APP_NAME;
$activeMenu = 'products';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Product</h5>
        <small class="text-muted">Update catalog details and rates for <?= e($product['product_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Catalog (ESC)
        </a>
    </div>
</div>

<form method="POST" action="<?= APP_URL ?>/modules/products/edit.php?id=<?= $product['id'] ?>">
    <div class="row g-3">
        <div class="col-md-7">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-info-circle me-1"></i> Product Information</span>
                </div>
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Product SKU / Code <span class="text-danger">*</span></label>
                            <input type="text" name="product_code" class="form-control form-control-sm" value="<?= e($product['product_code']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Barcode / EAN</label>
                            <input type="text" name="barcode" class="form-control form-control-sm" value="<?= e($product['barcode']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="product_name" class="form-control form-control-sm" value="<?= e($product['product_name']) ?>" required autofocus>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label small fw-semibold mb-0">Category <span class="text-danger">*</span></label>
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" onclick="openDrawer('drawer-quick-add-category')">
                                    <i class="bi bi-plus-circle me-1"></i>New Category
                                </button>
                            </div>
                            <select name="category_id" id="productCategoryId" class="form-select form-select-sm" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                        <?= e($cat['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Supplier / Company</label>
                            <select name="supplier_id" id="productSupplierId" class="form-select form-select-sm">
                                <option value="">-- Select Supplier Company --</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>" <?= (int)($product['supplier_id'] ?? 0) === (int)$sup['id'] ? 'selected' : '' ?>>
                                        <?= e($sup['supplier_name']) ?><?= !empty($sup['company_name']) ? ' (' . e($sup['company_name']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label small fw-semibold mb-0">Brand / Manufacturer</label>
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" onclick="openDrawer('drawer-quick-add-brand')">
                                    <i class="bi bi-plus-circle me-1"></i>New Brand
                                </button>
                            </div>
                            <select name="brand_id" id="productBrandId" class="form-select form-select-sm">
                                <option value="">-- Optional Brand --</option>
                                <?php foreach ($brands as $br): ?>
                                    <option value="<?= $br['id'] ?>" <?= (int)$product['brand_id'] === (int)$br['id'] ? 'selected' : '' ?>>
                                        <?= e($br['brand_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2"><?= e($product['description']) ?></textarea>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="prdActiveEdit" <?= $product['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="prdActiveEdit">Active in Catalog & Order Entry</label>
                    </div>
                </div>
            </div>

            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-box me-1"></i> Packaging Conversion</span>
                </div>
                <div class="card-dms-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Base Stock Unit <span class="text-danger">*</span></label>
                            <select name="base_unit_id" class="form-select form-select-sm" required>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= (int)$product['base_unit_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                                        <?= e($u['unit_name']) ?> (<?= e($u['symbol']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-1">Base accounting unit for stock balances</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Carton Packing (1 Ctn = ? Packs)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" name="carton_qty" id="input_carton_qty" class="form-control" value="<?= e($product['carton_qty']) ?>" required oninput="updateConversionPreview()">
                                <span class="input-group-text">Packs</span>
                            </div>
                            <small class="text-muted d-block mt-1">Number of packs per carton (e.g. 10)</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Pack Packing (1 Pack = ? Pieces)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" name="pack_qty" id="input_pack_qty" class="form-control" value="<?= e($product['pack_qty']) ?>" oninput="updateConversionPreview()">
                                <span class="input-group-text">Pieces</span>
                            </div>
                            <small class="text-muted d-block mt-1">Number of pieces per pack (e.g. 200)</small>
                        </div>
                    </div>
                    <?php 
                    $currPacks = (float)$product['carton_qty'] > 0 ? (float)$product['carton_qty'] : 1;
                    $currPcs = (float)$product['pack_qty'] > 0 ? (float)$product['pack_qty'] : 1;
                    $currTotalPcs = $currPacks * $currPcs;
                    ?>
                    <div class="alert alert-info py-1 px-3 mt-2 mb-0 small d-flex align-items-center" id="conversionPreviewBox">
                        <i class="bi bi-info-circle-fill text-primary me-2"></i>
                        <span><strong>Conversion:</strong> 1 Carton = <strong id="previewPacks"><?= number_format($currPacks) ?></strong> Packs = <strong id="previewPieces"><?= number_format($currTotalPcs) ?></strong> Pieces</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-currency-dollar me-1"></i> Rates & Thresholds</span>
                </div>
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-primary">Wholesale / Selling Rate <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="text-muted small" style="font-size:0.75rem;">Carton Rate (Rs. / Ctn)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                                    <input type="number" step="0.01" name="carton_rate" id="input_carton_rate" class="form-control fw-bold" value="<?= e($product['carton_rate'] ?? $product['wholesale_rate']) ?>" required oninput="syncRatesFromCarton('wholesale')">
                                </div>
                                <input type="hidden" name="wholesale_rate" id="hidden_wholesale_rate" value="<?= e($product['wholesale_rate']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="text-muted small" style="font-size:0.75rem;">Piece Rate (Rs. / Pc)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                                    <input type="number" step="0.01" name="piece_rate" id="input_piece_rate" class="form-control" value="<?= e($product['piece_rate'] ?? '') ?>" oninput="syncRatesFromPiece('wholesale')">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Purchase / Cost Rate</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="text-muted small" style="font-size:0.75rem;">Purchase Ctn Rate</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                                    <input type="number" step="0.01" name="purchase_carton_rate" id="input_purchase_carton_rate" class="form-control" value="<?= e($product['purchase_carton_rate'] ?? $product['purchase_rate']) ?>" oninput="syncRatesFromCarton('purchase')">
                                </div>
                                <input type="hidden" name="purchase_rate" id="hidden_purchase_rate" value="<?= e($product['purchase_rate']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="text-muted small" style="font-size:0.75rem;">Purchase Pc Rate</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                                    <input type="number" step="0.01" name="purchase_piece_rate" id="input_purchase_piece_rate" class="form-control" value="<?= e($product['purchase_piece_rate'] ?? '') ?>" oninput="syncRatesFromPiece('purchase')">
                                </div>
                            </div>
                        </div>
                        <small class="text-muted" style="font-size: 0.72rem;">Cost used for purchase orders and gross profit calculation</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Printed MRP / Retail Rate</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="text-muted small" style="font-size:0.75rem;">Retail Ctn Rate</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                                    <input type="number" step="0.01" name="retail_carton_rate" id="input_retail_carton_rate" class="form-control" value="<?= e($product['retail_carton_rate'] ?? $product['retail_rate']) ?>" oninput="syncRatesFromCarton('retail')">
                                </div>
                                <input type="hidden" name="retail_rate" id="hidden_retail_rate" value="<?= e($product['retail_rate']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="text-muted small" style="font-size:0.75rem;">Retail Pc Rate</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                                    <input type="number" step="0.01" name="retail_piece_rate" id="input_retail_piece_rate" class="form-control" value="<?= e($product['retail_piece_rate'] ?? '') ?>" oninput="syncRatesFromPiece('retail')">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Default Counter Rate</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="default_sale_rate" class="form-control" value="<?= e($product['default_sale_rate']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Minimum Stock Alert Level</label>
                        <input type="number" step="0.01" name="min_stock_level" class="form-control form-control-sm" value="<?= e($product['min_stock_level']) ?>">
                        <small class="text-muted">Triggers low stock alerts on the dashboard</small>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <button type="submit" class="btn btn-primary w-100 btn-save-dms">
                        <i class="bi bi-check2 me-1"></i> Update Product (F9)
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Non-Modal Slide Drawer: Quick Add Category -->
<div id="drawer-quick-add-category" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-tags me-2 text-primary"></i> Quick Add Category</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form id="form-quick-add-cat" onsubmit="handleQuickAddCategory(event)">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                <input type="text" name="category_name" id="quickCatName" class="form-control form-control-sm" placeholder="e.g. Snacks, Confectionery, Spices" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Category Code</label>
                <input type="text" name="category_code" id="quickCatCode" class="form-control form-control-sm" placeholder="Auto-generated if left blank">
                <div class="form-text">Leave blank to auto-generate (e.g. CAT-005).</div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea name="description" id="quickCatDesc" class="form-control form-control-sm" rows="3" placeholder="Category notes or line summary..."></textarea>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary" id="btnSaveQuickCat"><i class="bi bi-check2 me-1"></i> Save & Select</button>
        </div>
    </form>
</div>

<!-- Non-Modal Slide Drawer: Quick Add Brand -->
<div id="drawer-quick-add-brand" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-bookmark-star me-2 text-primary"></i> Quick Add Brand</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form id="form-quick-add-brd" onsubmit="handleQuickAddBrand(event)">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Brand / Manufacturer Name <span class="text-danger">*</span></label>
                <input type="text" name="brand_name" id="quickBrandName" class="form-control form-control-sm" placeholder="e.g. National Foods, Mitchells, Lays" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Brand Code</label>
                <input type="text" name="brand_code" id="quickBrandCode" class="form-control form-control-sm" placeholder="Auto-generated if left blank">
                <div class="form-text">Leave blank to auto-generate (e.g. BRD-005).</div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea name="description" id="quickBrandDesc" class="form-control form-control-sm" rows="3" placeholder="Brand notes or manufacturer details..."></textarea>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary" id="btnSaveQuickBrd"><i class="bi bi-check2 me-1"></i> Save & Select</button>
        </div>
    </form>
</div>

<script>
async function handleQuickAddCategory(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveQuickCat');
    const nameInput = document.getElementById('quickCatName');
    const codeInput = document.getElementById('quickCatCode');
    const descInput = document.getElementById('quickCatDesc');
    const catName = nameInput.value.trim();

    if (!catName) {
        showToast('Category name is required.', 'danger');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('category_name', catName);
    formData.append('category_code', codeInput.value.trim());
    formData.append('description', descInput.value.trim());

    try {
        const res = await dmsFetch(window.DMS_APP_URL + '/modules/categories/create.php', {
            method: 'POST',
            body: formData
        });

        if (res && res.success) {
            const select = document.getElementById('productCategoryId');
            if (select) {
                const opt = new Option(res.data.category_name, res.data.id, true, true);
                select.add(opt);
                select.value = res.data.id;
            }
            nameInput.value = '';
            codeInput.value = '';
            descInput.value = '';
            closeDrawer();
            showToast(res.message || 'Category added successfully.', 'success');
        }
    } catch (err) {
        showToast('Error saving category: ' + err.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Save & Select';
    }
}

async function handleQuickAddBrand(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveQuickBrd');
    const nameInput = document.getElementById('quickBrandName');
    const codeInput = document.getElementById('quickBrandCode');
    const descInput = document.getElementById('quickBrandDesc');
    const brandName = nameInput.value.trim();

    if (!brandName) {
        showToast('Brand name is required.', 'danger');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('brand_name', brandName);
    formData.append('brand_code', codeInput.value.trim());
    formData.append('description', descInput.value.trim());

    try {
        const res = await dmsFetch(window.DMS_APP_URL + '/modules/brands/create.php', {
            method: 'POST',
            body: formData
        });

        if (res && res.success) {
            const select = document.getElementById('productBrandId');
            if (select) {
                const opt = new Option(res.data.brand_name, res.data.id, true, true);
                select.add(opt);
                select.value = res.data.id;
            }
            nameInput.value = '';
            codeInput.value = '';
            descInput.value = '';
            closeDrawer();
            showToast(res.message || 'Brand added successfully.', 'success');
        }
    } catch (err) {
        showToast('Error saving brand: ' + err.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Save & Select';
    }
}

function updateConversionPreview() {
    const packsInput = document.getElementById('input_carton_qty');
    const pcsInput = document.getElementById('input_pack_qty');
    const prevPacks = document.getElementById('previewPacks');
    const prevPieces = document.getElementById('previewPieces');

    const packs = parseFloat(packsInput ? packsInput.value : 10) || 1;
    const pcs = parseFloat(pcsInput ? pcsInput.value : 1) || 1;
    const totalPcs = Math.max(1, packs * pcs);

    if (prevPacks) prevPacks.textContent = packs.toLocaleString();
    if (prevPieces) prevPieces.textContent = totalPcs.toLocaleString();
}

function syncRatesFromCarton(type) {
    const packs = parseFloat(document.getElementById('input_carton_qty')?.value) || 1;
    const pcs = parseFloat(document.getElementById('input_pack_qty')?.value) || 1;
    const totalPcs = Math.max(1, packs * pcs);

    if (type === 'wholesale') {
        const ctnInput = document.getElementById('input_carton_rate');
        const pcInput = document.getElementById('input_piece_rate');
        const hid = document.getElementById('hidden_wholesale_rate');
        const val = parseFloat(ctnInput?.value) || 0;
        if (hid) hid.value = val;
        if (val > 0 && pcInput) pcInput.value = (val / totalPcs).toFixed(2);
    } else if (type === 'purchase') {
        const ctnInput = document.getElementById('input_purchase_carton_rate');
        const pcInput = document.getElementById('input_purchase_piece_rate');
        const hid = document.getElementById('hidden_purchase_rate');
        const val = parseFloat(ctnInput?.value) || 0;
        if (hid) hid.value = val;
        if (val > 0 && pcInput) pcInput.value = (val / totalPcs).toFixed(2);
    } else if (type === 'retail') {
        const ctnInput = document.getElementById('input_retail_carton_rate');
        const pcInput = document.getElementById('input_retail_piece_rate');
        const hid = document.getElementById('hidden_retail_rate');
        const val = parseFloat(ctnInput?.value) || 0;
        if (hid) hid.value = val;
        if (val > 0 && pcInput) pcInput.value = (val / totalPcs).toFixed(2);
    }
}

function syncRatesFromPiece(type) {
    const packs = parseFloat(document.getElementById('input_carton_qty')?.value) || 1;
    const pcs = parseFloat(document.getElementById('input_pack_qty')?.value) || 1;
    const totalPcs = Math.max(1, packs * pcs);

    if (type === 'wholesale') {
        const ctnInput = document.getElementById('input_carton_rate');
        const pcInput = document.getElementById('input_piece_rate');
        const hid = document.getElementById('hidden_wholesale_rate');
        const val = parseFloat(pcInput?.value) || 0;
        if (val > 0 && ctnInput) {
            ctnInput.value = (val * totalPcs).toFixed(2);
            if (hid) hid.value = ctnInput.value;
        }
    } else if (type === 'purchase') {
        const ctnInput = document.getElementById('input_purchase_carton_rate');
        const pcInput = document.getElementById('input_purchase_piece_rate');
        const hid = document.getElementById('hidden_purchase_rate');
        const val = parseFloat(pcInput?.value) || 0;
        if (val > 0 && ctnInput) {
            ctnInput.value = (val * totalPcs).toFixed(2);
            if (hid) hid.value = ctnInput.value;
        }
    } else if (type === 'retail') {
        const ctnInput = document.getElementById('input_retail_carton_rate');
        const pcInput = document.getElementById('input_retail_piece_rate');
        const hid = document.getElementById('hidden_retail_rate');
        const val = parseFloat(pcInput?.value) || 0;
        if (val > 0 && ctnInput) {
            ctnInput.value = (val * totalPcs).toFixed(2);
            if (hid) hid.value = ctnInput.value;
        }
    }
}
document.addEventListener('DOMContentLoaded', updateConversionPreview);
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
