<?php
/**
 * DMS - Measurement Units Management
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Packaging Units — ' . APP_NAME;
$activeMenu = 'products';

$units = db_fetch_all("SELECT * FROM units WHERE is_deleted = 0 ORDER BY id ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-box me-2 text-primary"></i>Packaging & Stock Units</h5>
        <small class="text-muted">Standard stock units for wholesale inventory, cartons, packs, and retail pieces</small>
    </div>
    <div>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-unit')">
            <i class="bi bi-plus-lg me-1"></i> Add Unit (Ctrl+N)
        </button>
    </div>
</div>

<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-grid me-1"></i> Packaging Units (<?= count($units) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead>
                <tr>
                    <th style="width: 120px;">Unit Code</th>
                    <th>Unit Name</th>
                    <th>Display Symbol</th>
                    <th class="text-center" style="width: 90px;">Status</th>
                    <th class="text-end" style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($units as $u): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border font-monospace"><?= e($u['unit_code']) ?></span></td>
                        <td class="fw-semibold text-dark"><?= e($u['unit_name']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-dark border"><?= e($u['symbol']) ?></span></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $u['is_active'] ? 'success' : 'dark' ?> border">
                                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/modules/units/edit.php?id=<?= $u['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Unit">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Non-Modal Slide Drawer: Add Unit -->
<div id="drawer-add-unit" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-plus-circle me-2 text-primary"></i> Add Packaging Unit</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/units/create.php">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Unit Code <span class="text-danger">*</span></label>
                <input type="text" name="unit_code" class="form-control form-control-sm" placeholder="e.g. ROLL, DRUM, DOZ" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Unit Name <span class="text-danger">*</span></label>
                <input type="text" name="unit_name" class="form-control form-control-sm" placeholder="e.g. Dozen, Roll" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Display Symbol <span class="text-danger">*</span></label>
                <input type="text" name="symbol" class="form-control form-control-sm" placeholder="e.g. Doz, Rl" required>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="unitActive" checked>
                <label class="form-check-label small" for="unitActive">Unit Active in Order Entry</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Unit (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
