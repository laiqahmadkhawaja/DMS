<?php
/**
 * DMS - Distribution Management System
 * Route Settlements & Daily Reconciliation - Create Settlement (F8)
 * Matches Client Photo 1 (DAILY SALE RECIVED + CREDIT)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('settlements_create');

$pageTitle = 'Route Settlement & Daily Reconciliation';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settlements', 'url' => 'index.php'],
    ['label' => 'New Settlement', 'url' => '']
];

$preRunId  = isset($_GET['delivery_run_id']) ? (int)$_GET['delivery_run_id'] : 0;
$preDate   = isset($_GET['date']) ? sanitize_input($_GET['date']) : date('Y-m-d');
$errors    = [];

// Pre-fill salesman if delivery run provided
$defaultSalesmanId = 0;
if ($preRunId > 0) {
    $preRun = db_fetch_one("SELECT * FROM delivery_runs WHERE id = ?", [$preRunId]);
    if ($preRun) {
        $defaultSalesmanId = (int)$preRun['salesman_id'];
        $preDate = $preRun['run_date'];
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settlementDate = sanitize_input($_POST['settlement_date'] ?? date('Y-m-d'));
    $salesmanId     = (int)($_POST['salesman_id'] ?? 0);
    $deliveryRunId  = !empty($_POST['delivery_run_id']) ? (int)$_POST['delivery_run_id'] : null;
    $loadSheetId    = !empty($_POST['load_sheet_id']) ? (int)$_POST['load_sheet_id'] : null;
    if (!$loadSheetId && $deliveryRunId) {
        $foundLs = db_fetch_val("SELECT load_sheet_id FROM delivery_runs WHERE id = ?", [$deliveryRunId]);
        $loadSheetId = $foundLs ? (int)$foundLs : null;
    }
    $remarks        = sanitize_input($_POST['remarks'] ?? '');
    $itemsData      = $_POST['items'] ?? [];

    if ($salesmanId <= 0) {
        $errors[] = "Please select a delivery salesman.";
    }
    if (empty($itemsData) || !is_array($itemsData)) {
        $errors[] = "No customer bills found for settlement. Please select a salesman with active route bills.";
    }

    $totalInvoiced = 0.0;
    $totalCash     = 0.0;
    $totalCredit   = 0.0;
    $totalReturns  = 0.0;
    $validItems    = [];

    foreach ($itemsData as $invId => $row) {
        $invoiceId = (int)$invId;
        $billAmount = (float)($row['bill_amount'] ?? 0);
        $cash       = (float)($row['cash'] ?? 0);
        $credit     = (float)($row['credit'] ?? 0);
        $return     = (float)($row['return'] ?? 0);
        $remarksRow = sanitize_input($row['remarks'] ?? '');

        if ($invoiceId <= 0) continue;

        $diff = round($billAmount - ($cash + $credit + $return), 2);

        $totalInvoiced += $billAmount;
        $totalCash     += $cash;
        $totalCredit   += $credit;
        $totalReturns  += $return;

        $validItems[] = [
            'invoice_id'        => $invoiceId,
            'customer_id'       => (int)($row['customer_id'] ?? 0),
            'bill_amount'       => $billAmount,
            'cash_amount'       => $cash,
            'credit_amount'     => $credit,
            'return_amount'     => $return,
            'difference_amount' => $diff,
            'return_items_json' => $row['return_items_json'] ?? '',
            'remarks'           => $remarksRow
        ];
    }

    $netDifference = round($totalInvoiced - ($totalCash + $totalCredit + $totalReturns), 2);

    if (empty($validItems)) {
        $errors[] = "No valid bills submitted.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $settlementNumber = generate_document_number(PREFIX_SETTLEMENT, 'settlements', 'settlement_number');
            $status = (abs($netDifference) <= 0.01) ? 'finalized' : 'reconciled';

            // 1. Insert settlements header
            $stmtSet = db()->prepare("
                INSERT INTO settlements (
                    settlement_number, settlement_date, delivery_run_id, salesman_id, load_sheet_id, 
                    total_invoices_amount, total_cash_received, total_credit_given, total_returns_amount, 
                    difference_amount, status, remarks, created_by, reconciled_by, finalized_at, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");
            $stmtSet->execute([
                $settlementNumber, $settlementDate, $deliveryRunId, $salesmanId, $loadSheetId,
                $totalInvoiced, $totalCash, $totalCredit, $totalReturns,
                $netDifference, $status, $remarks, $userId, $userId
            ]);
            $settlementId = (int)db()->lastInsertId();

            // 2. Insert settlement_items & update each invoice
            $stmtItem = db()->prepare("
                INSERT INTO settlement_items (
                    settlement_id, invoice_id, customer_id, bill_amount, 
                    cash_amount, credit_amount, return_amount, difference_amount, remarks, created_at
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            $stmtUpdInv = db()->prepare("
                UPDATE sales_invoices 
                SET paid_amount = ?, credit_amount = ?, return_amount = ?, 
                    status = 'settled', reconciliation_status = 'settled', payment_status = ?, updated_at = NOW()
                WHERE id = ?
            ");

            $stmtSalesReturn = db()->prepare("
                INSERT INTO sales_returns (
                    return_number, invoice_id, customer_id, settlement_id, return_date, 
                    total_amount, reason, condition_type, status, stock_settled, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, 'Route Return', 'saleable', 'processed', ?, ?, NOW()
                )
            ");

            $stmtReturnItem = db()->prepare("
                INSERT INTO sales_return_items (
                    sales_return_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
                    quantity, rate, discount_percent, amount, reason, condition_type, created_at
                ) VALUES (
                    ?, ?, ?, ?, 0, 0,
                    ?, ?, 0.00, ?, 'Route Return', 'saleable', NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $settlementId, $vi['invoice_id'], $vi['customer_id'], $vi['bill_amount'],
                    $vi['cash_amount'], $vi['credit_amount'], $vi['return_amount'], $vi['difference_amount'],
                    $vi['remarks']
                ]);

                // Determine payment status
                $payStatus = 'unpaid';
                if ($vi['cash_amount'] >= $vi['bill_amount']) {
                    $payStatus = 'paid';
                } elseif ($vi['cash_amount'] > 0) {
                    $payStatus = 'partial';
                }

                $stmtUpdInv->execute([
                    $vi['cash_amount'], $vi['credit_amount'], $vi['return_amount'],
                    $payStatus, $vi['invoice_id']
                ]);

                // Update customer balance for credit portion
                if ($vi['credit_amount'] > 0) {
                    update_customer_balance($vi['customer_id'], $vi['credit_amount'], 'increase');
                }

                // If return amount recorded, create sales return voucher & record inventory return
                if ($vi['return_amount'] > 0) {
                    $retNumber = generate_document_number(PREFIX_RETURN, 'sales_returns', 'return_number');
                    $retItemsJson = $vi['return_items_json'] ?? '';
                    $decodedItems = !empty($retItemsJson) ? json_decode($retItemsJson, true) : null;
                    $isStockSettled = (!empty($decodedItems) && is_array($decodedItems) && count($decodedItems) > 0) ? 1 : 0;

                    $stmtSalesReturn->execute([
                        $retNumber, $vi['invoice_id'], $vi['customer_id'], $settlementId,
                        $settlementDate, $vi['return_amount'], $isStockSettled, $userId
                    ]);
                    $returnId = (int)db()->lastInsertId();

                    if ($isStockSettled) {
                        foreach ($decodedItems as $dItem) {
                            $rPid = (int)($dItem['product_id'] ?? 0);
                            $rCtn = (float)($dItem['carton_qty'] ?? 0);
                            $rRate = (float)($dItem['rate'] ?? 0);
                            $rAmount = (float)($dItem['amount'] ?? ($rCtn * $rRate));
                            if ($rPid <= 0 || $rCtn <= 0) continue;

                            $rProd = db_fetch_one("SELECT * FROM products WHERE id = ?", [$rPid]);
                            if (!$rProd) continue;

                            $rRatios = get_product_packaging_ratios($rProd);
                            $rPieces = round($rCtn * $rRatios['pcs_per_ctn'], 4);

                            $stmtReturnItem->execute([
                                $returnId, $rPid, (int)$rProd['base_unit_id'],
                                $rCtn,
                                $rCtn, $rRate, $rAmount
                            ]);

                            record_stock_movement([
                                'product_id'     => $rPid,
                                'warehouse_id'   => 1,
                                'movement_type'  => 'sales_return_saleable',
                                'reference_type' => 'sales_return',
                                'reference_id'   => $returnId,
                                'qty_in'         => $rPieces,
                                'qty_out'        => 0,
                                'unit_cost'      => $rRate,
                                'remarks'        => "Stock returned from route settlement #{$settlementNumber} for bill #{$vi['invoice_id']}",
                                'condition_type' => 'saleable'
                            ]);
                        }
                    } else {
                        // Stock items not yet specified; fall back to first item estimation if single item invoice
                        $invItem = db_fetch_one("SELECT product_id, rate FROM sales_invoice_items WHERE invoice_id = ? LIMIT 1", [$vi['invoice_id']]);
                        if ($invItem) {
                            $rate = (float)$invItem['rate'] > 0 ? (float)$invItem['rate'] : 1;
                            $ctnReturned = $vi['return_amount'] / $rate;
                            $ratios = get_product_packaging_ratios((int)$invItem['product_id']);
                            $piecesReturned = round($ctnReturned * $ratios['pcs_per_ctn'], 4);

                            $stmtReturnItem->execute([
                                $returnId, (int)$invItem['product_id'], 1,
                                $ctnReturned,
                                $ctnReturned, $rate, $vi['return_amount']
                            ]);

                            record_stock_movement([
                                'product_id'     => (int)$invItem['product_id'],
                                'warehouse_id'   => 1,
                                'movement_type'  => 'sales_return_saleable',
                                'reference_type' => 'sales_return',
                                'reference_id'   => $returnId,
                                'qty_in'         => $piecesReturned,
                                'qty_out'        => 0,
                                'unit_cost'      => (float)$invItem['rate'],
                                'remarks'        => "Stock returned from route settlement #{$settlementNumber} (Unverified item check-in)",
                                'condition_type' => 'saleable'
                            ]);
                        }
                    }
                }
            }

            // 3. Insert cash_submissions (Office Cash Drawer Handover)
            $cashDepositAcc = (int)($_POST['cash_account_id'] ?? ACC_CASH_MAIN);
            if ($cashDepositAcc <= 0) {
                $cashDepositAcc = ACC_CASH_MAIN;
            }

            if ($totalCash > 0) {
                $subNumber = generate_document_number('CSUB', 'cash_submissions', 'submission_number');
                $stmtCSub = db()->prepare("
                    INSERT INTO cash_submissions (
                        submission_number, submission_date, salesman_id, settlement_id, 
                        amount, cash_account_id, received_by, shortage_amount, excess_amount, remarks, created_at
                    ) VALUES (
                        ?, ?, ?, ?, 
                        ?, ?, ?, 0.00, 0.00, ?, NOW()
                    )
                ");
                $stmtCSub->execute([
                    $subNumber, $settlementDate, $salesmanId, $settlementId,
                    $totalCash, $cashDepositAcc, $userId, "Cash submission from route settlement #{$settlementNumber}"
                ]);
            }

            // 4. Mark Delivery Run as completed and Load Sheet as returned_closed if linked
            if ($deliveryRunId) {
                db()->prepare("UPDATE delivery_runs SET status = 'completed', updated_at = NOW() WHERE id = ?")
                    ->execute([$deliveryRunId]);
            }
            if ($loadSheetId) {
                db()->prepare("UPDATE load_sheets SET status = 'returned_closed', updated_at = NOW() WHERE id = ?")
                    ->execute([$loadSheetId]);
            }

            // 5. Post to General Ledger (Balanced Double-Entry)
            // Debit: Cash / Bank / Wallet (Selected liquid account), Debit: Receivables (1020), Debit: Sales Returns (4030)
            // Credit: Sales Revenue (4010) or Sales Dispatched Clearing
            $journalLines = [];
            if ($totalCash > 0) {
                $journalLines[] = [
                    'account_id'  => $cashDepositAcc,
                    'debit'       => $totalCash,
                    'credit'      => 0.00,
                    'description' => "Cash collected from route settlement #{$settlementNumber}"
                ];
            }
            // Post itemized customer credit lines to Accounts Receivable (1020) with customer party tags
            foreach ($validItems as $vi) {
                if ($vi['credit_amount'] > 0) {
                    $inv = db_fetch_one("SELECT invoice_number FROM sales_invoices WHERE id = ?", [$vi['invoice_id']]);
                    $cust = db_fetch_one("SELECT shop_name FROM customers WHERE id = ?", [$vi['customer_id']]);
                    $invNum = $inv ? $inv['invoice_number'] : "Bill #{$vi['invoice_id']}";
                    $shopName = $cust ? $cust['shop_name'] : "Customer #{$vi['customer_id']}";

                    $journalLines[] = [
                        'account_id'  => ACC_RECEIVABLES,
                        'debit'       => (float)$vi['credit_amount'],
                        'credit'      => 0.00,
                        'party_type'  => 'customer',
                        'party_id'    => (int)$vi['customer_id'],
                        'description' => "Route Credit - {$invNum} ({$shopName}) [Settlement #{$settlementNumber}]"
                    ];
                }
            }
            if ($totalReturns > 0) {
                $journalLines[] = [
                    'account_id'  => ACC_RETURNS,
                    'debit'       => $totalReturns,
                    'credit'      => 0.00,
                    'description' => "Goods returns from route settlement #{$settlementNumber}"
                ];
            }
            // Balance difference or sales clearing
            $creditedAmount = $totalCash + $totalCredit + $totalReturns;
            $journalLines[] = [
                'account_id'  => ACC_SALES,
                'debit'       => 0.00,
                'credit'      => $creditedAmount,
                'description' => "Sales realized from route settlement #{$settlementNumber}"
            ];

            post_journal_entry([
                'entry_date'     => $settlementDate,
                'reference_type' => 'settlement',
                'reference_id'   => $settlementId,
                'narration'      => "Daily Route Settlement #{$settlementNumber}"
            ], $journalLines);

            db()->commit();
            set_flash('success', "Route Settlement <strong>{$settlementNumber}</strong> successfully finalized and reconciled to the general ledger!");
            redirect(APP_URL . '/modules/settlements/view.php?id=' . $settlementId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error saving settlement: " . $e->getMessage());
            $errors[] = "Error saving settlement: " . $e->getMessage();
        }
    }
}

$salesmen = db_fetch_all("SELECT id, salesman_name FROM salesmen WHERE is_active = 1 ORDER BY salesman_name ASC");

$pageTitle = 'Create Route Settlement';
$activeMenu = 'settlements';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-calculator me-2"></i>Route Settlement & Reconciliation
                </h4>
                <p class="text-muted small mb-0">Record evening route recovery: Cash Collected, Credit Given, and Returns (Photo 1)</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Settlements
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Settlement Error:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="create.php" id="settlementForm">
            <input type="hidden" name="delivery_run_id" value="<?= $preRunId ?>">

            <!-- Route Selection Parameters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-sliders me-1"></i> Route & Salesman Selection</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Settlement Date <span class="text-danger">*</span></label>
                            <input type="date" name="settlement_date" id="settleDate" class="form-control" value="<?= htmlspecialchars($preDate) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Salesman <span class="text-danger">*</span></label>
                            <select name="salesman_id" id="salesmanSelect" class="form-select" required>
                                <option value="">-- Select Salesman --</option>
                                <?php foreach ($salesmen as $sm): ?>
                                    <option value="<?= $sm['id'] ?>" <?= ($defaultSalesmanId === (int)$sm['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sm['salesman_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Deposit Cash Collection Into <span class="text-danger">*</span></label>
                            <?php
                                $settleLiquidAccounts = db_fetch_all("
                                    SELECT id, account_code, account_name, sub_type, bank_name, current_balance 
                                    FROM accounts 
                                    WHERE sub_type IN ('cash', 'bank', 'wallet') AND is_active = 1 
                                    ORDER BY CASE sub_type WHEN 'cash' THEN 1 WHEN 'bank' THEN 2 WHEN 'wallet' THEN 3 END, account_code ASC
                                ");
                                $sGrp = ['cash' => [], 'bank' => [], 'wallet' => []];
                                foreach ($settleLiquidAccounts as $sla) {
                                    $sGrp[$sla['sub_type']][] = $sla;
                                }
                            ?>
                            <select name="cash_account_id" class="form-select">
                                <?php if (!empty($sGrp['cash'])): ?>
                                    <optgroup label="💵 Cash in Hand / Drawers">
                                        <?php foreach ($sGrp['cash'] as $sla): ?>
                                            <option value="<?= $sla['id'] ?>" <?= ($sla['id'] == ACC_CASH_MAIN) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sla['account_code']) ?> - <?= htmlspecialchars($sla['account_name']) ?> [Bal: <?= format_currency($sla['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <?php if (!empty($sGrp['bank'])): ?>
                                    <optgroup label="🏦 Commercial Bank Accounts">
                                        <?php foreach ($sGrp['bank'] as $sla): ?>
                                            <option value="<?= $sla['id'] ?>">
                                                <?= htmlspecialchars($sla['account_code']) ?> - <?= htmlspecialchars($sla['account_name']) ?> [Bal: <?= format_currency($sla['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <?php if (!empty($sGrp['wallet'])): ?>
                                    <optgroup label="📱 Digital Wallets (JazzCash / EasyPaisa)">
                                        <?php foreach ($sGrp['wallet'] as $sla): ?>
                                            <option value="<?= $sla['id'] ?>">
                                                <?= htmlspecialchars($sla['account_code']) ?> - <?= htmlspecialchars($sla['account_name']) ?> [Bal: <?= format_currency($sla['current_balance']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-primary btn-sm w-100" id="btnLoadBills">
                                <i class="bi bi-arrow-repeat me-1"></i> Load Bills
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reconciliation Sheet Table (Exact Photo 1 Replica) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-navy text-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 fw-bold text-white">
                            <i class="bi bi-table me-1"></i> DAILY SALE RECIVED + CREDIT (Evening Reconciliation)
                        </h6>
                        <small class="text-white-50">Matches client Excel settlement format (Photo 1 & 3)</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-xs btn-light fw-bold text-dark" id="btnAllCash">All Cash</button>
                        <button type="button" class="btn btn-xs btn-outline-light" id="btnResetValues">Reset Values</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="settleTable" style="font-size: 13px;">
                            <thead class="text-center small text-uppercase" style="background-color: #d1b80d; color: #000; font-weight: bold;">
                                <tr>
                                    <th style="width: 45px;">Sr.</th>
                                    <th style="width: 26%;" class="text-start ps-3">Shop Name</th>
                                    <th style="width: 12%;">Bill No.</th>
                                    <th style="width: 13%;">Total Amount</th>
                                    <th style="width: 13%;">Credit</th>
                                    <th style="width: 13%;">Return</th>
                                    <th style="width: 13%;">Cash</th>
                                    <th style="width: 10%;">Difference</th>
                                </tr>
                            </thead>
                            <tbody id="settleTbody">
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-info-circle fs-3 d-block mb-2"></i>
                                        Select a salesman and click "Load Route Dispatched Bills" to populate the daily recovery sheet.
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="fw-bold fs-6" style="background-color: #c99c04; color: #000;">
                                <tr>
                                    <td colspan="3" class="text-center text-uppercase">Total:</td>
                                    <td class="text-center" id="footTotalAmount">0.00</td>
                                    <td class="text-center" id="footCredit">0.00</td>
                                    <td class="text-center" id="footReturn">0.00</td>
                                    <td class="text-center" id="footCash">0.00</td>
                                    <td class="text-center" id="footDiff">0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Zero-Sum Reconciliation Banner & Submission -->
            <div class="card border-0 shadow-sm mb-5">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <div class="d-flex align-items-center gap-3">
                                <div id="reconciliationAlert" class="alert alert-secondary py-2 px-3 mb-0 flex-grow-1">
                                    <i class="bi bi-hourglass-split me-1"></i> Awaiting bill recovery entry...
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5 text-end d-flex gap-2 justify-content-end align-items-center">
                            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm" id="btnFinalizeSettlement">
                                <i class="bi bi-check-all me-1"></i> Finalize Settlement & Post to GL
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Select Return Items From Specific Bill -->
<div class="modal fade" id="billReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-2 bg-navy text-white">
                <h6 class="modal-title fw-bold" id="billReturnModalTitle">
                    <i class="bi bi-box-arrow-in-down-left me-1"></i> Select Return Items from Bill
                </h6>
                <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-light border small py-2 px-3 mb-3 text-secondary">
                    <i class="bi bi-info-circle me-1 text-primary"></i> Enter the exact quantities of items returned from this bill. The return rate is automatically matched to the billed rate.
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0" id="billReturnItemsTable">
                        <thead class="table-light small text-center text-uppercase">
                            <tr>
                                <th class="text-start ps-2">Product Description</th>
                                <th style="width: 16%;">Billed Ctns</th>
                                <th style="width: 22%;">Return Ctns</th>
                                <th style="width: 18%;">Billed Rate</th>
                                <th style="width: 22%;" class="text-end pe-2">Return Total</th>
                            </tr>
                        </thead>
                        <tbody id="billReturnItemsTbody">
                            <!-- Populated via openBillReturnModal -->
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="4" class="text-end text-uppercase">Total Return Amount:</td>
                                <td class="text-end pe-2 text-danger" id="modalReturnTotalLabel">Rs. 0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="applyBillReturnItems()">
                    <i class="bi bi-check2-circle me-1"></i> Apply Return to Bill
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let loadedBills = [];
let currentReturnBillIndex = -1;
let billReturnModalInstance = null;

function openBillReturnModal(idx) {
    currentReturnBillIndex = idx;
    const b = loadedBills[idx];
    if (!b) return;

    document.getElementById('billReturnModalTitle').innerHTML = `<i class="bi bi-box-arrow-in-down-left me-1 text-warning"></i> Return Items - ${escapeHtml(b.customer_name)} (#${escapeHtml(b.invoice_number)})`;

    const tbody = document.getElementById('billReturnItemsTbody');
    const items = b.items || [];

    if (items.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3 text-muted">No line items found for this invoice.</td></tr>`;
    } else {
        let html = '';
        items.forEach((item, itmIdx) => {
            const billedQty = parseFloat(item.quantity) || 0;
            const rate = parseFloat(item.rate) || 0;

            html += `
                <tr class="modal-return-row" data-product-id="${item.product_id}" data-rate="${rate}">
                    <td class="ps-2 fw-bold text-dark">
                        ${escapeHtml(item.product_name)}
                    </td>
                    <td class="text-center fw-semibold text-secondary">${billedQty.toFixed(2)}</td>
                    <td class="text-center">
                        <input type="number" step="0.01" min="0" max="${billedQty}" class="form-control form-control-sm text-center modal-ret-qty fw-bold text-danger" placeholder="0" oninput="recalcModalReturnTotal()">
                    </td>
                    <td class="text-center fw-semibold text-dark">Rs. ${rate.toFixed(2)}</td>
                    <td class="text-end pe-2 fw-bold text-danger modal-line-return-total">Rs. 0.00</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    recalcModalReturnTotal();

    if (!billReturnModalInstance) {
        billReturnModalInstance = new bootstrap.Modal(document.getElementById('billReturnModal'));
    }
    billReturnModalInstance.show();
}

function recalcModalReturnTotal() {
    let totalAmt = 0;
    document.querySelectorAll('#billReturnItemsTbody tr.modal-return-row').forEach(tr => {
        const rate = parseFloat(tr.getAttribute('data-rate')) || 0;
        const qtyInp = tr.querySelector('.modal-ret-qty');
        const qty = parseFloat(qtyInp?.value) || 0;
        const lineTotal = qty * rate;
        totalAmt += lineTotal;

        const lineLbl = tr.querySelector('.modal-line-return-total');
        if (lineLbl) lineLbl.innerText = `Rs. ${lineTotal.toFixed(2)}`;
    });

    document.getElementById('modalReturnTotalLabel').innerText = `Rs. ${totalAmt.toFixed(2)}`;
}

function applyBillReturnItems() {
    if (currentReturnBillIndex < 0 || !loadedBills[currentReturnBillIndex]) return;
    const b = loadedBills[currentReturnBillIndex];

    const selectedItems = [];
    let totalReturnAmt = 0;

    document.querySelectorAll('#billReturnItemsTbody tr.modal-return-row').forEach(tr => {
        const prodId = parseInt(tr.getAttribute('data-product-id'), 10);
        const rate = parseFloat(tr.getAttribute('data-rate')) || 0;
        const qtyInp = tr.querySelector('.modal-ret-qty');
        const qty = parseFloat(qtyInp?.value) || 0;

        if (prodId > 0 && qty > 0) {
            const lineAmt = qty * rate;
            totalReturnAmt += lineAmt;
            selectedItems.push({
                product_id: prodId,
                carton_qty: qty,
                rate: rate,
                amount: lineAmt
            });
        }
    });

    const retInp = document.getElementById(`return_inp_${b.invoice_id}`);
    const jsonInp = document.getElementById(`return_items_json_${b.invoice_id}`);
    const badge = document.getElementById(`return_badge_${b.invoice_id}`);

    if (retInp) {
        retInp.value = totalReturnAmt > 0 ? totalReturnAmt.toFixed(0) : '';
    }
    if (jsonInp) {
        jsonInp.value = selectedItems.length > 0 ? JSON.stringify(selectedItems) : '';
    }
    if (badge) {
        if (selectedItems.length > 0) {
            badge.classList.remove('d-none');
            badge.innerHTML = `<i class="bi bi-check2-circle"></i> ${selectedItems.length} item${selectedItems.length === 1 ? '' : 's'} selected`;
        } else {
            badge.classList.add('d-none');
        }
    }

    // Trigger row input recalculation
    const row = document.querySelector(`tr.settle-row[data-index="${currentReturnBillIndex}"]`);
    if (row) {
        handleRowInput(row, 'return');
    }

    if (billReturnModalInstance) {
        billReturnModalInstance.hide();
    }
}

function fetchRouteBills() {
    const sm = document.getElementById('salesmanSelect').value;
    const date = document.getElementById('settleDate').value;
    const runId = <?= $preRunId ?>;
    const tbody = document.getElementById('settleTbody');

    if (!sm && runId === 0) {
        alert('Please select a delivery salesman.');
        return;
    }

    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted"><i class="bi bi-hourglass-split me-1"></i> Loading route bills...</td></tr>`;

    let url = `../../ajax/settlements/get_dispatched_bills.php?salesman_id=${sm}&date=${encodeURIComponent(date)}`;
    if (runId > 0) {
        url += `&delivery_run_id=${runId}`;
    }

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                loadedBills = data.data.bills || [];
                renderSettlementTable();
            } else {
                tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">${data.message}</td></tr>`;
            }
        })
        .catch(err => {
            console.error(err);
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Error communicating with server.</td></tr>`;
        });
}

function renderSettlementTable() {
    const tbody = document.getElementById('settleTbody');
    if (loadedBills.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted">No dispatched bills found for this salesman on ${document.getElementById('settleDate').value}.</td></tr>`;
        recalcTotals();
        return;
    }

    let html = '';
    loadedBills.forEach((b, idx) => {
        const sr = idx + 1;
        const billAmt = parseFloat(b.net_amount) || 0;

        html += `
            <tr class="settle-row" data-index="${idx}" data-amount="${billAmt}">
                <td class="text-center fw-bold text-muted">${sr}</td>
                <td class="ps-3 fw-bold text-dark">
                    ${escapeHtml(b.customer_name)}
                    <input type="hidden" name="items[${b.invoice_id}][customer_id]" value="${b.customer_id}">
                    <input type="hidden" name="items[${b.invoice_id}][bill_amount]" value="${billAmt}">
                </td>
                <td class="text-center fw-semibold text-navy">
                    <a href="../invoices/view.php?id=${b.invoice_id}" target="_blank" class="text-decoration-none">
                        ${escapeHtml(b.invoice_number)}
                    </a>
                </td>
                <td class="text-center fw-bold text-dark fs-6">${billAmt.toFixed(0)}</td>
                <td>
                    <input type="number" name="items[${b.invoice_id}][credit]" class="form-control form-control-sm text-center credit-inp" step="1" min="0" placeholder="0">
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" name="items[${b.invoice_id}][return]" id="return_inp_${b.invoice_id}" class="form-control form-control-sm text-center return-inp" step="1" min="0" placeholder="0">
                        <button type="button" class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="openBillReturnModal(${idx})" title="Select exact return items from this bill">
                            <i class="bi bi-box-seam" style="font-size: 11px;"></i>
                        </button>
                    </div>
                    <input type="hidden" name="items[${b.invoice_id}][return_items_json]" id="return_items_json_${b.invoice_id}" value="">
                    <div id="return_badge_${b.invoice_id}" class="text-success small fw-semibold mt-1 d-none" style="font-size: 10px;">
                        <i class="bi bi-check2-circle"></i> Items selected
                    </div>
                </td>
                <td>
                    <input type="number" name="items[${b.invoice_id}][cash]" class="form-control form-control-sm text-center cash-inp fw-bold text-success" step="1" min="0" placeholder="0">
                </td>
                <td class="text-center fw-bold diff-cell text-muted">
                    ${billAmt.toFixed(0)}
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;

    // Attach listeners with dynamic auto-adjusting
    document.querySelectorAll('.cash-inp').forEach(inp => {
        inp.addEventListener('input', function() {
            handleRowInput(this.closest('.settle-row'), 'cash');
        });
    });
    document.querySelectorAll('.credit-inp').forEach(inp => {
        inp.addEventListener('input', function() {
            handleRowInput(this.closest('.settle-row'), 'credit');
        });
    });
    document.querySelectorAll('.return-inp').forEach(inp => {
        inp.addEventListener('input', function() {
            handleRowInput(this.closest('.settle-row'), 'return');
        });
    });

    recalcTotals();
}

function handleRowInput(row, changedField) {
    const billAmt = parseFloat(row.getAttribute('data-amount')) || 0;
    const cashInp = row.querySelector('.cash-inp');
    const creditInp = row.querySelector('.credit-inp');
    const returnInp = row.querySelector('.return-inp');

    let cash = parseFloat(cashInp.value);
    let credit = parseFloat(creditInp.value);
    let ret = parseFloat(returnInp.value) || 0;

    if (changedField === 'cash') {
        if (!isNaN(cash)) {
            const rem = billAmt - ret - cash;
            if (rem >= 0) {
                creditInp.value = rem > 0 ? rem : '';
            } else {
                creditInp.value = '';
            }
        }
    } else if (changedField === 'credit') {
        if (!isNaN(credit)) {
            const rem = billAmt - ret - credit;
            if (rem >= 0) {
                cashInp.value = rem > 0 ? rem : '';
            } else {
                cashInp.value = '';
            }
        }
    } else if (changedField === 'return') {
        const hasCash = !isNaN(cash);
        const hasCredit = !isNaN(credit);
        if (hasCash) {
            const rem = billAmt - ret - cash;
            if (rem >= 0) {
                creditInp.value = rem > 0 ? rem : '';
            } else {
                cashInp.value = Math.max(0, billAmt - ret);
                creditInp.value = '';
            }
        } else if (hasCredit) {
            const rem = billAmt - ret - credit;
            if (rem >= 0) {
                cashInp.value = rem > 0 ? rem : '';
            } else {
                creditInp.value = Math.max(0, billAmt - ret);
                cashInp.value = '';
            }
        } else {
            const rem = billAmt - ret;
            cashInp.value = rem > 0 ? rem : '';
        }
    }

    recalcRow(row);
    recalcTotals();
}

function recalcRow(row) {
    const billAmt = parseFloat(row.getAttribute('data-amount')) || 0;
    const credit  = parseFloat(row.querySelector('.credit-inp').value) || 0;
    const retVal  = parseFloat(row.querySelector('.return-inp').value) || 0;
    const cash    = parseFloat(row.querySelector('.cash-inp').value) || 0;

    const diff = billAmt - (credit + retVal + cash);
    const diffCell = row.querySelector('.diff-cell');

    if (Math.abs(diff) < 0.01) {
        diffCell.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> 0</span>';
        row.classList.remove('table-warning', 'table-danger');
        row.classList.add('table-success-subtle');
    } else {
        diffCell.innerHTML = `<span class="badge ${diff > 0 ? 'bg-warning text-dark' : 'bg-danger'}">${diff.toFixed(0)}</span>`;
        row.classList.remove('table-success-subtle');
        row.classList.add('table-warning');
    }
}

function recalcTotals() {
    let sumBill = 0;
    let sumCredit = 0;
    let sumReturn = 0;
    let sumCash = 0;

    document.querySelectorAll('.settle-row').forEach(row => {
        const billAmt = parseFloat(row.getAttribute('data-amount')) || 0;
        const credit  = parseFloat(row.querySelector('.credit-inp').value) || 0;
        const retVal  = parseFloat(row.querySelector('.return-inp').value) || 0;
        const cash    = parseFloat(row.querySelector('.cash-inp').value) || 0;

        sumBill += billAmt;
        sumCredit += credit;
        sumReturn += retVal;
        sumCash += cash;
    });

    const netDiff = sumBill - (sumCredit + sumReturn + sumCash);

    document.getElementById('footTotalAmount').innerText = sumBill.toLocaleString('en-US', {minimumFractionDigits: 0});
    document.getElementById('footCredit').innerText = sumCredit.toLocaleString('en-US', {minimumFractionDigits: 0});
    document.getElementById('footReturn').innerText = sumReturn.toLocaleString('en-US', {minimumFractionDigits: 0});
    document.getElementById('footCash').innerText = sumCash.toLocaleString('en-US', {minimumFractionDigits: 0});
    document.getElementById('footDiff').innerText = netDiff.toLocaleString('en-US', {minimumFractionDigits: 0});

    const alertBox = document.getElementById('reconciliationAlert');
    if (loadedBills.length === 0) {
        alertBox.className = 'alert alert-secondary py-2 px-3 mb-0 flex-grow-1';
        alertBox.innerHTML = '<i class="bi bi-info-circle me-1"></i> No bills loaded.';
    } else if (Math.abs(netDiff) < 0.01) {
        alertBox.className = 'alert alert-success py-2 px-3 mb-0 flex-grow-1 fw-bold';
        alertBox.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Reconciled! Difference is exactly 0.00. Ready to finalize.';
    } else {
        alertBox.className = 'alert alert-warning py-2 px-3 mb-0 flex-grow-1 fw-bold';
        alertBox.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Unbalanced: Difference of Rs. ${netDiff.toFixed(2)} detected.`;
    }
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

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('btnLoadBills').addEventListener('click', fetchRouteBills);
    document.getElementById('salesmanSelect').addEventListener('change', fetchRouteBills);
    document.getElementById('settleDate').addEventListener('change', fetchRouteBills);

    // If pre-filled salesman, auto load bills
    if (document.getElementById('salesmanSelect').value) {
        fetchRouteBills();
    }

    // "All Cash" shortcut
    document.getElementById('btnAllCash').addEventListener('click', function() {
        document.querySelectorAll('.settle-row').forEach(row => {
            const billAmt = parseFloat(row.getAttribute('data-amount')) || 0;
            row.querySelector('.cash-inp').value = billAmt;
            row.querySelector('.credit-inp').value = '';
            row.querySelector('.return-inp').value = '';
            recalcRow(row);
        });
        recalcTotals();
    });

    // Reset shortcut
    document.getElementById('btnResetValues').addEventListener('click', function() {
        document.querySelectorAll('.settle-row').forEach(row => {
            row.querySelector('.cash-inp').value = '';
            row.querySelector('.credit-inp').value = '';
            row.querySelector('.return-inp').value = '';
            recalcRow(row);
        });
        recalcTotals();
    });
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
