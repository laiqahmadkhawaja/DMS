<?php
/**
 * DMS - Salesmen & Delivery Staff Management
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Delivery Salesmen & Drivers — ' . APP_NAME;
$activeMenu = 'salesmen';

$search = clean_input($_GET['search'] ?? '');
$roleFilter = clean_input($_GET['role'] ?? 'all');
$statusFilter = clean_input($_GET['status'] ?? 'all');

$sql = "
    SELECT sm.*, a.area_name as assigned_area_name 
    FROM salesmen sm 
    LEFT JOIN areas a ON a.id = sm.assigned_area_id 
    WHERE sm.is_deleted = 0
";
$params = [];

if ($search !== '') {
    $sql .= " AND (sm.salesman_name LIKE ? OR sm.employee_code LIKE ? OR sm.phone LIKE ? OR sm.vehicle_number LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($roleFilter !== 'all') {
    $sql .= " AND sm.role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter === 'active') {
    $sql .= " AND sm.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND sm.is_active = 0";
}

$sql .= " ORDER BY sm.salesman_name ASC";
$salesmen = db_fetch_all($sql, $params);

// Load areas for assignment
$allAreas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

// Auto generated employee code suggestion
$nextCode = 'EMP-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM salesmen") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-truck me-2 text-primary"></i>Delivery Salesmen & Drivers</h5>
        <small class="text-muted">Staff managing van loading, route deliveries, and evening cash settlement</small>
    </div>
    <div>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-salesman')">
            <i class="bi bi-plus-lg me-1"></i> Add Salesman (Ctrl+N)
        </button>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/salesmen/index.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search by name, employee code, vehicle #, or phone... (F2)">
            </div>
        </div>
        <div class="col-md-2">
            <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All Roles</option>
                <option value="delivery_man" <?= $roleFilter === 'delivery_man' ? 'selected' : '' ?>>Delivery Salesman</option>
                <option value="van_driver" <?= $roleFilter === 'van_driver' ? 'selected' : '' ?>>Van Driver</option>
                <option value="salesman" <?= $roleFilter === 'salesman' ? 'selected' : '' ?>>Sales Representative</option>
            </select>
        </div>
        <div class="col-md-2">
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
        <?php if ($search !== '' || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
            <div class="col-md-1">
                <a href="<?= APP_URL ?>/modules/salesmen/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Salesmen Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-person-badge me-1"></i> Sales Staff Roster (<?= count($salesmen) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead>
                <tr>
                    <th style="width: 100px;">Emp Code</th>
                    <th>Salesman / Driver Name</th>
                    <th>Role</th>
                    <th>Phone</th>
                    <th>Vehicle Assigned</th>
                    <th>Assigned Beat / Route</th>
                    <th class="text-center" style="width: 80px;">Status</th>
                    <th class="text-end" style="width: 130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salesmen)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No delivery staff found. Click "Add Salesman" to register route delivery staff.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salesmen as $sm): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($sm['employee_code']) ?></span></td>
                            <td class="fw-semibold text-dark"><?= e($sm['salesman_name']) ?></td>
                            <td>
                                <span class="badge bg-secondary-subtle text-dark border">
                                    <?= ucwords(str_replace('_', ' ', $sm['role'])) ?>
                                </span>
                            </td>
                            <td><i class="bi bi-telephone text-muted me-1"></i><?= e($sm['phone']) ?></td>
                            <td><?= !empty($sm['vehicle_number']) ? '<span class="badge bg-warning-subtle text-dark border font-monospace">' . e($sm['vehicle_number']) . '</span>' : '-' ?></td>
                            <td><?= e($sm['assigned_area_name'] ?? '-') ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $sm['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $sm['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $sm['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/salesmen/ledger.php?id=<?= $sm['id'] ?>" class="btn btn-xs btn-outline-info py-0 px-2" title="View Cash Settlement Ledger">
                                    <i class="bi bi-cash-stack"></i>
                                </a>
                                <a href="<?= APP_URL ?>/modules/salesmen/edit.php?id=<?= $sm['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Staff Details">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to deactivate this salesman?', function(){ window.location.href='<?= APP_URL ?>/modules/salesmen/delete.php?id=<?= $sm['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Salesman">
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

<!-- Non-Modal Slide Drawer: Add Salesman -->
<div id="drawer-add-salesman" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-person-plus me-2 text-primary"></i> Register Delivery Salesman</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/salesmen/create.php">
        <div class="dms-drawer-body">
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Employee Code <span class="text-danger">*</span></label>
                    <input type="text" name="employee_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select form-select-sm" required>
                        <option value="delivery_man">Delivery Salesman</option>
                        <option value="van_driver">Van Driver</option>
                        <option value="salesman">Sales Representative</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Salesman / Driver Full Name <span class="text-danger">*</span></label>
                <input type="text" name="salesman_name" class="form-control form-control-sm" placeholder="e.g. Saghar, Muzamal Shah" required autofocus>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" class="form-control form-control-sm" placeholder="0300-xxxxxxx" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Vehicle Number / Plate</label>
                    <input type="text" name="vehicle_number" class="form-control form-control-sm" placeholder="e.g. GAK-1234, Suzuki Van">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Assigned Delivery Beat / Route</label>
                <select name="assigned_area_id" class="form-select form-select-sm">
                    <option value="">-- No primary route assigned --</option>
                    <?php foreach ($allAreas as $ar): ?>
                        <option value="<?= $ar['id'] ?>"><?= e($ar['area_name']) ?> (<?= e($ar['city']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Remarks / Guarantee Notes</label>
                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Driver license details, security deposit or address..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="smActive" checked>
                <label class="form-check-label small" for="smActive">Active for Loading & Delivery Run Sheets</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Staff Member (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
