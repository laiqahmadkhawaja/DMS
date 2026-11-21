<?php
/**
 * DMS - Edit Customer / Retailer
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('customers_edit');

$id = (int)($_GET['id'] ?? 0);
$customer = db_fetch_one("SELECT * FROM customers WHERE id = ? AND is_deleted = 0", [$id]);

if (!$customer) {
    set_flash_message('danger', 'Customer record not found.');
    redirect(APP_URL . '/modules/customers/index.php');
}

$areas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerCode   = clean_input($_POST['customer_code'] ?? '');
    $shopName       = clean_input($_POST['shop_name'] ?? '');
    $ownerName      = clean_input($_POST['owner_name'] ?? '');
    $phone          = clean_input($_POST['phone'] ?? '');
    $cnicOrNtn      = clean_input($_POST['cnic_or_ntn'] ?? '');
    $address        = clean_input($_POST['address'] ?? '');
    $areaId         = (int)($_POST['area_id'] ?? 0);
    $bookerId       = !empty($_POST['booker_id']) ? (int)$_POST['booker_id'] : null;
    $creditLimit    = (float)($_POST['credit_limit'] ?? 0);
    $creditDays     = (int)($_POST['credit_days'] ?? 0);
    $discountPct    = (float)($_POST['default_discount_percent'] ?? 0);
    $notes          = clean_input($_POST['notes'] ?? '');
    $isActive       = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('customer_code', $customerCode)
              ->unique('customer_code', $customerCode, 'customers', 'customer_code', $id)
              ->required('shop_name', $shopName)
              ->required('phone', $phone)
              ->required('address', $address)
              ->required('area_id', $areaId)
              ->exists('area_id', $areaId, 'areas', 'id')
              ->numeric('credit_limit', $creditLimit, 0);

    if ($validator->passes()) {
        try {
            $stmt = db()->prepare("
                UPDATE customers 
                SET customer_code = ?, shop_name = ?, owner_name = ?, phone = ?, cnic_or_ntn = ?, 
                    address = ?, area_id = ?, booker_id = ?, credit_limit = ?, credit_days = ?, 
                    default_discount_percent = ?, notes = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $customerCode, $shopName, $ownerName, $phone, $cnicOrNtn,
                $address, $areaId, $bookerId, $creditLimit, $creditDays,
                $discountPct, $notes, $isActive, $id
            ]);

            log_audit('update_customer', 'customers', $id, "Updated customer: {$shopName}");
            set_flash_message('success', "Customer '{$shopName}' updated successfully.");
            redirect(APP_URL . '/modules/customers/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', 'Error updating customer: ' . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Edit Customer: ' . $customer['shop_name'] . ' — ' . APP_NAME;
$activeMenu = 'customers';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Customer Details</h5>
        <small class="text-muted">Update profile, route assignment, and credit limits for <?= e($customer['shop_name']) ?></small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/customers/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Directory (ESC)
        </a>
    </div>
</div>

<form method="POST" action="<?= APP_URL ?>/modules/customers/edit.php?id=<?= $customer['id'] ?>">
    <div class="row g-3">
        <div class="col-md-7">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-shop me-1"></i> Store Information</span>
                </div>
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Customer Code <span class="text-danger">*</span></label>
                            <input type="text" name="customer_code" class="form-control form-control-sm" value="<?= e($customer['customer_code']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Shop / Business Name <span class="text-danger">*</span></label>
                            <input type="text" name="shop_name" class="form-control form-control-sm" value="<?= e($customer['shop_name']) ?>" required autofocus>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Owner Name</label>
                            <input type="text" name="owner_name" class="form-control form-control-sm" value="<?= e($customer['owner_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone / Mobile <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($customer['phone']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Complete Address <span class="text-danger">*</span></label>
                        <textarea name="address" class="form-control form-control-sm" rows="2" required><?= e($customer['address']) ?></textarea>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                                <span>Area / Route <span class="text-danger">*</span></span>
                                <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                                <a href="javascript:void(0)" onclick="openQuickAreaDrawer('custEditAreaSelect')" class="text-primary text-decoration-none" title="Quick Add Route">
                                    <i class="bi bi-plus-circle-fill"></i> New Route
                                </a>
                                <?php endif; ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <select name="area_id" id="custEditAreaSelect" class="form-select form-select-sm" required>
                                    <?php foreach ($areas as $ar): ?>
                                        <option value="<?= $ar['id'] ?>" <?= (int)$customer['area_id'] === (int)$ar['id'] ? 'selected' : '' ?>>
                                            <?= e($ar['area_name']) ?> (<?= e($ar['city']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                                <button type="button" class="btn btn-outline-primary" onclick="openQuickAreaDrawer('custEditAreaSelect')" title="Add New Area in Real-Time">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Assigned Booker</label>
                            <select name="booker_id" class="form-select form-select-sm">
                                <option value="">-- No Booker --</option>
                                <?php foreach ($bookers as $bk): ?>
                                    <option value="<?= $bk['id'] ?>" <?= (int)$customer['booker_id'] === (int)$bk['id'] ? 'selected' : '' ?>>
                                        <?= e($bk['booker_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">CNIC / NTN</label>
                            <input type="text" name="cnic_or_ntn" class="form-control form-control-sm" value="<?= e($customer['cnic_or_ntn']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Default Discount (%)</label>
                            <input type="number" step="0.01" name="default_discount_percent" class="form-control form-control-sm" value="<?= e($customer['default_discount_percent']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Remarks</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= e($customer['notes']) ?></textarea>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="custEditActive" <?= $customer['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="custEditActive">Active Customer</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-wallet2 me-1"></i> Balance & Credit Control</span>
                </div>
                <div class="card-dms-body">
                    <div class="p-3 bg-light rounded border mb-3">
                        <div class="small text-muted">Current Ledger Balance:</div>
                        <h4 class="fw-bold mb-0 <?= (float)$customer['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= format_currency($customer['current_balance']) ?>
                        </h4>
                        <small class="text-muted">Calculated from posted invoices, settlements, and payments</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Credit Limit Allowed</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="credit_limit" class="form-control" value="<?= e($customer['credit_limit']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Allowed Credit Days</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="credit_days" class="form-control" value="<?= e($customer['credit_days']) ?>">
                            <span class="input-group-text">Days</span>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <button type="submit" class="btn btn-primary w-100 btn-save-dms">
                        <i class="bi bi-check2 me-1"></i> Update Customer (F9)
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
