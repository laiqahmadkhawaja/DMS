<?php
/**
 * DMS - Add New Customer / Retailer
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('customers_create');

$areas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");

// Auto generated customer code suggestion
$nextCode = 'CUST-' . sprintf('%05d', (int)db_fetch_val("SELECT COUNT(*) FROM customers") + 1);

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
    $openingBalance = (float)($_POST['opening_balance'] ?? 0);
    $discountPct    = (float)($_POST['default_discount_percent'] ?? 0);
    $notes          = clean_input($_POST['notes'] ?? '');
    $isActive       = isset($_POST['is_active']) ? 1 : 0;

    $validator = new Validator();
    $validator->required('customer_code', $customerCode)
              ->unique('customer_code', $customerCode, 'customers', 'customer_code')
              ->required('shop_name', $shopName)
              ->required('phone', $phone)
              ->required('address', $address)
              ->required('area_id', $areaId)
              ->exists('area_id', $areaId, 'areas', 'id')
              ->numeric('credit_limit', $creditLimit, 0)
              ->numeric('opening_balance', $openingBalance, 0);

    // Configurable Duplicate Check: Shop name + Area or Phone check
    if ($areaId > 0 && !empty($shopName)) {
        $duplicate = db_fetch_val("
            SELECT id FROM customers 
            WHERE LOWER(shop_name) = LOWER(?) AND area_id = ? AND is_deleted = 0 
            LIMIT 1
        ", [$shopName, $areaId]);
        if ($duplicate) {
            $validator->addError('shop_name', "A shop named '{$shopName}' already exists in this area. Avoid duplicate registrations.");
        }
    }

    if ($validator->passes()) {
        try {
            db_transaction(function ($pdo) use (
                $customerCode, $shopName, $ownerName, $phone, $cnicOrNtn, $address,
                $areaId, $bookerId, $creditLimit, $creditDays, $openingBalance,
                $discountPct, $notes, $isActive
            ) {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (
                        customer_code, shop_name, owner_name, phone, cnic_or_ntn, address,
                        area_id, booker_id, credit_limit, credit_days, opening_balance,
                        current_balance, default_discount_percent, notes, is_active, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $customerCode, $shopName, $ownerName, $phone, $cnicOrNtn, $address,
                    $areaId, $bookerId, $creditLimit, $creditDays, $openingBalance,
                    $openingBalance, $discountPct, $notes, $isActive
                ]);
                $customerId = (int)$pdo->lastInsertId();

                // If opening balance > 0, post double-entry opening journal voucher
                if ($openingBalance > 0) {
                    post_journal_entry([
                        'entry_date'     => date('Y-m-d'),
                        'reference_type' => 'opening',
                        'reference_id'   => $customerId,
                        'narration'      => "Opening Receivable Balance for Customer: {$shopName}"
                    ], [
                        [
                            'account_id'  => ACC_RECEIVABLES,
                            'debit'       => $openingBalance,
                            'credit'      => 0,
                            'description' => "Opening debit balance for {$shopName}",
                            'party_type'  => 'customer',
                            'party_id'    => $customerId
                        ],
                        [
                            'account_id'  => ACC_CAPITAL,
                            'debit'       => 0,
                            'credit'      => $openingBalance,
                            'description' => "Opening capital reserve for {$shopName}",
                            'party_type'  => null,
                            'party_id'    => null
                        ]
                    ]);
                }

                log_audit('create_customer', 'customers', $customerId, "Created customer: {$shopName} ({$customerCode}), Opening Balance: {$openingBalance}");
            });

            set_flash_message('success', "Customer '{$shopName}' registered successfully.");
            redirect(APP_URL . '/modules/customers/index.php');
        } catch (Exception $e) {
            set_flash_message('danger', "Failed to register customer: " . $e->getMessage());
        }
    } else {
        set_flash_message('danger', $validator->getFirstError());
    }
}

$pageTitle = 'Add Customer / Retailer — ' . APP_NAME;
$activeMenu = 'customers';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-person-plus me-2 text-primary"></i>Register New Retailer / Shop</h5>
        <small class="text-muted">Add retailer store with delivery route, assigned booker, and credit terms</small>
    </div>
    <div>
        <a href="<?= APP_URL ?>/modules/customers/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Directory (ESC)
        </a>
    </div>
</div>

<form method="POST" action="<?= APP_URL ?>/modules/customers/create.php">
    <div class="row g-3">
        <!-- Left: Shop Details & Contact -->
        <div class="col-md-7">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-shop me-1"></i> Retail Store Profile</span>
                </div>
                <div class="card-dms-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Customer Code <span class="text-danger">*</span></label>
                            <input type="text" name="customer_code" class="form-control form-control-sm" value="<?= e($nextCode) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Shop / Business Name <span class="text-danger">*</span></label>
                            <input type="text" name="shop_name" class="form-control form-control-sm" placeholder="e.g. Prince Grocery, Ramzan Sweets" required autofocus>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Owner / Contact Person</label>
                            <input type="text" name="owner_name" class="form-control form-control-sm" placeholder="e.g. Mian Ramzan, Imran Butt">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control form-control-sm" placeholder="0300-xxxxxxx" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Complete Shop Address <span class="text-danger">*</span></label>
                        <textarea name="address" class="form-control form-control-sm" rows="2" placeholder="e.g. Daal Bazar, Jandiala Bhagwala G.T. Road" required></textarea>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Area / Route Beat <span class="text-danger">*</span></label>
                            <select name="area_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Market Route --</option>
                                <?php foreach ($areas as $ar): ?>
                                    <option value="<?= $ar['id'] ?>"><?= e($ar['area_name']) ?> (<?= e($ar['city']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Assigned Order Booker</label>
                            <select name="booker_id" class="form-select form-select-sm">
                                <option value="">-- Optional Default Booker --</option>
                                <?php foreach ($bookers as $bk): ?>
                                    <option value="<?= $bk['id'] ?>"><?= e($bk['booker_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">CNIC / NTN</label>
                            <input type="text" name="cnic_or_ntn" class="form-control form-control-sm" placeholder="Optional tax ID">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Special Discount (%)</label>
                            <input type="number" step="0.01" name="default_discount_percent" class="form-control form-control-sm" value="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Remarks / Guarantee Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Shop landmarks or delivery instructions..."></textarea>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="custActive" checked>
                        <label class="form-check-label small" for="custActive">Customer Active for Order Booking & Billing</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Credit Terms & Opening Balance -->
        <div class="col-md-5">
            <div class="card-dms">
                <div class="card-dms-header">
                    <span><i class="bi bi-wallet2 me-1"></i> Credit Terms & Opening Balance</span>
                </div>
                <div class="card-dms-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Opening Khata / Balance (Debtor Receivable)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="opening_balance" class="form-control fw-bold" value="0.00">
                        </div>
                        <small class="text-muted d-block mt-1">If the shop already owes balance from old software, enter it here. Posts to General Ledger.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Credit Limit Allowed</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                            <input type="number" step="0.01" name="credit_limit" class="form-control" value="0.00">
                        </div>
                        <small class="text-muted d-block mt-1">Maximum allowed credit before warning on order entry</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Allowed Credit Days</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="credit_days" class="form-control" value="7">
                            <span class="input-group-text">Days</span>
                        </div>
                        <small class="text-muted d-block mt-1">Weekly or monthly recovery cycle</small>
                    </div>
                </div>
                <div class="card-footer bg-light p-3 text-end border-top">
                    <button type="submit" class="btn btn-primary w-100 btn-save-dms">
                        <i class="bi bi-check2-circle me-1"></i> Save Customer (F9 / Ctrl+S)
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
