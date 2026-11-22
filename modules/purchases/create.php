<?php
/**
 * DMS - High-Speed Keyboard & Card-Driven Purchase Order Booking
 * Matches the fast POS sales/order workflow for factory purchasing & stock replenishment
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('purchases_create');
require_once INCLUDES_PATH . '/inventory_functions.php';

$suppliers = db_fetch_all("
    SELECT s.id, s.supplier_code, s.supplier_name, s.phone, s.address, s.city, s.current_balance 
    FROM suppliers s 
    WHERE s.is_deleted = 0 AND s.is_active = 1 
    ORDER BY s.supplier_name ASC
");

$preselectedSupplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

$categories = db_fetch_all("
    SELECT id, category_name 
    FROM product_categories 
    WHERE is_active = 1 
    ORDER BY category_name ASC
");

$products = db_fetch_all("
    SELECT 
        p.id, p.product_name, p.product_code, p.barcode, p.category_id, p.supplier_id, p.brand_id,
        cat.category_name, b.brand_name,
        p.purchase_rate, p.purchase_carton_rate, p.purchase_piece_rate, p.purchase_rate as cost_price, p.default_sale_rate, p.wholesale_rate, p.carton_rate, p.piece_rate, p.retail_rate,
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

$nextPurchaseNumber = generate_document_number(PREFIX_PURCHASE, 'purchase_orders', 'purchase_number');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $poNumber     = clean_input($_POST['purchase_number'] ?? $nextPurchaseNumber);
    $orderDate    = sanitize_input($_POST['order_date'] ?? date('Y-m-d'));
    $deliveryDate = !empty($_POST['expected_delivery_date']) ? sanitize_input($_POST['expected_delivery_date']) : null;
    $supplierId   = (int)($_POST['supplier_id'] ?? 0);
    $supplierInv  = sanitize_input($_POST['supplier_invoice_no'] ?? '');
    $status       = isset($_POST['save_draft']) ? 'draft' : sanitize_input($_POST['status'] ?? 'ordered');
    $remarks      = sanitize_input($_POST['remarks'] ?? '');

    // Dual discount modes
    $discountMode = clean_input($_POST['discount_mode'] ?? 'item_rate');
    if ($discountMode !== 'subtotal') {
        $discountMode = 'item_rate';
    }
    $subtotalDiscountPercent = (float)($_POST['subtotal_discount_percent'] ?? 0);
    $subtotalDiscountAmount  = (float)($_POST['subtotal_discount_amount'] ?? 0);

    // Support both fast cart array format and legacy items table format
    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPackQtys   = $_POST['item_pack_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemUnits      = $_POST['item_unit'] ?? [];
    $itemRates      = $_POST['item_rate'] ?? [];
    $itemDiscounts  = $_POST['item_discount_percent'] ?? [];

    // Fallback to legacy format if submitted through old form
    if (empty($itemProductIds) && !empty($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $it) {
            if (!empty($it['product_id'])) {
                $itemProductIds[] = $it['product_id'];
                $itemCartonQtys[] = $it['carton_qty'] ?? 0;
                $itemPackQtys[]   = $it['pack_qty'] ?? 0;
                $itemPcsQtys[]    = $it['pcs_qty'] ?? 0;
                $itemUnits[]      = $it['unit'] ?? 'ctn';
                $itemRates[]      = $it['rate'] ?? 0;
                $itemDiscounts[]  = $it['discount_percent'] ?? ($it['discount_amount'] ?? 0);
            }
        }
    }

    if ($supplierId <= 0) {
        $errors[] = "Please select a supplier / manufacturer.";
    }
    if (empty($itemProductIds)) {
        $errors[] = "Please add at least one product to the purchase order cart.";
    }

    $validItems = [];
    $grossAmount = 0.0;
    $discountAmount = 0.0;
    $totalCartons = 0.0;
    $totalPieces = 0.0;
    $totalUnits = 0.0;

    foreach ($itemProductIds as $idx => $pid) {
        $prodId    = (int)$pid;
        $cartonQty = (float)($itemCartonQtys[$idx] ?? 0);
        $packQty   = (float)($itemPackQtys[$idx] ?? 0);
        $pcsQty    = (float)($itemPcsQtys[$idx] ?? 0);
        $unitCode  = strtolower(trim((string)($itemUnits[$idx] ?? 'ctn')));
        $rate      = (float)($itemRates[$idx] ?? 0);
        $discPct   = (float)($itemDiscounts[$idx] ?? 0);

        if ($prodId <= 0 || ($cartonQty <= 0 && $packQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $totalQuantityInBaseUnits = calculate_base_pieces($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
        $baseCartons = calculate_carton_equivalent($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);

        if ($unitCode === 'pcs') {
            $lineGross = $totalQuantityInBaseUnits * $rate;
            $unitId = 4; // Piece unit
            $itemQty = $totalQuantityInBaseUnits;
        } else {
            $lineGross = $baseCartons * $rate;
            $unitId = (int)$prod['base_unit_id']; // Carton unit
            $itemQty = $baseCartons;
        }

        if ($discountMode === 'subtotal') {
            $itemDiscPct  = 0.0;
            $lineDiscount = 0.0;
            $lineNet      = $lineGross;
        } else {
            $itemDiscPct  = $discPct;
            $lineDiscount = ($lineGross * $itemDiscPct) / 100.0;
            $lineNet      = max(0, $lineGross - $lineDiscount);
            $discountAmount += $lineDiscount;
        }

        $grossAmount  += $lineGross;
        $totalCartons += $cartonQty;
        $totalPieces  += $pcsQty;
        $totalUnits   += $totalQuantityInBaseUnits;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => $unitId,
            'carton_qty'       => $cartonQty,
            'pack_qty'         => $packQty,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $itemQty,
            'rate'             => $rate,
            'discount_percent' => $itemDiscPct,
            'discount_amount'  => $lineDiscount,
            'line_total'       => $lineNet
        ];
    }

    if (empty($validItems) && empty($errors)) {
        $errors[] = "Please enter valid quantities greater than zero for order items.";
    }

    if (empty($errors)) {
        try {
            if ($discountMode === 'subtotal') {
                if ($subtotalDiscountAmount > 0) {
                    $discountAmount = $subtotalDiscountAmount;
                    if ($grossAmount > 0 && $subtotalDiscountPercent <= 0) {
                        $subtotalDiscountPercent = round(($discountAmount / $grossAmount) * 100, 2);
                    }
                } elseif ($subtotalDiscountPercent > 0) {
                    $discountAmount = round(($grossAmount * $subtotalDiscountPercent) / 100.0, 2);
                    $subtotalDiscountAmount = $discountAmount;
                }
            }

            $netAmount = max(0, $grossAmount - $discountAmount);

            db()->beginTransaction();
            $userId = current_user_id();

            $stmtPO = db()->prepare("
                INSERT INTO purchase_orders (
                    purchase_number, supplier_id, supplier_invoice_no, discount_mode,
                    order_date, expected_delivery_date, total_amount, discount_amount,
                    subtotal_discount_percent, subtotal_discount_amount, total_cartons, total_units,
                    net_amount, status, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, NOW()
                )
            ");
            $stmtPO->execute([
                $poNumber, $supplierId, $supplierInv, $discountMode,
                $orderDate, $deliveryDate, $grossAmount, $discountAmount,
                $subtotalDiscountPercent, $subtotalDiscountAmount, $totalCartons, $totalUnits,
                $netAmount, $status, $remarks, $userId
            ]);
            $poId = (int)db()->lastInsertId();

            $stmtItem = db()->prepare("
                INSERT INTO purchase_order_items (
                    purchase_order_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
                    quantity, rate, discount_percent, discount_amount, line_total, received_quantity, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, 0.00, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $poId, $vi['product_id'], $vi['unit_id'],
                    $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['quantity'], $vi['rate'], $vi['discount_percent'],
                    $vi['discount_amount'], $vi['line_total']
                ]);
            }

            if (function_exists('audit_log')) {
                audit_log('create_po', 'purchase_orders', (string)$poId, "Created purchase order {$poNumber} for {$totalCartons} ctns, Net: {$netAmount}, Status: {$status}");
            }

            db()->commit();
            set_flash('success', "Purchase Order <strong>{$poNumber}</strong> created successfully (" . ucfirst($status) . ")!");
            redirect(APP_URL . '/modules/purchases/view.php?id=' . $poId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error creating purchase order: " . $e->getMessage());
            $errors[] = "Error saving purchase order: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Create Purchase Order';
$activeMenu = 'purchases';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Purchases', 'url' => 'index.php'],
    ['label' => 'New Order', 'url' => '']
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
                <h5 class="mb-0 fw-bold text-navy"><i class="bi bi-cart-plus me-2 text-primary"></i>Create Purchase Order</h5>
                <small class="text-muted">Fast card selection: Click products or search to add to purchase cart instantly</small>
            </div>
            <div>
                <a href="<?= APP_URL ?>/modules/purchases/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> PO List (ESC)
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

        <form method="POST" action="<?= APP_URL ?>/modules/purchases/create.php" id="poForm" autocomplete="off">
            <input type="hidden" name="purchase_number" value="<?= e($nextPurchaseNumber) ?>">

            <!-- Supplier & Shipment Terms Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Supplier / Factory <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="supplierSelect" class="form-select form-select-sm" required autofocus>
                                <option value="">-- Select Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ($preselectedSupplierId === (int)$s['id']) ? 'selected' : '' ?>
                                            data-balance="<?= (float)$s['current_balance'] ?>"
                                            data-phone="<?= htmlspecialchars($s['phone'] ?? '') ?>"
                                            data-city="<?= htmlspecialchars($s['city'] ?? '') ?>">
                                        <?= htmlspecialchars($s['supplier_name']) ?> <?= !empty($s['city']) ? '(' . htmlspecialchars($s['city']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Order Date <span class="text-danger">*</span></label>
                            <input type="date" name="order_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Expected Delivery</label>
                            <input type="date" name="expected_delivery_date" class="form-control form-control-sm" value="">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Supplier Bilty / Inv #</label>
                            <input type="text" name="supplier_invoice_no" class="form-control form-control-sm" placeholder="e.g. BL-9842">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Order Status</label>
                            <select name="status" id="orderStatusSelect" class="form-select form-select-sm">
                                <option value="ordered" selected>Ordered (Confirmed)</option>
                                <option value="draft">Draft (Preparing)</option>
                            </select>
                        </div>

                        <div class="col-md-1 text-end">
                            <span class="badge bg-navy text-white font-monospace py-2 px-2" style="font-size: 11px;"><?= e($nextPurchaseNumber) ?></span>
                        </div>
                    </div>

                    <!-- Supplier Financial Status Banner -->
                    <div id="supplierAlert" class="mt-2 py-1 px-2 rounded small bg-light border text-secondary" style="display:none;">
                        <span id="supplierInfo"></span>
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

                            <!-- Company / Supplier Filter -->
                            <div class="d-flex align-items-center gap-2 mb-2 px-1">
                                <span class="small fw-bold text-secondary text-nowrap"><i class="bi bi-building me-1 text-primary"></i>Company / Supplier:</span>
                                <select id="catalogSupplierFilter" class="form-select form-select-sm py-0" style="font-size:12px; height:28px;">
                                    <option value="">-- All Companies / Suppliers --</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?><?= !empty($sup['company_name']) ? ' (' . htmlspecialchars($sup['company_name']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Category Filter Pills -->
                            <div class="pos-category-pills">
                                <button type="button" class="pos-category-pill active" data-cat="">All Products</button>
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
                                $isOutOfStock = (float)$p['saleable_qty'] <= 0;
                                $ratioText = $pcsPerPack > 1 ? "1*{$packsPerCtn}*{$pcsPerPack}" : "1*{$packsPerCtn}";
                                $purchaseCartonRate = (float)($p['purchase_carton_rate'] > 0 ? $p['purchase_carton_rate'] : ($p['carton_rate'] > 0 ? $p['carton_rate'] : $p['wholesale_rate']));
                                $purchasePieceRate  = (float)($p['purchase_piece_rate'] > 0 ? $p['purchase_piece_rate'] : ($p['piece_rate'] > 0 ? $p['piece_rate'] : ($pcsPerCtn > 0 ? $purchaseCartonRate / $pcsPerCtn : $purchaseCartonRate)));
                            ?>
                                <div class="pos-product-card <?= $isOutOfStock ? 'out-of-stock' : '' ?>" 
                                     id="card_prod_<?= $p['id'] ?>"
                                     data-id="<?= $p['id'] ?>"
                                     data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                     data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                     data-cat="<?= $p['category_id'] ?>"
                                     data-supplier-id="<?= (int)($p['supplier_id'] ?? 0) ?>"
                                     data-rate="<?= $purchaseCartonRate ?>"
                                     data-carton-rate="<?= $purchaseCartonRate ?>"
                                     data-piece-rate="<?= $purchasePieceRate ?>"
                                     data-packs-per-ctn="<?= $packsPerCtn ?>"
                                     data-pcs-per-pack="<?= $pcsPerPack ?>"
                                     data-pcs-per-ctn="<?= $pcsPerCtn ?>"
                                     onclick="addProductToCart(<?= $p['id'] ?>)">
                                    
                                    <span class="card-badge-cart d-none" id="badge_cart_<?= $p['id'] ?>">0 in cart</span>
                                    <button type="button" class="btn-card-info" title="View Prices & Rates (خرید/ہول سیل/پرچون)" onclick="showProductPriceModal(event, <?= $p['id'] ?>)">
                                        <i class="bi bi-info-circle"></i>
                                    </button>
                                    
                                    <div>
                                        <div class="prod-name"><?= htmlspecialchars($p['product_name']) ?></div>
                                        <div class="prod-meta">
                                            <span>Pack: <strong><?= htmlspecialchars(!empty($p['packing_description']) ? $p['packing_description'] : $ratioText) ?></strong></span> &bull; 
                                            <span><?= htmlspecialchars($p['product_code']) ?></span>
                                        </div>
                                    </div>

                                    <div class="prod-price-stock">
                                        <div>
                                            <div class="prod-price" style="font-size: 13px; line-height: 1.2;">
                                                Cost: <?= DEFAULT_CURRENCY ?> <?= number_format($purchaseCartonRate, 0) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>
                                            </div>
                                            <div class="text-secondary fw-semibold" style="font-size: 11px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format($purchasePieceRate, 2) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Pc</span>
                                            </div>
                                        </div>
                                        <div class="prod-stock text-end">
                                            <?= $isOutOfStock ? '0 avail' : htmlspecialchars($stockBreakdown['formatted']) . ' avail' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive Purchase Items Cart -->
                <div class="col-lg-7">
                    <div class="pos-cart-container">
                        <div class="pos-cart-header">
                            <span class="fw-bold text-navy">
                                <i class="bi bi-cart3 me-1 text-primary"></i> Purchase Items Cart 
                                <span class="badge bg-primary ms-1" id="cartItemCount">0 items</span>
                            </span>
                            <button type="button" class="btn btn-xs btn-outline-danger" onclick="clearCart()" id="btnClearCart" style="display:none;">
                                <i class="bi bi-trash me-1"></i> Clear Cart
                            </button>
                        </div>

                        <!-- Discount Mode Radio Selection Bar -->
                        <div class="pos-discount-mode-bar bg-light p-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <span class="small fw-bold text-secondary"><i class="bi bi-tag-fill me-1 text-primary"></i> Discount:</span>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeItem" value="item_rate" checked onchange="onDiscountModeChange()">
                                    <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeItem">Discount from per item rate</label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeSubtotal" value="subtotal" onchange="onDiscountModeChange()">
                                    <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeSubtotal">Discount from subtotal</label>
                                </div>
                            </div>
                            <div id="subtotalDiscountInputs" class="d-none align-items-center gap-2">
                                <span class="small fw-bold text-navy">Subtotal Disc:</span>
                                <div class="input-group input-group-sm" style="width: 110px;">
                                    <input type="number" step="0.01" min="0" max="100" name="subtotal_discount_percent" id="subtotalDiscountPercent" class="form-control form-control-sm text-end" placeholder="0" oninput="onSubtotalPercentChange()">
                                    <span class="input-group-text small">%</span>
                                </div>
                                <div class="input-group input-group-sm" style="width: 140px;">
                                    <span class="input-group-text small">Rs</span>
                                    <input type="number" step="0.01" min="0" name="subtotal_discount_amount" id="subtotalDiscountAmount" class="form-control form-control-sm text-end" placeholder="0.00" oninput="onSubtotalAmountChange()">
                                </div>
                            </div>
                        </div>

                        <div class="pos-cart-table-wrapper">
                            <table class="table pos-cart-table table-hover align-middle" id="cartTable">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Product Details</th>
                                        <th style="width: 9%;" class="text-center">Unit</th>
                                        <th style="width: 10%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 9%;" class="text-center">Packs</th>
                                        <th style="width: 9%;" class="text-center">Extra Pcs</th>
                                        <th style="width: 15%;" class="text-end">Cost</th>
                                        <th style="width: 8%;" class="text-end">Disc %</th>
                                        <th style="width: 12%;" class="text-end">Line Total</th>
                                        <th style="width: 30px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody">
                                    <tr id="emptyCartRow">
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-hand-index-thumb fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                            <div class="fw-semibold">No items added to purchase order yet.</div>
                                            <small>Click on product cards on the left or use the search bar to add items.</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Real-time Purchase Totals Footer -->
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
                                    <div class="text-muted small" id="lblSummaryDiscount">Total Discount</div>
                                    <div class="fw-bold fs-6 text-danger" id="summaryDiscountAmount">Rs. 0.00</div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="text-muted small">Net Order Amount</div>
                                    <div class="fw-bold fs-5 text-navy" id="summaryNetAmount">Rs. 0.00</div>
                                </div>
                            </div>

                            <div class="row g-2 align-items-center">
                                <div class="col-md-7">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Purchase order instructions, bilty terms, or delivery notes...">
                                </div>
                                <div class="col-md-5 text-end">
                                    <button type="submit" name="save_draft" class="btn btn-secondary btn-sm px-3 me-1">
                                        <i class="bi bi-save me-1"></i> Save Draft
                                    </button>
                                    <button type="submit" name="confirm_now" class="btn btn-primary btn-sm px-3 fw-bold">
                                        <i class="bi bi-check2-circle me-1"></i> Submit Order (F9)
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

<!-- Embedded Product Cache for Real-Time Calculations -->
<script>
    const DMS_PRODUCTS = <?= json_encode($products) ?>;
    const productMap = {};
    DMS_PRODUCTS.forEach(p => { productMap[p.id] = p; });
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const supplierSelect = document.getElementById('supplierSelect');
    const supplierAlert  = document.getElementById('supplierAlert');
    const supplierInfo   = document.getElementById('supplierInfo');
    const productSearch  = document.getElementById('productSearch');

    // Supplier selection updates financial balance & contact banner, and filters products
    supplierSelect.addEventListener('change', function () {
        const catalogSupplierFilter = document.getElementById('catalogSupplierFilter');
        if (catalogSupplierFilter) {
            catalogSupplierFilter.value = this.value;
        }
        const opt = this.options[this.selectedIndex];
        if (!opt || !opt.value) {
            supplierAlert.style.display = 'none';
        } else {
            const balance = parseFloat(opt.getAttribute('data-balance') || 0);
            const phone = opt.getAttribute('data-phone') || 'N/A';
            const city  = opt.getAttribute('data-city') || '';

            supplierAlert.style.display = 'block';
            supplierInfo.innerHTML = `<i class="bi bi-wallet2 me-1"></i> Current Payable Balance: <strong><?= DEFAULT_CURRENCY ?> ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}</strong> | Phone: ${phone} ${city ? `| City: <strong>${city}</strong>` : ''}`;
        }
        filterProductCards();
    });

    const catalogSupplierFilter = document.getElementById('catalogSupplierFilter');
    if (catalogSupplierFilter) {
        catalogSupplierFilter.addEventListener('change', function () {
            if (supplierSelect) {
                supplierSelect.value = this.value;
                supplierSelect.dispatchEvent(new Event('change'));
            } else {
                filterProductCards();
            }
        });
    }

    if (supplierSelect.value) {
        supplierSelect.dispatchEvent(new Event('change'));
    }

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

    // Arrow Key Navigation & Enter on Search Input
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

    // Global Keyboard Shortcuts: '/' or F2 to search, F9 to submit, arrows when outside inputs
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
            document.getElementById('poForm').submit();
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

    // Initial highlight on first card
    highlightCard(0);
});

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
    const catalogSupplierFilter = document.getElementById('catalogSupplierFilter');
    const supplierSelect = document.getElementById('supplierSelect');
    const selectedSupplier = (catalogSupplierFilter && catalogSupplierFilter.value) ? catalogSupplierFilter.value : (supplierSelect ? supplierSelect.value : '');

    let visibleCount = 0;
    document.querySelectorAll('.pos-product-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const code = (card.getAttribute('data-code') || '').toLowerCase();
        const cat  = card.getAttribute('data-cat');
        const sup  = card.getAttribute('data-supplier-id');

        const matchQuery = !query || name.includes(query) || code.includes(query);
        const matchCat   = !activeCat || cat === activeCat;
        const matchSup   = !selectedSupplier || sup === selectedSupplier;

        if (matchQuery && matchCat && matchSup) {
            card.classList.remove('d-none');
            visibleCount++;
        } else {
            card.classList.add('d-none');
            card.classList.remove('active-selected');
        }
    });

    // Check empty state
    let emptyNotice = document.getElementById('noProductsSupplierNotice');
    if (visibleCount === 0) {
        if (!emptyNotice) {
            emptyNotice = document.createElement('div');
            emptyNotice.id = 'noProductsSupplierNotice';
            emptyNotice.className = 'col-12 text-center py-5 text-muted';
            const grid = document.getElementById('productCardsGrid');
            if (grid) grid.appendChild(emptyNotice);
        }
        if (emptyNotice) {
            emptyNotice.innerHTML = `
                <i class="bi bi-box-seam fs-1 d-block mb-2 text-secondary opacity-50"></i>
                <div class="fw-semibold">No products found for this selection.</div>
                <small class="text-muted">${selectedSupplier ? 'This supplier has no products linked under this filter.' : 'Try selecting another supplier or clearing filters.'}</small>
            `;
            emptyNotice.classList.remove('d-none');
        }
    } else if (emptyNotice) {
        emptyNotice.classList.add('d-none');
    }

    const visible = getVisibleCards();
    if (visible.length > 0) {
        highlightCard(0);
    } else {
        selectedCardIndex = -1;
    }
}

function getDiscountMode() {
    const radio = document.querySelector('input[name="discount_mode"]:checked');
    return radio ? radio.value : 'item_rate';
}

function onDiscountModeChange() {
    const mode = getDiscountMode();
    const subtotalBox = document.getElementById('subtotalDiscountInputs');
    const discInps = document.querySelectorAll('.pos-cart-table input[name="item_discount_percent[]"]');
    const lblSummary = document.getElementById('lblSummaryDiscount');

    if (mode === 'subtotal') {
        if (subtotalBox) {
            subtotalBox.classList.remove('d-none');
            subtotalBox.classList.add('d-flex');
        }
        discInps.forEach(inp => {
            inp.disabled = true;
            inp.classList.add('bg-light', 'text-muted');
            inp.title = 'Disabled in subtotal discount mode';
        });
        if (lblSummary) lblSummary.innerText = 'Subtotal Discount';
        const pctEl = document.getElementById('subtotalDiscountPercent');
        const amtEl = document.getElementById('subtotalDiscountAmount');
        if (parseFloat(pctEl?.value) > 0) {
            onSubtotalPercentChange();
        } else if (parseFloat(amtEl?.value) > 0) {
            onSubtotalAmountChange();
        }
    } else {
        if (subtotalBox) {
            subtotalBox.classList.remove('d-flex');
            subtotalBox.classList.add('d-none');
        }
        discInps.forEach(inp => {
            inp.disabled = false;
            inp.classList.remove('bg-light', 'text-muted');
            inp.title = '';
        });
        if (lblSummary) lblSummary.innerText = 'Total Discount';
    }

    recalculateAllRows();
    recalculateCartSummary();
}

function onSubtotalPercentChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const pctVal = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
    let grossAmount = 0;
    Object.values(cartItems).forEach(item => {
        const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (item.pcs_qty / item.pcs_per_ctn);
        grossAmount += (baseCartons * item.rate);
    });

    const amt = Math.round(((grossAmount * pctVal) / 100.0) * 100) / 100;
    const amtEl = document.getElementById('subtotalDiscountAmount');
    if (amtEl && document.activeElement !== amtEl) {
        amtEl.value = amt > 0 ? amt.toFixed(2) : '';
    }

    recalculateCartSummary();
}

function calculatePurchaseLineGross(item) {
    if (item.unit === 'pcs') {
        const totalPieces = (item.carton_qty * item.pcs_per_ctn) + (item.pack_qty * item.pcs_per_pack) + item.pcs_qty;
        return totalPieces * item.rate;
    } else {
        const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (item.pcs_qty / item.pcs_per_ctn);
        return baseCartons * item.rate;
    }
}

function onSubtotalPercentChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const pctVal = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
    let grossAmount = 0;
    Object.values(cartItems).forEach(item => {
        grossAmount += calculatePurchaseLineGross(item);
    });

    const amt = Math.round(((grossAmount * pctVal) / 100.0) * 100) / 100;
    const amtEl = document.getElementById('subtotalDiscountAmount');
    if (amtEl && document.activeElement !== amtEl) {
        amtEl.value = amt > 0 ? amt.toFixed(2) : '';
    }

    recalculateCartSummary();
}

function onSubtotalAmountChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const amtVal = parseFloat(document.getElementById('subtotalDiscountAmount')?.value) || 0;
    let grossAmount = 0;
    Object.values(cartItems).forEach(item => {
        grossAmount += calculatePurchaseLineGross(item);
    });

    const pct = grossAmount > 0 ? Math.round(((amtVal / grossAmount) * 100.0) * 100) / 100 : 0;
    const pctEl = document.getElementById('subtotalDiscountPercent');
    if (pctEl && document.activeElement !== pctEl) {
        pctEl.value = pct > 0 ? pct.toFixed(2) : '';
    }

    recalculateCartSummary();
}

function recalculateAllRows() {
    const mode = getDiscountMode();
    Object.keys(cartItems).forEach(productId => {
        const item = cartItems[productId];
        const lineGross = calculatePurchaseLineGross(item);
        const lineDisc = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
        item.line_total = Math.max(0, lineGross - lineDisc);

        const lblTotal = document.getElementById('lbl_total_' + productId);
        if (lblTotal) {
            lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        }
    });
}

let cartItems = {};

function addProductToCart(productId) {
    const prod = productMap[productId];
    if (!prod) return;

    if (cartItems[productId]) {
        if (cartItems[productId].unit === 'pcs') {
            cartItems[productId].pcs_qty += cartItems[productId].pcs_per_ctn > 0 ? cartItems[productId].pcs_per_ctn : 1;
        } else {
            cartItems[productId].carton_qty += 1;
        }
        updateCartRow(productId);
    } else {
        const packsPerCtn = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
        const pcsPerCtn   = packsPerCtn * pcsPerPack;
        const cartonRate  = parseFloat(prod.purchase_carton_rate) || parseFloat(prod.carton_rate) || parseFloat(prod.cost_price > 0 ? prod.cost_price : prod.wholesale_rate) || 0;
        const pieceRate   = parseFloat(prod.purchase_piece_rate) || (pcsPerCtn > 0 ? cartonRate / pcsPerCtn : cartonRate);
        const costRate    = cartonRate;

        cartItems[productId] = {
            product_id: productId,
            product_name: prod.product_name,
            product_code: prod.product_code,
            packs_per_ctn: packsPerCtn,
            pcs_per_pack: pcsPerPack,
            pcs_per_ctn: pcsPerCtn,
            unit: 'ctn',
            carton_rate: cartonRate,
            piece_rate: pieceRate,
            carton_qty: 1,
            pack_qty: 0,
            pcs_qty: 0,
            rate: costRate,
            discount_percent: 0,
            line_total: costRate
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

    // Auto-focus carton quantity input of newly added item
    const rowInput = document.getElementById('input_carton_' + productId);
    if (rowInput) {
        rowInput.focus();
        rowInput.select();
    }
}

function onPurchaseUnitChange(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const unitSel = document.getElementById('input_unit_' + productId);
    const unit = unitSel ? unitSel.value : 'ctn';
    item.unit = unit;

    if (unit === 'pcs') {
        item.rate = item.piece_rate;
        if (item.carton_qty > 0 && item.pcs_qty === 0) {
            item.pcs_qty = item.carton_qty * item.pcs_per_ctn;
            item.carton_qty = 0;
            const inputCarton = document.getElementById('input_carton_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCarton) inputCarton.value = 0;
            if (inputPcs) inputPcs.value = item.pcs_qty;
        }
    } else {
        item.rate = item.carton_rate;
        if (item.carton_qty === 0 && item.pcs_qty > 0 && item.pcs_per_ctn > 0) {
            item.carton_qty = Math.floor(item.pcs_qty / item.pcs_per_ctn);
            item.pcs_qty = item.pcs_qty % item.pcs_per_ctn;
            const inputCarton = document.getElementById('input_carton_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCarton) inputCarton.value = item.carton_qty;
            if (inputPcs) inputPcs.value = item.pcs_qty;
        }
    }

    const inputRate = document.getElementById('input_rate_' + productId);
    if (inputRate) inputRate.value = item.rate.toFixed(2);

    const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (unit === 'pcs' ? 'Piece' : 'Carton');

    updateCartRow(productId);
    recalculateCartSummary();
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

    const tr = document.createElement('tr');
    tr.id = 'cart_row_' + productId;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="item_product_id[]" value="${item.product_id}">
            <div class="fw-bold text-navy" style="font-size: 13px;">${item.product_name}</div>
            <small class="text-muted">Pack: ${ratioText} &bull; ${item.product_code}</small>
        </td>
        <td class="text-center">
            <select name="item_unit[]" id="input_unit_${productId}" class="form-select form-select-sm text-center py-0 px-1" onchange="onPurchaseUnitChange(${productId})">
                <option value="ctn" ${item.unit === 'ctn' ? 'selected' : ''}>Ctn</option>
                <option value="pcs" ${item.unit === 'pcs' ? 'selected' : ''}>Pcs</option>
            </select>
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="item_carton_qty[]" id="input_carton_${productId}" 
                   class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                   value="${item.carton_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'carton')" required>
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="item_pack_qty[]" id="input_pack_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pack_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'pack')">
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="item_pcs_qty[]" id="input_pcs_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pcs_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'pcs')">
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="item_rate[]" id="input_rate_${productId}" 
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'rate')" required>
            <span class="small text-muted" id="lbl_rate_unit_${productId}" style="font-size: 10px;">/ ${item.unit === 'pcs' ? 'Piece' : 'Carton'}</span>
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="item_discount_percent[]" id="input_disc_${productId}" 
                   class="form-control form-control-sm text-end pos-cart-input" 
                   value="${item.discount_percent}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'disc')">
        </td>
        <td class="text-end fw-bold text-navy" id="lbl_total_${productId}">
            <?= DEFAULT_CURRENCY ?> ${item.line_total.toFixed(2)}
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" onclick="removeProductFromCart(${productId})" title="Remove item">
                <i class="bi bi-x-lg"></i>
            </button>
        </td>
    `;
    tbody.prepend(tr);

    if (getDiscountMode() === 'subtotal') {
        const discInp = document.getElementById('input_disc_' + productId);
        if (discInp) {
            discInp.disabled = true;
            discInp.classList.add('bg-light', 'text-muted');
            discInp.title = 'Disabled in subtotal discount mode';
        }
    }
}

function updateCartRow(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const inputCarton = document.getElementById('input_carton_' + productId);
    const inputPack   = document.getElementById('input_pack_' + productId);
    const inputPcs    = document.getElementById('input_pcs_' + productId);
    const inputUnit   = document.getElementById('input_unit_' + productId);
    const inputRate   = document.getElementById('input_rate_' + productId);
    const inputDisc   = document.getElementById('input_disc_' + productId);
    const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
    const lblTotal    = document.getElementById('lbl_total_' + productId);

    if (inputCarton) inputCarton.value = item.carton_qty;
    if (inputPack) inputPack.value = item.pack_qty;
    if (inputPcs) inputPcs.value = item.pcs_qty;
    if (inputUnit) inputUnit.value = item.unit || 'ctn';
    if (inputRate) inputRate.value = item.rate.toFixed(2);
    if (inputDisc) inputDisc.value = item.discount_percent;
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (item.unit === 'pcs' ? 'Piece' : 'Carton');

    const lineGross = calculatePurchaseLineGross(item);
    const mode = getDiscountMode();
    const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
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
    const discVal = parseFloat(document.getElementById('input_disc_' + productId).value) || 0;

    item.carton_qty = ctnVal;
    item.pack_qty = packVal;
    item.pcs_qty = pcsVal;
    item.rate = rateVal;
    item.discount_percent = discVal;

    // Keep carton_rate and piece_rate in sync if rate input was edited
    if (item.unit === 'pcs') {
        item.piece_rate = rateVal;
        item.carton_rate = rateVal * item.pcs_per_ctn;
    } else {
        item.carton_rate = rateVal;
        item.piece_rate = item.pcs_per_ctn > 0 ? (rateVal / item.pcs_per_ctn) : rateVal;
    }

    // Auto-switch unit if user specifically entered 0 Cartons and >0 Pieces
    if (item.unit === 'ctn' && ctnVal === 0 && pcsVal > 0) {
        item.unit = 'pcs';
        item.rate = item.piece_rate;
        const unitSel = document.getElementById('input_unit_' + productId);
        if (unitSel) unitSel.value = 'pcs';
        const inputRate = document.getElementById('input_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Piece';
    } else if (item.unit === 'pcs' && ctnVal > 0 && pcsVal === 0) {
        item.unit = 'ctn';
        item.rate = item.carton_rate;
        const unitSel = document.getElementById('input_unit_' + productId);
        if (unitSel) unitSel.value = 'ctn';
        const inputRate = document.getElementById('input_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Carton';
    }

    const lineGross = calculatePurchaseLineGross(item);
    const mode = getDiscountMode();
    const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
    item.line_total = Math.max(0, lineGross - lineDisc);

    const lblTotal = document.getElementById('lbl_total_' + productId);
    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }

    updateCardBadge(productId);

    if (mode === 'subtotal') {
        const pctEl = document.getElementById('subtotalDiscountPercent');
        if (pctEl && parseFloat(pctEl.value) > 0) {
            onSubtotalPercentChange();
            return;
        }
    }

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
    if (confirm('Clear all items from the current purchase order?')) {
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
            badge.innerText = '0 in cart';
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
            badge.innerText = '0 in cart';
        }
    }
}

function recalculateCartSummary() {
    const mode = getDiscountMode();
    let totalCartons = 0;
    let totalPacks   = 0;
    let totalPieces  = 0;
    let grossAmount  = 0;
    let itemDiscSum  = 0;
    let itemCount    = 0;

    Object.values(cartItems).forEach(item => {
        itemCount++;
        totalCartons += item.carton_qty;
        totalPacks   += item.pack_qty;
        totalPieces  += item.pcs_qty;

        const lineGross = calculatePurchaseLineGross(item);
        const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);

        grossAmount += lineGross;
        itemDiscSum += lineDisc;
    });

    let discountAmt = 0;
    if (mode === 'subtotal') {
        const subtotalAmt = parseFloat(document.getElementById('subtotalDiscountAmount')?.value) || 0;
        const subtotalPct = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
        if (subtotalAmt > 0) {
            discountAmt = subtotalAmt;
        } else if (subtotalPct > 0) {
            discountAmt = (grossAmount * subtotalPct) / 100.0;
        }
    } else {
        discountAmt = itemDiscSum;
    }

    const netAmount = Math.max(0, grossAmount - discountAmt);

    document.getElementById('cartItemCount').innerText = `${itemCount} item${itemCount === 1 ? '' : 's'}`;
    let ctnSummaryText = `${totalCartons.toLocaleString('en-US', {maximumFractionDigits:2})} Ctns`;
    if (totalPacks > 0) ctnSummaryText += ` + ${totalPacks} Pck`;
    document.getElementById('summaryTotalCartons').innerText = ctnSummaryText;
    document.getElementById('summaryTotalPieces').innerText = `${totalPieces} Pcs`;
    document.getElementById('summaryDiscountAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${discountAmt.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    document.getElementById('summaryNetAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${netAmount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
}
</script>

<?php
require_once INCLUDES_PATH . '/drawers/product_price_modal.php';
require_once INCLUDES_PATH . '/footer.php';
?>
