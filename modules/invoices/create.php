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

    $validItems = [];
    $grossTotal = 0.0;
    $discountTotal = 0.0;
    $netTotal = 0.0;

    foreach ($itemsData as $idx => $line) {
        $productId = (int)($line['product_id'] ?? 0);
        $ctnQty    = (float)($line['carton_qty'] ?? 0);
        $pcsQty    = (float)($line['pcs_qty'] ?? 0);
        $rate      = (float)($line['rate'] ?? 0);
        $discPct   = (float)($line['discount_percent'] ?? 0);

        if ($productId <= 0 || ($ctnQty <= 0 && $pcsQty <= 0)) {
            continue;
        }

        $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
        if (!$prod) {
            continue;
        }

        $ctnRatio = (float)$prod['carton_qty'] > 0 ? (float)$prod['carton_qty'] : 1;
        $totalBaseUnits = ($ctnQty * $ctnRatio) + $pcsQty;
        $lineGross = ($ctnQty > 0 ? ($ctnQty * $rate) : (($pcsQty / $ctnRatio) * $rate));
        $lineDisc = ($lineGross * $discPct) / 100.0;
        $lineNet = $lineGross - $lineDisc;

        $grossTotal += $lineGross;
        $discountTotal += $lineDisc;
        $netTotal += $lineNet;

        $validItems[] = [
            'product_id'       => $productId,
            'unit_id'          => (int)$prod['base_unit_id'],
            'carton_qty'       => $ctnQty,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $totalBaseUnits,
            'rate'             => $rate,
            'discount_percent' => $discPct,
            'discount_amount'  => $lineDisc,
            'line_total'       => $lineNet
        ];
    }

    if (empty($validItems)) {
        $errors[] = "Please add at least one valid product line with quantity greater than zero.";
    }

    if (empty($errors)) {
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
                    gross_amount, item_discount_amount, extra_discount_amount, net_amount,
                    paid_amount, status, reconciliation_status, payment_status,
                    remarks, created_by, finalized_at, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, 0.00, ?,
                    ?, 'finalized', 'pending', ?,
                    ?, ?, NOW(), NOW()
                )
            ");
            $stmtInv->execute([
                $invoiceNumber, $invoiceDate, $customerId, $bookerId, $salesmanId, $areaId,
                $grossTotal, $discountTotal, $netTotal,
                $paidAmount, $paymentStatus,
                $remarks, $userId
            ]);
            $invoiceId = (int)db()->lastInsertId();

            // 3. Insert Invoice Items & Record Immediate Stock Deductions
            $stmtItem = db()->prepare("
                INSERT INTO sales_invoice_items (
                    invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
                    quantity, rate, discount_percent, discount_amount, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, 0.00, ?,
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $invoiceId, $vi['product_id'], $vi['unit_id'],
                    $vi['carton_qty'], $vi['pcs_qty'], $vi['quantity'],
                    $vi['rate'], $vi['discount_percent'], $vi['discount_amount'],
                    $vi['line_total']
                ]);

                // Record stock movement (Direct spot sale dispatch)
                record_stock_movement([
                    'product_id'     => $vi['product_id'],
                    'warehouse_id'   => 1, // Default Central Warehouse
                    'movement_type'  => 'sales_dispatch',
                    'reference_type' => 'invoice',
                    'reference_id'   => $invoiceId,
                    'qty_in'         => 0,
                    'qty_out'        => $vi['quantity'],
                    'unit_cost'      => $vi['rate'],
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
                // Spot cash sale: Debit Cash in Hand (1010), Credit Sales Revenue (4010)
                $glLines = [
                    [
                        'account_id' => 1010, // Cash in Hand
                        'debit'      => $netTotal,
                        'credit'     => 0,
                        'memo'       => "Cash received for invoice #{$invoiceNumber}"
                    ],
                    [
                        'account_id' => 4010, // Sales Revenue
                        'debit'      => 0,
                        'credit'     => $netTotal,
                        'memo'       => "Direct spot cash sale #{$invoiceNumber}"
                    ]
                ];
                post_journal_entry([
                    'entry_date'     => $invoiceDate,
                    'reference_type' => 'invoice',
                    'reference_id'   => $invoiceId,
                    'description'    => "Direct Spot Cash Invoice #{$invoiceNumber}",
                    'created_by'     => $userId
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
$customers  = db_fetch_all("SELECT id, customer_code, shop_name, address, area_id, booker_id, current_balance, credit_limit FROM customers WHERE is_deleted = 0 ORDER BY shop_name ASC");
$areas      = db_fetch_all("SELECT id, area_name FROM areas WHERE is_active = 1 ORDER BY area_name ASC");
$bookers    = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_active = 1 ORDER BY booker_name ASC");
$salesmen   = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");
$categories = db_fetch_all("SELECT id, category_name FROM product_categories WHERE is_active = 1 ORDER BY category_name ASC");

$products   = db_fetch_all("
    SELECT p.id, p.product_code, p.product_name, p.category_id, cat.category_name, p.carton_qty, p.wholesale_rate,
           COALESCE(sb.saleable_qty, 0) as saleable_qty
    FROM products p
    LEFT JOIN product_categories cat ON cat.id = p.category_id
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
                            <label class="form-label small fw-bold mb-1">Customer / Retail Shop <span class="text-danger">*</span></label>
                            <select name="customer_id" id="customerSelect" class="form-select form-select-sm" required autofocus>
                                <option value="">-- Select Retail Customer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                            data-area="<?= $c['area_id'] ?>" 
                                            data-booker="<?= $c['booker_id'] ?>"
                                            data-balance="<?= $c['current_balance'] ?>"
                                            data-limit="<?= $c['credit_limit'] ?>">
                                        <?= htmlspecialchars($c['shop_name']) ?> (<?= htmlspecialchars($c['customer_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                            <label class="form-label small fw-bold mb-1">Delivery Route <span class="text-danger">*</span></label>
                            <select name="area_id" id="areaSelect" class="form-select form-select-sm" required>
                                <option value="">-- Select Route --</option>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['area_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_type" class="form-select form-select-sm fw-bold">
                                <option value="credit">Credit Sale (Post to Party Ledger)</option>
                                <option value="cash">Spot Cash Sale (Office Cash Drawer)</option>
                            </select>
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
                                     onclick="addProductToInvoice(<?= $p['id'] ?>)">
                                    
                                    <span class="card-badge-cart d-none" id="badge_cart_<?= $p['id'] ?>">0 in bill</span>
                                    
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

                        <div class="pos-cart-table-wrapper">
                            <table class="table pos-cart-table table-hover align-middle" id="invoiceTable">
                                <thead>
                                    <tr>
                                        <th style="width: 34%;">Product Description</th>
                                        <th style="width: 13%;" class="text-center">Ctn Qty</th>
                                        <th style="width: 13%;" class="text-center">Extra Pcs</th>
                                        <th style="width: 16%;" class="text-end">Rate / Ctn</th>
                                        <th style="width: 10%;" class="text-end">Disc %</th>
                                        <th style="width: 14%;" class="text-end">Line Total</th>
                                        <th style="width: 40px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="invoiceItemsBody">
                                    <tr id="emptyInvoiceRow">
                                        <td colspan="7" class="text-center py-5 text-muted">
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
                                    <div class="text-muted small">Total Discount</div>
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

    function filterProductCards() {
        const query = productSearch.value.trim().toLowerCase();
        const activeCat = document.querySelector('.pos-category-pill.active').getAttribute('data-cat');

        document.querySelectorAll('.pos-product-card').forEach(card => {
            const name = card.getAttribute('data-name').toLowerCase();
            const code = card.getAttribute('data-code').toLowerCase();
            const cat  = card.getAttribute('data-cat');

            const matchQuery = !query || name.includes(query) || code.includes(query);
            const matchCat   = !activeCat || cat === activeCat;

            if (matchQuery && matchCat) {
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

let invoiceItems = {};
let itemIdxCounter = 0;

function addProductToInvoice(productId) {
    const prod = productMap[productId];
    if (!prod) return;

    if (invoiceItems[productId]) {
        invoiceItems[productId].carton_qty += 1;
        updateInvoiceRow(productId);
    } else {
        const ctnRatio = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const rate = parseFloat(prod.wholesale_rate) || 0;
        itemIdxCounter++;

        invoiceItems[productId] = {
            index: itemIdxCounter,
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

function renderNewInvoiceRow(productId) {
    const emptyRow = document.getElementById('emptyInvoiceRow');
    if (emptyRow) emptyRow.style.display = 'none';
    document.getElementById('btnClearCart').style.display = 'inline-block';

    const item = invoiceItems[productId];
    const tbody = document.getElementById('invoiceItemsBody');
    const idx = item.index;

    const tr = document.createElement('tr');
    tr.id = 'inv_row_' + productId;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="items[${idx}][product_id]" value="${item.product_id}">
            <div class="fw-bold text-navy" style="font-size: 13px;">${item.product_name}</div>
            <small class="text-muted">Pack: 1*${item.carton_ratio} &bull; ${item.product_code}</small>
        </td>
        <td class="text-center">
            <input type="number" step="0.01" name="items[${idx}][carton_qty]" id="inv_carton_${productId}" 
                   class="form-control form-control-sm text-center fw-bold pos-cart-input" 
                   value="${item.carton_qty}" 
                   oninput="onInvoiceRowChange(${productId})" required>
        </td>
        <td class="text-center">
            <input type="number" step="1" name="items[${idx}][pcs_qty]" id="inv_pcs_${productId}" 
                   class="form-control form-control-sm text-center pos-cart-input" 
                   value="${item.pcs_qty}" 
                   oninput="onInvoiceRowChange(${productId})">
        </td>
        <td class="text-end">
            <input type="number" step="0.01" name="items[${idx}][rate]" id="inv_rate_${productId}" 
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onInvoiceRowChange(${productId})" required>
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
}

function updateInvoiceRow(productId) {
    const item = invoiceItems[productId];
    if (!item) return;

    const inputCarton = document.getElementById('inv_carton_' + productId);
    const inputPcs    = document.getElementById('inv_pcs_' + productId);
    const inputRate   = document.getElementById('inv_rate_' + productId);
    const inputDisc   = document.getElementById('inv_disc_' + productId);
    const lblTotal    = document.getElementById('inv_lbl_total_' + productId);

    if (inputCarton) inputCarton.value = item.carton_qty;
    if (inputPcs) inputPcs.value = item.pcs_qty;
    if (inputRate) inputRate.value = item.rate.toFixed(2);
    if (inputDisc) inputDisc.value = item.discount_percent;

    const baseCartons = item.carton_qty + (item.pcs_qty / item.carton_ratio);
    const lineGross = baseCartons * item.rate;
    const lineDisc  = (lineGross * item.discount_percent) / 100.0;
    item.line_total = lineGross - lineDisc;

    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }
}

function onInvoiceRowChange(productId) {
    const item = invoiceItems[productId];
    if (!item) return;

    const ctnVal  = parseFloat(document.getElementById('inv_carton_' + productId).value) || 0;
    const pcsVal  = parseFloat(document.getElementById('inv_pcs_' + productId).value) || 0;
    const rateVal = parseFloat(document.getElementById('inv_rate_' + productId).value) || 0;
    const discVal = parseFloat(document.getElementById('inv_disc_' + productId).value) || 0;

    item.carton_qty = ctnVal;
    item.pcs_qty = pcsVal;
    item.rate = rateVal;
    item.discount_percent = discVal;

    const baseCartons = item.carton_qty + (item.pcs_qty / item.carton_ratio);
    const lineGross = baseCartons * item.rate;
    const lineDisc  = (lineGross * item.discount_percent) / 100.0;
    item.line_total = lineGross - lineDisc;

    const lblTotal = document.getElementById('inv_lbl_total_' + productId);
    if (lblTotal) {
        lblTotal.innerText = `<?= DEFAULT_CURRENCY ?> ${item.line_total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    }

    updateCardBadge(productId);
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
        if (item && (item.carton_qty > 0 || item.pcs_qty > 0)) {
            card.classList.add('in-cart');
            badge.classList.remove('d-none');
            badge.innerText = `${item.carton_qty} Ctn${item.pcs_qty > 0 ? ' +' + item.pcs_qty + 'p' : ''}`;
        } else {
            card.classList.remove('in-cart');
            badge.classList.add('d-none');
            badge.innerText = '0 in bill';
        }
    }
}

function recalculateInvoiceSummary() {
    let totalCartons = 0;
    let totalPieces  = 0;
    let grossAmount  = 0;
    let discountAmt  = 0;
    let netAmount    = 0;
    let itemCount    = 0;

    Object.values(invoiceItems).forEach(item => {
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
