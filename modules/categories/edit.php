<?php
/**
 * DMS - Edit Product Category
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$category = db_fetch_one("SELECT * FROM product_categories WHERE id = ? AND is_deleted = 0", [$id]);

if (!$category) {
    set_flash_message('danger', 'Category not found.');
    redirect(APP_URL . '/modules/categories/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryCode = clean_input($_POST['category_code'] ?? '');
    $categoryName = clean_input($_POST['category_name'] ?? '');
    $description  = clean_input($_POST['description'] ?? '');
    $isActive     = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('category_code', $categoryCode)
              ->unique('category_code', $categoryCode, 'product_categories', 'category_code', $id)
              ->required('category_name', $categoryName);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE product_categories 
                SET category_code = ?, category_name = ?, description = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$categoryCode, $categoryName, $description, $isActive, $id]);

            log_audit('update_category', 'categories', $id, "Updated category: {$categoryName}");
            set_flash_message('success', "Category '{$categoryName}' updated successfully.");
            redirect(APP_URL . '/modules/categories/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating category: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Category: ' . $category['category_name'] . ' — ' . APP_NAME;
$activeMenu = 'products';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Category</h5>
        <small class="text-muted">Modify category code and classification name</small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/categories/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Categories (ESC)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-tag me-1"></i> Category Details</span>
            </div>
            <form method="POST" action="<?= APP_URL ?>/modules/categories/edit.php?id=<?= $category['id'] ?>">
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Category Code <span class="text-danger">*</span></label>
                        <input type="text" name="category_code" class="form-control form-control-sm" value="<?= e($category['category_code']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" class="form-control form-control-sm" value="<?= e($category['category_name']) ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3"><?= e($category['description']) ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="catEditActive" <?= $category['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="catEditActive">Category Active in Catalog</label>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <a href="<?= APP_URL ?>/modules/categories/index.php" class="btn btn-sm btn-light border me-1">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i> Update Category (F9)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
