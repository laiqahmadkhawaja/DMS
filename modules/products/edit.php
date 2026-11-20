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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productCode     = clean_input($_POST['product_code'] ?? '');
    $barcode         = clean_input($_POST['barcode'] ?? '');
    $productName     = clean_input($_POST['product_name'] ?? '');
    $categoryId      = (int)($_POST['category_id'] ?? 0);
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

    $validator = new Validator();
    $validator->required('product_code', $productCode)
              ->unique('product_code', $productCode, 'products', 'product_code', $id)
              ->required('product_name', $productName)
              ->required('category_id', $categoryId)
              ->exists('category_id', $categoryId, 'product_categories', 'id')
              ->required('base_unit_id', $baseUnitId)
              ->exists('base_unit_id', $baseUnitId, 'units', 'id')
              ->numeric('wholesale_rate', $wholesaleRate, 0)
              ->numeric('carton_qty', $cartonQty, 0.01);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE products 
                SET product_code = ?, barcode = ?, product_name = ?, category_id = ?, brand_id = ?, base_unit_id = ?,
                    purchase_rate = ?, default_sale_rate = ?, wholesale_rate = ?, retail_rate = ?,
                    carton_qty = ?, pack_qty = ?, min_stock_level = ?, description = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $productCode, $barcode, $productName, $categoryId, $brandId, $baseUnitId,
                $purchaseRate, $defaultSaleRate, $wholesaleRate, $retailRate,
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
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select form-select-sm" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                        <?= e($cat['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Brand / Manufacturer</label>
                            <select name="brand_id" class="form-select form-select-sm">
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
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Carton Packing (Pieces/Ctn)</label>
                            <input type="number" step="0.01" name="carton_qty" class="form-control form-control-sm" value="<?= e($product['carton_qty']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Pack Packing</label>
                            <input type="number" step="0.01" name="pack_qty" class="form-control form-control-sm" value="<?= e($product['pack_qty']) ?>">
                        </div>
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
                        <label class="form-label small fw-semibold">Wholesale Rate <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="wholesale_rate" class="form-control fw-bold" value="<?= e($product['wholesale_rate']) ?>" required>
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
                        <label class="form-label small fw-semibold">Purchase Cost Rate</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="purchase_rate" class="form-control" value="<?= e($product['purchase_rate']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Minimum Stock Alert Level</label>
                        <input type="number" step="0.01" name="min_stock_level" class="form-control form-control-sm" value="<?= e($product['min_stock_level']) ?>">
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

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
