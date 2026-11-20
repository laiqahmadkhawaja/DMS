<?php
/**
 * DMS - Edit Measurement Unit
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$unit = db_fetch_one("SELECT * FROM units WHERE id = ? AND is_deleted = 0", [$id]);

if (!$unit) {
    set_flash_message('danger', 'Unit not found.');
    redirect(APP_URL . '/modules/units/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitCode = strtoupper(clean_input($_POST['unit_code'] ?? ''));
    $unitName = clean_input($_POST['unit_name'] ?? '');
    $symbol   = clean_input($_POST['symbol'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('unit_code', $unitCode)
              ->unique('unit_code', $unitCode, 'units', 'unit_code', $id)
              ->required('unit_name', $unitName)
              ->required('symbol', $symbol);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE units 
                SET unit_code = ?, unit_name = ?, symbol = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$unitCode, $unitName, $symbol, $isActive, $id]);

            log_audit('update_unit', 'units', $id, "Updated unit: {$unitName}");
            set_flash_message('success', "Unit '{$unitName}' updated successfully.");
            redirect(APP_URL . '/modules/units/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating unit: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Unit: ' . $unit['unit_name'] . ' — ' . APP_NAME;
$activeMenu = 'products';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Packaging Unit</h5>
        <small class="text-muted">Modify symbol or status for <?= e($unit['unit_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/units/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Units (ESC)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-box me-1"></i> Unit Details</span>
            </div>
            <form method="POST" action="<?= APP_URL ?>/modules/units/edit.php?id=<?= $unit['id'] ?>">
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Unit Code <span class="text-danger">*</span></label>
                        <input type="text" name="unit_code" class="form-control form-control-sm" value="<?= e($unit['unit_code']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Unit Name <span class="text-danger">*</span></label>
                        <input type="text" name="unit_name" class="form-control form-control-sm" value="<?= e($unit['unit_name']) ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Symbol <span class="text-danger">*</span></label>
                        <input type="text" name="symbol" class="form-control form-control-sm" value="<?= e($unit['symbol']) ?>" required>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editUnitActive" <?= $unit['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="editUnitActive">Unit Active in Order Entry</label>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <a href="<?= APP_URL ?>/modules/units/index.php" class="btn btn-sm btn-light border me-1">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i> Update Unit (F9)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
