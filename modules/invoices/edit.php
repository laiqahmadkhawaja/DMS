<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - Edit Bill / Invoice (POS 2-Column Split Cart & Real-Time Wholesale/MRP Pricing)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('invoices_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid invoice ID.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$invoice = db_fetch_one("
    SELECT inv.*, c.shop_name, c.current_balance as cust_balance, a.area_name, b.booker_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    WHERE inv.id = ?
", [$id]);

if (!$invoice) {
    set_flash('danger', 'Invoice not found.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$items = db_fetch_all("
    SELECT sii.*, p.product_name, p.product_code, p.carton_qty as ctn_ratio, p.pack_qty as pack_ratio,
           p.carton_rate, p.piece_rate, p.retail_carton_rate, p.retail_piece_rate, p.retail_rate, p.wholesale_rate,
           u.symbol as unit_symbol
    FROM sales_invoice_items sii
    JOIN products p ON sii.product_id = p.id
    LEFT JOIN units u ON u.id = sii.unit_id
    WHERE sii.invoice_id = ?
    ORDER BY sii.id ASC
", [$id]);

$products = db_fetch_all("
    SELECT 
        p.id, p.product_name, p.product_code, p.barcode, p.category_id, p.supplier_id, p.brand_id,
        cat.category_name, b.brand_name,
        p.purchase_rate, p.purchase_carton_rate, p.purchase_piece_rate, p.default_sale_rate, p.wholesale_rate, p.carton_rate, p.piece_rate, p.retail_rate, p.retail_carton_rate, p.retail_piece_rate,
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

$categories = db_fetch_all("
    SELECT id, category_name 
    FROM product_categories 
    WHERE is_active = 1 
    ORDER BY category_name ASC
");

$suppliers = db_fetch_all("
    SELECT id, supplier_name, company_name 
    FROM suppliers 
    WHERE is_deleted = 0 AND is_active = 1 
    ORDER BY supplier_name ASC
");

$errors = [];

// Handle POST Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoiceDate = sanitize_input($_POST['invoice_date'] ?? $invoice['invoice_date']);
    $remarks     = sanitize_input($_POST['remarks'] ?? '');
    
    $itemIds     = $_POST['item_product_id'] ?? [];
    $ctnQtys     = $_POST['item_carton_qty'] ?? [];
    $packQtys    = $_POST['item_pack_qty'] ?? [];
    $pcsQtys     = $_POST['item_pcs_qty'] ?? [];
    $itemUnits   = $_POST['item_unit'] ?? [];
    $rates       = $_POST['item_rate'] ?? [];
    $discounts   = $_POST['item_discount_percent'] ?? [];

    $pricingMode = sanitize_input($_POST['pricing_mode'] ?? ($invoice['pricing_mode'] ?? 'wholesale'));
    if ($pricingMode !== 'mrp') {
        $pricingMode = 'wholesale';
    }

    $discountMode = sanitize_input($_POST['discount_mode'] ?? ($invoice['discount_mode'] ?? 'item_rate'));
    if ($discountMode !== 'subtotal') {
        $discountMode = 'item_rate';
    }
    $freeLoosePieces = isset($_POST['free_loose_pieces']) ? 1 : 0;
    $subtotalDiscountPercent = (float)($_POST['subtotal_discount_percent'] ?? 0);
    $subtotalDiscountAmount  = (float)($_POST['subtotal_discount_amount'] ?? 0);

    $grossTotal = 0.0;
    $discTotal  = 0.0;
    $validItems = [];

    foreach ($itemIds as $idx => $pid) {
        $prodId = (int)$pid;
        $ctn  = (float)($ctnQtys[$idx] ?? 0);
        $pack = (float)($packQtys[$idx] ?? 0);
        $pcs  = (float)($pcsQtys[$idx] ?? 0);
        $unitCode = strtolower(trim((string)($itemUnits[$idx] ?? 'ctn')));
        $rate = (float)($rates[$idx] ?? 0);
        $disc = (float)($discounts[$idx] ?? 0);

        if ($prodId <= 0 || ($ctn <= 0 && $pack <= 0 && $pcs <= 0)) continue;

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$prodId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $baseQty = calculate_base_pieces($ctn, $pack, $pcs, $packsPerCtn, $pcsPerPack);
        $baseCartons = calculate_carton_equivalent($ctn, $pack, $pcs, $packsPerCtn, $pcsPerPack);

        // If Free Loose Pieces scheme is active, loose pieces rate is not charged
        $billablePcs = $freeLoosePieces ? 0 : $pcs;
        $billableBasePieces = calculate_base_pieces($ctn, $pack, $billablePcs, $packsPerCtn, $pcsPerPack);
        $billableCartons    = calculate_carton_equivalent($ctn, $pack, $billablePcs, $packsPerCtn, $pcsPerPack);

        if ($unitCode === 'pcs') {
            $lineGross = $billableBasePieces * $rate;
            $unitId = 4; // Piece unit
        } elseif ($unitCode === 'pack' || $unitCode === 'pck') {
            $billablePacks = ($ctn * $packsPerCtn) + $pack + ($pcsPerPack > 0 ? ($billablePcs / $pcsPerPack) : 0);
            $lineGross = $billablePacks * $rate;
            $unitId = 3; // Pack unit
        } else {
            $lineGross = $billableCartons * $rate;
            $unitId = 1; // Carton unit (CTN)
        }

        if ($discountMode === 'subtotal') {
            $itemDiscPct = 0.0;
            $lineDisc = 0.0;
            $lineNet = $lineGross;
        } else {
            $itemDiscPct = $disc;
            $lineDisc = ($lineGross * $itemDiscPct) / 100.0;
            $lineNet = $lineGross - $lineDisc;
            $discTotal += $lineDisc;
        }

        $grossTotal += $lineGross;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => $unitId,
            'carton_qty'       => $ctn,
            'pack_qty'         => $pack,
            'pcs_qty'          => $pcs,
            'quantity'         => $baseQty,
            'rate'             => $rate,
            'discount_percent' => $itemDiscPct,
            'discount_amount'  => $lineDisc,
            'line_total'       => $lineNet
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line to the bill.";
    }

    if (empty($errors)) {
        if ($discountMode === 'subtotal') {
            if ($subtotalDiscountAmount > 0) {
                $extraDiscount = $subtotalDiscountAmount;
                if ($grossTotal > 0 && $subtotalDiscountPercent <= 0) {
                    $subtotalDiscountPercent = round(($extraDiscount / $grossTotal) * 100, 2);
                }
            } elseif ($subtotalDiscountPercent > 0) {
                $extraDiscount = round(($grossTotal * $subtotalDiscountPercent) / 100.0, 2);
                $subtotalDiscountAmount = $extraDiscount;
            } else {
                $extraDiscount = 0.0;
                $subtotalDiscountAmount = 0.0;
                $subtotalDiscountPercent = 0.0;
            }
            $itemDiscount = 0.0;
        } else {
            $itemDiscount = $discTotal;
            $extraDiscount = 0.0;
            $subtotalDiscountPercent = 0.0;
            $subtotalDiscountAmount = 0.0;
        }

        $netTotal = max(0, round($grossTotal - ($itemDiscount + $extraDiscount), 2));

        try {
            db()->beginTransaction();

            $oldNet = (float)$invoice['net_amount'];
            $oldCredit = (float)$invoice['credit_amount'];

            // Update invoice items
            db()->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?")->execute([$id]);

            $stmtItem = db()->prepare("
                INSERT INTO sales_invoice_items (
                    invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
                    quantity, rate, discount_percent, discount_amount, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $id, $vi['product_id'], $vi['unit_id'], $vi['carton_qty'],
                    $vi['pack_qty'], $vi['pcs_qty'], $vi['quantity'], $vi['rate'], $vi['discount_percent'],
                    $vi['discount_amount'], $vi['line_total']
                ]);
            }

            // Adjust Customer Balance if credit was involved
            if ($oldCredit > 0 || ($invoice['payment_status'] ?? '') === 'unpaid' || ($invoice['payment_status'] ?? '') === 'partial') {
                $newCredit = max(0, $netTotal - (float)$invoice['paid_amount'] - (float)$invoice['return_amount']);
                $creditDelta = $newCredit - $oldCredit;
                if (abs($creditDelta) > 0.001) {
                    if ($creditDelta > 0) {
                        update_customer_balance((int)$invoice['customer_id'], $creditDelta, 'add');
                    } else {
                        update_customer_balance((int)$invoice['customer_id'], abs($creditDelta), 'subtract');
                    }
                    db()->prepare("UPDATE sales_invoices SET credit_amount = ? WHERE id = ?")->execute([$newCredit, $id]);
                }
            }

            // Update invoice header
            $stmtUpd = db()->prepare("
                UPDATE sales_invoices 
                SET invoice_date = ?, pricing_mode = ?, discount_mode = ?, free_loose_pieces = ?, gross_amount = ?, item_discount_amount = ?, 
                    extra_discount_amount = ?, subtotal_discount_percent = ?,
                    net_amount = ?, remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $invoiceDate, $pricingMode, $discountMode, $freeLoosePieces, $grossTotal, $itemDiscount, $extraDiscount,
                $subtotalDiscountPercent, $netTotal, $remarks, $id
            ]);

            db()->commit();

            if (function_exists('audit_log')) {
                audit_log('update_invoice', 'sales_invoices', (string)$id, "Updated invoice #{$invoice['invoice_number']} ({$pricingMode}), Net: {$netTotal}");
            }
            set_flash('success', "Invoice <strong>{$invoice['invoice_number']}</strong> updated successfully! Relative balances and pricing have been recalculated.");
            redirect(APP_URL . '/modules/invoices/view.php?id=' . $id);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error editing invoice: " . $e->getMessage());
            $errors[] = "Error saving changes: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Bill ' . $invoice['invoice_number'] . ' — ' . APP_NAME;
$activeMenu = 'invoices';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Breadcrumb / Header Bar -->
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <div>
                <h5 class="mb-0 fw-bold text-navy"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Sales Bill: <?= htmlspecialchars($invoice['invoice_number']) ?></h5>
                <small class="text-muted">Fast card selection: Click products to add to cart, edit quantities or rates instantly</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Bill View (ESC)
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-3">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Unable to proceed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/modules/invoices/edit.php?id=<?= $invoice['id'] ?>" id="invoiceForm" autocomplete="off">
            <!-- Bill Route & Retailer Metadata Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Customer / Retail Shop</label>
                            <input type="text" class="form-control form-control-sm bg-light fw-bold text-dark" value="<?= htmlspecialchars($invoice['shop_name']) ?>" disabled>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Order Booker</label>
                            <input type="text" class="form-control form-control-sm bg-light text-dark" value="<?= htmlspecialchars($invoice['booker_name'] ?? 'Direct') ?>" disabled>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Route / Area</label>
                            <input type="text" class="form-control form-control-sm bg-light text-dark" value="<?= htmlspecialchars($invoice['area_name'] ?? 'General') ?>" disabled>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" class="form-control form-control-sm" value="<?= htmlspecialchars($invoice['invoice_date']) ?>" required>
                        </div>

                        <div class="col-md-3 text-end">
                            <span class="badge bg-navy text-white font-monospace py-2 px-3" style="font-size: 12px;"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
                            <div class="small text-muted mt-1">Customer Balance: <strong class="text-danger"><?= format_currency((float)$invoice['cust_balance']) ?></strong></div>
                        </div>
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
                                        <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['supplier_name']) ?><?= !empty($sup['company_name']) ? ' (' . htmlspecialchars($sup['company_name']) . ')' : '' ?></option>
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
                                     onclick="addProductToInvoice(<?= $p['id'] ?>)">
                                    
                                    <span class="card-badge-cart d-none" id="badge_cart_<?= $p['id'] ?>">0 in bill</span>
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
                                            <div class="prod-price" id="card_c_rate_<?= $p['id'] ?>" style="font-size: 12.5px; line-height: 1.25; margin-bottom: 2px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format((float)$p['carton_rate'], 0) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>
                                            </div>
                                            <div class="text-primary fw-semibold" id="card_pk_rate_<?= $p['id'] ?>" style="font-size: 11px; line-height: 1.25; margin-bottom: 2px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format($packsPerCtn > 0 ? ((float)$p['carton_rate'] / $packsPerCtn) : (float)$p['carton_rate'], 2) ?> <span class="text-muted fw-normal" style="font-size: 9.5px;">/ Pack</span>
                                            </div>
                                            <div class="text-secondary fw-semibold" id="card_pc_rate_<?= $p['id'] ?>" style="font-size: 11px; line-height: 1.25;">
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

                <!-- Right Column: Interactive Bill Items Cart (Editable quantities, rates & discounts) -->
                <div class="col-lg-7">
                    <div class="pos-cart-container">
                        <div class="pos-cart-header">
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <span class="fw-bold text-navy">
                                    <i class="bi bi-receipt me-1 text-primary"></i> Invoice Line Items 
                                    <span class="badge bg-primary ms-1" id="cartItemCount">0 items</span>
                                </span>
                            </div>

                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <?php $savedPricingMode = $invoice['pricing_mode'] ?? 'wholesale'; ?>
                                <input type="hidden" name="pricing_mode" id="selectedPricingMode" value="<?= htmlspecialchars($savedPricingMode) ?>">
                                <div class="btn-group btn-group-sm shadow-sm" role="group" aria-label="Pricing Mode Tier">
                                    <input type="radio" class="btn-check" name="pricing_mode_tier" id="tierWholesale" value="wholesale" <?= $savedPricingMode === 'wholesale' ? 'checked' : '' ?> autocomplete="off" onchange="setPricingMode('wholesale')">
                                    <label class="btn btn-outline-primary btn-sm px-2 py-1 fw-bold cursor-pointer" for="tierWholesale" id="lblTierWholesale" title="Apply standard Wholesale rates to bill">
                                        <i class="bi bi-shop me-1"></i> Wholesale Price
                                    </label>

                                    <input type="radio" class="btn-check" name="pricing_mode_tier" id="tierMrp" value="mrp" <?= $savedPricingMode === 'mrp' ? 'checked' : '' ?> autocomplete="off" onchange="setPricingMode('mrp')">
                                    <label class="btn btn-outline-success btn-sm px-2 py-1 fw-bold cursor-pointer" for="tierMrp" id="lblTierMrp" title="Apply printed MRP / Retail rates to bill">
                                        <i class="bi bi-tag-fill me-1"></i> MRP Price
                                    </label>
                                </div>

                                <button type="button" class="btn btn-xs btn-outline-danger" onclick="clearInvoiceCart()" id="btnClearCart" style="display:none;">
                                    <i class="bi bi-trash me-1"></i> Clear Items
                                </button>
                            </div>
                        </div>

                        <!-- Discount Mode & Free Loose Pieces Scheme Bar -->
                        <div class="pos-discount-mode-bar bg-light p-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center flex-wrap gap-3">
                                <span class="small fw-bold text-secondary"><i class="bi bi-tag-fill me-1 text-primary"></i> Discount:</span>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeItem" value="item_rate" <?= ($invoice['discount_mode'] ?? 'item_rate') === 'item_rate' ? 'checked' : '' ?> onchange="onDiscountModeChange()">
                                    <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeItem">Discount from per item rate</label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input cursor-pointer" type="radio" name="discount_mode" id="discountModeSubtotal" value="subtotal" <?= ($invoice['discount_mode'] ?? 'item_rate') === 'subtotal' ? 'checked' : '' ?> onchange="onDiscountModeChange()">
                                    <label class="form-check-label small fw-semibold cursor-pointer" for="discountModeSubtotal">Discount from subtotal</label>
                                </div>
                                <div class="form-check form-switch m-0 d-inline-flex align-items-center gap-2 border-start ps-3 ms-2">
                                    <input class="form-check-input cursor-pointer" type="checkbox" role="switch" name="free_loose_pieces" id="freeLoosePiecesToggle" value="1" <?= !empty($invoice['free_loose_pieces']) ? 'checked' : '' ?> onchange="onFreeLoosePiecesChange()">
                                    <label class="form-check-label small fw-bold text-success cursor-pointer" for="freeLoosePiecesToggle" title="Trade Scheme: Loose pieces will be free (Rs 0) in bill total, but will be dispatched on load form">
                                        <i class="bi bi-gift-fill me-1 text-warning"></i> Free Loose Pcs (سکیم / بونس پیس مفت)
                                    </label>
                                </div>
                            </div>
                            <div id="subtotalDiscountInputs" class="<?= ($invoice['discount_mode'] ?? 'item_rate') === 'subtotal' ? 'd-flex' : 'd-none' ?> align-items-center gap-2">
                                <span class="small fw-bold text-navy">Subtotal Disc:</span>
                                <div class="input-group input-group-sm" style="width: 110px;">
                                    <input type="number" step="0.01" min="0" max="100" name="subtotal_discount_percent" id="subtotalDiscountPercent" class="form-control form-control-sm text-end" placeholder="0" value="<?= (float)($invoice['subtotal_discount_percent'] ?? 0) > 0 ? (float)$invoice['subtotal_discount_percent'] : '' ?>" oninput="onSubtotalPercentChange()">
                                    <span class="input-group-text small">%</span>
                                </div>
                                <div class="input-group input-group-sm" style="width: 140px;">
                                    <span class="input-group-text small">Rs</span>
                                    <input type="number" step="0.01" min="0" name="subtotal_discount_amount" id="subtotalDiscountAmount" class="form-control form-control-sm text-end" placeholder="0.00" value="<?= (float)($invoice['extra_discount_amount'] ?? 0) > 0 ? (float)$invoice['extra_discount_amount'] : '' ?>" oninput="onSubtotalAmountChange()">
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
                                            <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                            <div class="fw-semibold">No items added to bill yet.</div>
                                            <small>Click on product cards on the left or use the search bar to add items.</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

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
                                    <div class="text-muted small">Net Bill Amount</div>
                                    <div class="fw-bold fs-5 text-navy" id="summaryNetAmount">Rs. 0.00</div>
                                </div>
                            </div>

                            <div class="row g-2 align-items-center">
                                <div class="col-md-7">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Invoice remarks, delivery notes, or special instructions..." value="<?= htmlspecialchars($invoice['remarks'] ?? '') ?>">
                                </div>
                                <div class="col-md-5 text-end d-flex align-items-center justify-content-end gap-2">
                                    <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-secondary btn-sm px-2">
                                        Cancel (ESC)
                                    </a>
                                    <button type="submit" name="save_changes" class="btn btn-navy btn-sm px-3 fw-bold">
                                        <i class="bi bi-check2-circle me-1"></i> Save Bill Changes (F9)
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

<!-- Embedded Product Cache & Existing Invoice Items for Real-Time Calculations -->
<script>
    const DMS_PRODUCTS = <?= json_encode($products) ?>;
    const EXISTING_INVOICE_ITEMS = <?= json_encode($items) ?>;
    const productMap = {};
    DMS_PRODUCTS.forEach(p => { productMap[p.id] = p; });
</script>

<script>
let cartItems = {};
let currentPricingMode = <?= json_encode($invoice['pricing_mode'] ?? 'wholesale') ?>;

function getProductRates(prod, mode) {
    mode = mode || currentPricingMode;
    const packsPerCtn = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
    const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
    const pcsPerCtn   = packsPerCtn * pcsPerPack;

    if (mode === 'mrp') {
        const mrpCarton = parseFloat(prod.retail_carton_rate) > 0 
            ? parseFloat(prod.retail_carton_rate) 
            : (parseFloat(prod.retail_rate) > 0 ? parseFloat(prod.retail_rate) : (parseFloat(prod.carton_rate) || parseFloat(prod.wholesale_rate) || 0));
        const mrpPack   = packsPerCtn > 0 ? (mrpCarton / packsPerCtn) : mrpCarton;
        const mrpPiece  = parseFloat(prod.retail_piece_rate) > 0 
            ? parseFloat(prod.retail_piece_rate) 
            : (pcsPerCtn > 0 ? (mrpCarton / pcsPerCtn) : mrpCarton);
        return {
            carton_rate: mrpCarton,
            pack_rate: mrpPack,
            piece_rate: mrpPiece,
            pcs_per_ctn: pcsPerCtn,
            packs_per_ctn: packsPerCtn
        };
    } else {
        const wsCarton  = parseFloat(prod.carton_rate) > 0 
            ? parseFloat(prod.carton_rate) 
            : (parseFloat(prod.wholesale_rate) || 0);
        const wsPack    = packsPerCtn > 0 ? (wsCarton / packsPerCtn) : wsCarton;
        const wsPiece   = parseFloat(prod.piece_rate) > 0 
            ? parseFloat(prod.piece_rate) 
            : (pcsPerCtn > 0 ? (wsCarton / pcsPerCtn) : wsCarton);
        return {
            carton_rate: wsCarton,
            pack_rate: wsPack,
            piece_rate: wsPiece,
            pcs_per_ctn: pcsPerCtn,
            packs_per_ctn: packsPerCtn
        };
    }
}

function setPricingMode(mode) {
    currentPricingMode = mode;
    const hiddenInp = document.getElementById('selectedPricingMode');
    if (hiddenInp) hiddenInp.value = mode;

    const radioWs = document.getElementById('tierWholesale');
    const radioMrp = document.getElementById('tierMrp');
    if (radioWs && radioMrp) {
        if (mode === 'mrp') {
            radioMrp.checked = true;
        } else {
            radioWs.checked = true;
        }
    }

    // 1. Update all items currently in cart in real-time
    Object.keys(cartItems).forEach(pid => {
        const item = cartItems[pid];
        const prod = productMap[pid];
        if (!item || !prod) return;

        const rates = getProductRates(prod, mode);
        item.carton_rate = rates.carton_rate;
        item.pack_rate   = rates.pack_rate;
        item.piece_rate  = rates.piece_rate;

        if (item.unit === 'pcs') {
            item.rate = rates.piece_rate;
        } else if (item.unit === 'pack') {
            item.rate = rates.pack_rate;
        } else {
            item.rate = rates.carton_rate;
        }

        const inputRate = document.getElementById('input_rate_' + pid);
        if (inputRate) {
            inputRate.value = Number(item.rate || 0).toFixed(2);
            inputRate.classList.add('bg-warning-subtle');
            setTimeout(() => inputRate.classList.remove('bg-warning-subtle'), 400);
        }

        updateCartRow(pid);
    });

    recalculateCartSummary();

    // 2. Update all product cards on left in real-time
    updateProductCardsPricing(mode);
}

function updateProductCardsPricing(mode) {
    DMS_PRODUCTS.forEach(p => {
        const rates = getProductRates(p, mode);
        const cardCtn = document.getElementById('card_c_rate_' + p.id);
        const cardPk  = document.getElementById('card_pk_rate_' + p.id);
        const cardPc  = document.getElementById('card_pc_rate_' + p.id);

        if (cardCtn) {
            cardCtn.innerHTML = `<?= DEFAULT_CURRENCY ?> ${Math.round(rates.carton_rate).toLocaleString()} <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>`;
        }
        if (cardPk) {
            cardPk.innerHTML = `<?= DEFAULT_CURRENCY ?> ${rates.pack_rate.toFixed(2)} <span class="text-muted fw-normal" style="font-size: 9.5px;">/ Pack</span>`;
        }
        if (cardPc) {
            cardPc.innerHTML = `<?= DEFAULT_CURRENCY ?> ${rates.piece_rate.toFixed(2)} <span class="text-muted fw-normal" style="font-size: 9.5px;">/ Pc</span>`;
        }
    });
}

function getDiscountMode() {
    return document.getElementById('discountModeSubtotal')?.checked ? 'subtotal' : 'item_rate';
}

function onDiscountModeChange() {
    const isSubtotal = getDiscountMode() === 'subtotal';
    const subtotalInputs = document.getElementById('subtotalDiscountInputs');
    const lblSummary = document.getElementById('lblSummaryDiscount');

    if (isSubtotal) {
        if (subtotalInputs) {
            subtotalInputs.classList.remove('d-none');
            subtotalInputs.classList.add('d-flex');
        }
        if (lblSummary) lblSummary.innerText = 'Subtotal Discount';

        document.querySelectorAll('input[id^="input_disc_"]').forEach(inp => {
            inp.disabled = true;
            inp.classList.add('bg-light', 'text-muted');
            inp.title = 'Disabled in subtotal discount mode';
        });
    } else {
        if (subtotalInputs) {
            subtotalInputs.classList.remove('d-flex');
            subtotalInputs.classList.add('d-none');
        }
        if (lblSummary) lblSummary.innerText = 'Total Discount';

        document.querySelectorAll('input[id^="input_disc_"]').forEach(inp => {
            inp.disabled = false;
            inp.classList.remove('bg-light', 'text-muted');
            inp.removeAttribute('title');
        });

        const pctEl = document.getElementById('subtotalDiscountPercent');
        const amtEl = document.getElementById('subtotalDiscountAmount');
        if (pctEl) pctEl.value = '';
        if (amtEl) amtEl.value = '';
    }

    recalculateAllRows();
    recalculateCartSummary();
}

function onFreeLoosePiecesChange() {
    recalculateAllRows();
    recalculateCartSummary();
}

function onSubtotalPercentChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const pctVal = parseFloat(document.getElementById('subtotalDiscountPercent')?.value) || 0;
    let grossAmount = 0;
    Object.values(cartItems).forEach(item => {
        grossAmount += calculateLineGross(item);
    });

    const amt = grossAmount > 0 ? Math.round(((grossAmount * pctVal) / 100.0) * 100) / 100 : 0;
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

function calculateLineGross(item) {
    const isFreePcs = document.getElementById('freeLoosePiecesToggle')?.checked;
    const billablePcs = isFreePcs ? 0 : item.pcs_qty;

    if (item.unit === 'pcs') {
        const totalBasePieces = (item.carton_qty * item.pcs_per_ctn) + (item.pack_qty * item.pcs_per_pack) + billablePcs;
        return totalBasePieces * item.rate;
    } else if (item.unit === 'pack') {
        const totalPacks = (item.carton_qty * item.packs_per_ctn) + item.pack_qty + (item.pcs_per_pack > 0 ? (billablePcs / item.pcs_per_pack) : 0);
        return totalPacks * item.rate;
    } else {
        const cartonEquivalent = item.carton_qty + (item.packs_per_ctn > 0 ? (item.pack_qty / item.packs_per_ctn) : 0) + (item.pcs_per_ctn > 0 ? (billablePcs / item.pcs_per_ctn) : 0);
        return cartonEquivalent * item.rate;
    }
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

function addProductToInvoice(productId) {
    const prod = productMap[productId];
    if (!prod) return;

    if (cartItems[productId]) {
        if (cartItems[productId].unit === 'pcs') {
            cartItems[productId].pcs_qty += cartItems[productId].pcs_per_ctn > 0 ? cartItems[productId].pcs_per_ctn : 1;
        } else if (cartItems[productId].unit === 'pack') {
            cartItems[productId].pack_qty += 1;
        } else {
            cartItems[productId].carton_qty += 1;
        }
        updateCartRow(productId);
    } else {
        const rates = getProductRates(prod, currentPricingMode);
        const packsPerCtn = rates.packs_per_ctn;
        const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
        const pcsPerCtn   = rates.pcs_per_ctn;
        const cartonRate  = rates.carton_rate;
        const packRate    = rates.pack_rate;
        const pieceRate   = rates.piece_rate;

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
                   oninput="onCartRowChange(${productId})" required>
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="item_pack_qty[]" id="input_pack_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pack_qty}" 
                   oninput="onCartRowChange(${productId})">
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="item_pcs_qty[]" id="input_pcs_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pcs_qty}" 
                   oninput="onCartRowChange(${productId})">
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
                   oninput="onCartRowChange(${productId})" required>
            <small class="text-muted text-end d-block" id="lbl_rate_unit_${productId}" style="font-size: 10px;">/ ${item.unit === 'pcs' ? 'Piece' : (item.unit === 'pack' ? 'Pack' : 'Carton')}</small>
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="item_discount_percent[]" id="input_disc_${productId}" 
                   class="form-control form-control-sm text-end pos-cart-input" 
                   value="${item.discount_percent}" 
                   oninput="onCartRowChange(${productId})">
        </td>
        <td class="text-end fw-bold text-navy" id="lbl_total_${productId}">
            <?= DEFAULT_CURRENCY ?> ${item.line_total.toFixed(2)}
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" onclick="removeProductFromInvoice(${productId})" title="Remove item">
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
    } else if (unit === 'pack') {
        item.rate = item.pack_rate || (packsPerCtn > 0 ? item.carton_rate / packsPerCtn : item.carton_rate);
    } else {
        item.rate = item.carton_rate;
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

function removeProductFromInvoice(productId) {
    delete cartItems[productId];
    const row = document.getElementById('cart_row_' + productId);
    if (row) row.remove();

    if (Object.keys(cartItems).length === 0) {
        const emptyRow = document.getElementById('emptyCartRow');
        if (emptyRow) emptyRow.style.display = '';
        document.getElementById('btnClearCart').style.display = 'none';
    }

    updateCardBadge(productId);
    recalculateCartSummary();
}

function clearInvoiceCart() {
    if (!confirm('Are you sure you want to clear all items from this bill?')) return;
    cartItems = {};
    const tbody = document.getElementById('cartItemsBody');
    tbody.innerHTML = `
        <tr id="emptyCartRow">
            <td colspan="9" class="text-center py-5 text-muted">
                <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                <div class="fw-semibold">No items added to bill yet.</div>
                <small>Click on product cards on the left or use the search bar to add items.</small>
            </td>
        </tr>
    `;
    document.getElementById('btnClearCart').style.display = 'none';

    document.querySelectorAll('.card-badge-cart').forEach(b => {
        b.classList.add('d-none');
        b.innerText = '0 in bill';
    });
    document.querySelectorAll('.pos-product-card').forEach(c => c.classList.remove('in-cart'));

    recalculateCartSummary();
}

function updateCardBadge(productId) {
    const card  = document.getElementById('card_prod_' + productId);
    const badge = document.getElementById('badge_cart_' + productId);
    const item  = cartItems[productId];

    if (!card || !badge) return;

    if (item && (item.carton_qty > 0 || item.pack_qty > 0 || item.pcs_qty > 0)) {
        card.classList.add('in-cart');
        badge.classList.remove('d-none');
        let badgeParts = [];
        if (item.carton_qty > 0) badgeParts.push(`${item.carton_qty} Ctn`);
        if (item.pack_qty > 0) badgeParts.push(`${item.pack_qty} Pk`);
        if (item.pcs_qty > 0) badgeParts.push(`${item.pcs_qty} Pc`);
        badge.innerText = badgeParts.join(', ') + ' in bill';
    } else {
        card.classList.remove('in-cart');
        badge.classList.add('d-none');
        badge.innerText = '0 in bill';
    }
}

function recalculateCartSummary() {
    let totalCartons = 0;
    let totalPieces  = 0;
    let grossAmount  = 0;
    let itemDiscSum  = 0;
    let itemCount    = 0;

    const mode = getDiscountMode();

    Object.values(cartItems).forEach(item => {
        totalCartons += parseFloat(item.carton_qty) || 0;
        totalPieces  += parseFloat(item.pcs_qty) || 0;
        itemCount++;

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
    document.getElementById('summaryTotalCartons').innerText = `${totalCartons.toLocaleString('en-US', {maximumFractionDigits:2})} Ctns`;
    document.getElementById('summaryTotalPieces').innerText = `${totalPieces} Pcs`;
    document.getElementById('summaryDiscountAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${discountAmt.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    document.getElementById('summaryNetAmount').innerText = `<?= DEFAULT_CURRENCY ?> ${netAmount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
}

document.addEventListener('DOMContentLoaded', function () {
    const productSearch  = document.getElementById('productSearch');
    const companyFilter  = document.getElementById('companyFilter');
    const categoryPills  = document.querySelectorAll('.pos-category-pill');
    const productCards   = document.querySelectorAll('.pos-product-card');

    let activeCategory = '';
    let activeCompany  = '';
    let searchTerm     = '';

    function filterCards() {
        productCards.forEach(card => {
            const name = card.getAttribute('data-name').toLowerCase();
            const code = card.getAttribute('data-code').toLowerCase();
            const cat  = card.getAttribute('data-cat');
            const sup  = card.getAttribute('data-supplier-id');

            const matchSearch = (!searchTerm || name.includes(searchTerm) || code.includes(searchTerm));
            const matchCat    = (!activeCategory || cat === activeCategory);
            const matchSup    = (!activeCompany || sup === activeCompany);

            if (matchSearch && matchCat && matchSup) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }

    if (productSearch) {
        productSearch.addEventListener('input', function () {
            searchTerm = this.value.trim().toLowerCase();
            filterCards();
        });
    }

    if (companyFilter) {
        companyFilter.addEventListener('change', function () {
            activeCompany = this.value;
            filterCards();
        });
    }

    categoryPills.forEach(pill => {
        pill.addEventListener('click', function () {
            categoryPills.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            activeCategory = this.getAttribute('data-cat');
            filterCards();
        });
    });

    // Populate Existing Invoice Items into Cart on Page Load
    if (Array.isArray(EXISTING_INVOICE_ITEMS) && EXISTING_INVOICE_ITEMS.length > 0) {
        EXISTING_INVOICE_ITEMS.forEach(sii => {
            const prod = productMap[sii.product_id];
            const packsPerCtn = parseFloat(sii.ctn_ratio) > 0 ? parseFloat(sii.ctn_ratio) : (parseFloat(prod?.carton_qty) || 1);
            const pcsPerPack  = parseFloat(sii.pack_ratio) > 0 ? parseFloat(sii.pack_ratio) : (parseFloat(prod?.pack_qty) || 1);
            const pcsPerCtn   = packsPerCtn * pcsPerPack;
            
            const rates = getProductRates(prod || {}, currentPricingMode);
            const cartonRate  = parseFloat(sii.rate) || rates.carton_rate;
            const packRate    = packsPerCtn > 0 ? (cartonRate / packsPerCtn) : cartonRate;
            const pieceRate   = parseFloat(prod?.piece_rate) || (pcsPerCtn > 0 ? (cartonRate / pcsPerCtn) : cartonRate);

            let unit = 'ctn';
            if (parseInt(sii.unit_id) === 4 || (sii.unit_symbol && sii.unit_symbol.toLowerCase().includes('pc'))) {
                unit = 'pcs';
            } else if (parseInt(sii.unit_id) === 3 || (sii.unit_symbol && sii.unit_symbol.toLowerCase().includes('pack'))) {
                unit = 'pack';
            }

            cartItems[sii.product_id] = {
                product_id: parseInt(sii.product_id),
                product_name: sii.product_name,
                product_code: sii.product_code,
                packs_per_ctn: packsPerCtn,
                pcs_per_pack: pcsPerPack,
                pcs_per_ctn: pcsPerCtn,
                carton_qty: parseFloat(sii.carton_qty) || 0,
                pack_qty: parseFloat(sii.pack_qty) || 0,
                pcs_qty: parseFloat(sii.pcs_qty) || 0,
                unit: unit,
                carton_rate: rates.carton_rate,
                pack_rate: rates.pack_rate,
                piece_rate: rates.piece_rate,
                rate: parseFloat(sii.rate) || rates.carton_rate,
                discount_percent: parseFloat(sii.discount_percent) || 0,
                line_total: parseFloat(sii.line_total) || 0
            };

            renderNewCartRow(sii.product_id);
            updateCardBadge(sii.product_id);
        });

        recalculateCartSummary();
    }

    // Keyboard Shortcuts
    document.addEventListener('keydown', function (e) {
        if (e.key === 'F9') {
            e.preventDefault();
            const form = document.getElementById('invoiceForm');
            if (form) form.submit();
        } else if (e.key === 'Escape') {
            window.location.href = '<?= APP_URL ?>/modules/invoices/view.php?id=<?= $invoice['id'] ?>';
        } else if (e.key === 'F2' || e.key === '/') {
            if (document.activeElement !== productSearch) {
                e.preventDefault();
                if (productSearch) {
                    productSearch.focus();
                    productSearch.select();
                }
            }
        }
    });

    // Update cards to reflect current pricing tier on initial load
    updateProductCardsPricing(currentPricingMode);
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
