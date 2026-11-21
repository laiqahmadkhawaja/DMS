<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - Create Load Sheet
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('loading_create');

$pageTitle = 'Create Load Sheet';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Warehouse Loading', 'url' => 'index.php'],
    ['label' => 'New Load Sheet', 'url' => '']
];

$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loadDate     = sanitize_input($_POST['load_date'] ?? date('Y-m-d'));
    $deliveryDate = sanitize_input($_POST['delivery_date'] ?? date('Y-m-d'));
    $salesmanId   = (int)($_POST['salesman_id'] ?? 0);
    $warehouseId  = (int)($_POST['warehouse_id'] ?? 1);
    $actionType   = sanitize_input($_POST['action_type'] ?? 'draft'); // 'draft' or 'issue'
    $remarks      = sanitize_input($_POST['remarks'] ?? '');
    $selectedOrders = $_POST['order_ids'] ?? [];

    if ($salesmanId <= 0) {
        $errors[] = "Please select a delivery salesman.";
    }
    if ($warehouseId <= 0) {
        $errors[] = "Please select a warehouse.";
    }
    if (empty($selectedOrders) || !is_array($selectedOrders)) {
        $errors[] = "Please select at least one confirmed order to include in the load sheet.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $loadNumber = generate_document_number(PREFIX_LOAD, 'load_sheets', 'load_number');
            $userId = current_user_id();

            // Fetch order items for the selected orders to aggregate products
            $inPlaceholders = implode(',', array_fill(0, count($selectedOrders), '?'));
            $orderItems = db_fetch_all("
                SELECT 
                    oi.order_id,
                    oi.product_id,
                    p.product_name,
                    p.carton_qty as ctn_ratio,
                    p.base_unit_id,
                    oi.unit_id,
                    oi.carton_qty,
                    oi.pcs_qty,
                    oi.quantity as base_qty,
                    oi.rate,
                    oi.line_total
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                JOIN orders o ON oi.order_id = o.id
                WHERE oi.order_id IN ({$inPlaceholders}) AND o.status = 'confirmed'
            ", $selectedOrders);

            if (empty($orderItems)) {
                throw new Exception("None of the selected orders are in 'confirmed' status or contain items.");
            }

            // Aggregate items by product
            $aggregated = [];
            $totalLoadAmount = 0.0;

            foreach ($orderItems as $item) {
                $pid = (int)$item['product_id'];
                if (!isset($aggregated[$pid])) {
                    $aggregated[$pid] = [
                        'product_id'   => $pid,
                        'unit_id'      => (int)$item['base_unit_id'],
                        'product_name' => $item['product_name'],
                        'ctn_ratio'    => (float)$item['ctn_ratio'],
                        'carton_qty'   => 0.0,
                        'pcs_qty'      => 0.0,
                        'total_base_qty' => 0.0,
                        'rate'         => (float)$item['rate'],
                        'total_amount' => 0.0
                    ];
                }
                $aggregated[$pid]['carton_qty'] += (float)$item['carton_qty'];
                $aggregated[$pid]['pcs_qty'] += (float)$item['pcs_qty'];
                $aggregated[$pid]['total_base_qty'] += (float)$item['base_qty'];
                $aggregated[$pid]['total_amount'] += (float)$item['line_total'];
                $totalLoadAmount += (float)$item['line_total'];
            }

            $sheetStatus = ($actionType === 'issue') ? 'issued' : 'draft';
            $issuedAt    = ($actionType === 'issue') ? date('Y-m-d H:i:s') : null;
            $issuedBy    = ($actionType === 'issue') ? $userId : null;

            // 1. Insert load_sheets header
            $stmtSheet = db()->prepare("
                INSERT INTO load_sheets (
                    load_number, load_date, delivery_date, salesman_id, warehouse_id, 
                    total_orders, total_amount, status, remarks, created_by, issued_at, issued_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            $stmtSheet->execute([
                $loadNumber, $loadDate, $deliveryDate, $salesmanId, $warehouseId,
                count($selectedOrders), $totalLoadAmount, $sheetStatus, $remarks,
                $userId, $issuedAt, $issuedBy
            ]);
            $loadSheetId = (int)db()->lastInsertId();

            // 2. Insert load_sheet_orders
            $stmtLSO = db()->prepare("
                INSERT INTO load_sheet_orders (load_sheet_id, order_id, created_at)
                VALUES (?, ?, NOW())
            ");
            foreach ($selectedOrders as $ordId) {
                $stmtLSO->execute([$loadSheetId, (int)$ordId]);
            }

            // 3. Insert load_sheet_items & if issuing, execute stock movements
            $stmtLSI = db()->prepare("
                INSERT INTO load_sheet_items (
                    load_sheet_id, product_id, unit_id, ordered_qty, required_qty, 
                    loaded_qty, short_qty, returned_qty, rate, total_amount, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, 0.00, 0.00, ?, ?, NOW()
                )
            ");

            foreach ($aggregated as $agg) {
                $reqQty = $agg['total_base_qty'];
                $loadedQty = ($actionType === 'issue') ? $reqQty : 0.00;

                $stmtLSI->execute([
                    $loadSheetId, $agg['product_id'], $agg['unit_id'],
                    $reqQty, $reqQty, $loadedQty, $agg['rate'], $agg['total_amount']
                ]);

                // If issuing right away, record stock deduction
                if ($actionType === 'issue') {
                    record_stock_movement([
                        'product_id'     => $agg['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => 'sales_dispatch',
                        'reference_type' => 'load_sheet',
                        'reference_id'   => $loadSheetId,
                        'qty_in'         => 0,
                        'qty_out'        => $reqQty,
                        'unit_cost'      => $agg['rate'], // Or cost from product
                        'remarks'        => "Stock dispatch for Load Sheet #{$loadNumber}",
                        'condition_type' => 'saleable'
                    ]);
                }
            }

            // 4. Update order statuses and auto-generate Sales Invoices
            if ($actionType === 'issue') {
                $stmtUpdOrder = db()->prepare("UPDATE orders SET status = 'loaded', updated_at = NOW() WHERE id = ?");
                
                // Prepared statement for auto-invoices
                $stmtInv = db()->prepare("
                    INSERT INTO sales_invoices (
                        invoice_number, invoice_date, order_id, load_sheet_id, customer_id, 
                        booker_id, salesman_id, area_id, gross_amount, item_discount_amount, 
                        extra_discount_amount, net_amount, status, reconciliation_status, payment_status,
                        created_by, finalized_at, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 
                        ?, ?, ?, ?, ?, 
                        ?, ?, 'finalized', 'pending', 'unpaid',
                        ?, NOW(), NOW()
                    )
                ");

                $stmtInvItem = db()->prepare("
                    INSERT INTO sales_invoice_items (
                        invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, 
                        quantity, rate, discount_percent, discount_amount, line_total, created_at
                    ) VALUES (
                        ?, ?, ?, ?, 0.00, ?, 
                        ?, ?, ?, ?, ?, NOW()
                    )
                ");

                foreach ($selectedOrders as $ordId) {
                    $stmtUpdOrder->execute([(int)$ordId]);

                    $ord = db_fetch_one("SELECT * FROM orders WHERE id = ?", [(int)$ordId]);
                    if ($ord) {
                        $invNumber = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');
                        $stmtInv->execute([
                            $invNumber, $deliveryDate, $ord['id'], $loadSheetId, $ord['customer_id'],
                            $ord['booker_id'], $salesmanId, $ord['area_id'], $ord['gross_amount'],
                            $ord['discount_amount'], 0.00, $ord['net_amount'],
                            $userId
                        ]);
                        $invoiceId = (int)db()->lastInsertId();

                        // Copy order items into invoice items
                        $thisOrderItems = db_fetch_all("SELECT * FROM order_items WHERE order_id = ?", [$ord['id']]);
                        foreach ($thisOrderItems as $toi) {
                            $stmtInvItem->execute([
                                $invoiceId, $toi['product_id'], $toi['unit_id'],
                                $toi['carton_qty'], $toi['pcs_qty'], $toi['quantity'],
                                $toi['rate'], $toi['discount_percent'], $toi['discount_amount'],
                                $toi['line_total']
                            ]);
                        }
                    }
                }
            }

            db()->commit();

            set_flash('success', "Load Sheet <strong>{$loadNumber}</strong> successfully " . ($actionType === 'issue' ? 'issued and dispatched with customer bills generated!' : 'saved as draft.'));
            redirect(APP_URL . '/modules/loading/view.php?id=' . $loadSheetId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error creating load sheet: " . $e->getMessage());
            $errors[] = "Error saving load sheet: " . $e->getMessage();
        }
    }
}

// Data for dropdowns
$salesmen   = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");
$warehouses = db_fetch_all("SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC");
$areas      = db_fetch_all("SELECT id, area_name FROM areas WHERE is_active = 1 ORDER BY area_name ASC");
$bookers    = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_active = 1 ORDER BY booker_name ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-truck-flatbed me-2"></i>Create Warehouse Loading Sheet
                </h4>
                <p class="text-muted small mb-0">Select route orders, review consolidated stock requirements, and issue stock for dispatch</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Load Sheets
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Unable to proceed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="create.php" id="loadSheetForm">
            <input type="hidden" name="action_type" id="actionType" value="draft">

            <!-- Sheet Header Setup -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-sliders me-1"></i> 1. Route & Dispatch Parameters</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Load Date <span class="text-danger">*</span></label>
                            <input type="date" name="load_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Date <span class="text-danger">*</span></label>
                            <input type="date" name="delivery_date" id="deliveryDate" class="form-control" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Salesman <span class="text-danger">*</span></label>
                            <select name="salesman_id" id="salesmanSelect" class="form-select" required>
                                <option value="">-- Select Salesman --</option>
                                <?php foreach ($salesmen as $sm): ?>
                                    <option value="<?= $sm['id'] ?>"><?= htmlspecialchars($sm['salesman_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Dispatch Warehouse <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filter Bar to narrow down orders -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Filter by Route / Area</label>
                            <select id="filterArea" class="form-select form-select-sm">
                                <option value="">-- All Routes / Areas --</option>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['area_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Filter by Order Booker</label>
                            <select id="filterBooker" class="form-select form-select-sm">
                                <option value="">-- All Bookers --</option>
                                <?php foreach ($bookers as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['booker_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Filter by Delivery Date</label>
                            <select id="filterDeliveryDate" class="form-select form-select-sm">
                                <option value="all" selected>-- All Confirmed Orders (Any Date) --</option>
                                <option value="match">Match Header Delivery Date</option>
                                <option value="today">Today (<?= date('d M Y') ?>)</option>
                                <option value="tomorrow">Tomorrow (<?= date('d M Y', strtotime('+1 day')) ?>)</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" id="btnFetchOrders" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-arrow-repeat me-1"></i> Refresh Confirmed Orders
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Confirmed Orders Selection Table -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy">
                        <i class="bi bi-cart-check me-1"></i> 2. Select Confirmed Orders for Loading 
                        <span class="badge bg-secondary ms-2" id="orderCountBadge">0 available</span>
                    </h6>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-xs btn-outline-secondary" id="btnSelectAll">Select All</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" id="btnDeselectAll">Deselect All</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0" id="ordersTable">
                            <thead class="table-light text-muted small text-uppercase sticky-top">
                                <tr>
                                    <th class="ps-3" style="width: 40px;">
                                        <input type="checkbox" class="form-check-input" id="checkMaster">
                                    </th>
                                    <th>Order #</th>
                                    <th>Delivery Date</th>
                                    <th>Customer / Retail Shop</th>
                                    <th>Route / Area</th>
                                    <th>Booker</th>
                                    <th class="text-center">Cartons</th>
                                    <th class="text-center">Units</th>
                                    <th class="text-end pe-3">Order Net (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTbody">
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <i class="bi bi-hourglass-split me-1"></i> Loading confirmed orders...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-muted">
                        Selected: <span id="lblSelectedOrdersCount" class="text-navy fw-bold">0</span> orders
                    </span>
                    <span class="fw-bold text-navy fs-6">
                        Selected Orders Value: <span id="lblSelectedOrdersAmount">Rs. 0.00</span>
                    </span>
                </div>
            </div>

            <!-- Consolidated Dispatch Summary (Matches Photo 5 & 9 TOTAL STOCK ISSUE SUMMARY) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-navy text-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 fw-bold text-white">
                            <i class="bi bi-boxes me-1"></i> 3. TOTAL STOCK ISSUE SUMMARY (Consolidated Dispatch List)
                        </h6>
                        <small class="text-white-50">Matches client warehouse loading format</small>
                    </div>
                    <span class="badge bg-light text-navy fw-bold" id="badgeUniqueProducts">0 Products</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0" id="summaryTable">
                            <thead class="bg-light text-navy small text-uppercase text-center">
                                <tr>
                                    <th style="width: 50px;">Sr.</th>
                                    <th class="text-start">Product Description</th>
                                    <th style="width: 120px;">Ctn Qty</th>
                                    <th style="width: 120px;">Pcs Qty</th>
                                    <th style="width: 130px;">Total Base Units</th>
                                    <th style="width: 140px;">Wholesale Rate</th>
                                    <th style="width: 160px;" class="text-end pe-3">Total Amount (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody id="summaryTbody">
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        Select confirmed orders above to automatically calculate consolidated warehouse dispatch requirements.
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light fw-bold" id="summaryTfoot" style="display: none;">
                                <tr>
                                    <td colspan="2" class="text-end text-uppercase">Total Dispatch:</td>
                                    <td class="text-center" id="footCtn">0</td>
                                    <td class="text-center" id="footPcs">0</td>
                                    <td class="text-center" id="footUnits">0</td>
                                    <td></td>
                                    <td class="text-end pe-3 text-navy fs-6" id="footTotal">Rs. 0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Footer Remarks & Submission Actions -->
            <div class="card border-0 shadow-sm mb-5">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Loading & Dispatch Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="e.g. Morning delivery run route 1 (Abdur Rehman)">
                        </div>
                        <div class="col-md-6 text-end d-flex gap-2 justify-content-end align-items-end mt-3 mt-md-0">
                            <a href="index.php" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                            <button type="button" class="btn btn-warning shadow-sm" id="btnSaveDraft">
                                <i class="bi bi-file-earmark me-1"></i> Save as Draft
                            </button>
                            <?php if (has_permission('loading_issue')): ?>
                                <button type="button" class="btn btn-success shadow-sm px-4 fw-bold" id="btnIssueStock">
                                    <i class="bi bi-box-arrow-right me-1"></i> Issue Stock & Dispatch Bills <kbd class="bg-navy ms-1">F9</kbd>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let fetchedOrders = [];

// Fetch confirmed orders via AJAX
function loadConfirmedOrders() {
    const areaId = document.getElementById('filterArea').value;
    const bookerId = document.getElementById('filterBooker').value;
    const dateFilter = document.getElementById('filterDeliveryDate').value;
    let deliveryDateParam = 'all';

    if (dateFilter === 'match') {
        deliveryDateParam = document.getElementById('deliveryDate').value;
    } else if (dateFilter === 'today') {
        deliveryDateParam = 'today';
    } else if (dateFilter === 'tomorrow') {
        deliveryDateParam = 'tomorrow';
    }

    const tbody = document.getElementById('ordersTbody');
    tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted"><i class="bi bi-hourglass-split me-1"></i> Fetching confirmed orders...</td></tr>`;

    fetch(`../../ajax/loading/get_orders.php?area_id=${areaId}&booker_id=${bookerId}&delivery_date=${encodeURIComponent(deliveryDateParam)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchedOrders = data.data.orders || [];
                renderOrdersTable();
            } else {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-danger">${data.message}</td></tr>`;
            }
        })
        .catch(err => {
            console.error(err);
            tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-danger">Error connecting to server.</td></tr>`;
        });
}

function formatDate(str) {
    if (!str) return '-';
    const parts = str.split('-');
    if (parts.length === 3) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const mIdx = parseInt(parts[1], 10) - 1;
        return `${parts[2]}-${months[mIdx] || parts[1]}-${parts[0]}`;
    }
    return str;
}

function renderOrdersTable() {
    const tbody = document.getElementById('ordersTbody');
    const badge = document.getElementById('orderCountBadge');

    badge.innerText = `${fetchedOrders.length} available`;

    if (fetchedOrders.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted"><i class="bi bi-info-circle me-1"></i> No confirmed orders found for these filters.</td></tr>`;
        recalculateSummary();
        return;
    }

    let html = '';
    fetchedOrders.forEach((o, idx) => {
        html += `
            <tr>
                <td class="ps-3">
                    <input type="checkbox" name="order_ids[]" value="${o.id}" class="form-check-input order-checkbox" id="chk_${o.id}" data-index="${idx}" checked>
                </td>
                <td class="fw-bold text-navy">
                    <a href="../orders/view.php?id=${o.id}" target="_blank" class="text-decoration-none">
                        ${o.order_number}
                    </a>
                </td>
                <td>
                    <div class="fw-bold text-navy"><i class="bi bi-calendar2-check me-1 text-primary"></i>${formatDate(o.delivery_date)}</div>
                    <small class="text-muted">Booked: ${formatDate(o.order_date)}</small>
                </td>
                <td>
                    <div class="fw-semibold text-dark">${escapeHtml(o.customer_name)}</div>
                    <small class="text-muted">${escapeHtml(o.customer_address || '')}</small>
                </td>
                <td><span class="badge bg-light text-dark border">${escapeHtml(o.area_name)}</span></td>
                <td>${escapeHtml(o.booker_name)}</td>
                <td class="text-center fw-semibold">${parseFloat(o.total_cartons).toFixed(0)}</td>
                <td class="text-center">${parseFloat(o.total_units).toFixed(0)}</td>
                <td class="text-end pe-3 fw-bold text-navy">Rs. ${parseFloat(o.net_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    document.getElementById('checkMaster').checked = true;

    // Attach change listeners
    document.querySelectorAll('.order-checkbox').forEach(cb => {
        cb.addEventListener('change', recalculateSummary);
    });

    recalculateSummary();
}

function recalculateSummary() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    const selectedIndices = Array.from(checkboxes).map(cb => parseInt(cb.getAttribute('data-index')));

    let totalVal = 0;
    let consolidated = {};

    selectedIndices.forEach(idx => {
        const order = fetchedOrders[idx];
        if (!order) return;

        totalVal += parseFloat(order.net_amount) || 0;

        (order.items || []).forEach(item => {
            const pid = item.product_id;
            if (!consolidated[pid]) {
                consolidated[pid] = {
                    name: item.product_name,
                    ctn_ratio: parseFloat(item.ctn_ratio) || 1,
                    carton_qty: 0,
                    pcs_qty: 0,
                    total_base_qty: 0,
                    rate: parseFloat(item.rate) || 0,
                    total_amount: 0
                };
            }
            consolidated[pid].carton_qty += parseFloat(item.carton_qty) || 0;
            consolidated[pid].pcs_qty += parseFloat(item.pcs_qty) || 0;
            consolidated[pid].total_base_qty += parseFloat(item.quantity) || 0;
            consolidated[pid].total_amount += parseFloat(item.line_total) || 0;
        });
    });

    document.getElementById('lblSelectedOrdersCount').innerText = selectedIndices.length;
    document.getElementById('lblSelectedOrdersAmount').innerText = 'Rs. ' + totalVal.toLocaleString('en-US', {minimumFractionDigits: 2});

    renderSummaryTable(consolidated);
}

function renderSummaryTable(consolidated) {
    const tbody = document.getElementById('summaryTbody');
    const tfoot = document.getElementById('summaryTfoot');
    const badge = document.getElementById('badgeUniqueProducts');

    const keys = Object.keys(consolidated);
    badge.innerText = `${keys.length} Products`;

    if (keys.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No orders selected. Please select at least one order above.</td></tr>`;
        tfoot.style.display = 'none';
        return;
    }

    let html = '';
    let sr = 1;
    let sumCtn = 0;
    let sumPcs = 0;
    let sumUnits = 0;
    let sumTotal = 0;

    keys.forEach(pid => {
        const p = consolidated[pid];
        sumCtn += p.carton_qty;
        sumPcs += p.pcs_qty;
        sumUnits += p.total_base_qty;
        sumTotal += p.total_amount;

        html += `
            <tr>
                <td class="text-center">${sr++}</td>
                <td class="fw-semibold text-dark">${escapeHtml(p.name)}</td>
                <td class="text-center fw-bold">${p.carton_qty > 0 ? p.carton_qty : '-'}</td>
                <td class="text-center">${p.pcs_qty > 0 ? p.pcs_qty : '-'}</td>
                <td class="text-center fw-bold text-navy">${p.total_base_qty}</td>
                <td class="text-center">Rs. ${p.rate.toFixed(2)}</td>
                <td class="text-end pe-3 fw-bold">Rs. ${p.total_amount.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    tfoot.style.display = '';
    document.getElementById('footCtn').innerText = sumCtn;
    document.getElementById('footPcs').innerText = sumPcs;
    document.getElementById('footUnits').innerText = sumUnits;
    document.getElementById('footTotal').innerText = 'Rs. ' + sumTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    loadConfirmedOrders();

    document.getElementById('btnFetchOrders').addEventListener('click', loadConfirmedOrders);
    document.getElementById('filterArea').addEventListener('change', loadConfirmedOrders);
    document.getElementById('filterBooker').addEventListener('change', loadConfirmedOrders);
    document.getElementById('filterDeliveryDate').addEventListener('change', loadConfirmedOrders);
    document.getElementById('deliveryDate').addEventListener('change', function() {
        if (document.getElementById('filterDeliveryDate').value === 'match') {
            loadConfirmedOrders();
        }
    });

    document.getElementById('checkMaster').addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = checked);
        recalculateSummary();
    });

    document.getElementById('btnSelectAll').addEventListener('click', function() {
        document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = true);
        document.getElementById('checkMaster').checked = true;
        recalculateSummary();
    });

    document.getElementById('btnDeselectAll').addEventListener('click', function() {
        document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('checkMaster').checked = false;
        recalculateSummary();
    });

    document.getElementById('btnSaveDraft').addEventListener('click', function() {
        if (!validateForm()) return;
        document.getElementById('actionType').value = 'draft';
        document.getElementById('loadSheetForm').submit();
    });

    const btnIssue = document.getElementById('btnIssueStock');
    if (btnIssue) {
        btnIssue.addEventListener('click', function() {
            if (!validateForm()) return;
            if (confirm('Issue stock and dispatch? This will immediately deduct warehouse inventory and generate customer sales invoices for route delivery.')) {
                document.getElementById('actionType').value = 'issue';
                document.getElementById('loadSheetForm').submit();
            }
        });
    }

    function validateForm() {
        const sm = document.getElementById('salesmanSelect').value;
        if (!sm) {
            alert('Please select a delivery salesman.');
            return false;
        }
        const checked = document.querySelectorAll('.order-checkbox:checked');
        if (checked.length === 0) {
            alert('Please select at least one confirmed order.');
            return false;
        }
        return true;
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'F9') {
            e.preventDefault();
            if (btnIssue) btnIssue.click();
        }
    });
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
