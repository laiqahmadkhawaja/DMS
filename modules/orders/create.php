<?php
/**
 * DMS - High-Speed Keyboard & Card-Driven Order Booking
 * Matches PRD Section 10 & 11
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_permission('orders_create');

$customers = db_fetch_all("
    SELECT c.id, c.shop_name, c.area_id, c.booker_id, c.current_balance, c.credit_limit, c.default_discount_percent, a.area_name 
    FROM customers c 
    JOIN areas a ON a.id = c.area_id 
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
        p.id, p.product_name, p.product_code, p.barcode, p.category_id, p.supplier_id, p.brand_id,
        cat.category_name, b.brand_name,
        p.purchase_rate, p.purchase_carton_rate, p.purchase_piece_rate, p.default_sale_rate, p.wholesale_rate, p.carton_rate, p.piece_rate, p.retail_rate,
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

$suppliers = db_fetch_all("SELECT id, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");
$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");
$preselectedCustomerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

$nextOrderNumber = generate_document_number(PREFIX_ORDER, 'orders', 'order_number');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderNumber  = clean_input($_POST['order_number'] ?? $nextOrderNumber);
    $orderDate    = clean_input($_POST['order_date'] ?? date('Y-m-d'));
    $deliveryDate = !empty($_POST['delivery_date']) ? clean_input($_POST['delivery_date']) : null;
    $customerId   = (int)($_POST['customer_id'] ?? 0);
    $bookerId     = (int)($_POST['booker_id'] ?? 0);
    $areaId       = (int)($_POST['area_id'] ?? 0);
    $remarks      = clean_input($_POST['remarks'] ?? '');
    $orderStatus  = isset($_POST['confirm_now']) ? 'confirmed' : 'draft';

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPackQtys   = $_POST['item_pack_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemUnits      = $_POST['item_unit'] ?? [];
    $itemRates      = $_POST['item_rate'] ?? [];
    $itemDiscounts  = $_POST['item_discount_percent'] ?? [];

    $discountMode = clean_input($_POST['discount_mode'] ?? 'item_rate');
    if ($discountMode !== 'subtotal') {
        $discountMode = 'item_rate';
    }
    $freeLoosePieces = !empty($_POST['free_loose_pieces']) ? 1 : 0;
    $subtotalDiscountPercent = (float)($_POST['subtotal_discount_percent'] ?? 0);
    $subtotalDiscountAmount  = (float)($_POST['subtotal_discount_amount'] ?? 0);

    $validator = new Validator();
    $validator->positiveInt('customer_id', $customerId, 'Please choose a Retailer / Shop before saving the order.')
              ->exists('customer_id', $customerId, 'customers', 'id', 'The selected customer does not exist in master records.')
              ->positiveInt('booker_id', $bookerId, 'Please choose an Order Booker.')
              ->exists('booker_id', $bookerId, 'bookers', 'id', 'The selected order booker does not exist.')
              ->positiveInt('area_id', $areaId, 'Please choose a Route / Area.')
              ->exists('area_id', $areaId, 'areas', 'id', 'The selected route/area does not exist.')
              ->date('order_date', $orderDate);

    if (!empty($deliveryDate)) {
        $validator->date('delivery_date', $deliveryDate);
    }

    if (empty($itemProductIds)) {
        $validator->addError('items', 'Please add at least one product to the order cart.');
    }

    if ($validator->passes()) {
        $validItems = [];
        $grossAmount = 0.0;
        $discountAmount = 0.0;
        $totalCartons = 0.0;
        $totalPieces = 0.0;
        $totalUnits = 0.0;

        foreach ($itemProductIds as $idx => $pid) {
            $prodId = (int)$pid;
            $cartonQty = (float)($itemCartonQtys[$idx] ?? 0);
            $packQty   = (float)($itemPackQtys[$idx] ?? 0);
            $pcsQty    = (float)($itemPcsQtys[$idx] ?? 0);
            $rate = (float)($itemRates[$idx] ?? 0);
            $discPercent = (float)($itemDiscounts[$idx] ?? 0);

            if ($prodId <= 0 || ($cartonQty <= 0 && $packQty <= 0 && $pcsQty <= 0)) {
                continue;
            }

            // Retrieve product master conversion
            $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
            if (!$prod) continue;

            $ratios = get_product_packaging_ratios($prod);
            $packsPerCtn = $ratios['packs_per_ctn'];
            $pcsPerPack  = $ratios['pcs_per_pack'];
            $pcsPerCtn   = $ratios['pcs_per_ctn'];

            $unitCode = strtolower(trim((string)($itemUnits[$idx] ?? 'ctn')));
            $totalQuantityInBaseUnits = calculate_base_pieces($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
            $baseCartons = calculate_carton_equivalent($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);

            // If Free Loose Pieces scheme is active, loose pieces rate is not charged
            $billablePcs = $freeLoosePieces ? 0 : $pcsQty;
            $billableBasePieces = calculate_base_pieces($cartonQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);
            $billableCartons    = calculate_carton_equivalent($cartonQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);

            if ($unitCode === 'pcs') {
                $lineGross = $billableBasePieces * $rate;
                $unitId = 4; // Piece unit
            } elseif ($unitCode === 'pack' || $unitCode === 'pck') {
                $billablePacks = ($cartonQty * $packsPerCtn) + $packQty + ($pcsPerPack > 0 ? ($billablePcs / $pcsPerPack) : 0);
                $lineGross = $billablePacks * $rate;
                $unitId = 3; // Packet / Pack unit
            } else {
                $lineGross = $billableCartons * $rate;
                $unitId = 1; // Carton unit (CTN) // Carton unit
            }

            if ($discountMode === 'subtotal') {
                $itemDiscPct = 0.0;
                $lineDiscount = 0.0;
                $lineTotal = $lineGross;
            } else {
                $itemDiscPct = $discPercent;
                $lineDiscount = ($lineGross * $itemDiscPct) / 100.0;
                $lineTotal = $lineGross - $lineDiscount;
                $discountAmount += $lineDiscount;
            }

            $grossAmount += $lineGross;
            $totalCartons += $cartonQty;
            $totalPieces += $pcsQty;
            $totalUnits += $totalQuantityInBaseUnits;

            $validItems[] = [
                'product_id'       => $prodId,
                'unit_id'          => $unitId,
                'carton_qty'       => $cartonQty,
                'pack_qty'         => $packQty,
                'pcs_qty'          => $pcsQty,
                'quantity'         => $totalQuantityInBaseUnits,
                'rate'             => $rate,
                'discount_percent' => $itemDiscPct,
                'discount_amount'  => $lineDiscount,
                'line_total'       => $lineTotal
            ];
        }

        if (empty($validItems)) {
            $validator->addError('items', 'Please add at least one valid product quantity greater than zero.');
        }
    }

    if ($validator->passes()) {
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
                } else {
                    $discountAmount = 0.0;
                    $subtotalDiscountAmount = 0.0;
                    $subtotalDiscountPercent = 0.0;
                }
            } else {
                $subtotalDiscountPercent = 0.0;
                $subtotalDiscountAmount = 0.0;
            }

            $netAmount = max(0, round($grossAmount - $discountAmount, 2));

            $createdOrderId = db_transaction(function () use (
                $orderNumber, $orderDate, $deliveryDate, $customerId, $bookerId, $areaId,
                $discountMode, $freeLoosePieces, $subtotalDiscountPercent, $subtotalDiscountAmount,
                $grossAmount, $discountAmount, $netAmount, $orderStatus, $remarks,
                $validItems, $totalCartons, $totalUnits
            ) {
                $confirmedAt = ($orderStatus === 'confirmed') ? date('Y-m-d H:i:s') : null;

                $stmt = db()->prepare("
                    INSERT INTO orders (
                        order_number, order_date, delivery_date, customer_id, booker_id, area_id,
                        discount_mode, free_loose_pieces, subtotal_discount_percent, subtotal_discount_amount,
                        total_cartons, total_units,
                        gross_amount, discount_amount, net_amount, status, remarks,
                        created_by, confirmed_at, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, NOW(), NOW()
                    )
                ");

                $stmt->execute([
                    $orderNumber, $orderDate, $deliveryDate, $customerId, $bookerId, $areaId,
                    $discountMode, $freeLoosePieces, $subtotalDiscountPercent, $subtotalDiscountAmount,
                    $totalCartons, $totalUnits,
                    $grossAmount, $discountAmount, $netAmount, $orderStatus, $remarks,
                    current_user_id(), $confirmedAt
                ]);

                $orderId = (int)db()->lastInsertId();

                $stmtItem = db()->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
                        quantity, rate, discount_percent, discount_amount, line_total, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, NOW()
                    )
                ");

                foreach ($validItems as $item) {
                    $stmtItem->execute([
                        $orderId, $item['product_id'], $item['unit_id'], $item['carton_qty'],
                        $item['pack_qty'], $item['pcs_qty'], $item['quantity'], $item['rate'],
                        $item['discount_percent'], $item['discount_amount'], $item['line_total']
                    ]);
                }

                if (function_exists('audit_log')) {
                    audit_log('create_order', 'orders', (string)$orderId, "Created order {$orderNumber} with {$totalCartons} ctns, Net: {$netAmount}, Status: {$orderStatus}");
                }
                return $orderId;
            });

            set_flash('success', "Order #<strong>{$orderNumber}</strong> saved successfully (" . ucfirst($orderStatus) . ").");
            redirect(APP_URL . '/modules/orders/view.php?id=' . $createdOrderId);
        } catch (Exception $e) {
            set_flash('danger', "Error saving order: " . $e->getMessage());
        }
    } else {
        set_flash('danger', $validator->getFirstError());
    }
}

$pageTitle = 'New Order Booking';
$activeMenu = 'orders';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Breadcrumb / Header Bar -->
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <div>
                <h5 class="mb-0 fw-bold text-navy"><i class="bi bi-cart-plus me-2 text-primary"></i>New Market Order Booking</h5>
                <small class="text-muted">Fast card selection: Click products to add to cart, edit quantities or rates instantly</small>
            </div>
            <div>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Order List (ESC)
                </a>
            </div>
        </div>

        <form method="POST" action="<?= APP_URL ?>/modules/orders/create.php" id="orderForm" autocomplete="off">
            <input type="hidden" name="order_number" value="<?= e($nextOrderNumber) ?>">

            <!-- Order Route & Retailer Setup Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1 d-flex justify-content-between align-items-center">
                                <span>Retailer / Shop <span class="text-danger">*</span></span>
                                <?php if (has_permission('customers_create')): ?>
                                <a href="javascript:void(0)" onclick="openQuickCustomerDrawer('customerSelect')" class="text-primary text-decoration-none" title="Quick Add Customer">
                                    <i class="bi bi-plus-circle-fill"></i> New
                                </a>
                                <?php endif; ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <select name="customer_id" id="customerSelect" class="form-select form-select-sm" required autofocus>
                                    <option value="">-- Choose Retailer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($preselectedCustomerId === (int)$c['id']) ? 'selected' : '' ?> 
                                            data-area="<?= $c['area_id'] ?>" 
                                            data-booker="<?= $c['booker_id'] ?>" 
                                            data-discount="<?= $c['default_discount_percent'] ?>"
                                            data-balance="<?= $c['current_balance'] ?>"
                                            data-limit="<?= $c['credit_limit'] ?>">
                                            <?= e($c['shop_name']) ?> (<?= e($c['area_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (has_permission('customers_create')): ?>
                                <button type="button" class="btn btn-outline-primary" onclick="openQuickCustomerDrawer('customerSelect')" title="Add New Customer in Real-Time">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Order Booker <span class="text-danger">*</span></label>
                            <select name="booker_id" id="bookerSelect" class="form-select form-select-sm" required>
                                <option value="">-- Booker --</option>
                                <?php foreach ($bookers as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= e($b['booker_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1 d-flex justify-content-between align-items-center">
                                <span>Route / Area <span class="text-danger">*</span></span>
                                <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                                <a href="javascript:void(0)" onclick="openQuickAreaDrawer('areaSelect')" class="text-primary text-decoration-none" title="Quick Add Route">
                                    <i class="bi bi-plus-circle-fill"></i> New
                                </a>
                                <?php endif; ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <select name="area_id" id="areaSelect" class="form-select form-select-sm" required>
                                    <option value="">-- Area --</option>
                                    <?php foreach ($areas as $a): ?>
                                        <option value="<?= $a['id'] ?>"><?= e($a['area_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                                <button type="button" class="btn btn-outline-primary" onclick="openQuickAreaDrawer('areaSelect')" title="Add New Area in Real-Time">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Booking Date</label>
                            <input type="date" name="order_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Delivery Date (Optional)</label>
                            <input type="date" name="delivery_date" class="form-control form-control-sm" value="">
                        </div>

                        <div class="col-md-1 text-end">
                            <span class="badge bg-navy text-white font-monospace py-2 px-2" style="font-size: 11px;"><?= e($nextOrderNumber) ?></span>
                        </div>
                    </div>

                    <!-- Customer Financial Status Banner -->
                    <div id="customerCreditAlert" class="mt-2 py-1 px-2 rounded small bg-light border text-secondary" style="display:none;">
                        <span id="custCreditInfo"></span>
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
                                <span class="small fw-bold text-secondary text-nowrap"><i class="bi bi-building me-1 text-primary"></i>Company:</span>
                                <select id="companyFilter" class="form-select form-select-sm py-0" style="font-size:12px; height:28px;">
                                    <option value="">-- All Companies / Suppliers --</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?= $sup['id'] ?>"><?= e($sup['supplier_name']) ?><?= !empty($sup['company_name']) ? ' (' . e($sup['company_name']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Category Filter Pills -->
                            <div class="pos-category-pills">
                                <button type="button" class="pos-category-pill active" data-cat="">All Categories</button>
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
                            ?>
                                <div class="pos-product-card <?= $isOutOfStock ? 'out-of-stock' : '' ?>" 
                                     id="card_prod_<?= $p['id'] ?>"
                                     data-id="<?= $p['id'] ?>"
                                     data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                     data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                     data-cat="<?= $p['category_id'] ?>"
                                     data-supplier-id="<?= (int)($p['supplier_id'] ?? 0) ?>"
                                     data-rate="<?= (float)$p['carton_rate'] ?>"
                                     data-carton-rate="<?= (float)$p['carton_rate'] ?>"
                                     data-piece-rate="<?= (float)$p['piece_rate'] ?>"
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
                                            <div class="prod-price" style="font-size: 12.5px; line-height: 1.25; margin-bottom: 2px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format((float)$p['carton_rate'], 0) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>
                                            </div>
                                            <div class="text-primary fw-semibold" style="font-size: 11px; line-height: 1.25; margin-bottom: 2px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format($packsPerCtn > 0 ? ((float)$p['carton_rate'] / $packsPerCtn) : (float)$p['carton_rate'], 2) ?> <span class="text-muted fw-normal" style="font-size: 9.5px;">/ Pack</span>
                                            </div>
                                            <div class="text-secondary fw-semibold" style="font-size: 11px; line-height: 1.25;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format((float)$p['piece_rate'], 2) ?> <span class="text-muted fw-normal" style="font-size: 9.5px;">/ Pc</span>
                                            </div>
                                        </div>
                                        <div class="prod-stock text-end">
                                            <?= $isOutOfStock ? '<span class="text-danger fw-semibold">Out of stock</span>' : htmlspecialchars($stockBreakdown['formatted']) . ' avail' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive Order Items Cart (Editable quantities, rates & discounts) -->
                <div class="col-lg-7">
                    <div class="pos-cart-container">
                        <div class="pos-cart-header">
                            <span class="fw-bold text-navy">
                                <i class="bi bi-cart3 me-1 text-primary"></i> Order Items Cart 
                                <span class="badge bg-primary ms-1" id="cartItemCount">0 items</span>
                            </span>
                            <button type="button" class="btn btn-xs btn-outline-danger" onclick="clearCart()" id="btnClearCart" style="display:none;">
                                <i class="bi bi-trash me-1"></i> Clear Cart
                            </button>
                        </div>

                        <!-- Discount Mode & Free Loose Pieces Scheme Bar -->
                        <div class="pos-discount-mode-bar bg-light p-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center flex-wrap gap-3">
                                <span class="small fw-bold text-secondary"><i class="bi bi-tag-fill me-1 text-primary"></i> Discount:</span>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeItem" value="item_rate" checked onchange="onDiscountModeChange()">
                                    <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeItem">Discount from per item rate</label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeSubtotal" value="subtotal" onchange="onDiscountModeChange()">
                                    <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeSubtotal">Discount from subtotal</label>
                                </div>
                                <div class="form-check form-switch m-0 d-inline-flex align-items-center gap-2 border-start ps-3 ms-2">
                                    <input class="form-check-input cursor-pointer" type="checkbox" role="switch" name="free_loose_pieces" id="freeLoosePiecesToggle" value="1" onchange="onFreeLoosePiecesChange()">
                                    <label class="form-check-label small fw-bold text-success cursor-pointer" for="freeLoosePiecesToggle" title="Trade Scheme: Loose pieces will be free (Rs 0) in bill total, but will be dispatched on load form">
                                        <i class="bi bi-gift-fill me-1 text-warning"></i> Free Loose Pcs (سکیم / بونس پیس مفت)
                                    </label>
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
                                        <th style="width: 10%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 9%;" class="text-center">Packs</th>
                                        <th style="width: 9%;" class="text-center">Extra Pcs</th>
                                        <th style="width: 11%;" class="text-center">Unit</th>
                                        <th style="width: 14%;" class="text-end">Rate</th>
                                        <th style="width: 8%;" class="text-end">Disc %</th>
                                        <th style="width: 14%;" class="text-end">Line Total</th>
                                        <th style="width: 30px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody">
                                    <tr id="emptyCartRow">
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-hand-index-thumb fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                            <div class="fw-semibold">No items added to order yet.</div>
                                            <small>Click on product cards on the left or use the search bar to add items.</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Real-time Order Totals Footer -->
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
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Booking remarks, delivery notes, or special instructions...">
                                </div>
                                <div class="col-md-5 text-end">
                                    <button type="submit" name="save_draft" class="btn btn-secondary btn-sm px-3 me-1">
                                        <i class="bi bi-save me-1"></i> Save Draft
                                    </button>
                                    <button type="submit" name="confirm_now" class="btn btn-navy btn-sm px-3 fw-bold">
                                        <i class="bi bi-check2-circle me-1"></i> Confirm (F9)
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
    const customerSelect = document.getElementById('customerSelect');
    const bookerSelect   = document.getElementById('bookerSelect');
    const areaSelect     = document.getElementById('areaSelect');
    const creditAlert    = document.getElementById('customerCreditAlert');
    const creditInfo     = document.getElementById('custCreditInfo');
    const productSearch  = document.getElementById('productSearch');

    // Customer change auto-populates route & booker
    customerSelect.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        if (!opt || !opt.value) {
            creditAlert.style.display = 'none';
            return;
        }

        const areaId = opt.getAttribute('data-area');
        const bookerId = opt.getAttribute('data-booker');
        const balance = parseFloat(opt.getAttribute('data-balance') || 0);
        const limit = parseFloat(opt.getAttribute('data-limit') || 0);

        if (areaId) areaSelect.value = areaId;
        if (bookerId) bookerSelect.value = bookerId;

        creditAlert.style.display = 'block';
        creditInfo.innerHTML = `<i class="bi bi-wallet2 me-1"></i> Outstanding Balance: <strong><?= DEFAULT_CURRENCY ?> ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}</strong> | Credit Limit: <?= DEFAULT_CURRENCY ?> ${limit.toLocaleString('en-US', {minimumFractionDigits:2})} ${balance > limit && limit > 0 ? '<span class="badge bg-danger ms-2">Limit Exceeded</span>' : ''}`;
    });

    if (customerSelect.value) {
        customerSelect.dispatchEvent(new Event('change'));
    }

    // Real-time Product Search Filter
    productSearch.addEventListener('input', function () {
        filterProductCards();
    });

    const companyFilter = document.getElementById('companyFilter');
    if (companyFilter) {
        companyFilter.addEventListener('change', function () {
            filterProductCards();
        });
    }

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

    function validateOrderForm() {
        const custVal = parseInt(customerSelect.value, 10);
        if (!custVal || isNaN(custVal) || custVal <= 0) {
            alert('Please select a Retailer / Shop before saving the order.');
            const wrapper = customerSelect.closest('.dms-searchable-wrapper');
            if (wrapper) {
                const ctrl = wrapper.querySelector('.dms-searchable-control');
                if (ctrl) ctrl.focus();
            } else {
                customerSelect.focus();
            }
            return false;
        }

        const bkrVal = parseInt(bookerSelect.value, 10);
        if (!bkrVal || isNaN(bkrVal) || bkrVal <= 0) {
            alert('Please select an Order Booker.');
            bookerSelect.focus();
            return false;
        }

        const areaVal = parseInt(areaSelect.value, 10);
        if (!areaVal || isNaN(areaVal) || areaVal <= 0) {
            alert('Please select a Route / Area.');
            areaSelect.focus();
            return false;
        }

        const cartKeys = Object.keys(orderCart);
        if (cartKeys.length === 0) {
            alert('Order cart is empty! Please click on products to add items to the order.');
            if (productSearch) productSearch.focus();
            return false;
        }

        return true;
    }

    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.addEventListener('submit', function (e) {
            if (!validateOrderForm()) {
                e.preventDefault();
                return false;
            }
        });
    }

    // Keyboard Shortcuts: '/' or F2 to search, F9 to submit, arrows when outside inputs
    document.addEventListener('keydown', function(e) {
        const tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isInput = tag === 'input' || tag === 'textarea' || tag === 'select' || (document.activeElement && document.activeElement.closest('.dms-searchable-wrapper'));

        if (e.key === '/' || e.key === 'F2') {
            if (document.activeElement !== productSearch) {
                e.preventDefault();
                productSearch.focus();
                productSearch.select();
            }
        } else if (e.key === 'F9') {
            e.preventDefault();
            if (validateOrderForm()) {
                const confirmBtn = document.querySelector('button[name="confirm_now"]');
                if (confirmBtn) {
                    confirmBtn.click();
                } else if (orderForm) {
                    orderForm.submit();
                }
            }
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
    const companyFilter = document.getElementById('companyFilter');
    const activeSup = companyFilter ? companyFilter.value : '';

    let visibleCount = 0;
    document.querySelectorAll('.pos-product-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const code = (card.getAttribute('data-code') || '').toLowerCase();
        const cat  = card.getAttribute('data-cat');
        const sup  = card.getAttribute('data-supplier-id');

        const matchQuery = !query || name.includes(query) || code.includes(query);
        const matchCat   = !activeCat || cat === activeCat;
        const matchSup   = !activeSup || sup === activeSup;

        if (matchQuery && matchCat && matchSup) {
            card.classList.remove('d-none');
            visibleCount++;
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
        // Auto-trigger calculation from subtotal percent or amount
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

function isFreeLoosePiecesActive() {
    const el = document.getElementById('freeLoosePiecesToggle');
    return el ? el.checked : false;
}

function onFreeLoosePiecesChange() {
    recalculateAllRows();
    if (getDiscountMode() === 'subtotal') {
        const pctEl = document.getElementById('subtotalDiscountPercent');
        if (pctEl && parseFloat(pctEl.value) > 0) {
            onSubtotalPercentChange();
            return;
        }
    }
    recalculateCartSummary();
}

function calculateLineGross(item) {
    if (!item) return 0;
    const isFreeLoose = isFreeLoosePiecesActive();
    const effectivePcs = isFreeLoose ? 0 : item.pcs_qty;
    if (item.unit === 'pcs') {
        const totalPieces = (item.carton_qty * item.pcs_per_ctn) + (item.pack_qty * item.pcs_per_pack) + effectivePcs;
        return totalPieces * item.rate;
    } else if (item.unit === 'pack') {
        const totalPacks = (item.carton_qty * item.packs_per_ctn) + item.pack_qty + (item.pcs_per_pack > 0 ? (effectivePcs / item.pcs_per_pack) : 0);
        return totalPacks * item.rate;
    } else {
        const baseCartons = item.carton_qty + (item.pack_qty / item.packs_per_ctn) + (effectivePcs / item.pcs_per_ctn);
        return baseCartons * item.rate;
    }
}

function onSubtotalPercentChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const pctVal = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
    let grossAmount = 0;
    Object.values(cartItems).forEach(item => {
        grossAmount += calculateLineGross(item);
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
        grossAmount += calculateLineGross(item);
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
        const lineGross = calculateLineGross(item);
        const lineDisc = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
        item.line_total = lineGross - lineDisc;

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
        // If already in cart, increment carton qty by +1
        cartItems[productId].carton_qty += 1;
        updateCartRow(productId);
    } else {
        const packsPerCtn = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
        const pcsPerCtn   = packsPerCtn * pcsPerPack;
        const cartonRate  = parseFloat(prod.carton_rate) || parseFloat(prod.wholesale_rate) || 0;
        const packRate    = packsPerCtn > 0 ? (cartonRate / packsPerCtn) : cartonRate;
        const pieceRate   = parseFloat(prod.piece_rate) || (pcsPerCtn > 0 ? (cartonRate / pcsPerCtn) : cartonRate);

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
            unit: 'ctn',
            carton_rate: cartonRate,
            pack_rate: packRate,
            piece_rate: pieceRate,
            rate: cartonRate,
            discount_percent: 0,
            line_total: cartonRate
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

    // Focus the carton quantity input of the added row
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

    const tr = document.createElement('tr');
    tr.id = 'cart_row_' + productId;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="item_product_id[]" value="${item.product_id}">
            <div class="fw-bold text-navy" style="font-size: 13px;">${item.product_name}</div>
            <small class="text-muted">Pack: ${ratioText} &bull; ${item.product_code}</small>
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
        <td class="text-center">
            <select name="item_unit[]" id="input_unit_${productId}" 
                    class="form-select form-select-sm text-center fw-bold py-0 px-1" 
                    onchange="onCartUnitChange(${productId}, this.value)">
                <option value="ctn" ${item.unit === 'ctn' ? 'selected' : ''}>Ctn</option>
                <option value="pack" ${item.unit === 'pack' ? 'selected' : ''}>Pack</option>
                <option value="pcs" ${item.unit === 'pcs' ? 'selected' : ''}>Pcs</option>
            </select>
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="item_rate[]" id="input_rate_${productId}" 
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'rate')" required>
            <small class="text-muted text-end d-block" id="lbl_rate_unit_${productId}" style="font-size: 10px;">/ ${item.unit === 'pcs' ? 'Piece' : (item.unit === 'pack' ? 'Pack' : 'Carton')}</small>
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

function onCartUnitChange(productId, unit) {
    const item = cartItems[productId];
    if (!item) return;

    item.unit = unit;
    const packsPerCtn = item.packs_per_ctn > 0 ? item.packs_per_ctn : 1;
    const pcsPerPack  = item.pcs_per_pack > 0 ? item.pcs_per_pack : 1;
    const pcsPerCtn   = item.pcs_per_ctn > 0 ? item.pcs_per_ctn : (packsPerCtn * pcsPerPack);

    if (unit === 'pcs') {
        item.rate = item.piece_rate || (pcsPerCtn > 0 ? item.carton_rate / pcsPerCtn : item.carton_rate);
        if ((item.carton_qty === 1 && item.pack_qty === 0 && item.pcs_qty === 0) ||
            (item.carton_qty === 0 && item.pack_qty === 1 && item.pcs_qty === 0)) {
            item.carton_qty = 0;
            item.pack_qty = 0;
            item.pcs_qty = 1;
            const inputCtn = document.getElementById('input_carton_' + productId);
            const inputPack = document.getElementById('input_pack_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCtn) inputCtn.value = 0;
            if (inputPack) inputPack.value = 0;
            if (inputPcs) inputPcs.value = 1;
        }
    } else if (unit === 'pack') {
        item.rate = item.pack_rate || (packsPerCtn > 0 ? item.carton_rate / packsPerCtn : item.carton_rate);
        if ((item.carton_qty === 1 && item.pack_qty === 0 && item.pcs_qty === 0) ||
            (item.carton_qty === 0 && item.pack_qty === 0 && item.pcs_qty === 1)) {
            item.carton_qty = 0;
            item.pack_qty = 1;
            item.pcs_qty = 0;
            const inputCtn = document.getElementById('input_carton_' + productId);
            const inputPack = document.getElementById('input_pack_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCtn) inputCtn.value = 0;
            if (inputPack) inputPack.value = 1;
            if (inputPcs) inputPcs.value = 0;
        }
    } else {
        item.rate = item.carton_rate;
        if ((item.carton_qty === 0 && item.pack_qty === 1 && item.pcs_qty === 0) ||
            (item.carton_qty === 0 && item.pack_qty === 0 && item.pcs_qty === 1)) {
            item.carton_qty = 1;
            item.pack_qty = 0;
            item.pcs_qty = 0;
            const inputCtn = document.getElementById('input_carton_' + productId);
            const inputPack = document.getElementById('input_pack_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCtn) inputCtn.value = 1;
            if (inputPack) inputPack.value = 0;
            if (inputPcs) inputPcs.value = 0;
        }
    }

    const inputRate = document.getElementById('input_rate_' + productId);
    if (inputRate) inputRate.value = Number(item.rate || 0).toFixed(2);

    const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (unit === 'pcs' ? 'Piece' : (unit === 'pack' ? 'Pack' : 'Carton'));

    updateCartRow(productId);
    recalculateCartSummary();
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
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (item.unit === 'pcs' ? 'Piece' : (item.unit === 'pack' ? 'Pack' : 'Carton'));

    const lineGross = calculateLineGross(item);
    const mode = getDiscountMode();
    const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
    item.line_total = lineGross - lineDisc;

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

    const lineGross = calculateLineGross(item);
    const mode = getDiscountMode();
    const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
    item.line_total = lineGross - lineDisc;

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
    if (confirm('Clear all items from the current order?')) {
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

        const lineGross = calculateLineGross(item);
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
