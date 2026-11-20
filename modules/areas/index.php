<?php
/**
 * DMS - Areas Management
 * List and quick add areas
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Areas / Routes — ' . APP_NAME;
$activeMenu = 'areas';

// Search query
$search = clean_input($_GET['search'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? 'all');

$sql = "SELECT * FROM areas WHERE is_deleted = 0";
$params = [];

if ($search !== '') {
    $sql .= " AND (area_name LIKE ? OR area_code LIKE ? OR city LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($statusFilter === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND is_active = 0";
}

$sql .= " ORDER BY area_name ASC";
$areas = db_fetch_all($sql, $params);

// Suggested new area code
$nextCode = 'AREA-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM areas") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-map me-2 text-primary"></i>Areas & Delivery Routes</h5>
        <small class="text-muted">Manage geographic market sectors, delivery beats, and route codes</small>
    </div>
    <div>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-area')">
            <i class="bi bi-plus-lg me-1"></i> Add New Area (Ctrl+N)
        </button>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/areas/index.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search by area name, route code, or city... (F2)">
            </div>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>
        <?php if ($search !== '' || $statusFilter !== 'all'): ?>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/areas/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Areas Data Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-list-ul me-1"></i> Area Catalog (<?= count($areas) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead>
                <tr>
                    <th style="width: 120px;">Route Code</th>
                    <th>Area / Sector Name</th>
                    <th>City / District</th>
                    <th>Description</th>
                    <th class="text-center" style="width: 90px;">Status</th>
                    <th class="text-end" style="width: 110px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($areas)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No areas found. Click "Add New Area" to register market routes.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($areas as $area): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($area['area_code']) ?></span></td>
                            <td class="fw-semibold text-dark"><?= e($area['area_name']) ?></td>
                            <td><?= e($area['city']) ?></td>
                            <td class="text-muted small"><?= e($area['description'] ?? '-') ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $area['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $area['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $area['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/areas/edit.php?id=<?= $area['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Area">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to deactivate this area?', function(){ window.location.href='<?= APP_URL ?>/modules/areas/delete.php?id=<?= $area['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Area">
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

<!-- Non-Modal Slide Drawer: Add Area -->
<div id="drawer-add-area" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-plus-circle me-2 text-primary"></i> Register New Area</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/areas/create.php">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Area / Route Code <span class="text-danger">*</span></label>
                <input type="text" name="area_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Area / Sector Name <span class="text-danger">*</span></label>
                <input type="text" name="area_name" class="form-control form-control-sm" placeholder="e.g. Jandiala Bhagwala, Rahwali, Daal Bazar" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">City / District</label>
                <input type="text" name="city" class="form-control form-control-sm" value="<?= e(get_setting('city', 'Gujranwala')) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Description / Landmark Notes</label>
                <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Optional delivery beat instructions or highway/main road boundaries..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="areaActive" checked>
                <label class="form-check-label small" for="areaActive">Route Active for Order Booking</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Area (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
