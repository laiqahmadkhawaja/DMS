<?php
/**
 * DMS - Edit Area
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$area = db_fetch_one("SELECT * FROM areas WHERE id = ? AND is_deleted = 0", [$id]);

if (!$area) {
    set_flash_message('danger', 'Area record not found or has been removed.');
    redirect(APP_URL . '/modules/areas/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $areaCode    = clean_input($_POST['area_code'] ?? '');
    $areaName    = clean_input($_POST['area_name'] ?? '');
    $city        = clean_input($_POST['city'] ?? 'Gujranwala');
    $description = clean_input($_POST['description'] ?? '');
    $isActive    = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('area_code', $areaCode)
              ->unique('area_code', $areaCode, 'areas', 'area_code', $id)
              ->required('area_name', $areaName);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE areas 
                SET area_code = ?, area_name = ?, city = ?, description = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$areaCode, $areaName, $city, $description, $isActive, $id]);

            log_audit('update_area', 'areas', $id, "Updated area: {$areaName} ({$areaCode})");
            set_flash_message('success', "Area '{$areaName}' updated successfully.");
            redirect(APP_URL . '/modules/areas/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating area: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Area: ' . $area['area_name'] . ' — ' . APP_NAME;
$activeMenu = 'areas';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Area / Route</h5>
        <small class="text-muted">Update route details for <?= e($area['area_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/areas/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Areas (ESC)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-geo-alt me-1"></i> Area Information</span>
            </div>
            <form method="POST" action="<?= APP_URL ?>/modules/areas/edit.php?id=<?= $area['id'] ?>">
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Area / Route Code <span class="text-danger">*</span></label>
                        <input type="text" name="area_code" class="form-control form-control-sm" value="<?= e($area['area_code']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Area / Sector Name <span class="text-danger">*</span></label>
                        <input type="text" name="area_name" class="form-control form-control-sm" value="<?= e($area['area_name']) ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">City / District</label>
                        <input type="text" name="city" class="form-control form-control-sm" value="<?= e($area['city']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description / Notes</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3"><?= e($area['description']) ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editActive" <?= $area['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="editActive">Area Active for Order Booking & Delivery</label>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <a href="<?= APP_URL ?>/modules/areas/index.php" class="btn btn-sm btn-light border me-1">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i> Update Area (F9)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
