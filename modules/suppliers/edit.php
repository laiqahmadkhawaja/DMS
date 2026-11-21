<?php
/**
 * DMS - Edit Supplier
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('suppliers_edit');

$id = (int)($_GET['id'] ?? 0);
$supplier = db_fetch_one("SELECT * FROM suppliers WHERE id = ? AND is_deleted = 0", [$id]);

if (!$supplier) {
    set_flash_message('danger', 'Supplier record not found.');
    redirect(APP_URL . '/modules/suppliers/index.php');
}

$brands = db_fetch_all("SELECT id, brand_code, brand_name FROM brands WHERE is_deleted = 0 ORDER BY brand_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierCode  = clean_input($_POST['supplier_code'] ?? '');
    $supplierName  = clean_input($_POST['supplier_name'] ?? '');
    $companyName   = clean_input($_POST['company_name'] ?? '');
    $contactPerson = clean_input($_POST['contact_person'] ?? '');
    $phone         = clean_input($_POST['phone'] ?? '');
    $email         = clean_input($_POST['email'] ?? '');
    $address       = clean_input($_POST['address'] ?? '');
    $city          = clean_input($_POST['city'] ?? '');
    $notes         = clean_input($_POST['notes'] ?? '');
    $isActive      = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('supplier_code', $supplierCode)
              ->unique('supplier_code', $supplierCode, 'suppliers', 'supplier_code', $id)
              ->required('supplier_name', $supplierName)
              ->required('phone', $phone);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE suppliers 
                SET supplier_code = ?, supplier_name = ?, company_name = ?, contact_person = ?, 
                    phone = ?, email = ?, address = ?, city = ?, notes = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $supplierCode, $supplierName, $companyName, $contactPerson,
                $phone, $email, $address, $city, $notes, $isActive, $id
            ]);

            log_audit('update_supplier', 'suppliers', $id, "Updated supplier: {$supplierName}");
            set_flash_message('success', "Supplier '{$supplierName}' updated successfully.");
            redirect(APP_URL . '/modules/suppliers/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating supplier: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Supplier: ' . $supplier['supplier_name'] . ' — ' . APP_NAME;
$activeMenu = 'suppliers';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Supplier</h5>
        <small class="text-muted">Modify contact and business details for <?= e($supplier['supplier_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/suppliers/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Suppliers (ESC)
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card-dms">
            <div class="card-dms-header">
                <span><i class="bi bi-building me-1"></i> Supplier Profile</span>
            </div>
            <form method="POST" action="<?= APP_URL ?>/modules/suppliers/edit.php?id=<?= $supplier['id'] ?>">
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Supplier Code <span class="text-danger">*</span></label>
                            <input type="text" name="supplier_code" class="form-control form-control-sm" value="<?= e($supplier['supplier_code']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                                <span>Company / Brand</span>
                                <a href="<?= APP_URL ?>/modules/brands/index.php" target="_blank" class="text-primary text-decoration-none small fw-normal" title="Manage Brands">
                                    <i class="bi bi-plus-circle me-1"></i>New Brand
                                </a>
                            </label>
                            <select name="company_name" class="form-select form-select-sm">
                                <option value="">-- Select Company / Brand --</option>
                                <?php foreach ($brands as $b): ?>
                                    <option value="<?= e($b['brand_name']) ?>" <?= ($supplier['company_name'] === $b['brand_name']) ? 'selected' : '' ?>>
                                        <?= e($b['brand_name']) ?> (<?= e($b['brand_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!empty($supplier['company_name']) && !in_array($supplier['company_name'], array_column($brands, 'brand_name'))): ?>
                                    <option value="<?= e($supplier['company_name']) ?>" selected>
                                        <?= e($supplier['company_name']) ?> (Current)
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_name" class="form-control form-control-sm" value="<?= e($supplier['supplier_name']) ?>" required autofocus>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control form-control-sm" value="<?= e($supplier['contact_person']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($supplier['phone']) ?>" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control form-control-sm" value="<?= e($supplier['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" name="city" class="form-control form-control-sm" value="<?= e($supplier['city']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Address</label>
                        <textarea name="address" class="form-control form-control-sm" rows="2"><?= e($supplier['address']) ?></textarea>
                    </div>

                    <div class="p-2 bg-light rounded border mb-3">
                        <div class="small text-muted">Current Payable Balance:</div>
                        <h5 class="fw-bold mb-0 <?= (float)$supplier['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= format_currency($supplier['current_balance']) ?>
                        </h5>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= e($supplier['notes']) ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="suppEditActive" <?= $supplier['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="suppEditActive">Active Supplier for Purchases</label>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <a href="<?= APP_URL ?>/modules/suppliers/index.php" class="btn btn-sm btn-light border me-1">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i> Update Supplier (F9)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
