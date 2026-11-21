<?php
/**
 * DMS - Add New Product
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('products_create');

$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 AND is_active = 1 ORDER BY category_name ASC");
$units = db_fetch_all("SELECT id, unit_name, symbol FROM units WHERE is_deleted = 0 AND is_active = 1 ORDER BY id ASC");
$brands = db_fetch_all("SELECT id, brand_name FROM brands WHERE is_deleted = 0 AND is_active = 1 ORDER BY brand_name ASC");
$suppliers = db_fetch_all("SELECT id, supplier_code, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");

// Auto generated code suggestion
$nextCode = 'PRD-' . sprintf('%04d', (int)db_fetch_val("SELECT COUNT(*) FROM products") + 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productCode     = clean_input($_POST['product_code'] ?? '');
    $barcode         = clean_input($_POST['barcode'] ?? '');
    $productName     = clean_input($_POST['product_name'] ?? '');
    $categoryId      = (int)($_POST['category_id'] ?? 0);
    $supplierId      = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $brandId         = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
    $baseUnitId      = (int)($_POST['base_unit_id'] ?? 0);
    $purchaseRate    = (float)($_POST['purchase_rate'] ?? 0);
    $defaultSaleRate = (float)($_POST['default_sale_rate'] ?? 0);
    $wholesaleRate   = (float)($_POST['wholesale_rate'] ?? 0);
    $retailRate      = (float)($_POST['retail_rate'] ?? 0);
    $cartonQty       = (float)($_POST['carton_qty'] ?? 1);
    $packQty         = (float)($_POST['pack_qty'] ?? 1);
    $minStockLevel   = (float)($_POST['min_stock_level'] ?? 0);
    $description     = clean_input($_POST['description'] ?? '');
    $isActive        = isset($_POST['is_active']) ? 1 : 0;
    $openingStock    = (float)($_POST['opening_stock'] ?? 0);

    $validator = new Validator();
    $validator->required('product_code', $productCode)
              ->unique('product_code', $productCode, 'products', 'product_code')
              ->required('product_name', $productName)
              ->required('category_id', $categoryId)
              ->exists('category_id', $categoryId, 'product_categories', 'id')
              ->required('base_unit_id', $baseUnitId)
              ->exists('base_unit_id', $baseUnitId, 'units', 'id')
              ->numeric('purchase_rate', $purchaseRate, 0)
              ->numeric('wholesale_rate', $wholesaleRate, 0)
              ->numeric('carton_qty', $cartonQty, 0.01);

    if ($validator->passes()) {
        try {
            db_transaction(function ($pdo) use (
                $productCode, $barcode, $productName, $categoryId, $supplierId, $brandId, $baseUnitId,
                $purchaseRate, $defaultSaleRate, $wholesaleRate, $retailRate,
                $cartonQty, $packQty, $minStockLevel, $description, $isActive, $openingStock
            ) {
                $stmt = $pdo->prepare("
                    INSERT INTO products (
                        product_code, barcode, product_name, category_id, supplier_id, brand_id, base_unit_id,
                        purchase_rate, default_sale_rate, wholesale_rate, retail_rate,
                        carton_qty, pack_qty, min_stock_level, description, is_active, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $productCode, $barcode, $productName, $categoryId, $supplierId, $brandId, $baseUnitId,
                    $purchaseRate, $defaultSaleRate, $wholesaleRate, $retailRate,
                    $cartonQty, $packQty, $minStockLevel, $description, $isActive
                ]);
                $productId = (int)$pdo->lastInsertId();

                // If opening stock is provided, record atomic stock ledger movement
                if ($openingStock > 0) {
                    record_stock_movement([
                        'product_id'     => $productId,
                        'warehouse_id'   => 1,
                        'movement_type'  => 'opening_stock',
                        'reference_type' => 'opening',
                        'reference_id'   => $productId,
                        'qty_in'         => $openingStock,
                        'qty_out'        => 0,
                        'unit_cost'      => $purchaseRate,
                        'remarks'        => 'Initial inventory opening balance'
                    ]);
                }

                log_audit('create_product', 'products', $productId, "Created product: {$productName} (SKU: {$productCode})");
            });

            set_flash_message('success', "Product '{$productName}' registered successfully.");
            redirect(APP_URL . '/modules/products/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', "Failed to create product: " . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Add New Product — ' . APP_NAME;
$activeMenu = 'products';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-box-seam me-2 text-primary"></i>Register New Product</h5>
        <small class="text-muted">Add new item with packaging ratios, rates, and optional opening stock</small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Catalog (ESC)
        </a>
    </div>
</div>

<form method="POST" action="<?= APP_URL ?>/modules/products/create.php">
    <div class="row g-3">
        <!-- Left: Product Identity & Classification -->
        <div class="col-md-7">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-info-circle me-1"></i> Product Basic Information</span>
                </div>
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Product SKU / Code <span class="text-danger">*</span></label>
                            <input type="text" name="product_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Barcode / EAN</label>
                            <input type="text" name="barcode" class="form-control form-control-sm" placeholder="Scan or enter barcode">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="product_name" class="form-control form-control-sm" placeholder="e.g. Nimko Daal Moth (1*36), Zoro Ring Puff BBQ" required autofocus>
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
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= e($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Supplier / Company</label>
                            <select name="supplier_id" id="productSupplierId" class="form-select form-select-sm">
                                <option value="">-- Select Supplier Company --</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>">
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
                                    <option value="<?= $br['id'] ?>"><?= e($br['brand_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description / Flavors / Weight</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="e.g. 50g packet, BBQ flavor..."></textarea>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="prdActive" checked>
                        <label class="form-check-label small" for="prdActive">Product Active in Catalog & Order Entry</label>
                    </div>
                </div>
            </div>

            <!-- Packaging & Conversion Ratios -->
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-box me-1"></i> Packaging Units & Conversion Ratios</span>
                </div>
                <div class="card-dms-body">
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Base Stock Unit <span class="text-danger">*</span></label>
                            <select name="base_unit_id" class="form-select form-select-sm" required>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $u['symbol'] === 'Pcs' || $u['unit_code'] === 'PCS' ? 'selected' : '' ?>>
                                        <?= e($u['unit_name']) ?> (<?= e($u['symbol']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-1">Base accounting unit for stock balances</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Carton Packing (1 Ctn = ? Packs)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" name="carton_qty" id="input_carton_qty" class="form-control" value="10.00" required oninput="updateConversionPreview()">
                                <span class="input-group-text">Packs</span>
                            </div>
                            <small class="text-muted d-block mt-1">Number of packs per carton (e.g. 10)</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Pack Packing (1 Pack = ? Pieces)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" name="pack_qty" id="input_pack_qty" class="form-control" value="200.00" oninput="updateConversionPreview()">
                                <span class="input-group-text">Pieces</span>
                            </div>
                            <small class="text-muted d-block mt-1">Number of pieces per pack (e.g. 200)</small>
                        </div>
                    </div>
                    <div class="alert alert-info py-1 px-3 mt-2 mb-0 small d-flex align-items-center" id="conversionPreviewBox">
                        <i class="bi bi-info-circle-fill text-primary me-2"></i>
                        <span><strong>Conversion:</strong> 1 Carton = <strong id="previewPacks">10</strong> Packs = <strong id="previewPieces">2,000</strong> Pieces</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Pricing, Stock & Reorder Levels -->
        <div class="col-md-5">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-currency-dollar me-1"></i> Price Tiers & Rates</span>
                </div>
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Wholesale Rate (Default Bill Rate) <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="wholesale_rate" class="form-control fw-bold" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Default Retail / Counter Rate</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="default_sale_rate" class="form-control" placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Factory / Purchase Cost Rate</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="purchase_rate" class="form-control" placeholder="0.00">
                        </div>
                        <small class="text-muted">Cost used for gross profit calculations</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Printed MRP / Retail Rate</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="retail_rate" class="form-control" placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Opening Stock & Reorder Alert -->
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-boxes me-1"></i> Initial Inventory & Stock Threshold</span>
                </div>
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Opening Stock Quantity (Pieces/Base)</label>
                        <input type="number" step="0.01" name="opening_stock" class="form-control form-control-sm" value="0.00">
                        <small class="text-muted">Automatically generates opening stock ledger movement</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Minimum Stock Alert Level</label>
                        <input type="number" step="0.01" name="min_stock_level" class="form-control form-control-sm" value="10.00">
                        <small class="text-muted">Triggers low stock alerts on the dashboard</small>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <button type="submit" class="btn btn-primary w-100 btn-save-dms">
                        <i class="bi bi-check2-circle me-1"></i> Save Product (F9 / Ctrl+S)
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
    const totalPcs = packs * pcs;

    if (prevPacks) prevPacks.textContent = packs.toLocaleString();
    if (prevPieces) prevPieces.textContent = totalPcs.toLocaleString();
}
document.addEventListener('DOMContentLoaded', updateConversionPreview);
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
