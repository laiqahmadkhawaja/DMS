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
        p.id, p.product_name, p.product_code, p.category_id, cat.category_name,
        p.wholesale_rate, p.carton_qty, p.base_unit_id, u.symbol as unit_symbol,
        COALESCE(sb.saleable_qty, 0) as saleable_qty
    FROM products p
    JOIN units u ON u.id = p.base_unit_id
    LEFT JOIN product_categories cat ON cat.id = p.category_id
    LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = 1
    WHERE p.is_deleted = 0 AND p.is_active = 1
    ORDER BY p.product_name ASC
");

$bookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
$areas = db_fetch_all("SELECT id, area_name FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");

$nextOrderNumber = generate_document_number(PREFIX_ORDER, 'orders', 'order_number');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderNumber  = clean_input($_POST['order_number'] ?? $nextOrderNumber);
    $orderDate    = clean_input($_POST['order_date'] ?? date('Y-m-d'));
    $deliveryDate = clean_input($_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day')));
    $customerId   = (int)($_POST['customer_id'] ?? 0);
    $bookerId     = (int)($_POST['booker_id'] ?? 0);
    $areaId       = (int)($_POST['area_id'] ?? 0);
    $remarks      = clean_input($_POST['remarks'] ?? '');
    $orderStatus  = isset($_POST['confirm_now']) ? 'confirmed' : 'draft';

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemRates      = $_POST['item_rate'] ?? [];
    $itemDiscounts  = $_POST['item_discount_percent'] ?? [];

    $validator = new Validator();
    $validator->required('customer_id', $customerId)
              ->exists('customer_id', $customerId, 'customers', 'id')
              ->required('booker_id', $bookerId)
              ->exists('booker_id', $bookerId, 'bookers', 'id')
              ->required('area_id', $areaId)
              ->exists('area_id', $areaId, 'areas', 'id')
              ->date('order_date', $orderDate)
              ->date('delivery_date', $deliveryDate);

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
            $pcsQty = (float)($itemPcsQtys[$idx] ?? 0);
            $rate = (float)($itemRates[$idx] ?? 0);
            $discPercent = (float)($itemDiscounts[$idx] ?? 0);

            if ($prodId <= 0 || ($cartonQty <= 0 && $pcsQty <= 0)) {
                continue;
            }

            // Retrieve product master conversion
            $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
            if (!$prod) continue;

            $cartonRatio = (float)$prod['carton_qty'] > 0 ? (float)$prod['carton_qty'] : 1;
            $totalQuantityInBaseUnits = ($cartonQty * $cartonRatio) + $pcsQty;
            $baseCartons = $cartonQty + ($pcsQty / $cartonRatio);
            $lineGross = $baseCartons * $rate;
            $lineDiscount = ($lineGross * $discPercent) / 100.0;
            $lineTotal = $lineGross - $lineDiscount;

            $grossAmount += $lineGross;
            $discountAmount += $lineDiscount;
            $totalCartons += $cartonQty;
            $totalPieces += $pcsQty;
            $totalUnits += $totalQuantityInBaseUnits;

            $validItems[] = [
                'product_id'       => $prodId,
                'unit_id'          => $prod['base_unit_id'],
                'carton_qty'       => $cartonQty,
                'pack_qty'         => 0,
                'pcs_qty'          => $pcsQty,
                'quantity'         => $totalQuantityInBaseUnits,
                'rate'             => $rate,
                'discount_percent' => $discPercent,
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
            $netAmount = $grossAmount - $discountAmount;

            $createdOrderId = db_transaction(function () use (
                $orderNumber, $orderDate, $deliveryDate, $customerId, $bookerId, $areaId,
                $grossAmount, $discountAmount, $netAmount, $orderStatus, $remarks,
                $validItems, $totalCartons, $totalUnits
            ) {
                $confirmedAt = ($orderStatus === 'confirmed') ? date('Y-m-d H:i:s') : null;

                $stmt = db()->prepare("
                    INSERT INTO orders (
                        order_number, order_date, delivery_date, customer_id, booker_id, area_id,
                        total_cartons, total_units,
                        gross_amount, discount_amount, net_amount, status, remarks,
                        created_by, confirmed_at, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, NOW(), NOW()
                    )
                ");

                $stmt->execute([
                    $orderNumber, $orderDate, $deliveryDate, $customerId, $bookerId, $areaId,
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
                            <label class="form-label small fw-bold mb-1">Retailer / Shop <span class="text-danger">*</span></label>
                            <select name="customer_id" id="customerSelect" class="form-select form-select-sm" required autofocus>
                                <option value="">-- Choose Retailer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                        data-area="<?= $c['area_id'] ?>" 
                                        data-booker="<?= $c['booker_id'] ?>" 
                                        data-discount="<?= $c['default_discount_percent'] ?>"
                                        data-balance="<?= $c['current_balance'] ?>"
                                        data-limit="<?= $c['credit_limit'] ?>">
                                        <?= e($c['shop_name']) ?> (<?= e($c['area_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                            <label class="form-label small fw-bold mb-1">Route / Area <span class="text-danger">*</span></label>
                            <select name="area_id" id="areaSelect" class="form-select form-select-sm" required>
                                <option value="">-- Area --</option>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= e($a['area_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Booking Date</label>
                            <input type="date" name="order_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Delivery Date</label>
                            <input type="date" name="delivery_date" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
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
                                $ctnRatio = (float)$p['carton_qty'] > 0 ? (int)$p['carton_qty'] : 1;
                                $stockCtns = floor((float)$p['saleable_qty'] / $ctnRatio);
                                $isOutOfStock = (float)$p['saleable_qty'] <= 0;
                            ?>
                                <div class="pos-product-card <?= $isOutOfStock ? 'out-of-stock' : '' ?>" 
                                     id="card_prod_<?= $p['id'] ?>"
                                     data-id="<?= $p['id'] ?>"
                                     data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                     data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                     data-cat="<?= $p['category_id'] ?>"
                                     data-rate="<?= (float)$p['wholesale_rate'] ?>"
                                     data-ratio="<?= $ctnRatio ?>"
                                     onclick="addProductToCart(<?= $p['id'] ?>)">
                                    
                                    <span class="card-badge-cart d-none" id="badge_cart_<?= $p['id'] ?>">0 in cart</span>
                                    
                                    <div>
                                        <div class="prod-name"><?= htmlspecialchars($p['product_name']) ?></div>
                                        <div class="prod-meta">
                                            <span>Pack: <strong>1*<?= $ctnRatio ?></strong></span> &bull; 
                                            <span><?= htmlspecialchars($p['product_code']) ?></span>
                                        </div>
                                    </div>

                                    <div class="prod-price-stock">
                                        <div class="prod-price"><?= DEFAULT_CURRENCY ?> <?= number_format((float)$p['wholesale_rate'], 0) ?></div>
                                        <div class="prod-stock">
                                            <?= $isOutOfStock ? 'Out of stock' : "{$stockCtns} Ctns avail" ?>
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

                        <div class="pos-cart-table-wrapper">
                            <table class="table pos-cart-table table-hover align-middle" id="cartTable">
                                <thead>
                                    <tr>
                                        <th style="width: 34%;">Product Details</th>
                                        <th style="width: 13%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 13%;" class="text-center">Extra Pcs</th>
                                        <th style="width: 16%;" class="text-end">Rate / Ctn</th>
                                        <th style="width: 10%;" class="text-end">Disc %</th>
                                        <th style="width: 14%;" class="text-end">Line Total</th>
                                        <th style="width: 40px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody">
                                    <tr id="emptyCartRow">
                                        <td colspan="7" class="text-center py-5 text-muted">
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
                                    <div class="text-muted small">Total Discount</div>
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

    // Keyboard Shortcuts: '/' or F2 to search, F9 to submit, arrows when outside inputs
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
            document.getElementById('orderForm').submit();
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

    document.querySelectorAll('.pos-product-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const code = (card.getAttribute('data-code') || '').toLowerCase();
        const cat  = card.getAttribute('data-cat');

        const matchQuery = !query || name.includes(query) || code.includes(query);
        const matchCat   = !activeCat || cat === activeCat;

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
        // If already in cart, increment carton qty by +1
        cartItems[productId].carton_qty += 1;
        updateCartRow(productId);
    } else {
        const ctnRatio = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const rate = parseFloat(prod.wholesale_rate) || 0;

        cartItems[productId] = {
            product_id: productId,
            product_name: prod.product_name,
            product_code: prod.product_code,
            carton_ratio: ctnRatio,
            carton_qty: 1,
            pcs_qty: 0,
            rate: rate,
            discount_percent: 0,
            line_total: rate
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

    const tr = document.createElement('tr');
    tr.id = 'cart_row_' + productId;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="item_product_id[]" value="${item.product_id}">
            <div class="fw-bold text-navy" style="font-size: 13px;">${item.product_name}</div>
            <small class="text-muted">Pack: 1*${item.carton_ratio} &bull; ${item.product_code}</small>
        </td>
        <td class="text-center">
            <input type="number" step="0.01" name="item_carton_qty[]" id="input_carton_${productId}" 
                   class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                   value="${item.carton_qty}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'carton')" required>
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
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'rate')" required>
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
}

function updateCartRow(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const inputCarton = document.getElementById('input_carton_' + productId);
    const inputPcs    = document.getElementById('input_pcs_' + productId);
    const inputRate   = document.getElementById('input_rate_' + productId);
    const inputDisc   = document.getElementById('input_disc_' + productId);
    const lblTotal    = document.getElementById('lbl_total_' + productId);

    if (inputCarton) inputCarton.value = item.carton_qty;
    if (inputPcs) inputPcs.value = item.pcs_qty;
    if (inputRate) inputRate.value = item.rate.toFixed(2);
    if (inputDisc) inputDisc.value = item.discount_percent;

    // Line total formula: (carton_qty + pcs_qty/ratio) * rate * (1 - disc/100)
    const baseCartons = item.carton_qty + (item.pcs_qty / item.carton_ratio);
    const lineGross = baseCartons * item.rate;
    const lineDisc  = (lineGross * item.discount_percent) / 100.0;
    item.line_total = lineGross - lineDisc;

    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }
}

function onCartRowChange(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const ctnVal  = parseFloat(document.getElementById('input_carton_' + productId).value) || 0;
    const pcsVal  = parseFloat(document.getElementById('input_pcs_' + productId).value) || 0;
    const rateVal = parseFloat(document.getElementById('input_rate_' + productId).value) || 0;
    const discVal = parseFloat(document.getElementById('input_disc_' + productId).value) || 0;

    item.carton_qty = ctnVal;
    item.pcs_qty = pcsVal;
    item.rate = rateVal;
    item.discount_percent = discVal;

    const baseCartons = item.carton_qty + (item.pcs_qty / item.carton_ratio);
    const lineGross = baseCartons * item.rate;
    const lineDisc  = (lineGross * item.discount_percent) / 100.0;
    item.line_total = lineGross - lineDisc;

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
        if (item && (item.carton_qty > 0 || item.pcs_qty > 0)) {
            card.classList.add('in-cart');
            badge.classList.remove('d-none');
            badge.innerText = `${item.carton_qty} Ctn${item.pcs_qty > 0 ? ' +' + item.pcs_qty + 'p' : ''}`;
        } else {
            card.classList.remove('in-cart');
            badge.classList.add('d-none');
            badge.innerText = '0 in cart';
        }
    }
}

function recalculateCartSummary() {
    let totalCartons = 0;
    let totalPieces  = 0;
    let grossAmount  = 0;
    let discountAmt  = 0;
    let netAmount    = 0;
    let itemCount    = 0;

    Object.values(cartItems).forEach(item => {
        itemCount++;
        totalCartons += item.carton_qty;
        totalPieces  += item.pcs_qty;

        const baseCartons = item.carton_qty + (item.pcs_qty / item.carton_ratio);
        const lineGross = baseCartons * item.rate;
        const lineDisc  = (lineGross * item.discount_percent) / 100.0;

        grossAmount += lineGross;
        discountAmt += lineDisc;
        netAmount   += (lineGross - lineDisc);
    });

    document.getElementById('cartItemCount').innerText = `${itemCount} item${itemCount === 1 ? '' : 's'}`;
    document.getElementById('summaryTotalCartons').innerText = `${totalCartons.toLocaleString('en-US', {maximumFractionDigits:2})} Ctns`;
    document.getElementById('summaryTotalPieces').innerText = `${totalPieces} Pcs`;
    document.getElementById('summaryDiscountAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${discountAmt.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    document.getElementById('summaryNetAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${netAmount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
}
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
