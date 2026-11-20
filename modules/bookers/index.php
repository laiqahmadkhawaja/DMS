<?php
/**
 * DMS - Bookers Management
 * List and quick register order bookers
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Order Bookers — ' . APP_NAME;
$activeMenu = 'bookers';

// Search and status filters
$search = clean_input($_GET['search'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? 'all');

$sql = "
    SELECT b.*, GROUP_CONCAT(a.area_name SEPARATOR ', ') as assigned_areas_list
    FROM bookers b
    LEFT JOIN booker_areas ba ON ba.booker_id = b.id
    LEFT JOIN areas a ON a.id = ba.area_id
    WHERE b.is_deleted = 0
";
$params = [];

if ($search !== '') {
    $sql .= " AND (b.booker_name LIKE ? OR b.booker_code LIKE ? OR b.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($statusFilter === 'active') {
    $sql .= " AND b.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND b.is_active = 0";
}

$sql .= " GROUP BY b.id ORDER BY b.booker_name ASC";
$bookers = db_fetch_all($sql, $params);

// Load all active areas for assignment in drawer
$allAreas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

// Auto generated code suggestion
$nextCode = 'BKR-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM bookers") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-people me-2 text-primary"></i>Order Bookers</h5>
        <small class="text-muted">Field staff booking retailer orders from designated market beats</small>
    </div>
    <div>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-booker')">
            <i class="bi bi-plus-lg me-1"></i> Register Booker (Ctrl+N)
        </button>
    </div>
</div>

<!-- Search & Filter -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/bookers/index.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search by booker name, code, or phone number... (F2)">
            </div>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Bookers Only</option>
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
                <a href="<?= APP_URL ?>/modules/bookers/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Bookers Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-person-lines-fill me-1"></i> Order Booker Directory (<?= count($bookers) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead>
                <tr>
                    <th style="width: 110px;">Booker Code</th>
                    <th>Booker Name</th>
                    <th>Phone / Mobile</th>
                    <th>Assigned Areas / Routes</th>
                    <th class="text-end" style="width: 120px;">Commission %</th>
                    <th class="text-center" style="width: 90px;">Status</th>
                    <th class="text-end" style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookers)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No order bookers found. Click "Register Booker" to add field staff.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bookers as $b): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($b['booker_code']) ?></span></td>
                            <td class="fw-semibold text-dark"><?= e($b['booker_name']) ?></td>
                            <td><i class="bi bi-telephone text-muted me-1"></i><?= e($b['phone']) ?></td>
                            <td>
                                <?php if (!empty($b['assigned_areas_list'])): ?>
                                    <span class="small text-secondary"><?= e($b['assigned_areas_list']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">No areas assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((float)$b['commission_rate'], 2) ?>%</td>
                            <td class="text-center">
                                <span class="badge bg-<?= $b['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $b['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $b['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/bookers/edit.php?id=<?= $b['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Booker">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to remove this booker?', function(){ window.location.href='<?= APP_URL ?>/modules/bookers/delete.php?id=<?= $b['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Booker">
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

<!-- Non-Modal Slide Drawer: Add Booker -->
<div id="drawer-add-booker" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-person-plus me-2 text-primary"></i> Register Order Booker</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/bookers/create.php">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Booker Code <span class="text-danger">*</span></label>
                <input type="text" name="booker_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Booker Full Name <span class="text-danger">*</span></label>
                <input type="text" name="booker_name" class="form-control form-control-sm" placeholder="e.g. Imran Butt, Abdur Rehman" required autofocus>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" class="form-control form-control-sm" placeholder="0300-1234567" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">CNIC / ID</label>
                    <input type="text" name="cnic" class="form-control form-control-sm" placeholder="34101-xxxxxxx-x">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Commission Rate (%)</label>
                <input type="number" step="0.01" name="commission_rate" class="form-control form-control-sm" value="0.00">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Assigned Market Route(s)</label>
                <div class="border rounded p-2 bg-light" style="max-height: 160px; overflow-y: auto;">
                    <?php if (empty($allAreas)): ?>
                        <small class="text-muted">No areas registered yet. Add areas first.</small>
                    <?php else: ?>
                        <?php foreach ($allAreas as $ar): ?>
                            <div class="form-check form-check-sm mb-1">
                                <input class="form-check-input" type="checkbox" name="area_ids[]" value="<?= $ar['id'] ?>" id="area_<?= $ar['id'] ?>">
                                <label class="form-check-label small" for="area_<?= $ar['id'] ?>">
                                    <?= e($ar['area_name']) ?> <span class="text-muted">(<?= e($ar['city']) ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Notes / Remarks</label>
                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Contact address or guarantee notes..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="bookerActive" checked>
                <label class="form-check-label small" for="bookerActive">Active Booker for Order Entry</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Booker (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
