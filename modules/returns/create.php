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

$preInvoice = null;
if ($preInvoiceId > 0) {
    $preInvoice = db_fetch_one("
        SELECT 
            inv.*, 
            c.shop_name, c.customer_code, c.current_balance, c.phone as customer_phone, c.address as customer_address,
            a.area_name, b.booker_name, sm.salesman_name, ls.load_number
        FROM sales_invoices inv
        JOIN customers c ON inv.customer_id = c.id
        LEFT JOIN areas a ON inv.area_id = a.id
        LEFT JOIN bookers b ON inv.booker_id = b.id
        LEFT JOIN salesmen sm ON inv.salesman_id = sm.id
        LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
        WHERE inv.id = ?
    ", [$preInvoiceId]);
    if ($preInvoice) {
        $preCustomerId = (int)$preInvoice['customer_id'];
    }
}

$recentInvoices = db_fetch_all("
    SELECT 
        inv.id, inv.invoice_number, inv.invoice_date, inv.customer_id, inv.net_amount, inv.return_amount, 
        c.shop_name, c.customer_code, a.area_name, ls.load_number, sm.salesman_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN areas a ON inv.area_id = a.id
    LEFT JOIN salesmen sm ON inv.salesman_id = sm.id
    LEFT JOIN load_sheets ls ON inv.load_sheet_id = ls.id
    WHERE inv.status != 'cancelled'
    ORDER BY inv.id DESC
    LIMIT 60
");

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

$suppliers = db_fetch_all("
    SELECT id, supplier_name, company_name 
    FROM suppliers 
    WHERE is_active = 1 
    ORDER BY supplier_name ASC
");

$products = db_fetch_all("
    SELECT 
        p.id, p.product_name, p.product_code, p.barcode, p.category_id, p.supplier_id, p.brand_id, cat.category_name, b.brand_name,
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

$nextReturnNumber = generate_document_number(PREFIX_RETURN, 'sales_returns', 'return_number');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnNumber  = clean_input($_POST['return_number'] ?? $nextReturnNumber);
    $returnDate    = sanitize_input($_POST['return_date'] ?? date('Y-m-d'));
    $customerId    = (int)($_POST['customer_id'] ?? 0);
    $invoiceId     = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $settlementId  = !empty($_POST['settlement_id']) ? (int)$_POST['settlement_id'] : null;
    $conditionType = sanitize_input($_POST['condition_type'] ?? 'saleable');
    $freeLoosePieces = !empty($_POST['free_loose_pieces']) ? 1 : 0;
    $reason        = sanitize_input($_POST['reason'] ?? '');
    $status        = isset($_POST['save_draft']) ? 'draft' : 'processed';

    // Item arrays from fast cart
    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemCartonQtys = $_POST['item_carton_qty'] ?? [];
    $itemPackQtys   = $_POST['item_pack_qty'] ?? [];
    $itemPcsQtys    = $_POST['item_pcs_qty'] ?? [];
    $itemUnits      = $_POST['item_unit'] ?? [];
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
                $itemUnits[]      = $it['unit'] ?? 'ctn';
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
        $unitCode  = strtolower(trim((string)($itemUnits[$idx] ?? 'ctn')));
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

        // If Free Loose Pieces scheme is active, loose pieces rate is not credited
        $billablePcs = $freeLoosePieces ? 0 : $pcsQty;
        $billableBasePieces = calculate_base_pieces($cartonQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);
        $billableCartons    = calculate_carton_equivalent($cartonQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);

        if ($unitCode === 'pcs') {
            $lineGross = $billableBasePieces * $rate;
            $unitId = 4; // Piece unit
            $itemQty = $billableBasePieces;
        } elseif ($unitCode === 'pack' || $unitCode === 'pck') {
            $packsEquivalent = $pcsPerPack > 0 ? ($billableBasePieces / $pcsPerPack) : 0;
            $lineGross = $packsEquivalent * $rate;
            $unitId = 3; // Packet / Pack unit
            $itemQty = $packsEquivalent;
        } else {
            $lineGross = $billableCartons * $rate;
            $unitId = 1; // Carton unit (CTN) // Carton unit
            $itemQty = $billableCartons;
        }

        $lineDiscount = ($lineGross * $discPct) / 100.0;
        $lineNet      = max(0, round($lineGross - $lineDiscount, 2));

        $totalAmount  += $lineNet;
        $totalCartons += $cartonQty;
        $totalPieces  += $pcsQty;

        $validItems[] = [
            'product_id'       => $prodId,
            'unit_id'          => $unitId,
            'carton_qty'       => $cartonQty,
            'pack_qty'         => $packQty,
            'pcs_qty'          => $pcsQty,
            'quantity'         => $itemQty,
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
                    SET total_amount = ?, free_loose_pieces = ?, reason = ?, condition_type = ?, status = 'processed', stock_settled = 1, updated_at = NOW() 
                    WHERE id = ?
                ", [$totalAmount, $freeLoosePieces, $reason, $conditionType, $returnId]);

                // Clear any stub items
                db_execute("DELETE FROM sales_return_items WHERE sales_return_id = ?", [$returnId]);
            } else {
                // 1. Insert new sales_returns header
                $stmt = db()->prepare("
                    INSERT INTO sales_returns (
                        return_number, invoice_id, customer_id, settlement_id, return_date, 
                        total_amount, free_loose_pieces, reason, condition_type, status, stock_settled, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 
                        ?, ?, ?, ?, ?, 1, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $returnNumber, $invoiceId, $customerId, $settlementId, $returnDate,
                    $totalAmount, $freeLoosePieces, $reason, $conditionType, $status, $userId
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

                $retQty = (float)$vi['base_pieces'];
                $retAmt = (float)$vi['amount'];
                $pieceCost = $retQty > 0 ? round($retAmt / $retQty, 6) : 0;
                record_stock_movement([
                    'product_id'     => $vi['product_id'],
                    'warehouse_id'   => 1,
                    'movement_type'  => $movementType,
                    'reference_type' => 'sales_return',
                    'reference_id'   => $returnId,
                    'qty_in'         => $retQty,
                    'qty_out'        => 0,
                    'unit_cost'      => $pieceCost,
                    'total_cost'     => $retAmt,
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

            // 4. Update Sales Invoice & Linked Load Sheet / Delivery Runs
            if ($invoiceId > 0 && $status === 'processed') {
                db_execute("
                    UPDATE sales_invoices 
                    SET return_amount = return_amount + ?,
                        credit_amount = GREATEST(0, net_amount - paid_amount - (return_amount + ?)),
                        payment_status = CASE 
                            WHEN (paid_amount + return_amount + ?) >= net_amount THEN 'paid' 
                            WHEN (paid_amount + return_amount + ?) > 0 THEN 'partial' 
                            ELSE 'unpaid' 
                        END,
                        updated_at = NOW() 
                    WHERE id = ?
                ", [$totalAmount, $totalAmount, $totalAmount, $totalAmount, $invoiceId]);

                // Find linked load sheet id (direct or via order)
                $linkedLsId = (int)db_fetch_val("
                    SELECT COALESCE(inv.load_sheet_id, lso.load_sheet_id) 
                    FROM sales_invoices inv
                    LEFT JOIN load_sheet_orders lso ON inv.order_id = lso.order_id
                    WHERE inv.id = ?
                    LIMIT 1
                ", [$invoiceId]);

                if ($linkedLsId > 0) {
                    foreach ($validItems as $vi) {
                        $pInfo = db_fetch_one("SELECT carton_qty, pack_qty FROM products WHERE id = ?", [$vi['product_id']]);
                        $pPerC = (float)($pInfo['carton_qty'] ?? 1);
                        $pcPerP = (float)($pInfo['pack_qty'] ?? 1);
                        if ($pPerC <= 0) $pPerC = 1.0;
                        if ($pcPerP <= 0) $pcPerP = 1.0;
                        $pcsPerCtnTotal = $pPerC * $pcPerP;

                        $cQty = (float)$vi['carton_qty'];
                        $pkQty = (float)$vi['pack_qty'];
                        $pcQty = (float)$vi['pcs_qty'];
                        $ctnEquivalent = $cQty + ($pkQty / $pPerC) + ($pcQty / $pcsPerCtnTotal);

                        $existsLsi = db_fetch_val("SELECT id FROM load_sheet_items WHERE load_sheet_id = ? AND product_id = ?", [$linkedLsId, $vi['product_id']]);
                        if ($existsLsi) {
                            db_execute("
                                UPDATE load_sheet_items 
                                SET returned_qty = returned_qty + ?
                                WHERE load_sheet_id = ? AND product_id = ?
                            ", [$ctnEquivalent, $linkedLsId, $vi['product_id']]);
                        } else {
                            db_execute("
                                INSERT INTO load_sheet_items (load_sheet_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, ordered_qty, required_qty, loaded_qty, short_qty, returned_qty, rate, total_amount, created_at)
                                VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, 0, ?, ?, ?, NOW())
                            ", [$linkedLsId, $vi['product_id'], $vi['unit_id'], $ctnEquivalent, $vi['rate'], $vi['amount']]);
                        }
                    }
                }

                // If invoice exists on delivery run bills, update return value
                db_execute("
                    UPDATE delivery_run_bills 
                    SET return_value = return_value + ?
                    WHERE invoice_id = ?
                ", [$totalAmount, $invoiceId]);
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

            <!-- Return Workflow Mode Switcher & Top Header -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="small fw-bold text-muted text-uppercase me-1"><i class="bi bi-shuffle me-1"></i> Mode:</span>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-primary fw-bold" id="tabBtnBillWise" onclick="switchReturnMode('bill_wise')">
                                    <i class="bi bi-receipt me-1"></i> Bill-Wise Return (بل کے مطابق مال واپسی)
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="tabBtnLoose" onclick="switchReturnMode('loose')">
                                    <i class="bi bi-grid me-1"></i> Loose Market Return (بغیر بل ریٹرن)
                                </button>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="d-flex align-items-center gap-1">
                                <span class="small text-muted fw-bold">Date:</span>
                                <input type="date" name="return_date" id="commonReturnDate" class="form-control form-control-sm" style="width: 140px;" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="small text-muted fw-bold">Stock Condition:</span>
                                <select name="condition_type" id="conditionSelect" class="form-select form-select-sm" style="width: 170px;">
                                    <option value="saleable">Saleable (Restock)</option>
                                    <option value="damaged">Damaged (Quarantine)</option>
                                    <option value="expired">Expired (Quarantine)</option>
                                </select>
                            </div>
                            <span class="badge bg-navy text-white font-monospace py-2 px-2" style="font-size: 11px;">Voucher: <?= e($nextReturnNumber) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- VIEW 1: BILL-WISE SALES RETURN             -->
            <!-- ========================================== -->
            <div id="viewBillWise">
                <input type="hidden" name="customer_id" id="bwCustomerId" value="<?= (int)$preCustomerId ?>">

                <!-- Invoice Search & Picker Bar -->
                <style>
                .invoice-dropdown-item {
                    cursor: pointer;
                    transition: background-color 0.1s ease-in-out;
                }
                .invoice-dropdown-item:not(.active):hover {
                    background-color: #f0f7ff !important;
                }
                .invoice-dropdown-item.active {
                    background-color: #0d6efd !important;
                    color: #ffffff !important;
                }
                .invoice-dropdown-item.active * {
                    color: #ffffff !important;
                }
                .invoice-dropdown-item.highlighted:not(.active) {
                    background-color: #e2eeff !important;
                }
                </style>
                <div class="card border-0 shadow-sm mb-3 border-start border-4 border-primary" style="position: relative; overflow: visible;">
                    <div class="card-body p-3" style="overflow: visible;">
                        <div class="row g-2 align-items-center" style="overflow: visible;">
                            <div class="col-md-7 position-relative" style="z-index: 1050;">
                                <label class="form-label small fw-bold mb-1 text-primary d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-receipt me-1"></i> Select Invoice / Bill (بل منتخب یا تلاش کریں) <span class="text-danger">*</span></span>
                                    <span class="text-muted fw-normal" style="font-size: 11px;"><i class="bi bi-keyboard me-1"></i>Live Search by Bill #, Shop, or Load Sheet</span>
                                </label>
                                <input type="hidden" name="invoice_id" id="billWiseInvoiceSelect" value="<?= (int)$preInvoiceId ?>">
                                
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white border-end-0 text-primary"><i class="bi bi-search"></i></span>
                                    <input type="text" id="invoiceSearchInput" class="form-control border-start-0 ps-0 fw-semibold" 
                                           placeholder="Click or type to search Bill #, Shop Name, or Load Sheet..." 
                                           autocomplete="off"
                                           value="<?= $preInvoice ? '#' . htmlspecialchars($preInvoice['invoice_number']) . ' (' . date('d-M', strtotime($preInvoice['invoice_date'])) . ') - ' . htmlspecialchars($preInvoice['shop_name']) . ' - Rs. ' . number_format((float)$preInvoice['net_amount'], 0) : '' ?>">
                                    <button type="button" class="btn btn-outline-secondary" id="btnToggleInvoiceDropdown" title="Toggle bill list">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger <?= empty($preInvoiceId) ? 'd-none' : '' ?>" id="btnClearInvoiceSelection" onclick="clearInvoiceSelection()" title="Clear selection">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>

                                <!-- Live Searchable Floating Dropdown Menu -->
                                <div id="invoiceDropdownList" 
                                     class="shadow-lg border rounded-2 p-0 mt-1" 
                                     style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 340px; overflow-y: auto; z-index: 9999; background: #ffffff; box-shadow: 0 10px 25px rgba(0,0,0,0.18) !important;">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-bold mb-1 text-secondary">Return Reason / Remarks</label>
                                <input type="text" name="reason" id="billWiseReason" class="form-control form-control-sm" placeholder="e.g. Route Return, Damaged, Customer Rejected">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bill Details & Meta Banner -->
                <div id="billWiseDetailsCard" class="card border-0 shadow-sm mb-3 bg-light d-none">
                    <div class="card-body py-2 px-3">
                        <div class="row g-2 align-items-center text-dark">
                            <div class="col-md-3 border-end">
                                <div class="small text-muted text-uppercase fw-bold">Customer / Shop</div>
                                <div class="fw-bold fs-6 text-navy" id="bwMetaShopName">-</div>
                                <div class="small text-muted" id="bwMetaAreaPhone">-</div>
                            </div>
                            <div class="col-md-2 border-end text-center">
                                <div class="small text-muted text-uppercase fw-bold">Bill & Date</div>
                                <div class="fw-bold fs-6 text-dark" id="bwMetaBillNo">-</div>
                                <div class="small text-muted" id="bwMetaDate">-</div>
                            </div>
                            <div class="col-md-3 border-end">
                                <div class="small text-muted text-uppercase fw-bold">Staff & Load Sheet</div>
                                <div class="small">Salesman: <strong id="bwMetaSalesman">-</strong></div>
                                <div class="small">Load Form: <span class="badge bg-primary" id="bwMetaLoadSheet">No Load Sheet</span></div>
                            </div>
                            <div class="col-md-2 border-end text-center">
                                <div class="small text-muted text-uppercase fw-bold">Bill Net Total</div>
                                <div class="fs-5 fw-bold text-dark" id="bwMetaNetTotal">Rs. 0.00</div>
                                <div class="small text-warning fw-semibold" id="bwMetaPrevReturns">0 Prev Returns</div>
                            </div>
                            <div class="col-md-2 text-center">
                                <div class="small text-muted text-uppercase fw-bold">Customer Balance</div>
                                <div class="fs-5 fw-bold text-danger" id="bwMetaBalance">Rs. 0.00</div>
                                <div class="small text-muted">Current Ledger</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dedicated Billed Items Return Table -->
                <div id="billWiseTableCard" class="card border-0 shadow-sm mb-3 d-none">
                    <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-navy">
                            <i class="bi bi-box-seam me-1 text-primary"></i> Billed Items on this Invoice (اس بل کے آئٹمز)
                        </span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-xs btn-outline-success" onclick="billWiseReturnAll()">
                                <i class="bi bi-check-all me-1"></i> Return All Available
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-secondary" onclick="billWiseResetAll()">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to 0
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0" id="billWiseItemsTable">
                                <thead class="table-light text-center small text-uppercase">
                                    <tr>
                                        <th style="width: 35px;">#</th>
                                        <th class="text-start ps-3" style="width: 22%;">Product Description</th>
                                        <th style="width: 13%;">Billed Qty</th>
                                        <th style="width: 12%;">Sold Rate</th>
                                        <th style="width: 9%;">Prev Ret.</th>
                                        <th style="width: 9%;" class="bg-warning-subtle text-dark">Return Cartons</th>
                                        <th style="width: 9%;" class="bg-warning-subtle text-dark">Return Packs</th>
                                        <th style="width: 9%;" class="bg-warning-subtle text-dark">Return Pieces</th>
                                        <th style="width: 8%;">Condition</th>
                                        <th style="width: 10%;" class="text-end pe-3">Credit Total</th>
                                    </tr>
                                </thead>
                                <tbody id="billWiseTableBody">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Real-Time Bill-Wise Summary Footer -->
                    <div class="card-footer bg-white p-3">
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col-md-2 text-center border-end">
                                <div class="text-muted small">Return Cartons</div>
                                <div class="fw-bold fs-5 text-dark" id="bwSummaryCartons">0 Ctns</div>
                            </div>
                            <div class="col-md-2 text-center border-end">
                                <div class="text-muted small">Return Packs</div>
                                <div class="fw-bold fs-5 text-dark" id="bwSummaryPacks">0 Packs</div>
                            </div>
                            <div class="col-md-2 text-center border-end">
                                <div class="text-muted small">Return Pieces</div>
                                <div class="fw-bold fs-5 text-dark" id="bwSummaryPieces">0 Pcs</div>
                            </div>
                            <div class="col-md-3 text-center border-end">
                                <div class="text-muted small">Expected New Balance</div>
                                <div class="fw-bold fs-5 text-primary" id="bwSummaryNewBalance">Rs. 0.00</div>
                                <small class="text-muted" id="bwSummaryBalanceDiff">Reduction: -Rs. 0.00</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <div class="text-muted small">Total Return Credit Amount</div>
                                <div class="fw-bold fs-4 text-danger" id="bwSummaryTotalAmount">Rs. 0.00</div>
                            </div>
                        </div>

                        <div id="bwLoadSheetAlert" class="alert alert-info py-2 px-3 small mb-3 d-none">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            <span id="bwLoadSheetText"></span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted small">
                                <i class="bi bi-shield-check text-success me-1"></i>
                                Physical stock will be immediately restocked into Warehouse & balances updated safely.
                            </div>
                            <button type="submit" name="confirm_now" class="btn btn-danger btn-lg px-4 fw-bold shadow">
                                <i class="bi bi-check2-circle me-1"></i> Save Bill Return & Update Load Sheet
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty State Prompt -->
                <div id="billWiseEmptyPrompt" class="card border-0 shadow-sm mb-3">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="bi bi-receipt fs-1 text-primary opacity-50 d-block mb-2"></i>
                        <h6 class="fw-bold text-dark">Please select a Sales Bill above to start Bill-Wise Return</h6>
                        <p class="small text-secondary mb-0">Select from the dropdown or search by Bill # or shop name. All billed products with their exact rates will appear automatically.</p>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- VIEW 2: LOOSE MARKET RETURN (LEGACY / POS) -->
            <!-- ========================================== -->
            <div id="viewLoose" class="d-none">
                <!-- Customer & Loose Setup Card -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2 px-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5">
                                <label class="form-label small fw-bold mb-1 d-flex justify-content-between align-items-center">
                                    <span>Customer / Retailer <span class="text-danger">*</span></span>
                                    <?php if (has_permission('customers_create')): ?>
                                    <a href="javascript:void(0)" onclick="openQuickCustomerDrawer('customerSelect')" class="text-primary text-decoration-none" title="Quick Add Customer">
                                        <i class="bi bi-plus-circle-fill"></i> New
                                    </a>
                                    <?php endif; ?>
                                </label>
                                <div class="input-group input-group-sm">
                                    <select name="customer_id" id="customerSelect" class="form-select form-select-sm" disabled>
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
                                    <?php if (has_permission('customers_create')): ?>
                                    <button type="button" class="btn btn-outline-primary" onclick="openQuickCustomerDrawer('customerSelect')" title="Add New Customer in Real-Time">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-bold mb-1">Loose Return Reason</label>
                                <input type="text" name="reason" id="looseReturnReason" class="form-control form-control-sm" placeholder="e.g. Market Return, Damaged, Expired">
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
                                $cartonRate = (float)($p['carton_rate'] > 0 ? $p['carton_rate'] : $p['wholesale_rate']);
                                $pieceRate  = (float)($p['piece_rate'] > 0 ? $p['piece_rate'] : ($pcsPerCtn > 0 ? $cartonRate / $pcsPerCtn : $cartonRate));
                            ?>
                                <div class="pos-product-card" 
                                     id="card_prod_<?= $p['id'] ?>"
                                     data-id="<?= $p['id'] ?>"
                                     data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                     data-code="<?= htmlspecialchars($p['product_code']) ?>"
                                     data-cat="<?= $p['category_id'] ?>"
                                     data-supplier-id="<?= (int)($p['supplier_id'] ?? 0) ?>"
                                     data-standard-rate="<?= $cartonRate ?>"
                                     data-rate="<?= $cartonRate ?>"
                                     data-carton-rate="<?= $cartonRate ?>"
                                     data-piece-rate="<?= $pieceRate ?>"
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
                                        <div>
                                            <div class="prod-price text-danger" id="card_rate_label_<?= $p['id'] ?>" style="font-size: 13px; line-height: 1.2;">
                                                Rate: <?= DEFAULT_CURRENCY ?> <?= number_format($cartonRate, 0) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Ctn</span>
                                            </div>
                                            <div class="text-dark fw-semibold" style="font-size: 10.5px; line-height: 1.1;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format($packsPerCtn > 0 ? ($cartonRate / $packsPerCtn) : $cartonRate, 2) ?> <span class="text-muted fw-normal" style="font-size: 9.5px;">/ Pack</span>
                                            </div>
                                            <div class="text-secondary fw-semibold" style="font-size: 11px;">
                                                <?= DEFAULT_CURRENCY ?> <?= number_format($pieceRate, 2) ?> <span class="text-muted fw-normal" style="font-size: 10px;">/ Pc</span>
                                            </div>
                                        </div>
                                        <div class="prod-stock text-end">
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

                        <!-- Scheme / Free Loose Pieces Bar -->
                        <div class="pos-discount-mode-bar bg-light p-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <span class="small fw-bold text-secondary"><i class="bi bi-tag-fill me-1 text-primary"></i> Trade Scheme:</span>
                                <div class="form-check form-switch m-0 d-inline-flex align-items-center gap-2">
                                    <input class="form-check-input cursor-pointer" type="checkbox" role="switch" name="free_loose_pieces" id="freeLoosePiecesToggle" value="1" onchange="onFreeLoosePiecesChange()">
                                    <label class="form-check-label small fw-bold text-success cursor-pointer" for="freeLoosePiecesToggle" title="Trade Scheme: Returned loose pieces will be zero-credit (Rs 0), but will be restocked in warehouse">
                                        <i class="bi bi-gift-fill me-1 text-warning"></i> Free Loose Pcs (سکیم / بونس پیس مفت)
                                    </label>
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
                                        <th style="width: 14%;" class="text-end">Return Rate</th>
                                        <th style="width: 11%;" class="text-center">Condition</th>
                                        <th style="width: 13%;" class="text-end">Credit Total</th>
                                        <th style="width: 30px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody">
                                    <tr id="emptyCartRow">
                                        <td colspan="9" class="text-center py-5 text-muted">
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
            </div><!-- /#viewLoose -->
        </form>
    </div>
</div>

<!-- Embedded Product Cache -->
<script>
    const DMS_PRODUCTS = <?= json_encode($products) ?>;
    const RECENT_INVOICES = <?= json_encode($recentInvoices) ?>;
    const productMap = {};
    DMS_PRODUCTS.forEach(p => { productMap[p.id] = p; });
    const TARGET_SETTLEMENT_AMOUNT = <?= (float)$targetAmount ?>;
    const PRE_INVOICE_ID = <?= (int)$preInvoiceId ?>;
</script>

<script>
let customerRateMap = {}; // product_id -> { rate, discount_percent, invoice_number, invoice_date }
let currentInvoiceItems = [];
let currentReturnMode = 'bill_wise';
let currentBillData = null;

function switchReturnMode(mode) {
    currentReturnMode = mode;
    const viewBillWise = document.getElementById('viewBillWise');
    const viewLoose = document.getElementById('viewLoose');
    const tabBtnBillWise = document.getElementById('tabBtnBillWise');
    const tabBtnLoose = document.getElementById('tabBtnLoose');
    const bwCustomerId = document.getElementById('bwCustomerId');
    const customerSelect = document.getElementById('customerSelect');

    if (mode === 'bill_wise') {
        viewBillWise.classList.remove('d-none');
        viewLoose.classList.add('d-none');
        tabBtnBillWise.className = 'btn btn-primary fw-bold';
        tabBtnLoose.className = 'btn btn-outline-secondary';
        if (bwCustomerId) bwCustomerId.disabled = false;
        if (customerSelect) customerSelect.disabled = true;
    } else {
        viewBillWise.classList.add('d-none');
        viewLoose.classList.remove('d-none');
        tabBtnBillWise.className = 'btn btn-outline-secondary';
        tabBtnLoose.className = 'btn btn-primary fw-bold';
        if (bwCustomerId) bwCustomerId.disabled = true;
        if (customerSelect) customerSelect.disabled = false;
    }
}

function onBillWiseInvoiceSelected(invoiceId) {
    if (!invoiceId) {
        document.getElementById('billWiseDetailsCard').classList.add('d-none');
        document.getElementById('billWiseTableCard').classList.add('d-none');
        document.getElementById('billWiseEmptyPrompt').classList.remove('d-none');
        currentBillData = null;
        return;
    }

    fetch(`../../ajax/returns/get_invoice_items.php?invoice_id=${invoiceId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('Error loading invoice: ' + (data.message || 'Unknown error'));
                return;
            }

            currentBillData = data.data;
            const inv = currentBillData.invoice;
            const items = currentBillData.items || [];

            // Update customer id
            const bwCustomerId = document.getElementById('bwCustomerId');
            if (bwCustomerId) bwCustomerId.value = inv.customer_id;

            // Update Meta Card
            document.getElementById('bwMetaShopName').innerText = `${inv.shop_name} (${inv.customer_code})`;
            document.getElementById('bwMetaAreaPhone').innerText = `Area: ${inv.area_name || 'N/A'} | Phone: ${inv.customer_phone || 'N/A'}`;
            document.getElementById('bwMetaBillNo').innerText = `#${inv.invoice_number}`;
            document.getElementById('bwMetaDate').innerText = `Date: ${inv.invoice_date}`;
            document.getElementById('bwMetaSalesman').innerText = inv.salesman_name || 'Route Salesman';
            
            const lsElem = document.getElementById('bwMetaLoadSheet');
            if (inv.load_number) {
                lsElem.innerText = `Load Form: #${inv.load_number}`;
                lsElem.className = 'badge bg-primary';
            } else {
                lsElem.innerText = 'No Direct Load Sheet';
                lsElem.className = 'badge bg-secondary';
            }

            document.getElementById('bwMetaNetTotal').innerText = `Rs. ${parseFloat(inv.net_amount).toLocaleString('en-US', {minimumFractionDigits:2})}`;
            const retAmt = parseFloat(inv.return_amount || 0);
            document.getElementById('bwMetaPrevReturns').innerText = retAmt > 0 ? `Rs. ${retAmt.toLocaleString()} Already Returned` : '0 Prev Returns';
            
            const balance = parseFloat(inv.current_balance || 0);
            document.getElementById('bwMetaBalance').innerText = `Rs. ${balance.toLocaleString('en-US', {minimumFractionDigits:2})}`;

            // Load Sheet Alert
            const lsAlert = document.getElementById('bwLoadSheetAlert');
            const lsText = document.getElementById('bwLoadSheetText');
            if (inv.load_number) {
                lsAlert.classList.remove('d-none');
                lsText.innerHTML = `This return is tied to <strong>Load Form #${inv.load_number}</strong> (${inv.salesman_name || 'Salesman'}). Returned quantities and deductions will automatically update on the loading summary!`;
            } else {
                lsAlert.classList.add('d-none');
            }

            // Render Table Rows
            const tbody = document.getElementById('billWiseTableBody');
            tbody.innerHTML = '';

            items.forEach((it, idx) => {
                const tr = document.createElement('tr');
                tr.id = `bw_row_${idx}`;
                const isFullyRet = it.is_fully_returned;
                if (isFullyRet) tr.classList.add('table-secondary', 'opacity-75');

                let billedParts = [];
                if (it.billed_carton_qty > 0) billedParts.push(`${it.billed_carton_qty} Ctn`);
                if (it.billed_pack_qty > 0) billedParts.push(`${it.billed_pack_qty} Pck`);
                if (it.billed_pcs_qty > 0) billedParts.push(`${it.billed_pcs_qty} Pcs`);
                if (billedParts.length === 0) billedParts.push('0');
                const billedQtyDisplay = billedParts.join(' + ');

                let prevRetParts = [];
                if (it.returned_cartons > 0) prevRetParts.push(`${it.returned_cartons}c`);
                if (it.returned_packs > 0) prevRetParts.push(`${it.returned_packs}pk`);
                if (it.returned_pcs > 0) prevRetParts.push(`${it.returned_pcs}p`);
                const prevRetDisplay = prevRetParts.length > 0 
                    ? `<span class="badge bg-warning-subtle text-dark">${prevRetParts.join(' ')}</span>` 
                    : '<span class="text-muted">0</span>';

                const unitLabel = it.unit_code === 'pcs' ? '/ Piece' : (it.unit_code === 'pack' ? '/ Pack' : '/ Carton');

                tr.innerHTML = `
                    <td class="text-center text-muted small">${idx + 1}</td>
                    <td class="ps-3">
                        <input type="hidden" name="item_product_id[]" value="${it.product_id}">
                        <input type="hidden" name="item_unit[]" value="${it.unit_code || 'ctn'}">
                        <input type="hidden" name="item_rate[]" value="${it.rate}">
                        <input type="hidden" name="item_discount_percent[]" value="${it.discount_percent}">
                        <div class="fw-bold text-dark">${escapeHtml(it.product_name)}</div>
                        <small class="text-muted">Pack: ${escapeHtml(it.packing_desc)} &bull; Code: ${escapeHtml(it.product_code)}</small>
                    </td>
                    <td class="text-center fw-semibold">
                        <span class="badge bg-light text-dark border px-2 py-1">${billedQtyDisplay}</span>
                    </td>
                    <td class="text-center fw-bold">
                        Rs. ${parseFloat(it.rate).toFixed(2)}
                        <small class="text-muted d-block" style="font-size:10px;">${unitLabel}</small>
                        ${parseFloat(it.discount_percent) > 0 ? `<small class="text-danger d-block">-${it.discount_percent}%</small>` : ''}
                    </td>
                    <td class="text-center small text-muted">
                        ${prevRetDisplay}
                    </td>
                    <td class="bg-warning-subtle">
                        <input type="number" step="1" min="0" max="${it.remaining_cartons}" name="item_carton_qty[]" id="bw_ctn_${idx}" 
                               class="form-control form-control-sm text-center fw-bold ${it.remaining_cartons <= 0 ? 'bg-light' : ''}" 
                               value="0" ${it.remaining_cartons <= 0 ? 'readonly' : ''} oninput="recalcBillWiseRow(${idx})">
                        <small class="text-muted d-block text-center" style="font-size:10px;">Max: ${it.remaining_cartons}</small>
                    </td>
                    <td class="bg-warning-subtle">
                        <input type="number" step="1" min="0" max="${it.remaining_packs}" name="item_pack_qty[]" id="bw_pack_${idx}" 
                               class="form-control form-control-sm text-center fw-bold ${it.remaining_packs <= 0 ? 'bg-light' : ''}" 
                               value="0" ${it.remaining_packs <= 0 ? 'readonly' : ''} oninput="recalcBillWiseRow(${idx})">
                        <small class="text-muted d-block text-center" style="font-size:10px;">Max: ${it.remaining_packs}</small>
                    </td>
                    <td class="bg-warning-subtle">
                        <input type="number" step="1" min="0" max="${it.remaining_pcs}" name="item_pcs_qty[]" id="bw_pcs_${idx}" 
                               class="form-control form-control-sm text-center fw-bold ${it.remaining_pcs <= 0 ? 'bg-light' : ''}" 
                               value="0" ${it.remaining_pcs <= 0 ? 'readonly' : ''} oninput="recalcBillWiseRow(${idx})">
                        <small class="text-muted d-block text-center" style="font-size:10px;">Max: ${it.remaining_pcs}</small>
                    </td>
                    <td>
                        <select name="item_condition[]" id="bw_cond_${idx}" class="form-select form-select-sm py-0 px-1" style="font-size: 11px;">
                            <option value="saleable">Saleable (Restock)</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                        </select>
                    </td>
                    <td class="text-end pe-3 fw-bold text-muted fs-6" id="bw_line_total_${idx}">
                        Rs. 0.00
                    </td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('billWiseDetailsCard').classList.remove('d-none');
            document.getElementById('billWiseTableCard').classList.remove('d-none');
            document.getElementById('billWiseEmptyPrompt').classList.add('d-none');

            recalcBillWiseSummary();
        })
        .catch(err => {
            console.error(err);
            alert('Failed to load invoice items. Check internet connection or server.');
        });
}

function recalcBillWiseRow(idx) {
    if (!currentBillData || !currentBillData.items || !currentBillData.items[idx]) return;
    const it = currentBillData.items[idx];

    const ctnInput  = document.getElementById(`bw_ctn_${idx}`);
    const packInput = document.getElementById(`bw_pack_${idx}`);
    const pcsInput  = document.getElementById(`bw_pcs_${idx}`);

    let ctn  = parseFloat(ctnInput?.value || 0);
    let pack = parseFloat(packInput?.value || 0);
    let pcs  = parseFloat(pcsInput?.value || 0);

    if (isNaN(ctn) || ctn < 0) { ctn = 0; if (ctnInput) ctnInput.value = 0; }
    if (isNaN(pack) || pack < 0) { pack = 0; if (packInput) packInput.value = 0; }
    if (isNaN(pcs) || pcs < 0) { pcs = 0; if (pcsInput) pcsInput.value = 0; }

    const pcsPerPack = it.pcs_per_pack > 0 ? it.pcs_per_pack : 1;
    const packsPerCtn = it.packs_per_ctn > 0 ? it.packs_per_ctn : 1;
    const pcsPerCtn = it.pcs_per_ctn > 0 ? it.pcs_per_ctn : (packsPerCtn * pcsPerPack);

    // Limit check: cap to remaining base pieces
    const maxPieces = it.remaining_base_pieces || ((it.remaining_cartons * pcsPerCtn) + (it.remaining_packs * pcsPerPack) + it.remaining_pcs);
    let enteredPieces = (ctn * pcsPerCtn) + (pack * pcsPerPack) + pcs;

    if (enteredPieces > maxPieces && maxPieces > 0) {
        let availPcs = maxPieces - (ctn * pcsPerCtn) - (pack * pcsPerPack);
        if (availPcs >= 0) {
            pcs = availPcs;
            if (pcsInput) pcsInput.value = pcs;
        } else {
            let availPacks = Math.floor((maxPieces - (ctn * pcsPerCtn)) / pcsPerPack);
            if (availPacks >= 0) {
                pack = availPacks;
                if (packInput) packInput.value = pack;
                pcs = Math.max(0, maxPieces - (ctn * pcsPerCtn) - (pack * pcsPerPack));
                if (pcsInput) pcsInput.value = pcs;
            } else {
                ctn = Math.floor(maxPieces / pcsPerCtn);
                if (ctnInput) ctnInput.value = ctn;
                pack = Math.floor((maxPieces - (ctn * pcsPerCtn)) / pcsPerPack);
                if (packInput) packInput.value = pack;
                pcs = Math.max(0, maxPieces - (ctn * pcsPerCtn) - (pack * pcsPerPack));
                if (pcsInput) pcsInput.value = pcs;
            }
        }
    }

    const ctnRate = it.carton_rate || (it.rate * (it.unit_code === 'pcs' ? pcsPerCtn : (it.unit_code === 'pack' ? packsPerCtn : 1)));
    const packRate = it.pack_rate || (ctnRate / packsPerCtn);
    const pieceRate = it.piece_rate || (pcsPerCtn > 0 ? ctnRate / pcsPerCtn : ctnRate);

    const gross = (ctn * ctnRate) + (pack * packRate) + (pcs * pieceRate);
    const disc = (gross * it.discount_percent) / 100;
    const net = Math.max(0, gross - disc);

    const lineTotalElem = document.getElementById(`bw_line_total_${idx}`);
    if (lineTotalElem) {
        lineTotalElem.innerText = `Rs. ${net.toFixed(2)}`;
        lineTotalElem.className = net > 0 ? 'text-end pe-3 fw-bold text-danger fs-6' : 'text-end pe-3 fw-bold text-muted fs-6';
    }

    recalcBillWiseSummary();
}

function recalcBillWiseSummary() {
    if (!currentBillData || !currentBillData.items) return;

    let totCartons = 0;
    let totPacks   = 0;
    let totPieces  = 0;
    let totAmount  = 0;

    currentBillData.items.forEach((it, idx) => {
        const ctn  = parseFloat(document.getElementById(`bw_ctn_${idx}`)?.value || 0);
        const pack = parseFloat(document.getElementById(`bw_pack_${idx}`)?.value || 0);
        const pcs  = parseFloat(document.getElementById(`bw_pcs_${idx}`)?.value || 0);

        const pcsPerPack = it.pcs_per_pack > 0 ? it.pcs_per_pack : 1;
        const packsPerCtn = it.packs_per_ctn > 0 ? it.packs_per_ctn : 1;
        const pcsPerCtn = it.pcs_per_ctn > 0 ? it.pcs_per_ctn : (packsPerCtn * pcsPerPack);

        const ctnRate = it.carton_rate || (it.rate * (it.unit_code === 'pcs' ? pcsPerCtn : (it.unit_code === 'pack' ? packsPerCtn : 1)));
        const packRate = it.pack_rate || (ctnRate / packsPerCtn);
        const pieceRate = it.piece_rate || (pcsPerCtn > 0 ? ctnRate / pcsPerCtn : ctnRate);

        const gross = (ctn * ctnRate) + (pack * packRate) + (pcs * pieceRate);
        const disc = (gross * it.discount_percent) / 100;
        const net = Math.max(0, gross - disc);

        totCartons += ctn;
        totPacks   += pack;
        totPieces  += pcs;
        totAmount  += net;
    });

    document.getElementById('bwSummaryCartons').innerText = `${totCartons} Ctns`;
    const bwPacksElem = document.getElementById('bwSummaryPacks');
    if (bwPacksElem) bwPacksElem.innerText = `${totPacks} Packs`;
    document.getElementById('bwSummaryPieces').innerText = `${totPieces} Pcs`;
    document.getElementById('bwSummaryTotalAmount').innerText = `Rs. ${totAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

    const curBal = parseFloat(currentBillData.invoice.current_balance || 0);
    const newBal = curBal - totAmount;
    document.getElementById('bwSummaryNewBalance').innerText = `Rs. ${newBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('bwSummaryBalanceDiff').innerText = `Reduction: -Rs. ${totAmount.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
}

function billWiseReturnAll() {
    if (!currentBillData || !currentBillData.items) return;
    currentBillData.items.forEach((it, idx) => {
        const ctnInput  = document.getElementById(`bw_ctn_${idx}`);
        const packInput = document.getElementById(`bw_pack_${idx}`);
        const pcsInput  = document.getElementById(`bw_pcs_${idx}`);

        if (ctnInput) ctnInput.value = it.remaining_cartons || 0;
        if (packInput) packInput.value = it.remaining_packs || 0;
        if (pcsInput) pcsInput.value = (it.remaining_cartons === 0 && it.remaining_packs === 0) ? (it.remaining_pcs || 0) : 0;
        recalcBillWiseRow(idx);
    });
}

function billWiseResetAll() {
    if (!currentBillData || !currentBillData.items) return;
    currentBillData.items.forEach((it, idx) => {
        const ctnInput  = document.getElementById(`bw_ctn_${idx}`);
        const packInput = document.getElementById(`bw_pack_${idx}`);
        const pcsInput  = document.getElementById(`bw_pcs_${idx}`);
        if (ctnInput) ctnInput.value = 0;
        if (packInput) packInput.value = 0;
        if (pcsInput) pcsInput.value = 0;
        recalcBillWiseRow(idx);
    });
}

let invoiceDropdownActiveIndex = -1;
let currentDropdownInvoices = [];
let invoiceSearchDebounceTimer = null;

function formatDate(dStr) {
    if (!dStr) return '';
    try {
        const parts = dStr.split('-');
        if (parts.length === 3) {
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const m = parseInt(parts[1], 10) - 1;
            return `${parts[2]}-${months[m] || parts[1]}`;
        }
        return dStr;
    } catch {
        return dStr;
    }
}

function renderInvoiceDropdown(invoices, filterText = '') {
    const listElem = document.getElementById('invoiceDropdownList');
    if (!listElem) return;
    currentDropdownInvoices = invoices || [];
    invoiceDropdownActiveIndex = -1;

    if (!invoices || invoices.length === 0) {
        listElem.innerHTML = `
            <div class="p-3 text-center text-muted small">
                <i class="bi bi-exclamation-circle text-warning me-1"></i>
                No bills found matching "${escapeHtml(filterText)}"
            </div>
        `;
        listElem.style.display = 'block';
        return;
    }

    const currentSelectedId = parseInt(document.getElementById('billWiseInvoiceSelect')?.value || 0, 10);

    let html = `<div class="py-1">`;
    invoices.forEach((inv, index) => {
        const isSelected = (parseInt(inv.id, 10) === currentSelectedId);
        const dateStr = inv.invoice_date ? formatDate(inv.invoice_date) : '';
        const amtStr = parseFloat(inv.net_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        const loadBadge = inv.load_number ? `<span class="badge ${isSelected ? 'bg-white text-primary' : 'bg-primary-subtle text-primary border'} ms-1">#${escapeHtml(inv.load_number)}</span>` : '';

        html += `
            <div class="py-2 px-3 border-bottom invoice-dropdown-item ${isSelected ? 'active' : ''}" 
                 id="inv_opt_${index}" 
                 data-index="${index}" 
                 onmousedown="selectInvoiceOption(${index}); event.preventDefault();"
                 onclick="selectInvoiceOption(${index})">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold ${isSelected ? 'text-white' : 'text-navy'}">
                        <i class="bi bi-receipt me-1 ${isSelected ? 'text-white' : 'text-primary'}"></i>#${escapeHtml(inv.invoice_number)}
                        <small class="${isSelected ? 'text-white-50' : 'text-muted'} ms-1 fw-normal">(${dateStr})</small>
                    </span>
                    <span class="badge ${isSelected ? 'bg-light text-dark' : 'bg-danger-subtle text-danger border border-danger-subtle'} fw-bold">
                        Rs. ${amtStr}
                    </span>
                </div>
                <div class="d-flex justify-content-between align-items-center ${isSelected ? 'text-white-50' : 'text-muted'} small" style="font-size: 11.5px;">
                    <span class="text-truncate me-2">
                        <i class="bi bi-shop me-1"></i><strong>${escapeHtml(inv.shop_name)}</strong> &bull; ${escapeHtml(inv.area_name || 'N/A')}
                    </span>
                    <span>${loadBadge}</span>
                </div>
            </div>
        `;
    });
    html += `</div>`;

    listElem.innerHTML = html;
    listElem.style.display = 'block';

    // Auto-highlight selected bill or top result
    if (currentSelectedId > 0) {
        const selectedIdx = invoices.findIndex(i => parseInt(i.id, 10) === currentSelectedId);
        if (selectedIdx >= 0) {
            invoiceDropdownActiveIndex = selectedIdx;
            const optElem = document.getElementById(`inv_opt_${selectedIdx}`);
            if (optElem) {
                setTimeout(() => optElem.scrollIntoView({ block: 'nearest' }), 10);
            }
        }
    }
}

function selectInvoiceOption(index) {
    if (!currentDropdownInvoices || !currentDropdownInvoices[index]) return;
    const inv = currentDropdownInvoices[index];
    
    const hiddenInput = document.getElementById('billWiseInvoiceSelect');
    const searchInput = document.getElementById('invoiceSearchInput');
    const clearBtn    = document.getElementById('btnClearInvoiceSelection');
    const listElem    = document.getElementById('invoiceDropdownList');

    if (hiddenInput) hiddenInput.value = inv.id;
    if (searchInput) {
        const dateStr = inv.invoice_date ? formatDate(inv.invoice_date) : '';
        const amtStr = parseFloat(inv.net_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        const label = `#${inv.invoice_number} (${dateStr}) - ${inv.shop_name} - Rs. ${amtStr}`;
        searchInput.value = label;
        searchInput.setAttribute('data-selected-label', label);
    }
    if (clearBtn) clearBtn.classList.remove('d-none');
    if (listElem) listElem.style.display = 'none';

    onBillWiseInvoiceSelected(inv.id);
}

function clearInvoiceSelection() {
    const hiddenInput = document.getElementById('billWiseInvoiceSelect');
    const searchInput = document.getElementById('invoiceSearchInput');
    const clearBtn    = document.getElementById('btnClearInvoiceSelection');
    const listElem    = document.getElementById('invoiceDropdownList');

    if (hiddenInput) hiddenInput.value = '';
    if (searchInput) {
        searchInput.value = '';
        searchInput.removeAttribute('data-selected-label');
        searchInput.focus();
    }
    if (clearBtn) clearBtn.classList.add('d-none');
    if (listElem) listElem.style.display = 'none';

    onBillWiseInvoiceSelected('');
}

function filterInvoiceDropdown(term) {
    const searchInput = document.getElementById('invoiceSearchInput');
    const selectedLabel = (searchInput ? searchInput.getAttribute('data-selected-label') : '') || '';
    
    let rawTerm = (term || '').trim();
    // If the input text is exactly what was previously selected, show all invoices
    if (rawTerm && selectedLabel && rawTerm === selectedLabel) {
        rawTerm = '';
    }

    const q = rawTerm.toLowerCase();
    const tokens = q.split(/\s+/).filter(t => t.length > 0);
    
    // 1. Filter local RECENT_INVOICES immediately
    const localMatches = (typeof RECENT_INVOICES !== 'undefined' ? RECENT_INVOICES : []).filter(inv => {
        if (tokens.length === 0) return true;
        const hay = `${inv.invoice_number || ''} ${inv.shop_name || ''} ${inv.customer_code || ''} ${inv.area_name || ''} ${inv.load_number || ''} ${inv.salesman_name || ''} ${inv.net_amount || ''}`.toLowerCase();
        return tokens.every(t => hay.includes(t));
    });

    renderInvoiceDropdown(localMatches, q);

    // 2. Query backend live for full-database search
    if (tokens.length > 0 && q.length >= 2) {
        clearTimeout(invoiceSearchDebounceTimer);
        invoiceSearchDebounceTimer = setTimeout(() => {
            fetch(`../../ajax/returns/search_invoices.php?q=${encodeURIComponent(rawTerm)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data) {
                        const seenIds = new Set(localMatches.map(m => parseInt(m.id, 10)));
                        const combined = [...localMatches];
                        data.data.forEach(item => {
                            const iid = parseInt(item.id, 10);
                            if (!seenIds.has(iid)) {
                                seenIds.add(iid);
                                combined.push(item);
                            }
                        });
                        renderInvoiceDropdown(combined, q);
                    }
                })
                .catch(() => {});
        }, 150);
    }
}

function highlightActiveDropdownItem() {
    document.querySelectorAll('.invoice-dropdown-item').forEach((elem, idx) => {
        if (idx === invoiceDropdownActiveIndex) {
            elem.classList.add('highlighted');
            elem.scrollIntoView({ block: 'nearest' });
        } else {
            elem.classList.remove('highlighted');
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.getElementById('customerSelect');
    const conditionSelect = document.getElementById('conditionSelect');
    const productSearch  = document.getElementById('productSearch');

    // Default mode: bill_wise
    if (PRE_INVOICE_ID > 0) {
        switchReturnMode('bill_wise');
        const sel = document.getElementById('billWiseInvoiceSelect');
        if (sel) sel.value = PRE_INVOICE_ID;
        onBillWiseInvoiceSelected(PRE_INVOICE_ID);
    } else {
        switchReturnMode('bill_wise');
    }

    const invoiceSearch = document.getElementById('invoiceSearchInput');
    const invoiceDropdown = document.getElementById('invoiceDropdownList');
    const btnToggleDropdown = document.getElementById('btnToggleInvoiceDropdown');

    if (invoiceSearch) {
        if (invoiceSearch.value.trim().length > 0) {
            invoiceSearch.setAttribute('data-selected-label', invoiceSearch.value.trim());
        }

        invoiceSearch.addEventListener('focus', function() {
            this.select();
            filterInvoiceDropdown(this.value);
        });

        invoiceSearch.addEventListener('click', function() {
            filterInvoiceDropdown(this.value);
        });

        invoiceSearch.addEventListener('input', function() {
            filterInvoiceDropdown(this.value);
        });

        invoiceSearch.addEventListener('keydown', function(e) {
            const isOpen = invoiceDropdown && invoiceDropdown.style.display === 'block';

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) {
                    filterInvoiceDropdown(this.value);
                    return;
                }
                if (currentDropdownInvoices.length > 0) {
                    invoiceDropdownActiveIndex = Math.min(invoiceDropdownActiveIndex + 1, currentDropdownInvoices.length - 1);
                    highlightActiveDropdownItem();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (isOpen && currentDropdownInvoices.length > 0) {
                    invoiceDropdownActiveIndex = Math.max(invoiceDropdownActiveIndex - 1, 0);
                    highlightActiveDropdownItem();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (isOpen && invoiceDropdownActiveIndex >= 0 && currentDropdownInvoices[invoiceDropdownActiveIndex]) {
                    selectInvoiceOption(invoiceDropdownActiveIndex);
                } else if (isOpen && currentDropdownInvoices.length === 1) {
                    selectInvoiceOption(0);
                }
            } else if (e.key === 'Escape') {
                if (invoiceDropdown) invoiceDropdown.style.display = 'none';
            }
        });
    }

    if (btnToggleDropdown) {
        btnToggleDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!invoiceDropdown || invoiceDropdown.style.display !== 'block') {
                invoiceSearch.focus();
                filterInvoiceDropdown('');
            } else {
                invoiceDropdown.style.display = 'none';
            }
        });
    }

    document.addEventListener('click', function(e) {
        if (!invoiceDropdown) return;
        if (!invoiceDropdown.contains(e.target) && e.target !== invoiceSearch && (!btnToggleDropdown || !btnToggleDropdown.contains(e.target))) {
            invoiceDropdown.style.display = 'none';
        }
    });

    // Customer change loads billing history & exact rates
    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            onCustomerChanged(this.value);
        });

        // If pre-selected customer
        if (customerSelect.value) {
            onCustomerChanged(customerSelect.value, PRE_INVOICE_ID);
        }
    }

    // Invoice selection change
    const invSelect = document.getElementById('invoiceSelect');
    if (invSelect) {
        invSelect.addEventListener('change', function () {
            onInvoiceChanged(this.value);
        });
    }

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

    const catalogSupplierFilter = document.getElementById('catalogSupplierFilter');
    if (catalogSupplierFilter) {
        catalogSupplierFilter.addEventListener('change', function () {
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
                const uLabel = hist.unit === 'pcs' ? '/ Pc' : (hist.unit === 'pack' ? '/ Pack' : '/ Ctn');
                rateLabel.innerHTML = `<strong>Last Sold: <?= DEFAULT_CURRENCY ?> ${hist.rate.toFixed(0)} <span class="fw-normal" style="font-size:10px;">${uLabel}</span></strong> <small class="text-muted d-block" style="font-size: 10px;">${hist.invoice_number}</small>`;
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

function isFreeLoosePiecesActive() {
    const el = document.getElementById('freeLoosePiecesToggle');
    return el ? el.checked : false;
}

function onFreeLoosePiecesChange() {
    recalculateAllRows();
    recalculateCartSummary();
}

function calculateReturnLineGross(item) {
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
            const unitCode    = item.unit_code || (parseInt(item.unit_id, 10) === 4 ? 'pcs' : (parseInt(item.unit_id, 10) === 3 ? 'pack' : 'ctn'));

            const ctnRate  = item.carton_rate || (unitCode === 'pcs' ? (billedRate * pcsPerCtn) : (unitCode === 'pack' ? (billedRate * packsPerCtn) : billedRate));
            const packRate = item.pack_rate || (unitCode === 'pack' ? billedRate : (packsPerCtn > 0 ? ctnRate / packsPerCtn : ctnRate));
            const pieceRate = item.piece_rate || (unitCode === 'pcs' ? billedRate : (pcsPerCtn > 0 ? ctnRate / pcsPerCtn : ctnRate));

            cartItems[pid] = {
                product_id: pid,
                product_name: item.product_name,
                product_code: item.product_code,
                packs_per_ctn: packsPerCtn,
                pcs_per_pack: pcsPerPack,
                pcs_per_ctn: pcsPerCtn,
                unit: unitCode,
                carton_rate: ctnRate,
                pack_rate: packRate,
                piece_rate: pieceRate,
                carton_qty: parseFloat(item.billed_carton_qty ?? item.carton_qty) || 0,
                pack_qty: parseFloat(item.billed_pack_qty ?? item.pack_qty) || 0,
                pcs_qty: parseFloat(item.billed_pcs_qty ?? item.pcs_qty) || 0,
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
    const catalogSupplierFilter = document.getElementById('catalogSupplierFilter');
    const selectedSupplier = catalogSupplierFilter ? catalogSupplierFilter.value : '';

    document.querySelectorAll('.pos-product-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const code = (card.getAttribute('data-code') || '').toLowerCase();
        const cat  = card.getAttribute('data-cat');
        const sup  = card.getAttribute('data-supplier-id');
        const inInvoice = card.getAttribute('data-in-invoice') === '1';

        const matchQuery = !query || name.includes(query) || code.includes(query);
        const matchSup   = !selectedSupplier || sup === selectedSupplier;
        let matchCat = false;

        if (!activeCat) {
            matchCat = true;
        } else if (activeCat === 'from_invoice') {
            matchCat = inInvoice;
        } else {
            matchCat = (cat === activeCat);
        }

        if (matchQuery && matchCat && matchSup) {
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
        if (cartItems[productId].unit === 'pcs') {
            cartItems[productId].pcs_qty += cartItems[productId].pcs_per_ctn > 0 ? cartItems[productId].pcs_per_ctn : 1;
        } else if (cartItems[productId].unit === 'pack') {
            cartItems[productId].pack_qty += 1;
        } else {
            cartItems[productId].carton_qty += 1;
        }
        updateCartRow(productId);
    } else {
        const packsPerCtn = parseFloat(prod.carton_qty) > 0 ? parseFloat(prod.carton_qty) : 1;
        const pcsPerPack  = parseFloat(prod.pack_qty) > 0 ? parseFloat(prod.pack_qty) : 1;
        const pcsPerCtn   = packsPerCtn * pcsPerPack;
        
        const cartonRate = parseFloat(prod.carton_rate) || parseFloat(prod.wholesale_rate) || 0;
        const packRate   = packsPerCtn > 0 ? (cartonRate / packsPerCtn) : cartonRate;
        const pieceRate  = parseFloat(prod.piece_rate) || (pcsPerCtn > 0 ? cartonRate / pcsPerCtn : cartonRate);

        let returnRate = cartonRate;
        let discPct = 0;
        let sourceInfo = '';
        let ctnRate = cartonRate;
        let pckRate = packRate;
        let pcRate  = pieceRate;

        let initialUnit = 'ctn';
        let initialCtnQty = 1;
        let initialPackQty = 0;
        let initialPcsQty = 0;
        let initialRate = ctnRate;

        if (customerRateMap[productId]) {
            returnRate = customerRateMap[productId].rate;
            discPct = customerRateMap[productId].discount_percent;
            sourceInfo = `Billed #${customerRateMap[productId].invoice_number}`;

            const custUnit = customerRateMap[productId].unit;
            if (custUnit === 'pcs') {
                initialUnit = 'pcs';
                initialCtnQty = 0;
                initialPackQty = 0;
                initialPcsQty = customerRateMap[productId].pcs_qty > 0 ? customerRateMap[productId].pcs_qty : 1;
                pcRate = returnRate;
                pckRate = pcsPerPack > 0 ? (returnRate * pcsPerPack) : returnRate;
                ctnRate = pcsPerCtn > 0 ? (returnRate * pcsPerCtn) : returnRate;
                initialRate = pcRate;
            } else if (custUnit === 'pack') {
                initialUnit = 'pack';
                initialCtnQty = 0;
                initialPackQty = customerRateMap[productId].pack_qty > 0 ? customerRateMap[productId].pack_qty : 1;
                initialPcsQty = 0;
                pckRate = returnRate;
                ctnRate = packsPerCtn > 0 ? (returnRate * packsPerCtn) : returnRate;
                pcRate = pcsPerPack > 0 ? (returnRate / pcsPerPack) : returnRate;
                initialRate = pckRate;
            } else {
                initialUnit = 'ctn';
                initialCtnQty = customerRateMap[productId].carton_qty > 0 ? customerRateMap[productId].carton_qty : 1;
                initialPackQty = 0;
                initialPcsQty = 0;
                ctnRate = returnRate;
                pckRate = packsPerCtn > 0 ? (returnRate / packsPerCtn) : returnRate;
                pcRate = pcsPerCtn > 0 ? (returnRate / pcsPerCtn) : returnRate;
                initialRate = ctnRate;
            }
        }

        const globalCond = document.getElementById('conditionSelect').value || 'saleable';

        cartItems[productId] = {
            product_id: productId,
            product_name: prod.product_name,
            product_code: prod.product_code,
            packs_per_ctn: packsPerCtn,
            pcs_per_pack: pcsPerPack,
            pcs_per_ctn: pcsPerCtn,
            unit: initialUnit,
            carton_rate: ctnRate,
            pack_rate: pckRate,
            piece_rate: pcRate,
            carton_qty: initialCtnQty,
            pack_qty: initialPackQty,
            pcs_qty: initialPcsQty,
            rate: initialRate,
            discount_percent: discPct,
            condition: globalCond,
            line_total: 0,
            source_inv: sourceInfo
        };
        const initGross = calculateReturnLineGross(cartItems[productId]);
        cartItems[productId].line_total = Math.max(0, initGross - ((initGross * discPct) / 100));

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

    const rowInput = (cartItems[productId]?.unit === 'pcs')
        ? document.getElementById('input_pcs_' + productId)
        : ((cartItems[productId]?.unit === 'pack') ? document.getElementById('input_pack_' + productId) : document.getElementById('input_carton_' + productId));
    if (rowInput) {
        rowInput.focus();
        rowInput.select();
    }
}

function onReturnUnitChange(productId) {
    const item = cartItems[productId];
    if (!item) return;

    const unitSel = document.getElementById('input_unit_' + productId);
    const unit = unitSel ? unitSel.value : 'ctn';
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
            const inputCarton = document.getElementById('input_carton_' + productId);
            const inputPack = document.getElementById('input_pack_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCarton) inputCarton.value = 0;
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
            const inputCarton = document.getElementById('input_carton_' + productId);
            const inputPack = document.getElementById('input_pack_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
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
            const inputCarton = document.getElementById('input_carton_' + productId);
            const inputPack = document.getElementById('input_pack_' + productId);
            const inputPcs = document.getElementById('input_pcs_' + productId);
            if (inputCarton) inputCarton.value = 1;
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
            <select name="item_unit[]" id="input_unit_${productId}" class="form-select form-select-sm text-center py-0 px-1" onchange="onReturnUnitChange(${productId})">
                <option value="ctn" ${item.unit === 'ctn' ? 'selected' : ''}>Ctn</option>
                <option value="pack" ${item.unit === 'pack' ? 'selected' : ''}>Pack</option>
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
                   class="form-control form-control-sm text-end fw-semibold pos-cart-input text-danger" 
                   value="${item.rate.toFixed(2)}" 
                   oninput="onCartRowChange(${productId})"
                   onkeydown="onCartInputKeyDown(event, ${productId}, 'rate')" required>
            <span class="small text-muted" id="lbl_rate_unit_${productId}" style="font-size: 10px;">/ ${item.unit === 'pcs' ? 'Piece' : (item.unit === 'pack' ? 'Pack' : 'Carton')}</span>
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
    const inputUnit   = document.getElementById('input_unit_' + productId);
    const inputRate   = document.getElementById('input_rate_' + productId);
    const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
    const lblTotal    = document.getElementById('lbl_total_' + productId);

    if (inputCarton) inputCarton.value = item.carton_qty;
    if (inputPack) inputPack.value = item.pack_qty;
    if (inputPcs) inputPcs.value = item.pcs_qty;
    if (inputUnit) inputUnit.value = item.unit || 'ctn';
    if (inputRate) inputRate.value = item.rate.toFixed(2);
    if (lblRateUnit) lblRateUnit.innerText = '/ ' + (item.unit === 'pcs' ? 'Piece' : (item.unit === 'pack' ? 'Pack' : 'Carton'));

    const lineGross = calculateReturnLineGross(item);
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

    // Keep carton_rate, pack_rate, and piece_rate in sync if rate input was edited
    if (item.unit === 'pcs') {
        item.piece_rate = rateVal;
        item.carton_rate = rateVal * item.pcs_per_ctn;
        item.pack_rate = item.pcs_per_pack > 0 ? (rateVal * item.pcs_per_pack) : rateVal;
    } else if (item.unit === 'pack') {
        item.pack_rate = rateVal;
        item.carton_rate = rateVal * item.packs_per_ctn;
        item.piece_rate = item.pcs_per_pack > 0 ? (rateVal / item.pcs_per_pack) : rateVal;
    } else {
        item.carton_rate = rateVal;
        item.pack_rate = item.packs_per_ctn > 0 ? (rateVal / item.packs_per_ctn) : rateVal;
        item.piece_rate = item.pcs_per_ctn > 0 ? (rateVal / item.pcs_per_ctn) : rateVal;
    }

    // Auto-switch unit if user specifically entered dedicated quantities:
    if (ctnVal === 0 && packVal > 0 && pcsVal === 0 && item.unit !== 'pack') {
        item.unit = 'pack';
        item.rate = item.pack_rate;
        const unitSel = document.getElementById('input_unit_' + productId);
        if (unitSel) unitSel.value = 'pack';
        const inputRate = document.getElementById('input_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Pack';
    } else if (ctnVal === 0 && packVal === 0 && pcsVal > 0 && item.unit !== 'pcs') {
        item.unit = 'pcs';
        item.rate = item.piece_rate;
        const unitSel = document.getElementById('input_unit_' + productId);
        if (unitSel) unitSel.value = 'pcs';
        const inputRate = document.getElementById('input_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Piece';
    } else if (ctnVal > 0 && packVal === 0 && pcsVal === 0 && item.unit !== 'ctn') {
        item.unit = 'ctn';
        item.rate = item.carton_rate;
        const unitSel = document.getElementById('input_unit_' + productId);
        if (unitSel) unitSel.value = 'ctn';
        const inputRate = document.getElementById('input_rate_' + productId);
        if (inputRate) inputRate.value = item.rate.toFixed(2);
        const lblRateUnit = document.getElementById('lbl_rate_unit_' + productId);
        if (lblRateUnit) lblRateUnit.innerText = '/ Carton';
    }

    const lineGross = calculateReturnLineGross(item);
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
        const lineGross = calculateReturnLineGross(item);
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

        const lineGross = calculateReturnLineGross(item);
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
