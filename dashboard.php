<?php
/**
 * DMS - Distribution Management System
 * Operations Dashboard
 */

require_once __DIR__ . '/config/config.php';
require_login();

$pageTitle = 'Operations Dashboard — ' . APP_NAME;
$activeMenu = 'dashboard';

// Date filter handling (Defaults to today)
$dateFilter = clean_input($_GET['date_filter'] ?? 'today');
$customDate = clean_input($_GET['custom_date'] ?? date('Y-m-d'));

switch ($dateFilter) {
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = $startDate;
        break;
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d');
        break;
    case 'this_month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-d');
        break;
    case 'custom':
        $startDate = $customDate;
        $endDate = $customDate;
        break;
    case 'today':
    default:
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        $dateFilter = 'today';
        break;
}

// 1. Order Booking Metrics
$stmtOrd = db()->prepare("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(net_amount), 0) as total_order_amount,
        COALESCE(SUM(total_cartons), 0) as total_cartons,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_orders,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders
    FROM orders 
    WHERE order_date BETWEEN ? AND ?
");
$stmtOrd->execute([$startDate, $endDate]);
$orderStats = $stmtOrd->fetch();

// 2. Warehouse Loading Metrics
$stmtLoad = db()->prepare("
    SELECT 
        COUNT(*) as total_loads,
        SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) as issued_loads,
        SUM(CASE WHEN status IN ('draft', 'approved') THEN 1 ELSE 0 END) as pending_loads
    FROM load_sheets 
    WHERE load_date BETWEEN ? AND ?
");
$stmtLoad->execute([$startDate, $endDate]);
$loadStats = $stmtLoad->fetch();

// 3. Sales Invoice Metrics
$stmtInv = db()->prepare("
    SELECT 
        COUNT(*) as total_invoices,
        COALESCE(SUM(gross_amount), 0) as gross_sales,
        COALESCE(SUM(net_amount), 0) as net_sales,
        COALESCE(SUM(paid_amount), 0) as cash_invoiced,
        COALESCE(SUM(credit_amount), 0) as credit_invoiced,
        COALESCE(SUM(return_amount), 0) as return_invoiced
    FROM sales_invoices 
    WHERE invoice_date BETWEEN ? AND ? AND status != 'cancelled'
");
$stmtInv->execute([$startDate, $endDate]);
$invStats = $stmtInv->fetch();

// 4. Daily Route Settlement Reconciliation
$stmtSet = db()->prepare("
    SELECT 
        COUNT(*) as total_settlements,
        COALESCE(SUM(total_invoices_amount), 0) as settled_invoices_amount,
        COALESCE(SUM(total_cash_received), 0) as total_cash_received,
        COALESCE(SUM(total_credit_given), 0) as total_credit_given,
        COALESCE(SUM(total_returns_amount), 0) as total_returns_amount,
        COALESCE(SUM(difference_amount), 0) as total_difference,
        SUM(CASE WHEN status != 'finalized' THEN 1 ELSE 0 END) as pending_settlements
    FROM settlements 
    WHERE settlement_date BETWEEN ? AND ?
");
$stmtSet->execute([$startDate, $endDate]);
$setStats = $stmtSet->fetch();

// 5. Total Market Receivables (Customer Outstanding)
$totalReceivables = (float)db_fetch_val("
    SELECT COALESCE(SUM(current_balance), 0) 
    FROM customers 
    WHERE current_balance > 0 AND is_deleted = 0
");

// 6. Total Supplier Payables
$totalPayables = (float)db_fetch_val("
    SELECT COALESCE(SUM(current_balance), 0) 
    FROM suppliers 
    WHERE current_balance > 0 AND is_deleted = 0
");

// 7. Inventory Stock Value
$totalStockValue = (float)db_fetch_val("
    SELECT COALESCE(SUM(total_value), 0) 
    FROM stock_balances
");

// 8. Low Stock Count
$lowStockCount = (int)db_fetch_val("
    SELECT COUNT(*) 
    FROM products p 
    LEFT JOIN stock_balances sb ON sb.product_id = p.id 
    WHERE p.is_active = 1 AND p.is_deleted = 0 
      AND COALESCE(sb.saleable_qty, 0) <= p.min_stock_level
");

// 9. Pending Orders Ready for Loading
$pendingOrders = db_fetch_all("
    SELECT o.id, o.order_number, o.order_date, c.shop_name, b.booker_name, a.area_name, o.net_amount, o.status
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    JOIN bookers b ON b.id = o.booker_id
    JOIN areas a ON a.id = o.area_id
    WHERE o.status IN ('draft', 'confirmed')
    ORDER BY o.id DESC 
    LIMIT 6
");

// 10. Unreconciled / Pending Settlements
$pendingSettlementRows = db_fetch_all("
    SELECT s.id, s.settlement_number, s.settlement_date, sm.salesman_name, s.total_invoices_amount, 
           s.total_cash_received, s.total_credit_given, s.total_returns_amount, s.difference_amount, s.status
    FROM settlements s
    JOIN salesmen sm ON sm.id = s.salesman_id
    WHERE s.status != 'finalized'
    ORDER BY s.id DESC 
    LIMIT 6
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-speedometer2 me-2 text-primary"></i>Distribution Control Center</h5>
        <small class="text-muted">Real-time daily operations, loading status, and field cash reconciliation</small>
    </div>
    
    <!-- Filter Toolbar -->
    <form method="GET" action="<?= APP_URL ?>/dashboard.php" class="d-flex align-items-center gap-2">
        <select name="date_filter" class="form-select form-select-sm" onchange="if(this.value==='custom'){ document.getElementById('custom_date_div').style.display='block'; } else { document.getElementById('custom_date_div').style.display='none'; this.form.submit(); }">
            <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="yesterday" <?= $dateFilter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
            <option value="this_week" <?= $dateFilter === 'this_week' ? 'selected' : '' ?>>This Week</option>
            <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
            <option value="custom" <?= $dateFilter === 'custom' ? 'selected' : '' ?>>Custom Date...</option>
        </select>
        
        <div id="custom_date_div" style="display: <?= $dateFilter === 'custom' ? 'block' : 'none' ?>;">
            <input type="date" name="custom_date" class="form-control form-control-sm" value="<?= e($customDate) ?>" onchange="this.form.submit()">
        </div>
    </form>
</div>

<!-- Primary Distribution KPIs (4 Key Metrics) -->
<div class="row g-2 mb-3">
    <!-- Today's Billed Sales -->
    <div class="col-md-3">
        <div class="card-dms p-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small fw-semibold text-uppercase">Sales Invoiced</span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= (int)$invStats['total_invoices'] ?> Bills</span>
            </div>
            <h4 class="mb-1 fw-bold text-dark"><?= format_currency($invStats['net_sales']) ?></h4>
            <div class="small text-muted">
                Gross: <?= format_currency($invStats['gross_sales']) ?>
            </div>
        </div>
    </div>

    <!-- Route Cash Collected -->
    <div class="col-md-3">
        <div class="card-dms p-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small fw-semibold text-uppercase">Cash Collected</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-cash"></i> Field</span>
            </div>
            <h4 class="mb-1 fw-bold text-success"><?= format_currency($setStats['total_cash_received']) ?></h4>
            <div class="small text-muted">
                From settled routes
            </div>
        </div>
    </div>

    <!-- Credit Given to Retailers -->
    <div class="col-md-3">
        <div class="card-dms p-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small fw-semibold text-uppercase">Market Credit Added</span>
                <span class="badge bg-warning-subtle text-dark border border-warning-subtle"><i class="bi bi-clock"></i> Khata</span>
            </div>
            <h4 class="mb-1 fw-bold text-dark"><?= format_currency($setStats['total_credit_given']) ?></h4>
            <div class="small text-muted">
                Total Market Balance: <?= format_currency($totalReceivables) ?>
            </div>
        </div>
    </div>

    <!-- Field Returns Recorded -->
    <div class="col-md-3">
        <div class="card-dms p-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small fw-semibold text-uppercase">Returns Recorded</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-arrow-counterclockwise"></i> Returns</span>
            </div>
            <h4 class="mb-1 fw-bold text-danger"><?= format_currency($setStats['total_returns_amount']) ?></h4>
            <div class="small text-muted">
                Unreconciled diff: <?= format_currency($setStats['total_difference']) ?>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Operational Status Cards -->
<div class="row g-2 mb-3">
    <!-- Orders Status -->
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex align-items-center">
                <div class="bg-primary text-white rounded p-2 me-2">
                    <i class="bi bi-card-checklist fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Orders Booked</div>
                    <div class="fw-bold"><?= (int)$orderStats['total_orders'] ?> orders (<?= format_qty($orderStats['total_cartons']) ?> Ctns)</div>
                    <small class="text-primary"><?= (int)$orderStats['draft_orders'] ?> draft | <?= (int)$orderStats['confirmed_orders'] ?> confirmed</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Status -->
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex align-items-center">
                <div class="bg-warning text-dark rounded p-2 me-2">
                    <i class="bi bi-truck-flatbed fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Loading Dispatches</div>
                    <div class="fw-bold"><?= (int)$loadStats['issued_loads'] ?> issued / <?= (int)$loadStats['total_loads'] ?> loads</div>
                    <small class="text-warning-emphasis"><?= (int)$loadStats['pending_loads'] ?> pending dispatch</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Valuation & Alerts -->
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex align-items-center">
                <div class="bg-info text-white rounded p-2 me-2">
                    <i class="bi bi-boxes fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Stock Value</div>
                    <div class="fw-bold"><?= format_currency($totalStockValue) ?></div>
                    <small class="text-danger"><?= $lowStockCount ?> items below minimum level</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier Liabilities -->
    <div class="col-md-3">
        <div class="card-dms p-2">
            <div class="d-flex align-items-center">
                <div class="bg-secondary text-white rounded p-2 me-2">
                    <i class="bi bi-building fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">Supplier Payables</div>
                    <div class="fw-bold"><?= format_currency($totalPayables) ?></div>
                    <small class="text-muted">Total trade creditor balances</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Operational Tables: Real-Time Pipelines -->
<div class="row g-2">
    <!-- Left: Pending Orders Pipeline -->
    <div class="col-md-6">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-clock-history me-1 text-primary"></i> Pending / Confirmed Orders for Loading</span>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-xs btn-outline-primary py-0 px-2 small">View All Orders</a>
            </div>
            <div class="table-responsive">
                <table class="table table-compact mb-0">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Shop / Customer</th>
                            <th>Area</th>
                            <th>Booker</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingOrders)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-4 d-block text-secondary mb-1"></i>
                                    No pending orders awaiting loading.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingOrders as $po): ?>
                                <tr>
                                    <td><a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $po['id'] ?>" class="fw-semibold text-primary"><?= e($po['order_number']) ?></a></td>
                                    <td><?= e($po['shop_name']) ?></td>
                                    <td><?= e($po['area_name']) ?></td>
                                    <td><?= e($po['booker_name']) ?></td>
                                    <td class="text-end fw-semibold"><?= format_currency($po['net_amount']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $po['status'] === 'confirmed' ? 'success' : 'secondary' ?>-subtle text-<?= $po['status'] === 'confirmed' ? 'success' : 'dark' ?> border">
                                            <?= ucfirst($po['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Active Route Settlements Pipeline -->
    <div class="col-md-6">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-cash-coin me-1 text-warning"></i> Route Settlements Requiring Reconciliation</span>
                <a href="<?= APP_URL ?>/modules/settlements/index.php" class="btn btn-xs btn-outline-warning text-dark py-0 px-2 small">View Settlements</a>
            </div>
            <div class="table-responsive">
                <table class="table table-compact mb-0">
                    <thead>
                        <tr>
                            <th>Settlement #</th>
                            <th>Salesman</th>
                            <th class="text-end">Total Bills</th>
                            <th class="text-end">Cash In</th>
                            <th class="text-end">Diff</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingSettlementRows)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-check2-circle fs-4 d-block text-success mb-1"></i>
                                    All route settlements are fully reconciled and finalized.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingSettlementRows as $ps): ?>
                                <tr>
                                    <td><a href="<?= APP_URL ?>/modules/settlements/edit.php?id=<?= $ps['id'] ?>" class="fw-semibold text-dark"><?= e($ps['settlement_number']) ?></a></td>
                                    <td><?= e($ps['salesman_name']) ?></td>
                                    <td class="text-end"><?= format_currency($ps['total_invoices_amount']) ?></td>
                                    <td class="text-end text-success fw-semibold"><?= format_currency($ps['total_cash_received']) ?></td>
                                    <td class="text-end <?= (float)$ps['difference_amount'] != 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                        <?= format_currency($ps['difference_amount']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning-subtle text-dark border">
                                            <?= ucfirst($ps['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
