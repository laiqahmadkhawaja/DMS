<?php
/**
 * DMS - Suppliers Management
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('suppliers_view');

$pageTitle = 'Suppliers & Vendors — ' . APP_NAME;
$activeMenu = 'suppliers';

$search = clean_input($_GET['search'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? 'all');

$sql = "SELECT * FROM suppliers WHERE is_deleted = 0";
$params = [];

if ($search !== '') {
    $sql .= " AND (supplier_name LIKE ? OR supplier_code LIKE ? OR company_name LIKE ? OR phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($statusFilter === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND is_active = 0";
}

$sql .= " ORDER BY supplier_name ASC";
$suppliers = db_fetch_all($sql, $params);

$totalPayables = 0;
foreach ($suppliers as $s) {
    if ((float)$s['current_balance'] > 0) {
        $totalPayables += (float)$s['current_balance'];
    }
}

$nextCode = 'SUPP-' . sprintf('%03d', (int)db_fetch_val("SELECT COUNT(*) FROM suppliers") + 1);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-building me-2 text-primary"></i>Suppliers & Manufacturers</h5>
        <small class="text-muted">Wholesale distributors, product manufacturers, and trade creditors</small>
    </div>
    <div class="d-flex gap-2">
        <?php if (has_permission('excel_import')): ?>
        <a href="<?= APP_URL ?>/modules/excel/import.php" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i> Import from Excel
        </a>
        <?php endif; ?>
        <?php if (has_permission('suppliers_create')): ?>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-supplier')">
            <i class="bi bi-plus-lg me-1"></i> Add Supplier (Ctrl+N)
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filter -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/suppliers/index.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search by supplier name, company, or phone... (F2)">
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
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
        </div>
        <?php if ($search !== '' || $statusFilter !== 'all'): ?>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/suppliers/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Total Payables Banner -->
<div class="card-dms p-2 mb-3 bg-light">
    <div class="d-flex justify-content-between align-items-center">
        <span class="small text-secondary">
            <i class="bi bi-info-circle me-1"></i> Registered Suppliers: <strong><?= count($suppliers) ?></strong>
        </span>
        <span class="small fw-semibold">
            Total Trade Payables Owed: <strong class="text-danger fs-6"><?= format_currency($totalPayables) ?></strong>
        </span>
    </div>
</div>

<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-list-columns-reverse me-1"></i> Supplier Directory</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th style="width: 100px;">Code</th>
                    <th>Supplier / Factory Name</th>
                    <th>Company / Brand</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>City</th>
                    <th class="text-end">Payable Balance</th>
                    <th class="text-center" style="width: 80px;">Status</th>
                    <th class="text-end" style="width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No suppliers found. Click "Add Supplier" to register factories and vendors.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($s['supplier_code']) ?></span></td>
                            <td class="fw-semibold text-dark"><?= e($s['supplier_name']) ?></td>
                            <td><?= e($s['company_name'] ?? '-') ?></td>
                            <td><?= e($s['contact_person'] ?? '-') ?></td>
                            <td><i class="bi bi-telephone text-muted me-1"></i><?= e($s['phone']) ?></td>
                            <td><?= e($s['city'] ?? '-') ?></td>
                            <td class="text-end fw-bold <?= (float)$s['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= format_currency($s['current_balance']) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $s['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $s['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/suppliers/ledger.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-info py-0 px-2" title="Supplier Ledger">
                                    <i class="bi bi-journal-text"></i>
                                </a>
                                <?php if (has_permission('suppliers_edit')): ?>
                                <a href="<?= APP_URL ?>/modules/suppliers/edit.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Supplier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (has_permission('suppliers_delete')): ?>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to deactivate this supplier?', function(){ window.location.href='<?= APP_URL ?>/modules/suppliers/delete.php?id=<?= $s['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Supplier">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Non-Modal Slide Drawer: Add Supplier -->
<div id="drawer-add-supplier" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-building-add me-2 text-primary"></i> Register New Supplier</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/suppliers/create.php">
        <div class="dms-drawer-body">
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Supplier Code <span class="text-danger">*</span></label>
                    <input type="text" name="supplier_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Company / Brand</label>
                    <input type="text" name="company_name" class="form-control form-control-sm" placeholder="e.g. Nimko Foods, Dawn Confectionery">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Supplier / Firm Name <span class="text-danger">*</span></label>
                <input type="text" name="supplier_name" class="form-control form-control-sm" placeholder="e.g. Muzzam Foods Gujranwala" required autofocus>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control form-control-sm" placeholder="e.g. Factory Manager">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" class="form-control form-control-sm" placeholder="0300-xxxxxxx" required>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm" placeholder="factory@domain.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">City</label>
                    <input type="text" name="city" class="form-control form-control-sm" value="Gujranwala">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Factory / Office Address</label>
                <textarea name="address" class="form-control form-control-sm" rows="2" placeholder="Factory address or dispatch location..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Opening Payable Balance</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                    <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
                </div>
                <small class="text-muted d-block mt-1">If money is owed from old software, enter here. Posts to ledger.</small>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Notes</label>
                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Payment terms or bank accounts..."></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="suppActive" checked>
                <label class="form-check-label small" for="suppActive">Active for Purchases & Stock Receiving</label>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Supplier (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
