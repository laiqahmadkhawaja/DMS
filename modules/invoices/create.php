<?php
/**
 * DMS - Distribution Management System
 * High-Speed Card-Driven Direct Sales Invoicing
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('invoices_create');

$pageTitle = 'Create Direct Sales Invoice';
$activeMenu = 'invoices';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Invoices', 'url' => 'index.php'],
    ['label' => 'New Invoice', 'url' => '']
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoiceDate = sanitize_input($_POST['invoice_date'] ?? date('Y-m-d'));
    $customerId  = (int)($_POST['customer_id'] ?? 0);
    $bookerId    = (int)($_POST['booker_id'] ?? 0);
    $salesmanId  = !empty($_POST['salesman_id']) ? (int)$_POST['salesman_id'] : null;
    $areaId      = (int)($_POST['area_id'] ?? 0);
    $paymentType = sanitize_input($_POST['payment_type'] ?? 'credit'); // 'cash' or 'credit'
    $depositAccountId = (int)($_POST['deposit_account_id'] ?? 1001);
    if ($depositAccountId <= 0) {
        $depositAccountId = 1001;
    }
    $remarks     = sanitize_input($_POST['remarks'] ?? '');
    $itemsData   = $_POST['items'] ?? [];

    if ($customerId <= 0) {
        $errors[] = "Please select a customer / retail shop.";
    }
    if ($bookerId <= 0) {
        $errors[] = "Please select an order booker.";
    }
    if ($areaId <= 0) {
        $errors[] = "Please select a delivery route / area.";
    }
    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "Please add at least one product to the invoice cart.";
    }

    $discountMode = sanitize_input($_POST['discount_mode'] ?? 'item_rate');
    if ($discountMode !== 'subtotal') {
        $discountMode = 'item_rate';
    }
    $freeLoosePieces = !empty($_POST['free_loose_pieces']) ? 1 : 0;
    $subtotalDiscountPercent = (float)($_POST['subtotal_discount_percent'] ?? 0);
    $subtotalDiscountAmount  = (float)($_POST['subtotal_discount_amount'] ?? 0);

    $validItems = [];
    $grossTotal = 0.0;
    $discountTotal = 0.0;
    $netTotal = 0.0;

    foreach ($itemsData as $idx => $line) {
        $productId = (int)($line['product_id'] ?? 0);
        $ctnQty    = (float)($line['carton_qty'] ?? 0);
        $packQty   = (float)($line['pack_qty'] ?? 0);
        $pcsQty    = (float)($line['pcs_qty'] ?? 0);
        $unitCode  = strtolower(trim((string)($line['unit'] ?? 'ctn')));
        $rate      = (float)($line['rate'] ?? 0);
        $discPct   = (float)($line['discount_percent'] ?? 0);

        if ($productId <= 0 || ($ctnQty <= 0 && $packQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
        if (!$prod) {
            continue;
        }

        $ratios = get_product_packaging_ratios($prod);
        $packsPerCtn = $ratios['packs_per_ctn'];
        $pcsPerPack  = $ratios['pcs_per_pack'];
        $pcsPerCtn   = $ratios['pcs_per_ctn'];

        $totalBaseUnits = calculate_base_pieces($ctnQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
        $baseCartons = calculate_carton_equivalent($ctnQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);

        // If Free Loose Pieces scheme is active, loose pieces rate is not charged
        $billablePcs = $freeLoosePieces ? 0 : $pcsQty;
        $billableBasePieces = calculate_base_pieces($ctnQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);
        $billableCartons    = calculate_carton_equivalent($ctnQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);

        if ($unitCode === 'pcs') {
            $lineGross = $billableBasePieces * $rate;
            $unitId = 4; // Piece unit
        } else {
            $lineGross = $billableCartons * $rate;
            $unitId = (int)$prod['base_unit_id']; // Carton unit
        }

        if ($discountMode === 'subtotal') {
            $itemDiscPct = 0.0;
            $lineDisc = 0.0;
            $lineNet = $lineGross;
        } else {
            $itemDiscPct = $discPct;
            $lineDisc = ($lineGross * $itemDiscPct) / 100.0;
            $lineNet = $lineGross - $lineDisc;
            $discountTotal += $lineDisc;
        }

        $grossTotal += $lineGross;

        $validItems[] = [
            'product_id'       => $productId,
            'unit_id'          => $unitId,
            'carton_qty'       => $ctnQty,
            'pack_qty'         => $packQty,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $totalBaseUnits,
            'rate'             => $rate,
            'discount_percent' => $itemDiscPct,
            'discount_amount'  => $lineDisc,
            'line_total'       => $lineNet
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line with quantity greater than zero.";
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
            $itemDiscount = $discountTotal;
            $extraDiscount = 0.0;
            $subtotalDiscountPercent = 0.0;
            $subtotalDiscountAmount = 0.0;
        }

        $netTotal = max(0, round($grossTotal - ($itemDiscount + $extraDiscount), 2));

        try {
            db()->beginTransaction();
            $userId = current_user_id();

            // 1. Generate Invoice Number
            $invoiceNumber = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');

            // 2. Insert Invoice Record
            $paymentStatus = ($paymentType === 'cash') ? 'paid' : 'unpaid';
            $paidAmount    = ($paymentType === 'cash') ? $netTotal : 0.00;

            $stmtInv = db()->prepare("
                INSERT INTO sales_invoices (
                    invoice_number, invoice_date, customer_id, booker_id, salesman_id, area_id,
                    discount_mode, free_loose_pieces, gross_amount, item_discount_amount, extra_discount_amount, subtotal_discount_percent,
                    net_amount, paid_amount, status, reconciliation_status, payment_status,
                    remarks, created_by, finalized_at, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, 'finalized', 'pending', ?,
                    ?, ?, NOW(), NOW()
                )
            ");
            $stmtInv->execute([
                $invoiceNumber, $invoiceDate, $customerId, $bookerId, $salesmanId, $areaId,
                $discountMode, $freeLoosePieces, $grossTotal, $itemDiscount, $extraDiscount, $subtotalDiscountPercent,
                $netTotal, $paidAmount, $paymentStatus,
                $remarks, $userId
            ]);
            $invoiceId = (int)db()->lastInsertId();

            // 3. Insert Invoice Items & Record Immediate Stock Deductions
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
                    $invoiceId, $vi['product_id'], $vi['unit_id'],
                    $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['quantity'], $vi['rate'], $vi['discount_percent'],
                    $vi['discount_amount'], $vi['line_total']
                ]);

                // Record stock movement (Direct spot sale dispatch)
                $qtyOut = (float)$vi['quantity'];
                $lineTot = (float)$vi['line_total'];
                $pieceCost = $qtyOut > 0 ? round($lineTot / $qtyOut, 6) : 0;
                record_stock_movement([
                    'product_id'     => $vi['product_id'],
                    'warehouse_id'   => 1, // Default Central Warehouse
                    'movement_type'  => 'sales_dispatch',
                    'reference_type' => 'invoice',
                    'reference_id'   => $invoiceId,
                    'qty_in'         => 0,
                    'qty_out'        => $qtyOut,
                    'unit_cost'      => $pieceCost,
                    'total_cost'     => $lineTot,
                    'remarks'        => "Stock dispatch for Direct Invoice #{$invoiceNumber}",
                    'condition_type' => 'saleable'
                ]);
            }

            // 4. Update Financial Balances & General Ledger
            if ($paymentType === 'credit') {
                // Customer ledger balance increases
                update_customer_balance($customerId, $netTotal, 'add');

                // General Ledger: Debit Accounts Receivable (1020), Credit Sales Revenue (4010)
                $glLines = [
                    [
                        'account_id'  => 1020, // Accounts Receivable
                        'debit'       => $netTotal,
                        'credit'      => 0,
                        'party_type'  => 'customer',
                        'party_id'    => $customerId,
                        'description' => "Credit sale invoice #{$invoiceNumber}"
                    ],
                    [
                        'account_id'  => 4010, // Sales Revenue
                        'debit'       => 0,
                        'credit'      => $netTotal,
                        'party_type'  => null,
                        'party_id'    => null,
                        'description' => "Gross sales invoice #{$invoiceNumber}"
                    ]
                ];
                post_journal_entry([
                    'entry_date'     => $invoiceDate,
                    'reference_type' => 'invoice',
                    'reference_id'   => $invoiceId,
                    'description'    => "Direct Credit Sale Invoice #{$invoiceNumber}",
                    'created_by'     => $userId
                ], $glLines);

            } else {
                // Spot payment sale: Debit Chosen Liquid Account (Cash, Bank, Wallet), Credit Sales Revenue (4010)
                $glLines = [
                    [
                        'account_id'  => $depositAccountId,
                        'debit'       => $netTotal,
                        'credit'      => 0,
                        'description' => "Spot payment received for invoice #{$invoiceNumber}"
                    ],
                    [
                        'account_id'  => 4010, // Sales Revenue
                        'debit'       => 0,
                        'credit'      => $netTotal,
                        'description' => "Direct spot sale #{$invoiceNumber}"
                    ]
                ];
                post_journal_entry([
                    'entry_date'     => $invoiceDate,
                    'reference_type' => 'sales_invoice',
                    'reference_id'   => $invoiceId,
                    'narration'      => "Direct Spot Sale Invoice #{$invoiceNumber}"
                ], $glLines);
            }

            if (function_exists('audit_log')) {
                audit_log('create_invoice', 'invoices', (string)$invoiceId, "Generated direct invoice #{$invoiceNumber} ({$paymentType}) for " . format_currency($netTotal));
            }

            db()->commit();

            set_flash('success', "Sales invoice <strong>{$invoiceNumber}</strong> successfully created and finalized!");
            redirect(APP_URL . '/modules/invoices/view.php?id=' . $invoiceId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error creating direct invoice: " . $e->getMessage());
            $errors[] = "Error saving invoice: " . $e->getMessage();
        }
    }
}

// Master lists
$preselectedCustomerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$customers  = db_fetch_all("SELECT id, customer_code, shop_name, address, area_id, booker_id, current_balance, credit_limit FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");
$areas      = db_fetch_all("SELECT id, area_name FROM areas WHERE is_active = 1 ORDER BY area_name ASC");
$bookers    = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_active = 1 ORDER BY booker_name ASC");
$salesmen   = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");
$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_active = 1 ORDER BY category_name ASC");
$suppliers  = db_fetch_all("SELECT id, supplier_name, company_name FROM suppliers WHERE is_deleted = 0 AND is_active = 1 ORDER BY supplier_name ASC");
$liquidAccounts = db_fetch_all("
    SELECT id, account_code, account_name, sub_type, bank_name, account_number, current_balance 
    FROM accounts 
    WHERE sub_type IN ('cash', 'bank', 'wallet') AND is_active = 1 
    ORDER BY 
        CASE sub_type 
            WHEN 'cash' THEN 1 
            WHEN 'bank' THEN 2 
            WHEN 'wallet' THEN 3 
            ELSE 4 
        END,
        account_code ASC
");

$products   = db_fetch_all("
    SELECT p.id, p.product_code, p.barcode, p.product_name, p.category_id, p.supplier_id, p.brand_id,
           cat.category_name, b.brand_name,
           p.purchase_rate, p.purchase_carton_rate, p.purchase_piece_rate, p.default_sale_rate, p.wholesale_rate, p.carton_rate, p.piece_rate, p.retail_rate,
           p.carton_qty, p.pack_qty, p.packing_description, p.base_unit_id,
           COALESCE(sb.saleable_qty, 0) as saleable_qty
    FROM products p
    LEFT JOIN product_categories cat ON cat.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id AND b.is_deleted = 0
    LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = 1
    WHERE p.is_active = 1 AND p.is_deleted = 0 
    ORDER BY p.product_name ASC
");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <h5 class="mb-0 text-navy fw-bold">
                    <i class="bi bi-file-earmark-plus me-2 text-primary"></i>Create Direct Sales Invoice (Spot Billing)
                </h5>
                <small class="text-muted">Fast card selection: Click products to add to cart, edit quantities or rates instantly</small>
            </div>
            <div>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Invoices (ESC)
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-3">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Form Validation Failed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="create.php" id="invoiceForm" autocomplete="off">
            <!-- Header Card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1 d-flex justify-content-between align-items-center">
                                <span>Customer / Retail Shop <span class="text-danger">*</span></span>
                                <?php if (has_permission('customers_create')): ?>
                                <a href="javascript:void(0)" onclick="openQuickCustomerDrawer('customerSelect')" class="text-primary text-decoration-none" title="Quick Add Customer">
                                    <i class="bi bi-plus-circle-fill"></i> New
                                </a>
                                <?php endif; ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <select name="customer_id" id="customerSelect" class="form-select form-select-sm" required autofocus>
                                    <option value="">-- Select Retail Customer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($preselectedCustomerId === (int)$c['id']) ? 'selected' : '' ?> 
                                                data-area="<?= $c['area_id'] ?>" 
                                                data-booker="<?= $c['booker_id'] ?>"
                                                data-balance="<?= $c['current_balance'] ?>"
                                                data-limit="<?= $c['credit_limit'] ?>">
                                            <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['customer_code']) ?>)
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
                            <label class="form-label small fw-bold mb-1">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">Order Booker <span class="text-danger">*</span></label>
                            <select name="booker_id" id="bookerSelect" class="form-select form-select-sm" required>
                                <option value="">-- Select Booker --</option>
                                <?php foreach ($bookers as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['booker_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1 d-flex justify-content-between align-items-center">
                                <span>Delivery Route <span class="text-danger">*</span></span>
                                <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                                <a href="javascript:void(0)" onclick="openQuickAreaDrawer('areaSelect')" class="text-primary text-decoration-none" title="Quick Add Route">
                                    <i class="bi bi-plus-circle-fill"></i> New
                                </a>
                                <?php endif; ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <select name="area_id" id="areaSelect" class="form-select form-select-sm" required>
                                    <option value="">-- Select Route --</option>
                                    <?php foreach ($areas as $a): ?>
                                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['area_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                                <button type="button" class="btn btn-outline-primary" onclick="openQuickAreaDrawer('areaSelect')" title="Add New Area in Real-Time">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Payment Method & Account <span class="text-danger">*</span></label>
                            <div class="d-flex gap-1">
                                <select name="payment_type" id="paymentTypeSelect" class="form-select form-select-sm fw-bold" style="max-width: 120px;">
                                    <option value="credit">Credit Sale</option>
                                    <option value="cash">Spot Paid</option>
                                </select>
                                <div id="depositAccWrapper" style="display: none; flex-grow: 1;">
                                    <?php
                                        $groupedLiq = ['cash' => [], 'bank' => [], 'wallet' => []];
                                        foreach ($liquidAccounts as $la) {
                                            $groupedLiq[$la['sub_type']][] = $la;
                                        }
                                    ?>
                                    <select name="deposit_account_id" class="form-select form-select-sm">
                                        <?php if (!empty($groupedLiq['cash'])): ?>
                                            <optgroup label="💵 Cash">
                                                <?php foreach ($groupedLiq['cash'] as $la): ?>
                                                    <option value="<?= $la['id'] ?>"><?= htmlspecialchars($la['account_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                        <?php if (!empty($groupedLiq['bank'])): ?>
                                            <optgroup label="🏦 Bank">
                                                <?php foreach ($groupedLiq['bank'] as $la): ?>
                                                    <option value="<?= $la['id'] ?>"><?= htmlspecialchars($la['account_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                        <?php if (!empty($groupedLiq['wallet'])): ?>
                                            <optgroup label="📱 Wallets">
                                                <?php foreach ($groupedLiq['wallet'] as $la): ?>
                                                    <option value="<?= $la['id'] ?>"><?= htmlspecialchars($la['account_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 py-1 px-2 rounded small bg-light border text-secondary" id="custBalanceAlert" style="display:none;">
                        <span id="custBalanceText"></span>
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
                                            <div class="prod-price" style="font-size: 13px; line-height: 1.2;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format((float)$p['carton_rate'], 0) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>
                                            </div>
                                            <div class="text-secondary fw-semibold" style="font-size: 11px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format((float)$p['piece_rate'], 2) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Pc</span>
                                            </div>
                                        </div>
                                        <div class="prod-stock text-end">
                                            <?= $isOutOfStock ? 'Out of stock' : htmlspecialchars($stockBreakdown['formatted']) . ' avail' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive Bill Items Cart -->
                <div class="col-lg-7">
                    <div class="pos-cart-container">
                        <div class="pos-cart-header">
                            <span class="fw-bold text-navy">
                                <i class="bi bi-receipt me-1 text-primary"></i> Invoice Line Items 
                                <span class="badge bg-primary ms-1" id="cartItemCount">0 items</span>
                            </span>
                            <button type="button" class="btn btn-xs btn-outline-danger" onclick="clearInvoiceCart()" id="btnClearCart" style="display:none;">
                                <i class="bi bi-trash me-1"></i> Clear Items
                            </button>
                        </div>

                        <!-- Discount Mode Radio Selection Bar -->
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
                            <table class="table pos-cart-table table-hover align-middle" id="invoiceTable">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Product Description</th>
                                        <th style="width: 9%;" class="text-center">Unit</th>
                                        <th style="width: 10%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 9%;" class="text-center">Packs</th>
                                        <th style="width: 9%;" class="text-center">Extra Pcs</th>
                                        <th style="width: 15%;" class="text-end">Rate</th>
                                        <th style="width: 8%;" class="text-end">Disc %</th>
                                        <th style="width: 12%;" class="text-end">Line Total</th>
                                        <th style="width: 30px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="invoiceItemsBody">
                                    <tr id="emptyInvoiceRow">
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                            <div class="fw-semibold">No items added to invoice yet.</div>
                                            <small>Click on product cards on the left or search to add items.</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Real-time Bill Totals Footer -->
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
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Invoice notes, driver notes, or spot sale remarks...">
                                </div>
                                <div class="col-md-5 text-end">
                                    <button type="submit" class="btn btn-navy btn-sm px-4 fw-bold shadow-sm">
                                        <i class="bi bi-check2-circle me-1"></i> Save & Generate Bill (F9)
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
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.getElementById('customerSelect');
    const bookerSelect   = document.getElementById('bookerSelect');
    const areaSelect     = document.getElementById('areaSelect');
    const creditAlert    = document.getElementById('custBalanceAlert');
    const creditText     = document.getElementById('custBalanceText');
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
        creditText.innerHTML = `<i class="bi bi-wallet2 me-1"></i> Customer Balance: <strong><?= DEFAULT_CURRENCY ?> ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}</strong> | Credit Limit: <?= DEFAULT_CURRENCY ?> ${limit.toLocaleString('en-US', {minimumFractionDigits:2})} ${balance > limit && limit > 0 ? '<span class="badge bg-danger ms-2">Limit Exceeded</span>' : ''}`;
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

    function filterProductCards() {
        const query = productSearch.value.trim().toLowerCase();
        const activeCatEl = document.querySelector('.pos-category-pill.active');
        const activeCat = activeCatEl ? activeCatEl.getAttribute('data-cat') : '';
        const companyFilter = document.getElementById('companyFilter');
        const activeSup = companyFilter ? companyFilter.value : '';

        document.querySelectorAll('.pos-product-card').forEach(card => {
            const name = card.getAttribute('data-name').toLowerCase();
            const code = card.getAttribute('data-code').toLowerCase();
            const cat  = card.getAttribute('data-cat');
            const sup  = card.getAttribute('data-supplier-id');

            const matchQuery = !query || name.includes(query) || code.includes(query);
            const matchCat   = !activeCat || cat === activeCat;
            const matchSup   = !activeSup || sup === activeSup;

            if (matchQuery && matchCat && matchSup) {
                card.classList.remove('d-none');
            } else {
                card.classList.add('d-none');
            }
        });
    }

    // Keyboard Shortcuts: '/' or F2 to search, F9 to submit
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' || e.key === 'F2') {
            if (document.activeElement !== productSearch) {
                e.preventDefault();
                productSearch.focus();
                productSearch.select();
            }
        } else if (e.key === 'F9') {
            e.preventDefault();
            document.getElementById('invoiceForm').submit();
        }
    });
});

function getDiscountMode() {
    const radio = document.querySelector('input[name="discount_mode"]:checked');
    return radio ? radio.value : 'item_rate';
}

function onDiscountModeChange() {
    const mode = getDiscountMode();
    const subtotalBox = document.getElementById('subtotalDiscountInputs');
    const discInps = document.querySelectorAll('#invoiceTable input[id^="inv_disc_"]');
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
    recalculateInvoiceSummary();
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
    recalculateInvoiceSummary();
}

function calculateInvoiceLineGross(item) {
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
    Object.values(invoiceItems).forEach(item => {
        grossAmount += calculateInvoiceLineGross(item);
    });

    const amt = Math.round(((grossAmount * pctVal) / 100.0) * 100) / 100;
    const amtEl = document.getElementById('subtotalDiscountAmount');
    if (amtEl && document.activeElement !== amtEl) {
        amtEl.value = amt > 0 ? amt.toFixed(2) : '';
    }

    recalculateInvoiceSummary();
}

function onSubtotalAmountChange() {
    const mode = getDiscountMode();
    if (mode !== 'subtotal') return;

    const amtVal = parseFloat(document.getElementById('subtotalDiscountAmount')?.value) || 0;
    let grossAmount = 0;
    Object.values(invoiceItems).forEach(item => {
        grossAmount += calculateInvoiceLineGross(item);
    });

    const pct = grossAmount > 0 ? Math.round(((amtVal / grossAmount) * 100.0) * 100) / 100 : 0;
    const pctEl = document.getElementById('subtotalDiscountPercent');
    if (pctEl && document.activeElement !== pctEl) {
        pctEl.value = pct > 0 ? pct.toFixed(2) : '';
    }

    recalculateInvoiceSummary();
}

function recalculateAllRows() {
    const mode = getDiscountMode();
    Object.keys(invoiceItems).forEach(productId => {
        const item = invoiceItems[productId];
        const lineGross = calculateInvoiceLineGross(item);
        const lineDisc = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
        item.line_total = lineGross - lineDisc;

        const lblTotal = document.getElementById('inv_lbl_total_' + productId);
        if (lblTotal) {
            lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        }
    });
}

let invoiceItems = {};
let itemIdxCounter = 0;

function addProductToInvoice(productId) {
    const prod = productMap[productId];
    if (!prod) return;

    if (invoiceItems[productId]) {
        if (invoiceItems[productId].unit === 'pcs') {
            invoiceItems[productId].pcs_qty += invoiceItems[productId].pcs_per_ctn > 0 ? invoiceItems[productId].pcs_per_ctn : 1;
        } else {
            invoiceItems[productId].carton_qty += 1;
        }
        updateInvoiceRow(productId);
    } else {
        const packsPerCtn = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
        const pcsPerCtn   = packsPerCtn * pcsPerPack;
        const cartonRate  = parseFloat(prod.carton_rate) || parseFloat(prod.wholesale_rate) || 0;
        const pieceRate   = parseFloat(prod.piece_rate) || (pcsPerCtn > 0 ? cartonRate / pcsPerCtn : cartonRate);
        const rate        = cartonRate;
        itemIdxCounter++;

        invoiceItems[productId] = {
            index: itemIdxCounter,
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
            rate: rate,
            discount_percent: 0,
            line_total: rate
        };

        renderNewInvoiceRow(productId);
    }

    updateCardBadge(productId);
    recalculateInvoiceSummary();

    const rowInput = document.getElementById('inv_carton_' + productId);
    if (rowInput) {
        rowInput.focus();
        rowInput.select();
    }
}

function onInvoiceUnitChange(productId) {
    const item = invoiceItems[productId];
    if (!item) return;

    const unitSel = document.getElementById('inv_unit_' + productId);
    const unit = unitSel ? unitSel.value : 'ctn';
    item.unit = unit;

    if (unit === 'pcs') {
        item.rate = item.piece_rate;
        if (item.carton_qty === 1 && item.pack_qty === 0 && item.pcs_qty === 0) {
            item.carton_qty = 0;
            item.pack_qty = 0;
            item.pcs_qty = 1;
            const inputCarton = document.getElementById('inv_carton_' + productId);
            const inputPack = document.getElementById('inv_pack_' + productId);
            const inputPcs = document.getElementById('inv_pcs_' + productId);
            if (inputCarton) inputCarton.value = 0;
            if (inputPack) inputPack.value = 0;
            if (inputPcs) inputPcs.value = 1;
        }
    } else if (unit === 'pack') {
        item.rate = item.pack_rate;
        if (item.carton_qty === 1 && item.pack_qty === 0 && item.pcs_qty === 0) {
            item.carton_qty = 0;
            item.pack_qty = 1;
            item.pcs_qty = 0;
            const inputCarton = document.getElementById('inv_carton_' + productId);
            const inputPack = document.getElementById('inv_pack_' + productId);
            const inputPcs = document.getElementById('inv_pcs_' + productId);
            if (inputCarton) inputCarton.value = 0;
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
            const inputCarton = document.getElementById('inv_carton_' + productId);
            const inputPack = document.getElementById('inv_pack_' + productId);
            const inputPcs = document.getElementById('inv_pcs_' + productId);
            if (inputCarton) inputCarton.value = 1;
            if (inputPack) inputPack.value = 0;
            if (inputPcs) inputPcs.value = 0;
        }
    }

    const inputRate = document.getElementById('inv_rate_' + productId);
    if (inputRate) inputRate.value = item.rate.toFixed(2);

    const lblRateUnit = document.getElementById('inv_lbl_rate_unit_' + productId);
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (unit === 'pcs' ? 'Piece' : (unit === 'pack' ? 'Pack' : 'Carton'));

    updateInvoiceRow(productId);
    recalculateInvoiceSummary();
}

function renderNewInvoiceRow(productId) {
    const emptyRow = document.getElementById('emptyInvoiceRow');
    if (emptyRow) emptyRow.style.display = 'none';
    document.getElementById('btnClearCart').style.display = 'inline-block';

    const item = invoiceItems[productId];
    const tbody = document.getElementById('invoiceItemsBody');
    const idx = item.index;

    const ratioText = item.pcs_per_pack > 1 
        ? `1*${item.packs_per_ctn}*${item.pcs_per_pack}` 
        : `1*${item.packs_per_ctn}`;

    const tr = document.createElement('tr');
    tr.id = 'inv_row_' + productId;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="items[${idx}][product_id]" value="${item.product_id}">
            <div class="fw-bold text-navy" style="font-size: 13px;">${item.product_name}</div>
            <small class="text-muted">Pack: ${ratioText} &bull; ${item.product_code}</small>
        </td>
        <td class="text-center">
            <select name="items[${idx}][unit]" id="inv_unit_${productId}" class="form-select form-select-sm text-center py-0 px-1" onchange="onInvoiceUnitChange(${productId})">
                <option value="ctn" ${item.unit === 'ctn' ? 'selected' : ''}>Ctn</option>
                <option value="pack" ${item.unit === 'pack' ? 'selected' : ''}>Pack</option>
                <option value="pcs" ${item.unit === 'pcs' ? 'selected' : ''}>Pcs</option>
            </select>
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="items[${idx}][carton_qty]" id="inv_carton_${productId}" 
                   class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                   value="${item.carton_qty}" 
                   oninput="onInvoiceRowChange(${productId})" required>
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="items[${idx}][pack_qty]" id="inv_pack_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pack_qty}" 
                   oninput="onInvoiceRowChange(${productId})">
        </td>
        <td class="text-center">
            <input type="number" step="1" min="0" name="items[${idx}][pcs_qty]" id="inv_pcs_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pcs_qty}" 
                   oninput="onInvoiceRowChange(${productId})">
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="items[${idx}][rate]" id="inv_rate_${productId}" 
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onInvoiceRowChange(${productId})" required>
            <span class="small text-muted" id="inv_lbl_rate_unit_${productId}" style="font-size: 10px;">/ ${item.unit === 'pcs' ? 'Piece' : (item.unit === 'pack' ? 'Pack' : 'Carton')}</span>
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="items[${idx}][discount_percent]" id="inv_disc_${productId}" 
                   class="form-control form-control-sm text-end pos-cart-input" 
                   value="${item.discount_percent}" 
                   oninput="onInvoiceRowChange(${productId})">
        </td>
        <td class="text-end fw-bold text-navy" id="inv_lbl_total_${productId}">
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
        const discInp = document.getElementById('inv_disc_' + productId);
        if (discInp) {
            discInp.disabled = true;
            discInp.classList.add('bg-light', 'text-muted');
            discInp.title = 'Disabled in subtotal discount mode';
        }
    }
}

function updateInvoiceRow(productId) {
    const item = invoiceItems[productId];
    if (!item) return;

    const inputCarton = document.getElementById('inv_carton_' + productId);
    const inputPack   = document.getElementById('inv_pack_' + productId);
    const inputPcs    = document.getElementById('inv_pcs_' + productId);
    const inputUnit   = document.getElementById('inv_unit_' + productId);
    const inputRate   = document.getElementById('inv_rate_' + productId);
    const inputDisc   = document.getElementById('inv_disc_' + productId);
    const lblRateUnit = document.getElementById('inv_lbl_rate_unit_' + productId);
    const lblTotal    = document.getElementById('inv_lbl_total_' + productId);

    if (inputCarton) inputCarton.value = item.carton_qty;
    if (inputPack) inputPack.value = item.pack_qty;
    if (inputPcs) inputPcs.value = item.pcs_qty;
    if (inputUnit) inputUnit.value = item.unit || 'ctn';
    if (inputRate) inputRate.value = item.rate.toFixed(2);
    if (inputDisc) inputDisc.value = item.discount_percent;
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (item.unit === 'pcs' ? 'Piece' : 'Carton');

    const lineGross = calculateInvoiceLineGross(item);
    const mode = getDiscountMode();
    const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
    item.line_total = lineGross - lineDisc;

    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }
}

function onInvoiceRowChange(productId) {
    const item = invoiceItems[productId];
    if (!item) return;

    const ctnVal  = parseFloat(document.getElementById('inv_carton_' + productId).value) || 0;
    const packVal = parseFloat(document.getElementById('inv_pack_' + productId).value) || 0;
    const pcsVal  = parseFloat(document.getElementById('inv_pcs_' + productId).value) || 0;
    const rateVal = parseFloat(document.getElementById('inv_rate_' + productId).value) || 0;
    const discVal = parseFloat(document.getElementById('inv_disc_' + productId).value) || 0;

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
        const unitSel = document.getElementById('inv_unit_' + productId);
        if (unitSel) unitSel.value = 'pcs';
        const inputRate = document.getElementById('inv_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('inv_lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Piece';
    } else if (item.unit === 'pcs' && ctnVal > 0 && pcsVal === 0) {
        item.unit = 'ctn';
        item.rate = item.carton_rate;
        const unitSel = document.getElementById('inv_unit_' + productId);
        if (unitSel) unitSel.value = 'ctn';
        const inputRate = document.getElementById('inv_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('inv_lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Carton';
    }

    const lineGross = calculateInvoiceLineGross(item);
    const mode = getDiscountMode();
    const lineDisc  = (mode === 'subtotal') ? 0 : ((lineGross * item.discount_percent) / 100.0);
    item.line_total = lineGross - lineDisc;

    const lblTotal = document.getElementById('inv_lbl_total_' + productId);
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

    recalculateInvoiceSummary();
}

function removeProductFromInvoice(productId) {
    delete invoiceItems[productId];
    const row = document.getElementById('inv_row_' + productId);
    if (row) row.remove();

    updateCardBadge(productId);

    if (Object.keys(invoiceItems).length === 0) {
        const emptyRow = document.getElementById('emptyInvoiceRow');
        if (emptyRow) emptyRow.style.display = '';
        document.getElementById('btnClearCart').style.display = 'none';
    }

    recalculateInvoiceSummary();
}

function clearInvoiceCart() {
    if (confirm('Clear all line items from this invoice?')) {
        invoiceItems = {};
        document.querySelectorAll('#invoiceItemsBody tr:not(#emptyInvoiceRow)').forEach(tr => tr.remove());
        const emptyRow = document.getElementById('emptyInvoiceRow');
        if (emptyRow) emptyRow.style.display = '';
        document.getElementById('btnClearCart').style.display = 'none';

        document.querySelectorAll('.pos-product-card').forEach(card => {
            card.classList.remove('in-cart');
        });
        document.querySelectorAll('.card-badge-cart').forEach(badge => {
            badge.classList.add('d-none');
            badge.innerText = '0 in bill';
        });

        recalculateInvoiceSummary();
    }
}

function updateCardBadge(productId) {
    const card  = document.getElementById('card_prod_' + productId);
    const badge = document.getElementById('badge_cart_' + productId);
    const item  = invoiceItems[productId];

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
            badge.innerText = '0 in bill';
        }
    }
}

function recalculateInvoiceSummary() {
    const mode = getDiscountMode();
    let totalCartons = 0;
    let totalPacks   = 0;
    let totalPieces  = 0;
    let grossAmount  = 0;
    let itemDiscSum  = 0;
    let itemCount    = 0;

    Object.values(invoiceItems).forEach(item => {
        itemCount++;
        totalCartons += item.carton_qty;
        totalPacks   += item.pack_qty;
        totalPieces  += item.pcs_qty;

        const lineGross = calculateInvoiceLineGross(item);
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
    const payTypeSel = document.getElementById('paymentTypeSelect');
    const depAccWrap = document.getElementById('depositAccWrapper');
    if (payTypeSel && depAccWrap) {
        payTypeSel.addEventListener('change', function() {
            depAccWrap.style.display = (this.value === 'cash') ? 'block' : 'none';
        });
    }
}
</script>

<?php
require_once INCLUDES_PATH . '/drawers/product_price_modal.php';
require_once INCLUDES_PATH . '/footer.php';
?>
