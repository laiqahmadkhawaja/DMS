<?php
/**
 * DMS - Edit Delivery Salesman
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$salesman = db_fetch_one("SELECT * FROM salesmen WHERE id = ? AND is_deleted = 0", [$id]);

if (!$salesman) {
    set_flash_message('danger', 'Salesman record not found.');
    redirect(APP_URL . '/modules/salesmen/index.php');
}

$allAreas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeCode   = clean_input($_POST['employee_code'] ?? '');
    $salesmanName   = clean_input($_POST['salesman_name'] ?? '');
    $phone          = clean_input($_POST['phone'] ?? '');
    $vehicleNumber  = clean_input($_POST['vehicle_number'] ?? '');
    $role           = clean_input($_POST['role'] ?? 'delivery_man');
    $assignedAreaId = !empty($_POST['assigned_area_id']) ? (int)$_POST['assigned_area_id'] : null;
    $notes          = clean_input($_POST['notes'] ?? '');
    $isActive       = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('employee_code', $employeeCode)
              ->unique('employee_code', $employeeCode, 'salesmen', 'employee_code', $id)
              ->required('salesman_name', $salesmanName)
              ->required('phone', $phone);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE salesmen 
                SET employee_code = ?, salesman_name = ?, phone = ?, vehicle_number = ?, role = ?, 
                    assigned_area_id = ?, notes = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$employeeCode, $salesmanName, $phone, $vehicleNumber, $role, $assignedAreaId, $notes, $isActive, $id]);

            log_audit('update_salesman', 'salesmen', $id, "Updated salesman: {$salesmanName}");
            set_flash_message('success', "Salesman '{$salesmanName}' updated successfully.");
            redirect(APP_URL . '/modules/salesmen/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating salesman: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Salesman: ' . $salesman['salesman_name'] . ' — ' . APP_NAME;
$activeMenu = 'salesmen';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Sales Staff</h5>
        <small class="text-muted">Update contact and vehicle assignments for <?= e($salesman['salesman_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/salesmen/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Salesmen (ESC)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-person-badge me-1"></i> Staff Details</span>
            </div>
            <form method="POST" action="<?= APP_URL ?>/modules/salesmen/edit.php?id=<?= $salesman['id'] ?>">
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Employee Code <span class="text-danger">*</span></label>
                            <input type="text" name="employee_code" class="form-control form-control-sm" value="<?= e($salesman['employee_code']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select form-select-sm" required>
                                <option value="delivery_man" <?= $salesman['role'] === 'delivery_man' ? 'selected' : '' ?>>Delivery Salesman</option>
                                <option value="van_driver" <?= $salesman['role'] === 'van_driver' ? 'selected' : '' ?>>Van Driver</option>
                                <option value="salesman" <?= $salesman['role'] === 'salesman' ? 'selected' : '' ?>>Sales Representative</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="salesman_name" class="form-control form-control-sm" value="<?= e($salesman['salesman_name']) ?>" required autofocus>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone / Mobile <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($salesman['phone']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Assigned Vehicle Number</label>
                            <input type="text" name="vehicle_number" class="form-control form-control-sm" value="<?= e($salesman['vehicle_number']) ?>" placeholder="e.g. GAK-1234">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                            <span>Primary Delivery Beat / Route</span>
                            <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                            <a href="javascript:void(0)" onclick="openQuickAreaDrawer('smEditAreaSelect')" class="text-primary text-decoration-none" title="Quick Add Route">
                                <i class="bi bi-plus-circle-fill"></i> New Beat
                            </a>
                            <?php endif; ?>
                        </label>
                        <div class="input-group input-group-sm">
                            <select name="assigned_area_id" id="smEditAreaSelect" class="form-select form-select-sm">
                                <option value="">-- No route assigned --</option>
                                <?php foreach ($allAreas as $ar): ?>
                                    <option value="<?= $ar['id'] ?>" <?= (int)$salesman['assigned_area_id'] === (int)$ar['id'] ? 'selected' : '' ?>>
                                        <?= e($ar['area_name']) ?> (<?= e($ar['city']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                            <button type="button" class="btn btn-outline-primary" onclick="openQuickAreaDrawer('smEditAreaSelect')" title="Add New Beat in Real-Time">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= e($salesman['notes']) ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="smActiveEdit" <?= $salesman['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="smActiveEdit">Active for Route Dispatch</label>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <a href="<?= APP_URL ?>/modules/salesmen/index.php" class="btn btn-sm btn-light border me-1">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i> Update Staff Member (F9)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
