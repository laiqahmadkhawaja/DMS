<?php
/**
 * DMS - Price Lists & Customer Specific Rates
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$pageTitle = 'Price Lists & Customer Rates — ' . APP_NAME;
$activeMenu = 'products';

// Fetch special customer rates
$customerPrices = db_fetch_all("
    SELECT 
        cp.*, 
        c.shop_name, c.customer_code,
        p.product_name, p.product_code, p.wholesale_rate,
        u.symbol as unit_symbol
    FROM customer_prices cp
    JOIN customers c ON c.id = cp.customer_id
    JOIN products p ON p.id = cp.product_id
    JOIN units u ON u.id = cp.unit_id
    ORDER BY c.shop_name ASC, p.product_name ASC
");

$customers = db_fetch_all("SELECT id, shop_name, area_id FROM customers WHERE is_deleted = 0 AND is_active = 1 ORDER BY shop_name ASC");
$products = db_fetch_all("SELECT id, product_name, wholesale_rate, base_unit_id FROM products WHERE is_deleted = 0 AND is_active = 1 ORDER BY product_name ASC");
$units = db_fetch_all("SELECT id, unit_name, symbol FROM units WHERE is_deleted = 0 AND is_active = 1 ORDER BY id ASC");

// Handle add customer special rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer_rate') {
    $custId    = (int)($_POST['customer_id'] ?? 0);
    $prodId    = (int)($_POST['product_id'] ?? 0);
    $unitId    = (int)($_POST['unit_id'] ?? 0);
    $rate      = (float)($_POST['special_rate'] ?? 0);

    if ($custId > 0 && $prodId > 0 && $unitId > 0 && $rate > 0) {
        $stmt = db()->prepare("
            INSERT INTO customer_prices (customer_id, product_id, unit_id, special_rate, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE special_rate = VALUES(special_rate), updated_at = NOW()
        ");
        $stmt->execute([$custId, $prodId, $unitId, $rate]);
        log_audit('set_customer_rate', 'price_lists', $custId, "Set special rate {$rate} for product {$prodId}");
        set_flash_message('success', 'Customer special rate configured successfully.');
        redirect(APP_URL . '/modules/price_lists/index.php');
    } else {
        set_flash_message('danger', 'Please provide valid customer, product, unit, and special rate.');
    }
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
    <div>
        <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-tag-fill me-2 text-primary"></i>Customer Special Rates & Price Lists</h5>
        <small class="text-muted">Override default wholesale rates for specific retailers (snapshots saved permanently on bills)</small>
    </div>
    <div>
        <button type="button" class="btn btn-sm btn-primary" onclick="openDrawer('drawer-add-custom-price')">
            <i class="bi bi-plus-lg me-1"></i> Add Special Rate (Ctrl+N)
        </button>
    </div>
</div>

<div class="card-dms">
    <div class="card-dms-header">
        <span><i class="bi bi-percent me-1"></i> Configured Customer-Specific Rates (<?= count($customerPrices) ?> Total)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-compact mb-0">
            <thead class="table-fmcg-header">
                <tr>
                    <th>Customer / Retailer Shop</th>
                    <th>Product Name & Code</th>
                    <th>Unit</th>
                    <th class="text-end">Standard Rate</th>
                    <th class="text-end">Customer Special Rate</th>
                    <th class="text-end">Price Difference</th>
                    <th class="text-end" style="width: 90px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customerPrices)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-info-circle fs-4 d-block text-secondary mb-1"></i>
                            No custom rates set. All customers currently billed at default catalog wholesale rates.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customerPrices as $cp): 
                        $diff = (float)$cp['special_rate'] - (float)$cp['wholesale_rate'];
                    ?>
                        <tr>
                            <td class="fw-semibold text-dark"><?= e($cp['shop_name']) ?> <small class="text-muted font-monospace">(<?= e($cp['customer_code']) ?>)</small></td>
                            <td><?= e($cp['product_name']) ?></td>
                            <td><span class="badge bg-secondary-subtle text-dark border"><?= e($cp['unit_symbol']) ?></span></td>
                            <td class="text-end text-muted"><?= format_currency($cp['wholesale_rate']) ?></td>
                            <td class="text-end fw-bold text-primary"><?= format_currency($cp['special_rate']) ?></td>
                            <td class="text-end <?= $diff < 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $diff < 0 ? '-' . format_currency(abs($diff)) : '+' . format_currency($diff) ?>
                            </td>
                            <td class="text-end">
                                <a href="javascript:void(0)" onclick="dmsConfirm('Remove this special rate override?', function(){ window.location.href='<?= APP_URL ?>/modules/price_lists/delete.php?id=<?= $cp['id'] ?>'; })" class="btn btn-xs btn-outline-danger py-0 px-2" title="Delete Override">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Non-Modal Slide Drawer: Add Customer Special Rate -->
<div id="drawer-add-custom-price" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-tag-fill me-2 text-primary"></i> Assign Customer Special Rate</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/price_lists/index.php">
        <input type="hidden" name="action" value="add_customer_rate">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Customer / Retailer <span class="text-danger">*</span></label>
                <select name="customer_id" class="form-select form-select-sm" required autofocus>
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['shop_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Product <span class="text-danger">*</span></label>
                <select name="product_id" class="form-select form-select-sm" required>
                    <option value="">-- Select Product --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= e($p['product_name']) ?> (Standard: <?= format_currency($p['wholesale_rate']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Unit <span class="text-danger">*</span></label>
                <select name="unit_id" class="form-select form-select-sm" required>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['unit_name']) ?> (<?= e($u['symbol']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Special Agreed Rate <span class="text-danger">*</span></label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><?= DEFAULT_CURRENCY ?></span>
                    <input type="number" step="0.01" name="special_rate" class="form-control fw-bold" placeholder="0.00" required>
                </div>
                <small class="text-muted d-block mt-1">This special rate will automatically load on order booking and invoices for this shop.</small>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-sm btn-light border" onclick="closeDrawer()">Cancel (ESC)</button>
            <button type="submit" class="btn btn-sm btn-primary btn-save-dms"><i class="bi bi-check2 me-1"></i> Save Custom Rate (F9)</button>
        </div>
    </form>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
