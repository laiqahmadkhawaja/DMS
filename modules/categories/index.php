<?php
/**
 * DMS - Product Categories Management
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Product Categories — ' . APP_NAME;
$activeMenu = 'categories';

$search = clean_input($_GET['search'] ?? '');
$sql = "SELECT * FROM product_categories WHERE is_deleted = 0";
$params = [];

if ($search !== '') {
    $sql .= " AND (category_name LIKE ? OR category_code LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql .= " ORDER BY category_name ASC";
$categories = db_fetch_all($sql, $params);

$nextCode = 'CAT-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM product_categories") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-tags me-2 text-primary"></i>Product Categories</h5>
        <small class="text-muted">Dynamic item classifications (e.g. Nimko, Snacks, Confectionery, Biscuits)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/categories/bulk_update.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-tags-fill me-1"></i> Bulk Category Updater
        </a>
        <a href="<?= APP_URL ?>/modules/brands/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-bookmark-star me-1"></i> Manage Brands
        </a>
        <a href="<?= APP_URL ?>/modules/products/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tag me-1"></i> Products Catalog
        </a>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-category')">
            <i class="bi bi-plus-lg me-1"></i> Add Category (Ctrl+N)
        </button>
    </div>
</div>

<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/categories/index.php" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search category by name or code... (F2)">
            </div>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                <i class="bi bi-funnel"></i> Search
            </button>
        </div>
        <?php if ($search !== ''): ?>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/categories/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-list-nested me-1"></i> Categories List (<?= count($categories) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead>
                <tr>
                    <th style="width: 120px;">Category Code</th>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th class="text-center" style="width: 90px;">Status</th>
                    <th class="text-end" style="width: 110px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No categories registered. Click "Add Category" to create product groupings.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($cat['category_code']) ?></span></td>
                            <td class="fw-semibold text-dark"><?= e($cat['category_name']) ?></td>
                            <td class="text-muted small"><?= e($cat['description'] ?? '-') ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $cat['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $cat['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $cat['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/categories/edit.php?id=<?= $cat['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Category">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to deactivate this category?', function(){ window.location.href='<?= APP_URL ?>/modules/categories/delete.php?id=<?= $cat['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Category">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Non-Modal Slide Drawer: Add Category -->
<div id="drawer-add-category" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-plus-circle me-2 text-primary"></i> Create Category</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/categories/create.php">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Category Code <span class="text-danger">*</span></label>
                <input type="text" name="category_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                <input type="text" name="category_name" class="form-control form-control-sm" placeholder="e.g. Nimko, Snacks, Confectionery" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Category notes or product lines..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="catActive" checked>
                <label class="form-check-label small" for="catActive">Category Active in Catalog</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Category (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
