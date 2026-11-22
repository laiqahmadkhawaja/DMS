<?php
/**
 * DMS - High-Speed Keyboard & Card-Driven Sales Returns
 * Matches POS sales/purchases workflow with Customer Rate History Auto-Match
 * Supports:
 *   1. Direct Return from Specific Invoice (Real-time stock return & settlement)
 *   2. Market Returns from Past Invoices (Auto-detects latest sold rate to that customer)
 *   3. Route Settlement Stock Reconciliations (Settles pending return stock from delivery runs)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('returns_create');

// URL params for pre-filling (e.g. from route settlement)
$preCustomerId   = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$preInvoiceId    = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$preSettlementId = isset($_GET['settlement_id']) ? (int)$_GET['settlement_id'] : 0;
$targetAmount    = isset($_GET['amount']) ? (float)$_GET['amount'] : 0.0;

$customers = db_fetch_all("
    SELECT c.id, c.customer_code, c.shop_name, c.current_balance, c.credit_limit, c.phone, c.address, a.area_name
    FROM customers c
    LEFT JOIN areas a ON c.area_id = a.id
    WHERE c.is_deleted = 0 AND c.is_active = 1
    ORDER BY c.shop_name ASC
");

$categories = db_fetch_all("
    SELECT id, category_name 
    FROM product_categories 
    WHERE is_active = 1 
    ORDER BY category_name ASC
");

$products = db_fetch_all("
    SELECT 
        p.id, p.product_name, p.product_code, p.barcode, p.category_id, p.brand_id, cat.category_name, b.brand_name,
        p.purchase_rate, p.purchase_rate as cost_price, p.default_sale_rate, p.wholesale_rate, p.retail_rate,
        p.carton_qty, p.pack_qty, p.packing_description, p.base_unit_id, u.symbol as unit_symbol,
        COALESCE(sb.saleable_qty, 0) as saleable_qty
    FROM products p
    JOIN units u ON u.id = p.base_unit_id
    LEFT JOIN product_categories cat ON cat.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id AND b.is_deleted = 0
    LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = 1
    WHERE p.is_deleted = 0 AND p.is_active = 1
    ORDER BY p.product_name ASC
");

$nextReturnNumber = generate_document_number(PREFIX_RETURN, 'sales_returns', 'return_number');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnNumber  = clean_input($_POST['return_number'] ?? $nextReturnNumber);
    $returnDate    = sanitize_input($_POST['return_date'] ?? date('Y-m-d'));
    $customerId    = (int)($_POST['customer_id'] ?? 0);
    $invoiceId     = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $settlementId  = !empty($_POST['settlement_id']) ? (int)$_POST['settlement_id'] : null;
    $conditionType = sanitize_input($_POST['condition_type'] ?? 'saleable');
    $reason        = sanitize_input($_POST['reason'] ?? '');
    $status        = isset($_POST['save_draft']) ? 'draft' : 'processed';

    // Item arrays from fast cart
    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPackQtys   = $_POST['item_pack_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemRates      = $_POST['item_rate'] ?? [];
    $itemDiscounts  = $_POST['item_discount_percent'] ?? [];
    $itemConditions = $_POST['item_condition'] ?? [];
    $itemReasons    = $_POST['item_reason'] ?? [];

    // Fallback for legacy format if any
    if (empty($itemProductIds) && !empty($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $it) {
            if (!empty($it['product_id'])) {
                $itemProductIds[] = $it['product_id'];
                $itemCartonQtys[] = $it['carton_qty'] ?? 0;
                $itemPackQtys[]   = $it['pack_qty'] ?? 0;
                $itemPcsQtys[]    = $it['pcs_qty'] ?? 0;
                $itemRates[]      = $it['rate'] ?? 0;
                $itemDiscounts[]  = $it['discount_percent'] ?? 0;
                $itemConditions[] = $it['condition_type'] ?? $conditionType;
                $itemReasons[]    = $it['reason'] ?? $reason;
            }
        }
    }

    if ($customerId <= 0) {
        $errors[] = "Please select a customer / retail shop.";
    }
    if (empty($itemProductIds)) {
        $errors[] = "Please add at least one product to the return cart.";
    }

    $validItems = [];
    $totalAmount = 0.0;
    $totalCartons = 0.0;
    $totalPieces = 0.0;

    foreach ($itemProductIds as $idx => $pid) {
        $prodId    = (int)$pid;
        $cartonQty = (float)($itemCartonQtys[$idx] ?? 0);
        $packQty   = (float)($itemPackQtys[$idx] ?? 0);
        $pcsQty    = (float)($itemPcsQtys[$idx] ?? 0);
        $rate      = (float)($itemRates[$idx] ?? 0);
        $discPct   = (float)($itemDiscounts[$idx] ?? 0);
        $lineCond  = sanitize_input($itemConditions[$idx] ?? $conditionType);
        $lineReas  = sanitize_input($itemReasons[$idx] ?? $reason);

        if ($prodId <= 0 || ($cartonQty <= 0 && $packQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $basePieces   = calculate_base_pieces($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
        $equivCartons = calculate_carton_equivalent($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
        $lineGross    = $equivCartons * $rate;
        $lineDiscount = ($lineGross * $discPct) / 100.0;
        $lineNet      = max(0, round($lineGross - $lineDiscount, 2));

        $totalAmount  += $lineNet;
        $totalCartons += $cartonQty;
        $totalPieces  += $pcsQty;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => (int)$prod['base_unit_id'],
            'carton_qty'       => $cartonQty,
            'pack_qty'         => $packQty,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $equivCartons,
            'base_pieces'      => $basePieces,
            'rate'             => $rate,
            'discount_percent' => $discPct,
            'amount'           => $lineNet,
            'reason'           => $lineReas,
            'condition_type'   => $lineCond
        ];
    }

    if (empty($validItems) && empty($errors)) {
        $errors[] = "Please add at least one valid product line with quantity greater than zero.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();

            // Check if there is an existing pending route settlement return for this invoice & settlement
            $existingReturnId = null;
            if ($settlementId > 0) {
                if ($invoiceId > 0) {
                    $existingReturnId = db_fetch_val("
                        SELECT id FROM sales_returns 
                        WHERE settlement_id = ? AND invoice_id = ? AND stock_settled = 0 
                        LIMIT 1
                    ", [$settlementId, $invoiceId]);
                } else {
                    $existingReturnId = db_fetch_val("
                        SELECT id FROM sales_returns 
                        WHERE settlement_id = ? AND customer_id = ? AND stock_settled = 0 
                        LIMIT 1
                    ", [$settlementId, $customerId]);
                }
            }

            if ($existingReturnId) {
                // Update existing settlement return record with settled status and details
                $returnId = (int)$existingReturnId;
                db_execute("
                    UPDATE sales_returns 
                    SET total_amount = ?, reason = ?, condition_type = ?, status = 'processed', stock_settled = 1, updated_at = NOW() 
                    WHERE id = ?
                ", [$totalAmount, $reason, $conditionType, $returnId]);

                // Clear any stub items
                db_execute("DELETE FROM sales_return_items WHERE sales_return_id = ?", [$returnId]);
            } else {
                // 1. Insert new sales_returns header
                $stmt = db()->prepare("
                    INSERT INTO sales_returns (
                        return_number, invoice_id, customer_id, settlement_id, return_date, 
                        total_amount, reason, condition_type, status, stock_settled, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 
                        ?, ?, ?, ?, 1, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $returnNumber, $invoiceId, $customerId, $settlementId, $returnDate,
                    $totalAmount, $reason, $conditionType, $status, $userId
                ]);
                $returnId = (int)db()->lastInsertId();
            }

            // 2. Insert items and update stock movements
            $stmtItem = db()->prepare("
                INSERT INTO sales_return_items (
                    sales_return_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, 
                    quantity, rate, discount_percent, amount, reason, condition_type, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $returnId, $vi['product_id'], $vi['unit_id'],
                    $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['quantity'], $vi['rate'], $vi['discount_percent'],
                    $vi['amount'], $vi['reason'], $vi['condition_type']
                ]);

                // Determine stock movement destination (saleable vs damaged)
                $movementType = 'sales_return_saleable';
                if ($vi['condition_type'] === 'damaged' || $vi['condition_type'] === 'expired') {
                    $movementType = 'sales_return_damaged';
                }

                record_stock_movement([
                    'product_id'     => $vi['product_id'],
                    'warehouse_id'   => 1,
                    'movement_type'  => $movementType,
                    'reference_type' => 'sales_return',
                    'reference_id'   => $returnId,
                    'qty_in'         => $vi['base_pieces'],
                    'qty_out'        => 0,
                    'unit_cost'      => $vi['rate'],
                    'remarks'        => "Sales return #{$returnNumber} ({$vi['condition_type']})",
                    'condition_type' => $vi['condition_type']
                ]);
            }

            // 3. Customer Ledger & Accounting Journal Entry
            // Note: If this return was already credited to customer in a finalized route settlement,
            // we do NOT double-credit the customer's balance.
            $alreadyCreditedInSettlement = false;
            if ($settlementId > 0) {
                $settleCheck = db_fetch_val("SELECT id FROM settlements WHERE id = ? AND status IN ('finalized', 'reconciled')", [$settlementId]);
                if ($settleCheck) {
                    $alreadyCreditedInSettlement = true;
                }
            }

            if (!$alreadyCreditedInSettlement && $status === 'processed') {
                update_customer_balance($customerId, $totalAmount, 'decrease');

                post_journal_entry([
                    'entry_date'     => $returnDate,
                    'reference_type' => 'sales_return',
                    'reference_id'   => $returnId,
                    'narration'      => "Sales Return #{$returnNumber} ({$conditionType})"
                ], [
                    ['account_id' => ACC_RETURNS, 'debit' => $totalAmount, 'credit' => 0.00, 'description' => "Sales Return Voucher #{$returnNumber}"],
                    ['account_id' => ACC_RECEIVABLES, 'debit' => 0.00, 'credit' => $totalAmount, 'party_type' => 'customer', 'party_id' => $customerId, 'description' => "Credit note for goods returned #{$returnNumber}"]
                ]);
            }

            if (function_exists('audit_log')) {
                audit_log('create_return', 'sales_returns', (string)$returnId, "Created sales return {$returnNumber} for {$totalAmount} Rs., stock checked into warehouse.");
            }

            db()->commit();
            set_flash('success', "Sales return <strong>{$returnNumber}</strong> successfully processed! Physical stock returned to warehouse and balanced.");
            redirect(APP_URL . '/modules/returns/view.php?id=' . $returnId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error saving return: " . $e->getMessage());
            $errors[] = "Error saving return: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Create Sales Return Voucher';
$activeMenu = 'returns';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Returns', 'url' => 'index.php'],
    ['label' => 'New Return', 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Breadcrumb / Header Bar -->
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <div>
                <h5 class="mb-0 fw-bold text-navy">
                    <i class="bi bi-arrow-return-left me-2 text-danger"></i>Create Sales Return Voucher
                </h5>
                <small class="text-muted">Fast POS selection: Auto-matches exact billed rates from customer purchase history & restocks inventory</small>
            </div>
            <div>
                <a href="<?= APP_URL ?>/modules/returns/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Return List (ESC)
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-3 py-2 px-3">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Form Validation Failed:</div>
                <ul class="mb-0 ps-3 small">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($preSettlementId > 0 && $targetAmount > 0): ?>
            <div class="alert alert-warning shadow-sm border-0 mb-3 py-2 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                    <strong>Route Settlement Stock Reconciliation:</strong>
                    A return of <strong><?= DEFAULT_CURRENCY ?> <?= number_format($targetAmount, 2) ?></strong> was recorded in Route Settlement. Select the physical items returned below to check stock into inventory and complete settlement.
                </div>
                <span class="badge bg-dark fs-6">Target: <?= DEFAULT_CURRENCY ?> <?= number_format($targetAmount, 2) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/modules/returns/create.php" id="returnForm" autocomplete="off">
            <input type="hidden" name="return_number" value="<?= e($nextReturnNumber) ?>">
            <input type="hidden" name="settlement_id" value="<?= $preSettlementId > 0 ? $preSettlementId : '' ?>">

            <!-- Customer & Return Setup Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Customer / Retailer <span class="text-danger">*</span></label>
                            <select name="customer_id" id="customerSelect" class="form-select form-select-sm" required autofocus>
                                <option value="">-- Choose Retailer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"
                                            data-code="<?= htmlspecialchars($c['customer_code'] ?? '') ?>"
                                            data-balance="<?= (float)$c['current_balance'] ?>"
                                            data-limit="<?= (float)$c['credit_limit'] ?>"
                                            data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                            data-area="<?= htmlspecialchars($c['area_name'] ?? '') ?>"
                                            <?= ($preCustomerId === (int)$c['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['shop_name']) ?> <?= !empty($c['area_name']) ? '(' . htmlspecialchars($c['area_name']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Direct Bill / Invoice</label>
                            <select name="invoice_id" id="invoiceSelect" class="form-select form-select-sm">
                                <option value="">-- General Market Return --</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Return Date <span class="text-danger">*</span></label>
                            <input type="date" name="return_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Condition</label>
                            <select name="condition_type" id="conditionSelect" class="form-select form-select-sm">
                                <option value="saleable">Saleable (Restock to Saleable)</option>
                                <option value="damaged">Damaged (Quarantine Stock)</option>
                                <option value="expired">Expired (Quarantine Stock)</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Return Reason</label>
                            <input type="text" name="reason" id="returnReason" class="form-control form-control-sm" placeholder="e.g. Route Return, Damaged, Replaced">
                        </div>

                        <div class="col-md-1 text-end">
                            <span class="badge bg-navy text-white font-monospace py-2 px-2" style="font-size: 11px;"><?= e($nextReturnNumber) ?></span>
                        </div>
                    </div>

                    <!-- Customer Financial & Rate Matching Info Banner -->
                    <div id="customerAlert" class="mt-2 py-1 px-2 rounded small bg-light border text-secondary" style="display:none;">
                        <span id="customerInfo"></span>
                    </div>
                </div>
            </div>

            <!-- Main Working Area: Split 5-Cols Product Catalog vs 7-Cols Cart -->
            <div class="row g-3 mb-3">
                <!-- Left Column: Fast Product Search & Card Selection -->
                <div class="col-lg-5">
                    <div class="pos-catalog-container">
                        <div class="pos-catalog-header">
                            <div class="pos-search-wrapper">
                                <i class="bi bi-search pos-search-icon"></i>
                                <input type="text" id="productSearch" class="form-control form-control-sm pos-search-input" 
                                       placeholder="Search product name, code, packing... (Press / or F2)" autocomplete="off">
                            </div>
                            
                            <!-- Category Filter Pills -->
                            <div class="pos-category-pills">
                                <button type="button" class="pos-category-pill active" data-cat="">All Products</button>
                                <button type="button" class="pos-category-pill d-none text-danger" id="pillFromInvoice" data-cat="from_invoice">
                                    <i class="bi bi-receipt me-1"></i> From Selected Bill
                                </button>
                                <?php foreach ($categories as $cat): ?>
                                    <button type="button" class="pos-category-pill" data-cat="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Product Cards Grid -->
                        <div class="pos-product-grid" id="productCardsGrid">
                            <?php foreach ($products as $p): 
                                $ratios = get_product_packaging_ratios($p);
                                $packsPerCtn = $ratios['packs_per_ctn'];
                                $pcsPerPack  = $ratios['pcs_per_pack'];
                                $pcsPerCtn   = $ratios['pcs_per_ctn'];
                                $stockBreakdown = breakdown_base_pieces((float)$p['saleable_qty'], $packsPerCtn, $pcsPerPack);
                                $ratioText = $pcsPerPack > 1 ? "1*{$packsPerCtn}*{$pcsPerPack}" : "1*{$packsPerCtn}";
                                $wholesaleRate = (float)$p['wholesale_rate'];
                            ?>
                                <div class="pos-product-card" 
                                     id="card_prod_<?= $p['id'] ?>"
                                     data-id="<?= $p['id'] ?>"
                                     data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                     data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                     data-cat="<?= $p['category_id'] ?>"
                                     data-standard-rate="<?= $wholesaleRate ?>"
                                     data-rate="<?= $wholesaleRate ?>"
                                     data-packs-per-ctn="<?= $packsPerCtn ?>"
                                     data-pcs-per-pack="<?= $pcsPerPack ?>"
                                     data-pcs-per-ctn="<?= $pcsPerCtn ?>"
                                     onclick="addProductToCart(<?= $p['id'] ?>)">
                                    
                                    <span class="card-badge-cart d-none" id="badge_cart_<?= $p['id'] ?>">0 in return</span>
                                    <button type="button" class="btn-card-info" title="View Prices & Rates (خرید/ہول سیل/پرچون)" onclick="showProductPriceModal(event, <?= $p['id'] ?>)">
                                        <i class="bi bi-info-circle"></i>
                                    </button>
                                    
                                    <div>
                                        <div class="prod-name"><?= htmlspecialchars($p['product_name']) ?></div>
                                        <div class="prod-meta">
                                            <span>Pack: <strong><?= $ratioText ?></strong></span> &bull; 
                                            <span><?= htmlspecialchars($p['product_code']) ?></span>
                                        </div>
                                    </div>

                                    <div class="prod-price-stock">
                                        <div class="prod-price text-danger" id="card_rate_label_<?= $p['id'] ?>">
                                            Rate: <?= DEFAULT_CURRENCY ?> <?= number_format($wholesaleRate, 0) ?>
                                        </div>
                                        <div class="prod-stock">
                                            <?= htmlspecialchars($stockBreakdown['formatted']) ?> avail
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive Return Items Cart -->
                <div class="col-lg-7">
                    <div class="pos-cart-container">
                        <div class="pos-cart-header d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy">
                                <i class="bi bi-arrow-return-left me-1 text-danger"></i> Return Items Cart 
                                <span class="badge bg-danger ms-1" id="cartItemCount">0 items</span>
                            </span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-xs btn-outline-primary d-none" id="btnLoadInvoiceItems" onclick="loadAllInvoiceItemsToCart()">
                                    <i class="bi bi-box-arrow-in-down me-1"></i> Load All Bill Items
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-danger" onclick="clearCart()" id="btnClearCart" style="display:none;">
                                    <i class="bi bi-trash me-1"></i> Clear Cart
                                </button>
                            </div>
                        </div>

                        <div class="pos-cart-table-wrapper">
                            <table class="table pos-cart-table table-hover align-middle" id="cartTable">
                                <thead>
                                    <tr>
                                        <th style="width: 28%;">Product Details</th>
                                        <th style="width: 11%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 10%;" class="text-center">Packs</th>
                                        <th style="width: 10%;" class="text-center">Extra Pcs</th>
                                        <th style="width: 15%;" class="text-end">Return Rate</th>
                                        <th style="width: 12%;" class="text-center">Condition</th>
                                        <th style="width: 14%;" class="text-end">Credit Total</th>
                                        <th style="width: 30px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody">
                                    <tr id="emptyCartRow">
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="bi bi-arrow-counterclockwise fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                            <div class="fw-semibold">No items added to return cart yet.</div>
                                            <small>Click on product cards on the left or use the search bar to add returned items.</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Real-time Return Totals Footer -->
                        <div class="pos-cart-footer">
                            <div class="row g-2 align-items-center mb-3">
                                <div class="col-md-3 text-center border-end">
                                    <div class="text-muted small">Total Cartons</div>
                                    <div class="fw-bold fs-6 text-dark" id="summaryTotalCartons">0 Ctns</div>
                                </div>
                                <div class="col-md-3 text-center border-end">
                                    <div class="text-muted small">Total Pieces</div>
                                    <div class="fw-bold fs-6 text-dark" id="summaryTotalPieces">0 Pcs</div>
                                </div>
                                <div class="col-md-3 text-center border-end">
                                    <div class="text-muted small">Stock Destination</div>
                                    <div class="fw-bold fs-6 text-success" id="summaryCondition">Saleable Warehouse</div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="text-muted small">Total Credit Value</div>
                                    <div class="fw-bold fs-5 text-danger" id="summaryNetAmount">Rs. 0.00</div>
                                </div>
                            </div>

                            <?php if ($targetAmount > 0): ?>
                                <div class="py-2 px-3 mb-2 rounded bg-light border d-flex justify-content-between align-items-center" id="settlementDiffBox">
                                    <span class="small fw-semibold text-secondary">
                                        Route Target: <strong><?= DEFAULT_CURRENCY ?> <?= number_format($targetAmount, 2) ?></strong>
                                    </span>
                                    <span class="small fw-bold" id="settlementDiffLabel">
                                        Remaining to Balance: <?= DEFAULT_CURRENCY ?> <?= number_format($targetAmount, 2) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="row g-2 align-items-center">
                                <div class="col-md-7">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Return notes, route verification, customer complaint reference...">
                                </div>
                                <div class="col-md-5 text-end">
                                    <button type="submit" name="save_draft" class="btn btn-secondary btn-sm px-3 me-1">
                                        <i class="bi bi-save me-1"></i> Save Draft
                                    </button>
                                    <button type="submit" name="confirm_now" class="btn btn-danger btn-sm px-3 fw-bold">
                                        <i class="bi bi-check2-circle me-1"></i> Confirm Return (F9)
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Embedded Product Cache -->
<script>
    const DMS_PRODUCTS = <?= json_encode($products) ?>;
    const productMap = {};
    DMS_PRODUCTS.forEach(p => { productMap[p.id] = p; });
    const TARGET_SETTLEMENT_AMOUNT = <?= (float)$targetAmount ?>;
    const PRE_INVOICE_ID = <?= (int)$preInvoiceId ?>;
</script>

<script>
let customerRateMap = {}; // product_id -> { rate, discount_percent, invoice_number, invoice_date }
let currentInvoiceItems = [];

document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.getElementById('customerSelect');
    const invoiceSelect  = document.getElementById('invoiceSelect');
    const conditionSelect = document.getElementById('conditionSelect');
    const productSearch  = document.getElementById('productSearch');

    // Customer change loads billing history & exact rates
    customerSelect.addEventListener('change', function () {
        onCustomerChanged(this.value);
    });

    // If pre-selected customer
    if (customerSelect.value) {
        onCustomerChanged(customerSelect.value, PRE_INVOICE_ID);
    }

    // Invoice selection change
    invoiceSelect.addEventListener('change', function () {
        onInvoiceChanged(this.value);
    });

    // Global condition change updates summary label
    conditionSelect.addEventListener('change', function () {
        const val = this.value;
        const lbl = document.getElementById('summaryCondition');
        if (val === 'saleable') {
            lbl.innerText = 'Saleable Warehouse';
            lbl.className = 'fw-bold fs-6 text-success';
        } else if (val === 'damaged') {
            lbl.innerText = 'Damaged Quarantine';
            lbl.className = 'fw-bold fs-6 text-danger';
        } else {
            lbl.innerText = 'Expired Quarantine';
            lbl.className = 'fw-bold fs-6 text-warning';
        }
    });

    // Real-time Product Search Filter
    productSearch.addEventListener('input', function () {
        filterProductCards();
    });

    // Category Filter Pills
    document.querySelectorAll('.pos-category-pill').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.pos-category-pill').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterProductCards();
        });
    });

    // Arrow Key Navigation on Search Input
    productSearch.addEventListener('keydown', function (e) {
        const visibleCards = getVisibleCards();
        if (visibleCards.length === 0) return;

        if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
            e.preventDefault();
            let nextIndex = selectedCardIndex + 1;
            if (nextIndex >= visibleCards.length) nextIndex = 0;
            highlightCard(nextIndex);
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
            e.preventDefault();
            let prevIndex = selectedCardIndex - 1;
            if (prevIndex < 0) prevIndex = visibleCards.length - 1;
            highlightCard(prevIndex);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedCardIndex >= 0 && selectedCardIndex < visibleCards.length) {
                const card = visibleCards[selectedCardIndex];
                const prodId = parseInt(card.getAttribute('data-id'), 10);
                if (prodId) addProductToCart(prodId);
            } else if (visibleCards.length > 0) {
                const prodId = parseInt(visibleCards[0].getAttribute('data-id'), 10);
                if (prodId) addProductToCart(prodId);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            productSearch.value = '';
            filterProductCards();
        }
    });

    // Global Shortcuts
    document.addEventListener('keydown', function(e) {
        const tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isInput = tag === 'input' || tag === 'textarea' || tag === 'select';

        if (e.key === '/' || e.key === 'F2') {
            if (document.activeElement !== productSearch) {
                e.preventDefault();
                productSearch.focus();
                productSearch.select();
            }
        } else if (e.key === 'F9') {
            e.preventDefault();
            document.getElementById('returnForm').submit();
        } else if (!isInput) {
            const visibleCards = getVisibleCards();
            if (visibleCards.length > 0) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    let nextIndex = selectedCardIndex + 1;
                    if (nextIndex >= visibleCards.length) nextIndex = 0;
                    highlightCard(nextIndex);
                } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    let prevIndex = selectedCardIndex - 1;
                    if (prevIndex < 0) prevIndex = visibleCards.length - 1;
                    highlightCard(prevIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (selectedCardIndex >= 0 && selectedCardIndex < visibleCards.length) {
                        const card = visibleCards[selectedCardIndex];
                        const prodId = parseInt(card.getAttribute('data-id'), 10);
                        if (prodId) addProductToCart(prodId);
                    }
                }
            }
        }
    });

    highlightCard(0);
});

function onCustomerChanged(customerId, targetInvoiceId = 0) {
    const custAlert = document.getElementById('customerAlert');
    const custInfo  = document.getElementById('customerInfo');
    const invSelect = document.getElementById('invoiceSelect');

    if (!customerId) {
        custAlert.style.display = 'none';
        invSelect.innerHTML = '<option value="">-- General Market Return --</option>';
        customerRateMap = {};
        resetCardRatesToStandard();
        return;
    }

    const opt = document.querySelector(`#customerSelect option[value="${customerId}"]`);
    const balance = parseFloat(opt?.getAttribute('data-balance') || 0);
    const area = opt?.getAttribute('data-area') || '';
    const phone = opt?.getAttribute('data-phone') || '';

    custAlert.style.display = 'block';
    custInfo.innerHTML = `<i class="bi bi-wallet2 me-1"></i> Balance: <strong><?= DEFAULT_CURRENCY ?> ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}</strong> | Area: ${area} | Phone: ${phone} &bull; <span class="text-primary fw-semibold"><i class="bi bi-arrow-repeat me-1"></i> Loading customer rate history...</span>`;

    // Fetch customer invoices & historical rate map via AJAX
    fetch(`../../ajax/returns/get_customer_rates.php?customer_id=${customerId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                customerRateMap = data.data.latest_rates || {};
                
                // Populate invoice dropdown
                let invHtml = '<option value="">-- General Market Return (Auto-Matched Rates) --</option>';
                (data.data.recent_invoices || []).forEach(inv => {
                    const sel = (targetInvoiceId > 0 && inv.id === targetInvoiceId) ? 'selected' : '';
                    invHtml += `<option value="${inv.id}" ${sel}>#${inv.invoice_number} (${inv.invoice_date}) - Rs. ${parseFloat(inv.net_amount).toFixed(0)}</option>`;
                });
                invSelect.innerHTML = invHtml;

                updateProductCardsWithCustomerRates();

                custInfo.innerHTML = `<i class="bi bi-wallet2 me-1"></i> Balance: <strong><?= DEFAULT_CURRENCY ?> ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}</strong> | Area: ${area} | Phone: ${phone} &bull; <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check2-circle me-1"></i> Customer Billed Rates Auto-Matched</span>`;

                if (targetInvoiceId > 0) {
                    onInvoiceChanged(targetInvoiceId);
                }
            }
        })
        .catch(err => {
            console.error(err);
            custInfo.innerHTML = `<i class="bi bi-wallet2 me-1"></i> Balance: <strong><?= DEFAULT_CURRENCY ?> ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}</strong> | Area: ${area}`;
        });
}

function updateProductCardsWithCustomerRates() {
    document.querySelectorAll('.pos-product-card').forEach(card => {
        const prodId = parseInt(card.getAttribute('data-id'), 10);
        const standardRate = parseFloat(card.getAttribute('data-standard-rate')) || 0;
        const rateLabel = document.getElementById('card_rate_label_' + prodId);

        if (customerRateMap[prodId]) {
            const hist = customerRateMap[prodId];
            card.setAttribute('data-rate', hist.rate);
            if (rateLabel) {
                rateLabel.innerHTML = `<strong>Last Sold: <?= DEFAULT_CURRENCY ?> ${hist.rate.toFixed(0)}</strong> <small class="text-muted d-block" style="font-size: 10px;">${hist.invoice_number}</small>`;
                rateLabel.className = 'prod-price text-success';
            }
        } else {
            card.setAttribute('data-rate', standardRate);
            if (rateLabel) {
                rateLabel.innerText = `Rate: <?= DEFAULT_CURRENCY ?> ${standardRate.toFixed(0)}`;
                rateLabel.className = 'prod-price text-danger';
            }
        }
    });
}

function resetCardRatesToStandard() {
    document.querySelectorAll('.pos-product-card').forEach(card => {
        const standardRate = parseFloat(card.getAttribute('data-standard-rate')) || 0;
        const prodId = card.getAttribute('data-id');
        const rateLabel = document.getElementById('card_rate_label_' + prodId);
        card.setAttribute('data-rate', standardRate);
        if (rateLabel) {
            rateLabel.innerText = `Rate: <?= DEFAULT_CURRENCY ?> ${standardRate.toFixed(0)}`;
            rateLabel.className = 'prod-price text-danger';
        }
    });
}

function onInvoiceChanged(invoiceId) {
    const btnLoad = document.getElementById('btnLoadInvoiceItems');
    const pill = document.getElementById('pillFromInvoice');

    if (!invoiceId) {
        btnLoad.classList.add('d-none');
        pill.classList.add('d-none');
        currentInvoiceItems = [];
        return;
    }

    fetch(`../../ajax/returns/get_invoice_items.php?invoice_id=${invoiceId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentInvoiceItems = data.data.items || [];
                btnLoad.classList.remove('d-none');
                pill.classList.remove('d-none');

                // Mark cards that belong to this invoice
                const invProductIds = currentInvoiceItems.map(i => parseInt(i.product_id, 10));
                document.querySelectorAll('.pos-product-card').forEach(card => {
                    const pid = parseInt(card.getAttribute('data-id'), 10);
                    if (invProductIds.includes(pid)) {
                        card.setAttribute('data-in-invoice', '1');
                    } else {
                        card.removeAttribute('data-in-invoice');
                    }
                });
            }
        })
        .catch(err => console.error(err));
}

function loadAllInvoiceItemsToCart() {
    if (currentInvoiceItems.length === 0) return;

    currentInvoiceItems.forEach(item => {
        const pid = parseInt(item.product_id, 10);
        if (!cartItems[pid]) {
            const prod = productMap[pid];
            const packsPerCtn = parseFloat(prod?.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
            const pcsPerPack  = parseFloat(prod?.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
            const pcsPerCtn   = packsPerCtn * pcsPerPack;
            const billedRate  = parseFloat(item.rate) || 0;

            cartItems[pid] = {
                product_id: pid,
                product_name: item.product_name,
                product_code: item.product_code,
                packs_per_ctn: packsPerCtn,
                pcs_per_pack: pcsPerPack,
                pcs_per_ctn: pcsPerCtn,
                carton_qty: parseFloat(item.carton_qty) || 1,
                pack_qty: parseFloat(item.pack_qty) || 0,
                pcs_qty: parseFloat(item.pcs_qty) || 0,
                rate: billedRate,
                discount_percent: parseFloat(item.discount_percent) || 0,
                condition: document.getElementById('conditionSelect').value,
                line_total: 0,
                source_inv: item.invoice_number || ''
            };

            renderNewCartRow(pid);
        }
        updateCardBadge(pid);
    });

    recalculateAllRows();
    recalculateCartSummary();
}

let selectedCardIndex = -1;

function getVisibleCards() {
    return Array.from(document.querySelectorAll('.pos-product-card:not(.d-none)'));
}

function highlightCard(index) {
    const visibleCards = getVisibleCards();
    if (visibleCards.length === 0) {
        selectedCardIndex = -1;
        return;
    }

    if (index < 0) index = 0;
    if (index >= visibleCards.length) index = visibleCards.length - 1;

    selectedCardIndex = index;

    document.querySelectorAll('.pos-product-card').forEach(c => c.classList.remove('active-selected'));
    const targetCard = visibleCards[selectedCardIndex];
    if (targetCard) {
        targetCard.classList.add('active-selected');
        targetCard.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function filterProductCards() {
    const productSearch = document.getElementById('productSearch');
    const query = productSearch ? productSearch.value.trim().toLowerCase() : '';
    const activeCatEl = document.querySelector('.pos-category-pill.active');
    const activeCat = activeCatEl ? activeCatEl.getAttribute('data-cat') : '';

    document.querySelectorAll('.pos-product-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const code = (card.getAttribute('data-code') || '').toLowerCase();
        const cat  = card.getAttribute('data-cat');
        const inInvoice = card.getAttribute('data-in-invoice') === '1';

        const matchQuery = !query || name.includes(query) || code.includes(query);
        let matchCat = false;

        if (!activeCat) {
            matchCat = true;
        } else if (activeCat === 'from_invoice') {
            matchCat = inInvoice;
        } else {
            matchCat = (cat === activeCat);
        }

        if (matchQuery && matchCat) {
            card.classList.remove('d-none');
        } else {
            card.classList.add('d-none');
            card.classList.remove('active-selected');
        }
    });

    const visible = getVisibleCards();
    if (visible.length > 0) {
        highlightCard(0);
    } else {
        selectedCardIndex = -1;
    }
}

let cartItems = {};

function addProductToCart(productId) {
    const prod = productMap[productId];
    if (!prod) return;

    if (cartItems[productId]) {
        cartItems[productId].carton_qty += 1;
        updateCartRow(productId);
    } else {
        const packsPerCtn = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
        const pcsPerCtn   = packsPerCtn * pcsPerPack;
        
        // Exact historical rate lookup
        let returnRate = parseFloat(prod.wholesale_rate) || 0;
        let discPct = 0;
        let sourceInfo = '';

        if (customerRateMap[productId]) {
            returnRate = customerRateMap[productId].rate;
            discPct = customerRateMap[productId].discount_percent;
            sourceInfo = `Billed #${customerRateMap[productId].invoice_number}`;
        }

        const globalCond = document.getElementById('conditionSelect').value || 'saleable';

        cartItems[productId] = {
            product_id: productId,
            product_name: prod.product_name,
            product_code: prod.product_code,
            packs_per_ctn: packsPerCtn,
            pcs_per_pack: pcsPerPack,
            pcs_per_ctn: pcsPerCtn,
            carton_qty: 1,
            pack_qty: 0,
            pcs_qty: 0,
            rate: returnRate,
            discount_percent: discPct,
            condition: globalCond,
            line_total: returnRate,
            source_inv: sourceInfo
        };

        renderNewCartRow(productId);
    }

    updateCardBadge(productId);
    recalculateCartSummary();

    const card = document.getElementById('card_prod_' + productId);
    if (card) {
        const visible = getVisibleCards();
        const idx = visible.indexOf(card);
        if (idx >= 0) highlightCard(idx);
    }

    // Auto-focus carton quantity input
    const rowInput = document.getElementById('input_carton_' + productId);
    if (rowInput) {
        rowInput.focus();
        rowInput.select();
    }
}

function renderNewCartRow(productId) {
    const emptyRow = document.getElementById('emptyCartRow');
    if (emptyRow) emptyRow.style.display = 'none';
    document.getElementById('btnClearCart').style.display = 'inline-block';

    const item = cartItems[productId];
    const tbody = document.getElementById('cartItemsBody');

    const ratioText = item.pcs_per_pack > 1 
        ? `1*${item.packs_per_ctn}*${item.pcs_per_pack}` 
        : `1*${item.packs_per_ctn}`;

    const sourceBadge = item.source_inv 
        ? `<span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size: 10px;">${item.source_inv}</span>` 
        : '';

    const tr = document.createElement('tr');
    tr.id = 'cart_row_' + productId;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="item_product_id[]" value="${item.product_id}">
            <div class="fw-bold text-navy" style="font-size: 13px;">
                ${item.product_name} ${sourceBadge}
            </div>
            <small class="text-muted">Pack: ${ratioText} &bull; ${item.product_code}</small>
        </td>
        <td class="text-center">
            <input type="number" step="0.01" name="item_carton_qty[]" id="input_carton_${productId}" 
                   class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                   value="${item.carton_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'carton')" required>
        </td>
        <td class="text-center">
            <input type="number" step="1" name="item_pack_qty[]" id="input_pack_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pack_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'pack')">
        </td>
        <td class="text-center">
            <input type="number" step="1" name="item_pcs_qty[]" id="input_pcs_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pcs_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'pcs')">
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="item_rate[]" id="input_rate_${productId}" 
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input text-danger" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'rate')" required>
            <input type="hidden" name="item_discount_percent[]" value="${item.discount_percent}">
        </td>
        <td class="text-center">
            <select name="item_condition[]" class="form-select form-select-sm" style="font-size: 11px;">
                <option value="saleable" ${item.condition === 'saleable' ? 'selected' : ''}>Saleable</option>
                <option value="damaged" ${item.condition === 'damaged' ? 'selected' : ''}>Damaged</option>
                <option value="expired" ${item.condition === 'expired' ? 'selected' : ''}>Expired</option>
            </select>
        </td>
        <td class="text-end fw-bold text-danger" id="lbl_total_${productId}">
            <?= DEFAULT_CURRENCY ?> ${item.line_total.toFixed(2)}
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" onclick="removeProductFromCart(${productId})" title="Remove item">
                <i class="bi bi-x-lg"></i>
            </button>
        </td>
    `;
    tbody.prepend(tr);
}

function updateCartRow(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const inputCarton = document.getElementById('input_carton_' + productId);
    const inputPack   = document.getElementById('input_pack_' + productId);
    const inputPcs    = document.getElementById('input_pcs_' + productId);
    const inputRate   = document.getElementById('input_rate_' + productId);
    const lblTotal    = document.getElementById('lbl_total_' + productId);

    if (inputCarton) inputCarton.value = item.carton_qty;
    if (inputPack) inputPack.value = item.pack_qty;
    if (inputPcs) inputPcs.value = item.pcs_qty;
    if (inputRate) inputRate.value = item.rate.toFixed(2);

    const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (item.pcs_qty / item.pcs_per_ctn);
    const lineGross = baseCartons * item.rate;
    const lineDisc = (lineGross * item.discount_percent) / 100.0;
    item.line_total = Math.max(0, lineGross - lineDisc);

    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }
}

function onCartRowChange(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const ctnVal  = parseFloat(document.getElementById('input_carton_' + productId).value) || 0;
    const packVal = parseFloat(document.getElementById('input_pack_' + productId).value) || 0;
    const pcsVal  = parseFloat(document.getElementById('input_pcs_' + productId).value) || 0;
    const rateVal = parseFloat(document.getElementById('input_rate_' + productId).value) || 0;

    item.carton_qty = ctnVal;
    item.pack_qty = packVal;
    item.pcs_qty = pcsVal;
    item.rate = rateVal;

    const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (item.pcs_qty / item.pcs_per_ctn);
    const lineGross = baseCartons * item.rate;
    const lineDisc = (lineGross * item.discount_percent) / 100.0;
    item.line_total = Math.max(0, lineGross - lineDisc);

    const lblTotal = document.getElementById('lbl_total_' + productId);
    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }

    updateCardBadge(productId);
    recalculateCartSummary();
}

function onCartInputKeyDown(e, productId, field) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const searchInput = document.getElementById('productSearch');
        if (searchInput) {
            searchInput.value = '';
            filterProductCards();
            searchInput.focus();
            searchInput.select();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        const searchInput = document.getElementById('productSearch');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
}

function removeProductFromCart(productId) {
    delete cartItems[productId];
    const row = document.getElementById('cart_row_' + productId);
    if (row) row.remove();

    updateCardBadge(productId);

    if (Object.keys(cartItems).length === 0) {
        const emptyRow = document.getElementById('emptyCartRow');
        if (emptyRow) emptyRow.style.display = '';
        document.getElementById('btnClearCart').style.display = 'none';
    }

    recalculateCartSummary();
}

function clearCart() {
    if (confirm('Clear all items from return cart?')) {
        cartItems = {};
        document.querySelectorAll('#cartItemsBody tr:not(#emptyCartRow)').forEach(tr => tr.remove());
        const emptyRow = document.getElementById('emptyCartRow');
        if (emptyRow) emptyRow.style.display = '';
        document.getElementById('btnClearCart').style.display = 'none';

        document.querySelectorAll('.pos-product-card').forEach(card => {
            card.classList.remove('in-cart');
        });
        document.querySelectorAll('.card-badge-cart').forEach(badge => {
            badge.classList.add('d-none');
            badge.innerText = '0 in return';
        });

        recalculateCartSummary();
    }
}

function updateCardBadge(productId) {
    const card  = document.getElementById('card_prod_' + productId);
    const badge = document.getElementById('badge_cart_' + productId);
    const item  = cartItems[productId];

    if (card && badge) {
        if (item && (item.carton_qty > 0 || item.pack_qty > 0 || item.pcs_qty > 0)) {
            card.classList.add('in-cart');
            badge.classList.remove('d-none');
            let badgeText = `${item.carton_qty} Ctn`;
            if (item.pack_qty > 0) badgeText += ` +${item.pack_qty}k`;
            if (item.pcs_qty > 0) badgeText += ` +${item.pcs_qty}p`;
            badge.innerText = badgeText;
        } else {
            card.classList.remove('in-cart');
            badge.classList.add('d-none');
            badge.innerText = '0 in return';
        }
    }
}

function recalculateAllRows() {
    Object.keys(cartItems).forEach(productId => {
        const item = cartItems[productId];
        const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (item.pcs_qty / item.pcs_per_ctn);
        const lineGross = baseCartons * item.rate;
        const lineDisc = (lineGross * item.discount_percent) / 100.0;
        item.line_total = Math.max(0, lineGross - lineDisc);

        const lblTotal = document.getElementById('lbl_total_' + productId);
        if (lblTotal) {
            lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        }
    });
}

function recalculateCartSummary() {
    let totalCartons = 0;
    let totalPacks   = 0;
    let totalPieces  = 0;
    let netAmount    = 0;
    let itemCount    = 0;

    Object.values(cartItems).forEach(item => {
        itemCount++;
        totalCartons += item.carton_qty;
        totalPacks   += item.pack_qty;
        totalPieces  += item.pcs_qty;

        const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (item.pcs_qty / item.pcs_per_ctn);
        const lineGross = baseCartons * item.rate;
        const lineDisc = (lineGross * item.discount_percent) / 100.0;
        netAmount += Math.max(0, lineGross - lineDisc);
    });

    document.getElementById('cartItemCount').innerText = `${itemCount} item${itemCount === 1 ? '' : 's'}`;
    let ctnSummaryText = `${totalCartons.toLocaleString('en-US', {maximumFractionDigits:2})} Ctns`;
    if (totalPacks > 0) ctnSummaryText += ` + ${totalPacks} Pck`;
    document.getElementById('summaryTotalCartons').innerText = ctnSummaryText;
    document.getElementById('summaryTotalPieces').innerText = `${totalPieces} Pcs`;
    document.getElementById('summaryNetAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${netAmount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;

    // Target settlement comparison if applicable
    if (TARGET_SETTLEMENT_AMOUNT > 0) {
        const diff = Math.round((TARGET_SETTLEMENT_AMOUNT - netAmount) * 100) / 100;
        const diffLabel = document.getElementById('settlementDiffLabel');
        if (diffLabel) {
            if (Math.abs(diff) < 0.01) {
                diffLabel.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i> Exactly Balanced with Settlement!</span>`;
            } else if (diff > 0) {
                diffLabel.innerHTML = `<span class="text-warning">Remaining: <?= DEFAULT_CURRENCY ?> ${diff.toFixed(2)}</span>`;
            } else {
                diffLabel.innerHTML = `<span class="text-danger">Exceeds Target by: <?= DEFAULT_CURRENCY ?> ${Math.abs(diff).toFixed(2)}</span>`;
            }
        }
    }
}
</script>

<?php
require_once INCLUDES_PATH . '/drawers/product_price_modal.php';
require_once INCLUDES_PATH . '/footer.php';
?>
