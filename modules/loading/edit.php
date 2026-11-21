<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - Edit Load Sheet
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('loading_create');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid load sheet ID.');
    redirect(APP_URL . '/modules/loading/index.php');
}

$sheet = db_fetch_one("SELECT * FROM load_sheets WHERE id = ?", [$id]);
if (!$sheet) {
    set_flash('danger', 'Load sheet not found.');
    redirect(APP_URL . '/modules/loading/index.php');
}

$salesmen   = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");
$warehouses = db_fetch_all("SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY is_primary DESC, warehouse_name ASC");

// Fetch included orders
$includedOrders = db_fetch_all("
    SELECT o.id, o.order_number, o.delivery_date, c.shop_name, a.area_name, b.booker_name, o.net_amount
    FROM load_sheet_orders lso
    JOIN orders o ON lso.order_id = o.id
    JOIN customers c ON o.customer_id = c.id
    JOIN areas a ON o.area_id = a.id
    JOIN bookers b ON o.booker_id = b.id
    WHERE lso.load_sheet_id = ?
    ORDER BY a.area_name ASC, c.shop_name ASC
", [$id]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loadDate     = sanitize_input($_POST['load_date'] ?? $sheet['load_date']);
    $deliveryDate = sanitize_input($_POST['delivery_date'] ?? $sheet['delivery_date']);
    $salesmanId   = (int)($_POST['salesman_id'] ?? $sheet['salesman_id']);
    $warehouseId  = (int)($_POST['warehouse_id'] ?? $sheet['warehouse_id']);
    $remarks      = sanitize_input($_POST['remarks'] ?? '');

    if ($salesmanId <= 0) {
        $errors[] = "Please select a delivery salesman.";
    }
    if ($warehouseId <= 0) {
        $errors[] = "Please select a warehouse.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $stmtUpd = db()->prepare("
                UPDATE load_sheets 
                SET load_date = ?, delivery_date = ?, salesman_id = ?, warehouse_id = ?, 
                    remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $loadDate, $deliveryDate, $salesmanId, $warehouseId, $remarks, $id
            ]);

            // If salesman or delivery date changed, also update linked sales invoices
            db()->prepare("
                UPDATE sales_invoices 
                SET salesman_id = ?, invoice_date = ?, updated_at = NOW() 
                WHERE load_sheet_id = ?
            ")->execute([$salesmanId, $deliveryDate, $id]);

            db()->commit();

            log_audit('update_load_sheet', 'load_sheets', $id, "Updated load sheet #{$sheet['load_number']}");
            set_flash('success', "Load sheet <strong>{$sheet['load_number']}</strong> updated successfully!");
            redirect(APP_URL . '/modules/loading/view.php?id=' . $id);

        } catch (Exception $e) {
            db()->rollBack();
            $errors[] = "Error updating load sheet: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Load Sheet ' . $sheet['load_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Warehouse Loading', 'url' => 'index.php'],
    ['label' => $sheet['load_number'], 'url' => 'view.php?id=' . $id],
    ['label' => 'Edit', 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Edit Load Sheet: <?= htmlspecialchars($sheet['load_number']) ?>
                </h4>
                <p class="text-muted small mb-0">Modify route dispatch parameters, delivery dates, or delivery personnel</p>
            </div>
            <div class="d-flex gap-2">
                <a href="view.php?id=<?= $sheet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Load Sheet
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

        <form method="POST" action="edit.php?id=<?= $sheet['id'] ?>">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-sliders me-1"></i> Dispatch Parameters</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Load Date <span class="text-danger">*</span></label>
                            <input type="date" name="load_date" class="form-control" value="<?= htmlspecialchars($sheet['load_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Date <span class="text-danger">*</span></label>
                            <input type="date" name="delivery_date" class="form-control" value="<?= htmlspecialchars($sheet['delivery_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Salesman <span class="text-danger">*</span></label>
                            <select name="salesman_id" class="form-select" required>
                                <?php foreach ($salesmen as $sm): ?>
                                    <option value="<?= $sm['id'] ?>" <?= $sm['id'] == $sheet['salesman_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sm['salesman_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Dispatch Warehouse <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>" <?= $wh['id'] == $sheet['warehouse_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($wh['warehouse_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Loading & Dispatch Remarks</label>
                            <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($sheet['remarks'] ?? '') ?>" placeholder="e.g. Morning delivery run route 1">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Included Orders Overview -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-cart-check me-1"></i> Dispatched Orders (<?= count($includedOrders) ?> Orders)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase">
                                <tr>
                                    <th class="ps-3">Order #</th>
                                    <th>Customer / Shop</th>
                                    <th>Route / Area</th>
                                    <th>Booker</th>
                                    <th class="text-end pe-3">Order Amount (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($includedOrders as $ord): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-navy"><?= htmlspecialchars($ord['order_number']) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($ord['shop_name']) ?></td>
                                        <td><?= htmlspecialchars($ord['area_name']) ?></td>
                                        <td><?= htmlspecialchars($ord['booker_name']) ?></td>
                                        <td class="text-end pe-3 fw-bold text-navy">Rs. <?= number_format((float)$ord['net_amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white py-3 px-3 d-flex justify-content-end gap-2">
                    <a href="view.php?id=<?= $sheet['id'] ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold">
                        <i class="bi bi-check2-circle me-1"></i> Save Changes (F9)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
