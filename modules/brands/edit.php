<?php
/**
 * DMS - Edit Product Brand
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$brand = db_fetch_one("SELECT * FROM brands WHERE id = ? AND is_deleted = 0", [$id]);

if (!$brand) {
    set_flash_message('danger', 'Brand not found.');
    redirect(APP_URL . '/modules/brands/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brandCode   = clean_input($_POST['brand_code'] ?? '');
    $brandName   = clean_input($_POST['brand_name'] ?? '');
    $description = clean_input($_POST['description'] ?? '');
    $isActive    = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('brand_code', $brandCode)
              ->unique('brand_code', $brandCode, 'brands', 'brand_code', $id)
              ->required('brand_name', $brandName);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE brands 
                SET brand_code = ?, brand_name = ?, description = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$brandCode, $brandName, $description, $isActive, $id]);

            log_audit('update_brand', 'brands', $id, "Updated brand: {$brandName}");
            set_flash_message('success', "Brand '{$brandName}' updated successfully.");
            redirect(APP_URL . '/modules/brands/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating brand: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Brand: ' . $brand['brand_name'] . ' — ' . APP_NAME;
$activeMenu = 'brands';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Brand</h5>
        <small class="text-muted">Modify brand code and manufacturer name</small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/brands/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Brands
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-bookmark-star me-1"></i> Brand Details</span>
            </div>
            <div class="card-dms-body">
                <form method="POST" action="<?= APP_URL ?>/modules/brands/edit.php?id=<?= $brand['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Brand Code <span class="text-danger">*</span></label>
                        <input type="text" name="brand_code" class="form-control form-control-sm" value="<?= e($brand['brand_code']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Brand / Manufacturer Name <span class="text-danger">*</span></label>
                        <input type="text" name="brand_name" class="form-control form-control-sm" value="<?= e($brand['brand_name']) ?>" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3"><?= e($brand['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="brdActive" <?= $brand['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="brdActive">Brand Active in Catalog</label>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="<?= APP_URL ?>/modules/brands/index.php" class="btn btn-sm btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Update Brand (F9)</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
