<?php
/**
 * DMS - Order Booking Module
 * List and filter market orders
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('orders_view');

$pageTitle = 'Order Booking — ' . APP_NAME;
$activeMenu = 'orders';

// Filters
$search        = clean_input($_GET['search'] ?? '');
$bookerFilter  = (int)($_GET['booker_id'] ?? 0);
$areaFilter    = (int)($_GET['area_id'] ?? 0);
$statusFilter  = clean_input($_GET['status'] ?? 'all');
$fromDate      = clean_input($_GET['from_date'] ?? date('Y-m-d'));
$toDate        = clean_input($_GET['to_date'] ?? date('Y-m-d'));

$sql = "
    SELECT 
        o.*,
        c.shop_name, c.phone as customer_phone,
        a.area_name,
        b.booker_name,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    JOIN areas a ON a.id = o.area_id
    JOIN bookers b ON b.id = o.booker_id
    WHERE 1=1
";
$params = [];

if ($fromDate !== '' && $toDate !== '') {
    $sql .= " AND o.order_date BETWEEN ? AND ?";
    $params[] = $fromDate;
    $params[] = $toDate;
}

if ($search !== '') {
    $sql .= " AND (o.order_number LIKE ? OR c.shop_name LIKE ? OR c.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($bookerFilter > 0) {
    $sql .= " AND o.booker_id = ?";
    $params[] = $bookerFilter;
}

if ($areaFilter > 0) {
    $sql .= " AND o.area_id = ?";
    $params[] = $areaFilter;
}

if ($statusFilter !== 'all') {
    $sql .= " AND o.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY o.id DESC";
$orders = db_fetch_all($sql, $params);

// Calculate totals
$totalOrders = count($orders);
$totalAmount = 0.0;
$totalCartons = 0.0;
$draftCount = 0;
$confirmedCount = 0;
$loadedCount = 0;

foreach ($orders as $o) {
    $totalAmount  += (float)$o['net_amount'];
    $totalCartons += (float)$o['total_cartons'];
    if ($o['status'] === 'draft') $draftCount++;
    if ($o['status'] === 'confirmed') $confirmedCount++;
    if (in_array($o['status'], ['loaded', 'partially_loaded', 'delivered'])) $loadedCount++;
}

$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-card-checklist me-2 text-primary"></i>Market Order Booking</h5>
        <small class="text-muted">Retailer orders collected by field bookers awaiting confirmation and loading dispatch</small>
    </div>
    <?php if (has_permission('orders_create')): ?>
    <div>
        <a href="<?= APP_URL ?>/modules/orders/create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i> New Order (F6)
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Summary Ribbon -->
<div class="row g-2 mb-3">
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted">Orders Booked</span>
                <span class="badge bg-primary-subtle text-primary border font-monospace"><?= $totalOrders ?> Orders</span>
            </div>
            <h5 class="fw-bold mb-0 text-dark"><?= format_currency($totalAmount) ?></h5>
            <small class="text-muted"><?= format_qty($totalCartons) ?> Total Cartons</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted">Draft Orders</span>
                <span class="badge bg-secondary-subtle text-dark border"><?= $draftCount ?> Draft</span>
            </div>
            <h5 class="fw-bold mb-0 text-secondary"><?= $draftCount ?></h5>
            <small class="text-muted">Awaiting confirmation</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted">Confirmed Ready for Loading</span>
                <span class="badge bg-success-subtle text-success border"><?= $confirmedCount ?> Ready</span>
            </div>
            <h5 class="fw-bold mb-0 text-success"><?= $confirmedCount ?></h5>
            <small class="text-success">Available for warehouse load sheet</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted">Loaded / Dispatched</span>
                <span class="badge bg-warning-subtle text-dark border"><?= $loadedCount ?> Loaded</span>
            </div>
            <h5 class="fw-bold mb-0 text-warning-emphasis"><?= $loadedCount ?></h5>
            <small class="text-muted">Issued on vehicle load sheets</small>
        </div>
    </div>
</div>

<!-- Filters Form -->
<div class="card-dms p-2 mb-3">
    <form method="GET" action="<?= APP_URL ?>/modules/orders/index.php" class="row g-2 align-items-center">
        <div class="col-md-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control dms-search-input" value="<?= e($search) ?>" placeholder="Search order #, shop name... (F2)">
            </div>
        </div>
        <div class="col-md-2">
            <select name="booker_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Bookers</option>
                <?php foreach ($bookers as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $bookerFilter ? 'selected' : '' ?>>
                        <?= e($b['booker_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="area_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">All Areas</option>
                <?php foreach ($areas as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $a['id'] == $areaFilter ? 'selected' : '' ?>>
                        <?= e($a['area_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="loaded" <?= $statusFilter === 'loaded' ? 'selected' : '' ?>>Loaded / Dispatched</option>
                <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-1">
            <input type="date" name="from_date" class="form-control form-control-sm" value="<?= e($fromDate) ?>" title="Order Date">
        </div>
        <div class="col-md-1">
            <input type="date" name="to_date" class="form-control form-control-sm" value="<?= e($toDate) ?>" title="To Date">
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
        </div>
    </form>
</div>

<!-- Orders Table -->
<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-list-check me-1"></i> Orders List (<?= $totalOrders ?> Total)</span>
        <span class="small text-muted">Showing orders booked for delivery</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th style="width: 120px;">Order #</th>
                    <th style="width: 90px;">Date</th>
                    <th>Customer / Retail Shop</th>
                    <th>Area / Route</th>
                    <th>Booker</th>
                    <th class="text-center" style="width: 80px;">Items</th>
                    <th class="text-center" style="width: 90px;">Cartons</th>
                    <th class="text-end" style="width: 120px;">Net Amount</th>
                    <th class="text-center" style="width: 100px;">Status</th>
                    <th class="text-end" style="width: 130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                            No orders found for the selected criteria. Click "New Order (F6)" to book market orders.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $ord): 
                        $badgeClass = match($ord['status']) {
                            'confirmed' => 'success',
                            'loaded', 'partially_loaded' => 'warning',
                            'delivered' => 'info',
                            'cancelled' => 'danger',
                            default     => 'secondary'
                        };
                    ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $ord['id'] ?>" class="fw-bold text-primary font-monospace">
                                    <?= e($ord['order_number']) ?>
                                </a>
                            </td>
                            <td><?= format_date($ord['order_date']) ?></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($ord['shop_name']) ?></div>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= e($ord['customer_phone']) ?></small>
                            </td>
                            <td><?= e($ord['area_name']) ?></td>
                            <td><?= e($ord['booker_name']) ?></td>
                            <td class="text-center"><?= (int)$ord['item_count'] ?></td>
                            <td class="text-center fw-semibold"><?= format_qty($ord['total_cartons']) ?></td>
                            <td class="text-end fw-bold text-dark"><?= format_currency($ord['net_amount']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $badgeClass ?>-subtle text-<?= $badgeClass ?> border">
                                    <?= ucfirst(str_replace('_', ' ', $ord['status'])) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $ord['id'] ?>" class="btn btn-xs btn-outline-info py-0 px-2" title="View / Print Order">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($ord['status'] === 'draft' && has_permission('orders_confirm')): ?>
                                    <a href="<?= APP_URL ?>/modules/orders/action.php?id=<?= $ord['id'] ?>&action=confirm" class="btn btn-xs btn-outline-success py-0 px-2" title="Confirm Order for Loading">
                                        <i class="bi bi-check-lg"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($ord['status'] === 'draft' && has_permission('orders_edit')): ?>
                                    <a href="<?= APP_URL ?>/modules/orders/edit.php?id=<?= $ord['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" title="Edit Draft">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-fmcg-total">
                <tr>
                    <th colspan="6" class="text-end">Total Page Summary:</th>
                    <th class="text-center"><?= format_qty($totalCartons) ?> Ctns</th>
                    <th class="text-end"><?= format_currency($totalAmount) ?></th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
