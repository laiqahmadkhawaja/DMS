<?php
/**
 * DMS - Customers / Retailers Management
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('customers_view');

$pageTitle = 'Customers / Retailers — ' . APP_NAME;
$activeMenu = 'customers';

$search = clean_input($_GET['search'] ?? '');
$areaFilter = (int)($_GET['area_id'] ?? 0);
$bookerFilter = (int)($_GET['booker_id'] ?? 0);
$statusFilter = clean_input($_GET['status'] ?? 'all');
$balanceFilter = clean_input($_GET['balance_filter'] ?? 'all');

$sql = "
    SELECT 
        c.*, 
        a.area_name, 
        b.booker_name 
    FROM customers c
    JOIN areas a ON a.id = c.area_id
    LEFT JOIN bookers b ON b.id = c.booker_id
    WHERE c.is_deleted = 0
";
$params = [];

if ($search !== '') {
    $sql .= " AND (c.shop_name LIKE ? OR c.owner_name LIKE ? OR c.phone LIKE ? OR c.customer_code LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($areaFilter > 0) {
    $sql .= " AND c.area_id = ?";
    $params[] = $areaFilter;
}

if ($bookerFilter > 0) {
    $sql .= " AND c.booker_id = ?";
    $params[] = $bookerFilter;
}

if ($statusFilter === 'active') {
    $sql .= " AND c.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND c.is_active = 0";
}

if ($balanceFilter === 'with_balance') {
    $sql .= " AND c.current_balance > 0";
}

$sql .= " ORDER BY c.shop_name ASC";
$customers = db_fetch_all($sql, $params);

$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");

// Calculate aggregate customer receivables
$totalOutstanding = 0;
foreach ($customers as $cust) {
    if ((float)$cust['current_balance'] > 0) {
        $totalOutstanding += (float)$cust['current_balance'];
    }
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-shop me-2 text-primary"></i>Customer / Retailer Directory</h5>
        <small class="text-muted">Master database of shops, credit limits, booker routes, and outstanding khata</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/customers/statement.php" class="btn btn-sm btn-outline-warning text-dark">
            <i class="bi bi-receipt-cutoff me-1"></i> Parties Credit List
        </a>
        <?php if (has_permission('customers_create')): ?>
        <a href="<?= APP_URL ?>/modules/customers/create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Add Retailer (Ctrl+N)
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/customers/index.php" class="row g-2 align-items-center">
        <div class="col-md-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search shop name, owner, phone... (F2)">
            </div>
        </div>
        <div class="col-md-2">
            <select name="area_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Areas / Routes</option>
                <?php foreach ($areas as $ar): ?>
                    <option value="<?= $ar['id'] ?>" <?= $ar['id'] == $areaFilter ? 'selected' : '' ?>>
                        <?= e($ar['area_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="booker_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Order Bookers</option>
                <?php foreach ($bookers as $bk): ?>
                    <option value="<?= $bk['id'] ?>" <?= $bk['id'] == $bookerFilter ? 'selected' : '' ?>>
                        <?= e($bk['booker_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="balance_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $balanceFilter === 'all' ? 'selected' : '' ?>>All Customers</option>
                <option value="with_balance" <?= $balanceFilter === 'with_balance' ? 'selected' : '' ?>>With Credit Balance Only</option>
            </select>
        </div>
        <div class="col-md-1">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
        </div>
        <?php if ($search !== '' || $areaFilter > 0 || $bookerFilter > 0 || $balanceFilter !== 'all' || $statusFilter !== 'all'): ?>
            <div class="col-md-1">
                <a href="<?= APP_URL ?>/modules/customers/index.php" class="btn btn-sm btn-link text-decoration-none text-muted">Clear</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Ribbon: Total Outstanding -->
<div class="card-dms p-2 mb-3 bg-light">
    <div class="d-flex justify-content-between align-items-center">
        <span class="small text-secondary">
            <i class="bi bi-info-circle me-1"></i> Showing <strong><?= count($customers) ?></strong> retailers.
        </span>
        <span class="small fw-semibold">
            Filtered Outstanding Receivables: <strong class="text-danger fs-6"><?= format_currency($totalOutstanding) ?></strong>
        </span>
    </div>
</div>

<!-- Customers Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-card-list me-1"></i> Retailers List</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th style="width: 100px;">Code</th>
                    <th>Shop Name & Owner</th>
                    <th>Area / Route</th>
                    <th>Booker</th>
                    <th>Phone / Contact</th>
                    <th class="text-end">Credit Limit</th>
                    <th class="text-end">Current Balance</th>
                    <th class="text-center" style="width: 70px;">Status</th>
                    <th class="text-end" style="width: 130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No retail customers found. Click "Add Retailer" to register shops.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($c['customer_code']) ?></span></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($c['shop_name']) ?></div>
                                <small class="text-muted"><?= e($c['owner_name'] ?? '-') ?></small>
                            </td>
                            <td><?= e($c['area_name']) ?></td>
                            <td><?= e($c['booker_name'] ?? '<span class="text-muted">-</span>') ?></td>
                            <td><i class="bi bi-telephone text-muted me-1"></i><?= e($c['phone']) ?></td>
                            <td class="text-end"><?= (float)$c['credit_limit'] > 0 ? format_currency($c['credit_limit']) : '<span class="text-muted">-</span>' ?></td>
                            <td class="text-end fw-bold <?= (float)$c['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= format_currency($c['current_balance']) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $c['is_active'] ? 'success' : 'dark' ?> border">
                                    <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/customers/statement.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-info py-0 px-2" title="Customer Statement / Ledger">
                                    <i class="bi bi-journal-text"></i>
                                </a>
                                <?php if (has_permission('customers_edit')): ?>
                                <a href="<?= APP_URL ?>/modules/customers/edit.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Customer">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (has_permission('customers_delete')): ?>
                                <a href="javascript:void(0)" onclick="dmsConfirm('Are you sure you want to delete this customer record?', function(){ window.location.href='<?= APP_URL ?>/modules/customers/delete.php?id=<?= $c['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Customer">
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

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
