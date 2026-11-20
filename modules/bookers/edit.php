<?php
/**
 * DMS - Edit Order Booker
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$booker = db_fetch_one("SELECT * FROM bookers WHERE id = ? AND is_deleted = 0", [$id]);

if (!$booker) {
    set_flash_message('danger', 'Booker record not found.');
    redirect(APP_URL . '/modules/bookers/index.php');
}

// Assigned area IDs
$assignedAreaIds = db_fetch_all("SELECT area_id FROM booker_areas WHERE booker_id = ?", [$id]);
$assignedAreaIds = array_column($assignedAreaIds, 'area_id');

// Active areas list
$allAreas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookerCode     = clean_input($_POST['booker_code'] ?? '');
    $bookerName     = clean_input($_POST['booker_name'] ?? '');
    $phone          = clean_input($_POST['phone'] ?? '');
    $cnic           = clean_input($_POST['cnic'] ?? '');
    $commissionRate = (float)($_POST['commission_rate'] ?? 0);
    $notes          = clean_input($_POST['notes'] ?? '');
    $isActive       = isset($_POST['is_active']) ? 1 : 0;
    $areaIds        = $_POST['area_ids'] ?? [];

    $validator = new Validator();
    $validator->required('booker_code', $bookerCode)
              ->unique('booker_code', $bookerCode, 'bookers', 'booker_code', $id)
              ->required('booker_name', $bookerName)
              ->required('phone', $phone);

    if ($validator->passes()) {
        try {
            db_transaction(function ($pdo) use ($id, $bookerCode, $bookerName, $phone, $cnic, $commissionRate, $notes, $isActive, $areaIds) {
                $stmt = $pdo->prepare("
                    UPDATE bookers 
                    SET booker_code = ?, booker_name = ?, phone = ?, cnic = ?, commission_rate = ?, notes = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$bookerCode, $bookerName, $phone, $cnic, $commissionRate, $notes, $isActive, $id]);

                // Reset and re-assign areas
                $pdo->prepare("DELETE FROM booker_areas WHERE booker_id = ?")->execute([$id]);
                if (!empty($areaIds) && is_array($areaIds)) {
                    $stmtArea = $pdo->prepare("INSERT INTO booker_areas (booker_id, area_id, created_at) VALUES (?, ?, NOW())");
                    foreach ($areaIds as $areaId) {
                        $stmtArea->execute([$id, (int)$areaId]);
                    }
                }

                log_audit('update_booker', 'bookers', $id, "Updated booker: {$bookerName}");
            });

            set_flash_message('success', "Booker '{$bookerName}' updated successfully.");
            redirect(APP_URL . '/modules/bookers/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating booker: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Booker: ' . $booker['booker_name'] . ' — ' . APP_NAME;
$activeMenu = 'bookers';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Order Booker</h5>
        <small class="text-muted">Modify details and assigned territories for <?= e($booker['booker_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/bookers/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Bookers (ESC)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-person me-1"></i> Booker Information</span>
            </div>
            <form method="POST" action="<?= APP_URL ?>/modules/bookers/edit.php?id=<?= $booker['id'] ?>">
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Booker Code <span class="text-danger">*</span></label>
                            <input type="text" name="booker_code" class="form-control form-control-sm" value="<?= e($booker['booker_code']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="booker_name" class="form-control form-control-sm" value="<?= e($booker['booker_name']) ?>" required autofocus>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone / Mobile <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($booker['phone']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">CNIC</label>
                            <input type="text" name="cnic" class="form-control form-control-sm" value="<?= e($booker['cnic']) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Commission Rate (%)</label>
                        <input type="number" step="0.01" name="commission_rate" class="form-control form-control-sm" value="<?= e($booker['commission_rate']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Assigned Market Route(s)</label>
                        <div class="border rounded p-2 bg-light" style="max-height: 180px; overflow-y: auto;">
                            <?php foreach ($allAreas as $ar): ?>
                                <div class="form-check form-check-sm mb-1">
                                    <input class="form-check-input" type="checkbox" name="area_ids[]" value="<?= $ar['id'] ?>" id="edit_area_<?= $ar['id'] ?>" <?= in_array($ar['id'], $assignedAreaIds) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="edit_area_<?= $ar['id'] ?>">
                                        <?= e($ar['area_name']) ?> <span class="text-muted">(<?= e($ar['city']) ?>)</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= e($booker['notes']) ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editActiveBooker" <?= $booker['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="editActiveBooker">Active for Order Booking</label>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <a href="<?= APP_URL ?>/modules/bookers/index.php" class="btn btn-sm btn-light border me-1">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i> Update Booker (F9)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
