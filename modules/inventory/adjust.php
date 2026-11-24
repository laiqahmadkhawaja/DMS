<?php
/**
 * DMS - Distribution Management System
 * Inventory - High-Speed POS-Style Physical Stock Audit & Opening Stock Adjustment
 * (فزیکل سٹاک آڈٹ اور اوپننگ سٹاک اندراج)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('inventory_adjust');

$pageTitle = 'Physical Stock Audit & Opening Stock';
$activeMenu = 'inventory';

$preProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$prePreset    = isset($_GET['preset']) ? sanitize_input($_GET['preset']) : 'opening_stock';
$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adjDate     = sanitize_input($_POST['adjustment_date'] ?? date('Y-m-d'));
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $auditPreset = sanitize_input($_POST['audit_preset'] ?? 'opening_stock');
    $reason      = sanitize_input($_POST['reason'] ?? '');
    $itemsData   = $_POST['items'] ?? [];

    if (empty($reason)) {
        $reason = match ($auditPreset) {
            'opening_stock'   => 'Opening Stock Initialization',
            'physical_audit'  => 'Periodic Physical Stock Audit Variance',
            'breakage_damage' => 'Warehouse Breakage & Transit Damage Write-off',
            'expired_goods'   => 'Expired & Spoiled Stock Write-off',
            default           => 'Stock Adjustment'
        };
    }

    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "Please select and add at least one product to the adjustment list.";
    }

    $validItems = [];
    $totalAdjUnits = 0.0;
    $totalAdjCartons = 0.0;
    $totalAdjValuation = 0.0;
    $netAdditionValue = 0.0;
    $netSubtractionValue = 0.0;

    foreach ($itemsData as $idx => $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $type      = sanitize_input($row['adjustment_type'] ?? 'add'); // 'add' or 'subtract'
        $condition = sanitize_input($row['condition_type'] ?? 'saleable'); // 'saleable', 'damaged', 'expired'
        $ctnQty    = (float)($row['carton_qty'] ?? 0);
        $packQty   = (float)($row['pack_qty'] ?? 0);
        $pcsQty    = (float)($row['pcs_qty'] ?? 0);
        $customPieceCost = isset($row['unit_cost']) && is_numeric($row['unit_cost']) ? (float)$row['unit_cost'] : null;
        $remarksRow = sanitize_input($row['remarks'] ?? $reason);

        if ($productId <= 0 || ($ctnQty <= 0 && $packQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("
            SELECT p.*, COALESCE(u.symbol, 'Ctn') as major_unit_symbol 
            FROM products p 
            LEFT JOIN units u ON p.base_unit_id = u.id 
            WHERE p.id = ?
        ", [$productId]);
        if (!$prod) continue;

        $ratios = get_product_packaging_ratios($prod);
        $basePieces = calculate_base_pieces($ctnQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);
        if ($basePieces <= 0) continue;

        $breakdown = breakdown_base_pieces($basePieces, $ratios['packs_per_ctn'], $ratios['pcs_per_pack'], $prod['major_unit_symbol']);

        $pcsPerCtn = max(1.0, (float)($ratios['pcs_per_ctn'] ?? 1));
        
        // Determine Piece Cost
        if ($customPieceCost !== null && $customPieceCost > 0) {
            $pieceCost = round($customPieceCost, 6);
        } else {
            $pieceCost = (float)($prod['purchase_piece_rate'] ?? 0) > 0 
                ? (float)$prod['purchase_piece_rate'] 
                : round((float)($prod['purchase_rate'] ?? 0) / $pcsPerCtn, 6);
        }

        $lineValue = round($basePieces * $pieceCost, 2);

        $validItems[] = [
            'product_id'      => $productId,
            'carton_qty'      => $ctnQty,
            'pack_qty'        => $packQty,
            'pcs_qty'         => $pcsQty,
            'adjustment_type' => $type,
            'condition_type'  => $condition,
            'quantity'        => $basePieces,
            'unit_cost'       => $pieceCost,
            'total_amount'    => $lineValue,
            'remarks'         => $remarksRow,
            'breakdown_text'  => $breakdown['formatted'],
            'product_name'    => $prod['product_name']
        ];

        $totalAdjUnits += $basePieces;
        $totalAdjCartons += $ctnQty;
        $totalAdjValuation += $lineValue;

        if ($type === 'add') {
            $netAdditionValue += $lineValue;
        } else {
            $netSubtractionValue += $lineValue;
        }
    }

    if (empty($validItems)) {
        $errors[] = "Please enter valid quantities greater than zero for at least one item.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $adjNumber = generate_document_number(PREFIX_ADJUSTMENT, 'stock_adjustments', 'adjustment_number');

            $isOpeningStock = ($auditPreset === 'opening_stock') || (stripos($reason, 'opening') !== false);

            // 1. Insert stock_adjustments header
            $stmtAdj = db()->prepare("
                INSERT INTO stock_adjustments (
                    adjustment_number, warehouse_id, adjustment_date, reason, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, 'approved', ?, NOW()
                )
            ");
            $stmtAdj->execute([$adjNumber, $warehouseId, $adjDate, $reason, $userId]);
            $adjId = (int)db()->lastInsertId();

            // 2. Insert items & record stock movements
            $stmtItem = db()->prepare("
                INSERT INTO stock_adjustment_items (
                    stock_adjustment_id, product_id, carton_qty, pack_qty, pcs_qty, 
                    adjustment_type, condition_type, quantity, unit_cost, total_amount, remarks, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $adjId, $vi['product_id'], $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['adjustment_type'], $vi['condition_type'],
                    $vi['quantity'], $vi['unit_cost'], $vi['total_amount'], $vi['remarks']
                ]);

                if ($vi['adjustment_type'] === 'add') {
                    $movType = $isOpeningStock ? 'opening_stock' : 'stock_adjustment_add';
                    $refType = $isOpeningStock ? 'opening' : 'adjustment';
                    $movRemarks = $isOpeningStock 
                        ? "Opening Stock #{$adjNumber} [{$vi['breakdown_text']}] ({$vi['remarks']})" 
                        : "Stock surplus #{$adjNumber} [{$vi['breakdown_text']}] ({$vi['remarks']})";

                    record_stock_movement([
                        'product_id'     => $vi['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => $movType,
                        'reference_type' => $refType,
                        'reference_id'   => $adjId,
                        'qty_in'         => $vi['quantity'],
                        'qty_out'        => 0,
                        'unit_cost'      => $vi['unit_cost'],
                        'total_cost'     => $vi['total_amount'],
                        'remarks'        => $movRemarks,
                        'condition_type' => $vi['condition_type']
                    ]);
                } else {
                    record_stock_movement([
                        'product_id'     => $vi['product_id'],
                        'warehouse_id'   => $warehouseId,
                        'movement_type'  => 'stock_adjustment_sub',
                        'reference_type' => 'adjustment',
                        'reference_id'   => $adjId,
                        'qty_in'         => 0,
                        'qty_out'        => $vi['quantity'],
                        'unit_cost'      => $vi['unit_cost'],
                        'total_cost'     => $vi['total_amount'],
                        'remarks'        => "Stock write-off #{$adjNumber} [{$vi['breakdown_text']}] ({$vi['remarks']})",
                        'condition_type' => $vi['condition_type']
                    ]);
                }
            }

            // 3. Post to General Ledger
            if ($isOpeningStock) {
                // Opening stock introduction:
                // Debit Inventory Asset (1030)
                // Credit Owner Equity / Capital (3010)
                if ($netAdditionValue > 0) {
                    post_journal_entry([
                        'entry_date'     => $adjDate,
                        'reference_type' => 'manual_journal',
                        'reference_id'   => $adjId,
                        'narration'      => "Initial Opening Stock Entry #{$adjNumber} - Valuation: " . format_currency($netAdditionValue)
                    ], [
                        ['account_id' => ACC_INVENTORY, 'debit' => $netAdditionValue, 'credit' => 0.00, 'description' => "Opening Inventory Asset - #{$adjNumber}"],
                        ['account_id' => 3010, 'debit' => 0.00, 'credit' => $netAdditionValue, 'description' => "Owner Opening Equity / Capital - #{$adjNumber}"]
                    ]);
                }
                if ($netSubtractionValue > 0) {
                    post_journal_entry([
                        'entry_date'     => $adjDate,
                        'reference_type' => 'manual_journal',
                        'reference_id'   => $adjId,
                        'narration'      => "Opening Stock Reconciliation Write-off #{$adjNumber}"
                    ], [
                        ['account_id' => 3010, 'debit' => $netSubtractionValue, 'credit' => 0.00, 'description' => "Opening Equity Adjustment - #{$adjNumber}"],
                        ['account_id' => ACC_INVENTORY, 'debit' => 0.00, 'credit' => $netSubtractionValue, 'description' => "Inventory reduced - #{$adjNumber}"]
                    ]);
                }
            } else {
                // Routine Physical Stock Take Variance
                if ($netSubtractionValue > 0) {
                    // Loss / Shortage: Debit COGS (5010), Credit Inventory Asset (1030)
                    post_journal_entry([
                        'entry_date'     => $adjDate,
                        'reference_type' => 'manual_journal',
                        'reference_id'   => $adjId,
                        'narration'      => "Stock adjustment shortage write-off #{$adjNumber}"
                    ], [
                        ['account_id' => ACC_COGS, 'debit' => $netSubtractionValue, 'credit' => 0.00, 'description' => "Stock loss write-off #{$adjNumber}"],
                        ['account_id' => ACC_INVENTORY, 'debit' => 0.00, 'credit' => $netSubtractionValue, 'description' => "Inventory reduced - #{$adjNumber}"]
                    ]);
                }
                if ($netAdditionValue > 0) {
                    // Surplus: Debit Inventory Asset (1030), Credit COGS (5010)
                    post_journal_entry([
                        'entry_date'     => $adjDate,
                        'reference_type' => 'manual_journal',
                        'reference_id'   => $adjId,
                        'narration'      => "Stock adjustment surplus gain #{$adjNumber}"
                    ], [
                        ['account_id' => ACC_INVENTORY, 'debit' => $netAdditionValue, 'credit' => 0.00, 'description' => "Inventory added - #{$adjNumber}"],
                        ['account_id' => ACC_COGS, 'debit' => 0.00, 'credit' => $netAdditionValue, 'description' => "Stock gain adjustment #{$adjNumber}"]
                    ]);
                }
            }

            db()->commit();

            $successMsg = "Stock adjustment <strong>{$adjNumber}</strong> successfully applied! " . count($validItems) . " products updated (" . number_format($totalAdjCartons, 1) . " Ctns / " . number_format($totalAdjUnits) . " units), Total Valuation: <strong>" . format_currency($totalAdjValuation) . "</strong>.";
            set_flash('success', $successMsg);
            redirect(APP_URL . '/modules/inventory/index.php');

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error saving stock adjustment: " . $e->getMessage());
            $errors[] = "Error processing adjustment: " . $e->getMessage();
        }
    }
}

// Fetch Master Lookups
$warehouses = db_fetch_all("SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC");
$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_active = 1 ORDER BY category_name ASC");
$suppliers  = db_fetch_all("SELECT id, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");
$nextAdjNumber = generate_document_number(PREFIX_ADJUSTMENT, 'stock_adjustments', 'adjustment_number');

// Fetch Products with Packaging, Rates & Warehouse Stock
$products = db_fetch_all("
    SELECT 
        p.id, p.product_code, p.product_name, p.barcode, p.category_id, p.supplier_id, p.brand_id,
        p.purchase_rate, p.purchase_carton_rate, p.purchase_piece_rate,
        p.default_sale_rate, p.wholesale_rate, p.carton_rate, p.piece_rate, p.retail_rate,
        p.carton_qty, p.pack_qty, p.packing_description, p.base_unit_id,
        COALESCE(u.symbol, 'Ctn') as major_unit_symbol,
        cat.category_name, b.brand_name, s.supplier_name, s.company_name,
        COALESCE(sb.saleable_qty, 0) as saleable_qty,
        COALESCE(sb.damaged_qty, 0) as damaged_qty,
        COALESCE(sb.expired_qty, 0) as expired_qty
    FROM products p 
    LEFT JOIN units u ON p.base_unit_id = u.id
    LEFT JOIN product_categories cat ON cat.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id AND b.is_deleted = 0
    LEFT JOIN suppliers s ON s.id = p.supplier_id AND s.is_deleted = 0
    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = 1
    WHERE p.is_active = 1 AND p.is_deleted = 0 
    ORDER BY p.product_name ASC
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Breadcrumb / Header Bar -->
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <div>
                <h4 class="mb-0 fw-bold text-navy">
                    <i class="bi bi-box-seam-fill text-warning me-2"></i>Physical Stock Audit & Opening Stock (سٹاک اندراج و آڈٹ)
                </h4>
                <small class="text-muted">High-speed card selection: initialize opening stock, physical count variance, or damage write-offs with live cost rates and valuation</small>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Stock Overview (ESC)
                </a>
                <a href="import_stock.php" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-file-earmark-excel me-1"></i> Excel Importer
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-3 py-2">
                <div class="fw-bold small mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Form Validation Failed:</div>
                <ul class="mb-0 ps-3 small">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="adjust.php" id="adjustForm" autocomplete="off">
            
            <!-- Audit Setup Header Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">
                                <i class="bi bi-lightning-charge text-warning me-1"></i>Adjustment Purpose (مقصد / نوعیت) <span class="text-danger">*</span>
                            </label>
                            <select name="audit_preset" id="auditPresetSelect" class="form-select form-select-sm fw-bold border-primary" onchange="onPresetChange()">
                                <option value="opening_stock" selected>📦 Opening Stock (اوپننگ سٹاک کا اندراج - نیا آغاز)</option>
                                <option value="physical_audit">🔍 Physical Audit Variance (فزیکل آڈٹ سرپلس / شارٹیج)</option>
                                <option value="breakage_damage">⚠️ Breakage & Damage Write-Off (ٹوٹ پھوٹ اور ڈیمیج)</option>
                                <option value="expired_goods">⏳ Expired Stock Write-Off (ایکسپائر شدہ مال کا اخراج)</option>
                                <option value="other">📝 Custom / Other (دیگر ایڈجسٹمنٹ)</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Adjustment Date (تاریخ)</label>
                            <input type="date" name="adjustment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Target Warehouse (گودام)</label>
                            <select name="warehouse_id" class="form-select form-select-sm" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold mb-1">Reason / Description (تفصیل / وجہ) <span class="text-danger">*</span></label>
                            <input type="text" name="reason" id="auditReasonInput" class="form-control form-control-sm" 
                                   value="Opening Stock Initialization" placeholder="e.g. Initial inventory count on software handover" required>
                        </div>

                        <div class="col-md-1 text-end">
                            <span class="badge bg-navy text-white font-monospace py-2 px-2" style="font-size: 11px;"><?= e($nextAdjNumber) ?></span>
                        </div>
                    </div>

                    <!-- Preset Explanation Banner -->
                    <div id="presetBanner" class="alert alert-info py-1 px-3 rounded small border-0 mb-0 mt-2 d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-info-circle-fill me-1 text-primary"></i>
                            <span id="presetBannerText">
                                <strong>اوپننگ سٹاک موڈ:</strong> آئٹمز پر کلک کر کے مقدار درج کریں۔ خریداری ریٹ خودبخود آ جائے گا اور کل مالیت کا حساب بن جائے گا تاکہ ڈبل چیک کر کے انیشیلائز کیا جا سکے۔
                            </span>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">Default: Add (Surplus / Inward)</span>
                    </div>
                </div>
            </div>

            <!-- Main Working Area: Split 5-Cols Product Catalog vs 7-Cols Adjustment Cart -->
            <div class="row g-3 mb-3">
                <!-- Left Column: Fast Product Search & Card Selection (Just like Sales Order Booking) -->
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

                                $costCtn = (float)($p['purchase_rate'] ?? 0);
                                $costPiece = (float)($p['purchase_piece_rate'] ?? 0) > 0 
                                    ? (float)$p['purchase_piece_rate'] 
                                    : ($pcsPerCtn > 0 ? round($costCtn / $pcsPerCtn, 2) : $costCtn);
                            ?>
                                <div class="pos-product-card <?= $isOutOfStock ? 'out-of-stock' : '' ?>" 
                                     id="card_prod_<?= $p['id'] ?>"
                                     data-id="<?= $p['id'] ?>"
                                     data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                     data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                     data-cat="<?= $p['category_id'] ?>"
                                     data-supplier-id="<?= (int)($p['supplier_id'] ?? 0) ?>"
                                     data-cost-ctn="<?= $costCtn ?>"
                                     data-cost-piece="<?= $costPiece ?>"
                                     data-carton-rate="<?= (float)$p['carton_rate'] ?>"
                                     data-piece-rate="<?= (float)$p['piece_rate'] ?>"
                                     data-packs-per-ctn="<?= $packsPerCtn ?>"
                                     data-pcs-per-pack="<?= $pcsPerPack ?>"
                                     data-pcs-per-ctn="<?= $pcsPerCtn ?>"
                                     onclick="addProductToAdjust(<?= $p['id'] ?>)">
                                    
                                    <span class="card-badge-cart d-none" id="badge_adj_<?= $p['id'] ?>">0 in list</span>
                                    <button type="button" class="btn-card-info" title="View Prices & Cost Rates" onclick="showProductPriceModal(event, <?= $p['id'] ?>)">
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
                                            <div class="prod-price" style="font-size: 12.5px; line-height: 1.2;">
                                                <span class="text-secondary small fw-normal">Cost:</span> <?= DEFAULT_CURRENCY ?> <?= number_format($costCtn, 0) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>
                                            </div>
                                            <div class="text-dark fw-semibold" style="font-size: 11px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format($costPiece, 2) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Pc</span>
                                            </div>
                                        </div>
                                        <div class="prod-stock text-end">
                                            <?= $isOutOfStock ? '<span class="text-danger fw-semibold">0 in stock</span>' : htmlspecialchars($stockBreakdown['formatted']) . ' avail' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive Stock Adjustment Table / Cart -->
                <div class="col-lg-7">
                    <div class="pos-cart-container">
                        <div class="pos-cart-header">
                            <span class="fw-bold text-navy">
                                <i class="bi bi-clipboard2-check-fill me-1 text-primary"></i> Stock Adjustment Items 
                                <span class="badge bg-primary ms-1" id="adjItemCount">0 items</span>
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <div class="btn-group btn-group-sm" role="group">
                                    <input type="radio" class="btn-check" name="global_action_default" id="defaultAdd" autocomplete="off" checked onchange="onDefaultActionChange('add')">
                                    <label class="btn btn-outline-success py-0 px-2 fw-semibold" for="defaultAdd" style="font-size:11px;" title="Set items to Add / Inward">+ Add (Surplus/Opening)</label>

                                    <input type="radio" class="btn-check" name="global_action_default" id="defaultSub" autocomplete="off" onchange="onDefaultActionChange('subtract')">
                                    <label class="btn btn-outline-danger py-0 px-2 fw-semibold" for="defaultSub" style="font-size:11px;" title="Set items to Subtract / Loss">- Subtract (Loss)</label>
                                </div>
                                <button type="button" class="btn btn-xs btn-outline-danger" onclick="clearAdjustmentList()" id="btnClearList" style="display:none;">
                                    <i class="bi bi-trash me-1"></i> Clear List
                                </button>
                            </div>
                        </div>

                        <!-- Table Wrapper -->
                        <div class="pos-cart-table-wrapper" style="max-height: 460px;">
                            <table class="table pos-cart-table table-hover align-middle mb-0" id="adjTable">
                                <thead>
                                    <tr>
                                        <th style="width: 26%;">Product Details</th>
                                        <th style="width: 14%;" class="text-center">Action / Type</th>
                                        <th style="width: 11%;" class="text-center">Condition</th>
                                        <th style="width: 10%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 8%;" class="text-center">Packs</th>
                                        <th style="width: 8%;" class="text-center">Pcs</th>
                                        <th style="width: 10%;" class="text-end">Cost / Rate</th>
                                        <th style="width: 13%;" class="text-end">Total Valuation</th>
                                        <th style="width: 30px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="adjItemsBody">
                                    <tr id="emptyAdjRow">
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-hand-index-thumb fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                            <div class="fw-semibold">No products added to adjustment list yet.</div>
                                            <small>Click on product cards on the left or search to add stock for audit or opening initialization.</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Real-time Verification & Totals Footer -->
                        <div class="pos-cart-footer bg-light border-top p-3">
                            <div class="row g-2 align-items-center mb-3">
                                <div class="col-md-3 text-center border-end">
                                    <div class="text-muted small">Total Products</div>
                                    <div class="fw-bold fs-6 text-dark" id="summaryTotalProducts">0 Items</div>
                                </div>
                                <div class="col-md-3 text-center border-end">
                                    <div class="text-muted small">Total Cartons</div>
                                    <div class="fw-bold fs-6 text-dark" id="summaryTotalCartons">0.00 Ctns</div>
                                </div>
                                <div class="col-md-3 text-center border-end">
                                    <div class="text-muted small">Total Base Units</div>
                                    <div class="fw-bold fs-6 text-dark" id="summaryTotalUnits">0 Units</div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="text-muted small">Total Inventory Valuation</div>
                                    <div class="fw-bold fs-5 text-navy" id="summaryTotalValuation">Rs. 0.00</div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="small text-muted">
                                    <i class="bi bi-check-all text-success me-1"></i>
                                    <span>برائے مہربانی کل مالیت (Valuation) اور مقدار ڈبل چیک کر کے بٹن دبائیں تاکہ انیشیالائزیشن مکمل ہو۔</span>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-warning text-dark px-4 fw-bold shadow-sm" id="btnSubmitAdjustment">
                                        <i class="bi bi-check2-circle me-1"></i> Apply Physical Adjustment (سٹاک محفوظ کریں)
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

<!-- Product Prices & Rates Quick Modal -->
<div class="modal fade" id="productPriceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light">
                <h6 class="modal-title fw-bold text-navy" id="priceModalTitle">Product Rates</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <table class="table table-sm table-borderless mb-0" style="font-size: 13px;">
                    <tr>
                        <td class="text-muted">Purchase Rate (خرید):</td>
                        <td class="text-end fw-bold text-danger" id="priceModalCost">-</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Wholesale Rate (ہول سیل):</td>
                        <td class="text-end fw-bold text-primary" id="priceModalWholesale">-</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Carton Rate (سیلز):</td>
                        <td class="text-end fw-bold text-navy" id="priceModalCarton">-</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Piece Rate (پرچون):</td>
                        <td class="text-end fw-bold text-dark" id="priceModalPiece">-</td>
                    </tr>
                    <tr class="border-top">
                        <td class="text-muted pt-2">Packaging:</td>
                        <td class="text-end fw-semibold pt-2" id="priceModalPacking">-</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Embedded Product Cache -->
<script>
    const DMS_PRODUCTS = <?= json_encode($products) ?>;
    const DEFAULT_CURRENCY = '<?= DEFAULT_CURRENCY ?>';
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const productSearch  = document.getElementById('productSearch');
    const companyFilter  = document.getElementById('companyFilter');
    const auditPreset    = document.getElementById('auditPresetSelect');
    const reasonInput    = document.getElementById('auditReasonInput');
    const presetBannerText = document.getElementById('presetBannerText');
    const productCardsGrid = document.getElementById('productCardsGrid');
    
    // In-memory Adjustment List: { [productId]: itemObj }
    window.adjItems = {};
    window.defaultAction = 'add';
    let selectedCardIndex = -1;

    // Handle Preset Switch
    window.onPresetChange = function () {
        const val = auditPreset.value;
        const addRadio = document.getElementById('defaultAdd');
        const subRadio = document.getElementById('defaultSub');

        if (val === 'opening_stock') {
            reasonInput.value = 'Opening Stock Initialization';
            presetBannerText.innerHTML = '<strong>اوپننگ سٹاک موڈ:</strong> آئٹمز پر کلک کر کے مقدار درج کریں۔ خریداری ریٹ خودبخود آ جائے گا اور کل مالیت کا حساب بن جائے گا تاکہ ڈبل چیک کر کے انیشیلائز کیا جا سکے۔';
            addRadio.checked = true;
            window.defaultAction = 'add';
        } else if (val === 'physical_audit') {
            reasonInput.value = 'Periodic Physical Stock Audit Variance';
            presetBannerText.innerHTML = '<strong>فزیکل آڈٹ موڈ:</strong> گودام کی فزیکل گنتی میں پائے جانے والے سرپلس (+) یا شارٹیج (-) کا اندراج کریں تاکہ سسٹم سٹاک اصل فزیکل سٹاک کے مطابق ہو جائے۔';
            addRadio.checked = true;
            window.defaultAction = 'add';
        } else if (val === 'breakage_damage') {
            reasonInput.value = 'Warehouse Breakage & Transit Damage Write-off';
            presetBannerText.innerHTML = '<strong>ٹوٹ پھوٹ نقصان موڈ:</strong> گودام یا دوران نقل و حمل ضائع شدہ مال کی کٹوتی (-) درج کریں۔ تمام آئٹمز بذریعہ Subtract منفی ہوں گے۔';
            subRadio.checked = true;
            window.defaultAction = 'subtract';
        } else if (val === 'expired_goods') {
            reasonInput.value = 'Expired & Spoiled Stock Write-off';
            presetBannerText.innerHTML = '<strong>ایکسپائرڈ مال کا اخراج:</strong> ایکسپائر شدہ مال کو سسٹم سے رائٹ آف (-) کریں۔ تمام آئٹمز بذریعہ Subtract منفی ہوں گے۔';
            subRadio.checked = true;
            window.defaultAction = 'subtract';
        } else {
            reasonInput.value = 'Manual Stock Adjustment';
            presetBannerText.innerHTML = '<strong>کسٹم ایڈجسٹمنٹ موڈ:</strong> ضرورت کے مطابق مقدار، لاگت اور نوعیت درج کر کے سٹاک ایڈجسٹ کریں۔';
        }
    };

    window.onDefaultActionChange = function (act) {
        window.defaultAction = act;
    };

    // Filter Product Cards
    function getVisibleCards() {
        return Array.from(document.querySelectorAll('.pos-product-card:not(.d-none)'));
    }

    function highlightCard(index) {
        const cards = getVisibleCards();
        cards.forEach(c => c.classList.remove('active-selected'));
        if (index >= 0 && index < cards.length) {
            cards[index].classList.add('active-selected');
            cards[index].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            selectedCardIndex = index;
        } else {
            selectedCardIndex = -1;
        }
    }

    function filterProductCards() {
        const query = (productSearch.value || '').trim().toLowerCase();
        const activePill = document.querySelector('.pos-category-pill.active');
        const selectedCat = activePill ? activePill.getAttribute('data-cat') : '';
        const selectedSup = companyFilter ? companyFilter.value : '';

        document.querySelectorAll('.pos-product-card').forEach(card => {
            const name = (card.getAttribute('data-name') || '').toLowerCase();
            const code = (card.getAttribute('data-code') || '').toLowerCase();
            const cat  = card.getAttribute('data-cat') || '';
            const sup  = card.getAttribute('data-supplier-id') || '';

            const matchesText = !query || name.includes(query) || code.includes(query);
            const matchesCat  = !selectedCat || cat === selectedCat;
            const matchesSup  = !selectedSup || sup === selectedSup;

            if (matchesText && matchesCat && matchesSup) {
                card.classList.remove('d-none');
            } else {
                card.classList.add('d-none');
            }
        });

        const visible = getVisibleCards();
        if (visible.length > 0) {
            highlightCard(0);
        } else {
            highlightCard(-1);
        }
    }

    productSearch.addEventListener('input', filterProductCards);
    if (companyFilter) {
        companyFilter.addEventListener('change', filterProductCards);
    }

    document.querySelectorAll('.pos-category-pill').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.pos-category-pill').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterProductCards();
        });
    });

    // Keyboard Shortcuts
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
                const prodId = parseInt(visibleCards[selectedCardIndex].getAttribute('data-id'), 10);
                if (prodId) addProductToAdjust(prodId);
            } else if (visibleCards.length > 0) {
                const prodId = parseInt(visibleCards[0].getAttribute('data-id'), 10);
                if (prodId) addProductToAdjust(prodId);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            productSearch.value = '';
            filterProductCards();
        }
    });

    document.addEventListener('keydown', function(e) {
        const tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isInput = tag === 'input' || tag === 'textarea' || tag === 'select';

        if (e.key === '/' || e.key === 'F2') {
            if (document.activeElement !== productSearch) {
                e.preventDefault();
                productSearch.focus();
                productSearch.select();
            }
        }
    });

    // Add Product to Adjustment Table
    window.addProductToAdjust = function (productId) {
        const prod = DMS_PRODUCTS.find(p => parseInt(p.id, 10) === parseInt(productId, 10));
        if (!prod) return;

        const defaultCond = auditPreset.value === 'breakage_damage' ? 'damaged' : (auditPreset.value === 'expired_goods' ? 'expired' : 'saleable');

        if (!adjItems[productId]) {
            const pcsPerCtn = parseFloat(prod.carton_qty || 1) * parseFloat(prod.pack_qty || 1);
            const costCtn = parseFloat(prod.purchase_rate || 0);
            const costPc  = parseFloat(prod.purchase_piece_rate || 0) > 0 
                ? parseFloat(prod.purchase_piece_rate) 
                : (pcsPerCtn > 0 ? (costCtn / pcsPerCtn) : costCtn);

            adjItems[productId] = {
                product_id: prod.id,
                product_name: prod.product_name,
                product_code: prod.product_code,
                carton_qty: 1,
                pack_qty: 0,
                pcs_qty: 0,
                adjustment_type: window.defaultAction,
                condition_type: defaultCond,
                cost_piece: costPc,
                cost_carton: costCtn,
                remarks: '',
                ratios: {
                    packs_per_ctn: parseFloat(prod.carton_qty || 1),
                    pcs_per_pack: parseFloat(prod.pack_qty || 1),
                    pcs_per_ctn: pcsPerCtn
                },
                saleable_qty: parseFloat(prod.saleable_qty || 0)
            };
            renderRow(adjItems[productId]);
        } else {
            // Already in list: increment carton quantity and focus input
            adjItems[productId].carton_qty += 1;
            updateRowInputs(productId);
        }

        updateCardBadge(productId);
        recalculateTotals();

        // Focus carton input of the added item
        setTimeout(() => {
            const input = document.getElementById(`adj_ctn_${productId}`);
            if (input) {
                input.focus();
                input.select();
            }
        }, 50);
    };

    function updateCardBadge(productId) {
        const card  = document.getElementById('card_prod_' + productId);
        const badge = document.getElementById('badge_adj_' + productId);
        const item  = adjItems[productId];

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
                badge.innerText = '0 in list';
            }
        }
    }

    function calculateItemUnits(item) {
        const ctn  = parseFloat(item.carton_qty) || 0;
        const pack = parseFloat(item.pack_qty) || 0;
        const pcs  = parseFloat(item.pcs_qty) || 0;
        const r = item.ratios;
        return Math.round((ctn * r.packs_per_ctn * r.pcs_per_pack) + (pack * r.pcs_per_pack) + pcs);
    }

    function calculateItemValuation(item) {
        const units = calculateItemUnits(item);
        return units * item.cost_piece;
    }

    function renderRow(item) {
        const emptyRow = document.getElementById('emptyAdjRow');
        if (emptyRow) emptyRow.style.display = 'none';
        document.getElementById('btnClearList').style.display = '';

        const tbody = document.getElementById('adjItemsBody');
        const pid = item.product_id;

        const totalUnits = calculateItemUnits(item);
        const lineVal = calculateItemValuation(item);

        const tr = document.createElement('tr');
        tr.id = `adj_row_${pid}`;
        tr.className = 'pos-cart-row';

        tr.innerHTML = `
            <td>
                <input type="hidden" name="items[${pid}][product_id]" value="${pid}">
                <div class="fw-bold text-navy" style="font-size: 13px;">${escapeHtml(item.product_name)}</div>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <span class="badge bg-light text-secondary border font-monospace" style="font-size: 10px;">${escapeHtml(item.product_code)}</span>
                    <span class="small text-muted" style="font-size: 11px;">1 Ctn = ${item.ratios.pcs_per_ctn} Pcs</span>
                    <span class="badge ${item.saleable_qty > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'} border" style="font-size: 10px;">
                        Current: ${item.saleable_qty} pcs
                    </span>
                </div>
            </td>
            <td>
                <select name="items[${pid}][adjustment_type]" class="form-select form-select-sm fw-bold ${item.adjustment_type === 'add' ? 'text-success' : 'text-danger'}" 
                        onchange="onRowTypeChange(${pid}, this)">
                    <option value="add" ${item.adjustment_type === 'add' ? 'selected' : ''} class="text-success">+ Add (Surplus/Opening)</option>
                    <option value="subtract" ${item.adjustment_type === 'subtract' ? 'selected' : ''} class="text-danger">- Subtract (Loss/Damage)</option>
                </select>
            </td>
            <td>
                <select name="items[${pid}][condition_type]" class="form-select form-select-sm" onchange="onRowConditionChange(${pid}, this)">
                    <option value="saleable" ${item.condition_type === 'saleable' ? 'selected' : ''}>Saleable (صحیح)</option>
                    <option value="damaged" ${item.condition_type === 'damaged' ? 'selected' : ''}>Damaged (ڈیمیج)</option>
                    <option value="expired" ${item.condition_type === 'expired' ? 'selected' : ''}>Expired (ایکسپائر)</option>
                </select>
            </td>
            <td>
                <input type="number" step="1" min="0" name="items[${pid}][carton_qty]" id="adj_ctn_${pid}" 
                       class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                       value="${item.carton_qty}" placeholder="0" oninput="onQtyChange(${pid})">
            </td>
            <td>
                <input type="number" step="1" min="0" name="items[${pid}][pack_qty]" id="adj_pack_${pid}" 
                       class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                       value="${item.pack_qty}" placeholder="0" oninput="onQtyChange(${pid})">
            </td>
            <td>
                <input type="number" step="1" min="0" name="items[${pid}][pcs_qty]" id="adj_pcs_${pid}" 
                       class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                       value="${item.pcs_qty}" placeholder="0" oninput="onQtyChange(${pid})">
                <input type="hidden" name="items[${pid}][quantity]" id="adj_units_${pid}" value="${totalUnits}">
            </td>
            <td class="text-end">
                <div class="input-group input-group-sm">
                    <span class="input-group-text py-0 px-1" style="font-size: 10px;">Rs</span>
                    <input type="number" step="0.01" min="0" name="items[${pid}][unit_cost]" id="adj_cost_${pid}" 
                           class="form-control form-control-sm text-end fw-semibold" 
                           value="${item.cost_piece.toFixed(2)}" title="Purchase Piece Cost" oninput="onCostChange(${pid})">
                </div>
                <div class="text-muted mt-1" style="font-size: 10px;" id="adj_lbl_cost_${pid}">
                    Rs. ${(item.cost_piece * item.ratios.pcs_per_ctn).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})} / Ctn
                </div>
            </td>
            <td class="text-end fw-bold text-navy" style="font-size: 13px;" id="adj_line_val_${pid}">
                Rs. ${lineVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                <div class="text-muted fw-normal" style="font-size: 10px;" id="adj_units_label_${pid}">${totalUnits} Pcs</div>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-xs btn-outline-danger" onclick="removeAdjustItem(${pid})" title="Remove item">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        `;

        tbody.appendChild(tr);
    }

    function updateRowInputs(pid) {
        const item = adjItems[pid];
        if (!item) return;

        const ctnInput  = document.getElementById(`adj_ctn_${pid}`);
        const packInput = document.getElementById(`adj_pack_${pid}`);
        const pcsInput  = document.getElementById(`adj_pcs_${pid}`);
        const unitsInput = document.getElementById(`adj_units_${pid}`);
        const lineValEl  = document.getElementById(`adj_line_val_${pid}`);
        const unitsLbl   = document.getElementById(`adj_units_label_${pid}`);

        if (ctnInput)  ctnInput.value = item.carton_qty;
        if (packInput) packInput.value = item.pack_qty;
        if (pcsInput)  pcsInput.value = item.pcs_qty;

        const totalUnits = calculateItemUnits(item);
        const lineVal = calculateItemValuation(item);

        if (unitsInput) unitsInput.value = totalUnits;
        if (lineValEl)  lineValEl.innerHTML = `Rs. ${lineVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}<div class="text-muted fw-normal" style="font-size: 10px;">${totalUnits} Pcs</div>`;
    }

    window.onQtyChange = function (pid) {
        const item = adjItems[pid];
        if (!item) return;

        const ctnInput  = document.getElementById(`adj_ctn_${pid}`);
        const packInput = document.getElementById(`adj_pack_${pid}`);
        const pcsInput  = document.getElementById(`adj_pcs_${pid}`);

        item.carton_qty = parseFloat(ctnInput.value) || 0;
        item.pack_qty   = parseFloat(packInput.value) || 0;
        item.pcs_qty    = parseFloat(pcsInput.value) || 0;

        updateRowInputs(pid);
        updateCardBadge(pid);
        recalculateTotals();
    };

    window.onCostChange = function (pid) {
        const item = adjItems[pid];
        if (!item) return;

        const costInput = document.getElementById(`adj_cost_${pid}`);
        const lblCost   = document.getElementById(`adj_lbl_cost_${pid}`);

        item.cost_piece = parseFloat(costInput.value) || 0;
        if (lblCost) {
            lblCost.innerText = `Rs. ${(item.cost_piece * item.ratios.pcs_per_ctn).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})} / Ctn`;
        }

        updateRowInputs(pid);
        recalculateTotals();
    };

    window.onRowTypeChange = function (pid, select) {
        const item = adjItems[pid];
        if (!item) return;
        item.adjustment_type = select.value;
        if (select.value === 'add') {
            select.classList.remove('text-danger');
            select.classList.add('text-success');
        } else {
            select.classList.remove('text-success');
            select.classList.add('text-danger');
        }
        recalculateTotals();
    };

    window.onRowConditionChange = function (pid, select) {
        const item = adjItems[pid];
        if (!item) return;
        item.condition_type = select.value;
    };

    window.removeAdjustItem = function (pid) {
        delete adjItems[pid];
        const row = document.getElementById(`adj_row_${pid}`);
        if (row) row.remove();

        updateCardBadge(pid);

        if (Object.keys(adjItems).length === 0) {
            const emptyRow = document.getElementById('emptyAdjRow');
            if (emptyRow) emptyRow.style.display = '';
            document.getElementById('btnClearList').style.display = 'none';
        }

        recalculateTotals();
    };

    window.clearAdjustmentList = function () {
        if (confirm('Clear all items from the adjustment list?')) {
            adjItems = {};
            document.querySelectorAll('#adjItemsBody tr:not(#emptyAdjRow)').forEach(tr => tr.remove());
            const emptyRow = document.getElementById('emptyAdjRow');
            if (emptyRow) emptyRow.style.display = '';
            document.getElementById('btnClearList').style.display = 'none';

            document.querySelectorAll('.pos-product-card').forEach(card => card.classList.remove('in-cart'));
            document.querySelectorAll('.card-badge-cart').forEach(badge => {
                badge.classList.add('d-none');
                badge.innerText = '0 in list';
            });

            recalculateTotals();
        }
    };

    function recalculateTotals() {
        let totalProducts = 0;
        let totalCartons = 0;
        let totalUnits = 0;
        let totalValuation = 0;

        Object.values(adjItems).forEach(item => {
            totalProducts++;
            totalCartons += parseFloat(item.carton_qty) || 0;
            const u = calculateItemUnits(item);
            totalUnits += u;
            totalValuation += (u * item.cost_piece);
        });

        document.getElementById('adjItemCount').innerText = `${totalProducts} items`;
        document.getElementById('summaryTotalProducts').innerText = `${totalProducts} Items`;
        document.getElementById('summaryTotalCartons').innerText = `${totalCartons.toFixed(2)} Ctns`;
        document.getElementById('summaryTotalUnits').innerText = `${totalUnits.toLocaleString('en-US')} Units`;
        document.getElementById('summaryTotalValuation').innerText = `Rs. ${totalValuation.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Modal Rates Viewer
    window.showProductPriceModal = function (e, productId) {
        e.stopPropagation();
        const p = DMS_PRODUCTS.find(item => parseInt(item.id, 10) === parseInt(productId, 10));
        if (!p) return;

        document.getElementById('priceModalTitle').innerText = p.product_name;
        document.getElementById('priceModalCost').innerText = `Rs. ${parseFloat(p.purchase_rate || 0).toLocaleString()} / Ctn (Rs. ${parseFloat(p.purchase_piece_rate || 0).toFixed(2)} / Pc)`;
        document.getElementById('priceModalWholesale').innerText = `Rs. ${parseFloat(p.wholesale_rate || 0).toLocaleString()} / Ctn`;
        document.getElementById('priceModalCarton').innerText = `Rs. ${parseFloat(p.carton_rate || 0).toLocaleString()} / Ctn`;
        document.getElementById('priceModalPiece').innerText = `Rs. ${parseFloat(p.piece_rate || 0).toFixed(2)} / Pc`;
        document.getElementById('priceModalPacking').innerText = `${p.carton_qty} Pck x ${p.pack_qty} Pcs (${(parseFloat(p.carton_qty||1)*parseFloat(p.pack_qty||1))} units/ctn)`;

        const modal = new bootstrap.Modal(document.getElementById('productPriceModal'));
        modal.show();
    };

    // Auto-open product if preProductId passed in GET
    <?php if ($preProductId > 0): ?>
        addProductToAdjust(<?= $preProductId ?>);
    <?php endif; ?>

    // Initialize preset on load
    onPresetChange();
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
