<?php
/**
 * DMS - Edit Warehouse / Depot
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$warehouse = db_fetch_one("SELECT * FROM warehouses WHERE id = ? AND is_deleted = 0", [$id]);

if (!$warehouse) {
    set_flash_message('danger', 'Warehouse not found.');
    redirect(APP_URL . '/modules/warehouses/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $warehouseCode = strtoupper(clean_input($_POST['warehouse_code'] ?? ''));
    $warehouseName = clean_input($_POST['warehouse_name'] ?? '');
    $location      = clean_input($_POST['location'] ?? '');
    $isPrimary     = isset($_POST['is_primary']) ? 1 : 0;
    $isActive      = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('warehouse_code', $warehouseCode)
              ->unique('warehouse_code', $warehouseCode, 'warehouses', 'warehouse_code', $id)
              ->required('warehouse_name', $warehouseName);

    if ($validator->passes()) {
        try {
            db()->beginTransaction();

            if ($isPrimary) {
                // Remove primary from all others
                db_execute("UPDATE warehouses SET is_primary = 0 WHERE id != ? AND is_deleted = 0", [$id]);
            } elseif ($warehouse['is_primary']) {
                // If unchecking primary on the current primary, ensure at least one primary remains
                $otherActive = db_fetch_val("SELECT id FROM warehouses WHERE id != ? AND is_active = 1 AND is_deleted = 0 LIMIT 1", [$id]);
                if ($otherActive) {
                    db_execute("UPDATE warehouses SET is_primary = 1 WHERE id = ?", [$otherActive]);
                } else {
                    $isPrimary = 1; // Must keep primary if no other warehouse exists
                }
            }

            $stmt = db()->prepare("
                UPDATE warehouses 
                SET warehouse_code = ?, warehouse_name = ?, location = ?, is_primary = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$warehouseCode, $warehouseName, $location, $isPrimary, $isActive, $id]);

            log_audit('update_warehouse', 'warehouses', $id, "Updated warehouse: {$warehouseName} ({$warehouseCode})");
            
            db()->commit();

            set_flash_message('success', "Warehouse '{$warehouseName}' updated successfully.");
            redirect(APP_URL . '/modules/warehouses/index.php');
        } catch (Exception $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            set_flash_message('danger', 'Error updating warehouse: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Warehouse: ' . $warehouse['warehouse_name'] . ' — ' . APP_NAME;
$activeMenu = 'warehouses';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Warehouse / Depot</h5>
        <small class="text-muted">Update storage location details and operational status</small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/warehouses/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Warehouses (ESC)
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="card-dms p-4">
            <form method="POST" action="<?= APP_URL ?>/modules/warehouses/edit.php?id=<?= $warehouse['id'] ?>">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Warehouse Code <span class="text-danger">*</span></label>
                    <input type="text" name="warehouse_code" class="form-control form-control-sm font-monospace" value="<?= e($warehouse['warehouse_code']) ?>" required>
                    <small class="text-muted">Must be unique across all storage facilities</small>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Warehouse / Depot Name <span class="text-danger">*</span></label>
                    <input type="text" name="warehouse_name" class="form-control form-control-sm" value="<?= e($warehouse['warehouse_name']) ?>" required autofocus>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Physical Location / Address</label>
                    <textarea name="location" class="form-control form-control-sm" rows="3"><?= e($warehouse['location']) ?></textarea>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="whPrimaryEdit" <?= $warehouse['is_primary'] ? 'checked' : '' ?>>
                    <label class="form-check-label small fw-semibold" for="whPrimaryEdit">Default Primary Warehouse Hub</label>
                    <div class="text-muted small">Primary warehouse is selected automatically on loading sheets and GRNs.</div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="whActiveEdit" <?= $warehouse['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label small fw-semibold" for="whActiveEdit">Warehouse Active & Operational</label>
                    <div class="text-muted small">Inactive facilities cannot be chosen for new order dispatches or GRNs.</div>
                </div>

                <div class="d-flex gap-2">
                    <a href="<?= APP_URL ?>/modules/warehouses/index.php" class="btn btn-sm btn-light border">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary px-4">
                        <i class="bi bi-check2 me-1"></i> Save Changes (F9)
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="col-md-5">
        <div class="card-dms p-3 bg-light">
            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-info-circle me-1 text-primary"></i> Warehouse Information</h6>
            <p class="small text-muted mb-2">
                Warehouses represent your company's physical stock storage centers. Every inventory movement (purchases, sales dispatches, returns, and damages) is tracked per warehouse.
            </p>
            <ul class="small text-muted ps-3 mb-0">
                <li>Primary Hub is used as default when booking loads and GRNs.</li>
                <li>You cannot delete a warehouse that already has stock transactions. Instead, mark it Inactive.</li>
            </ul>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
