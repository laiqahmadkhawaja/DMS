<?php
/**
 * DMS - Distribution Management System
 * Route Settlements & Daily Reconciliation - Edit Settlement
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();
require_permission('settlements_create');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid settlement ID.');
    redirect(APP_URL . '/modules/settlements/index.php');
}

$settlement = db_fetch_one("
    SELECT 
        st.*,
        s.salesman_name,
        dr.run_number,
        ls.load_number
    FROM settlements st
    JOIN salesmen s ON st.salesman_id = s.id
    LEFT JOIN delivery_runs dr ON st.delivery_run_id = dr.id
    LEFT JOIN load_sheets ls ON st.load_sheet_id = ls.id
    WHERE st.id = ?
", [$id]);

if (!$settlement) {
    set_flash('danger', 'Settlement record not found.');
    redirect(APP_URL . '/modules/settlements/index.php');
}

// Fetch items
$items = db_fetch_all("
    SELECT 
        si.*,
        c.shop_name,
        c.customer_code,
        inv.invoice_number,
        inv.net_amount as invoice_net
    FROM settlement_items si
    JOIN customers c ON si.customer_id = c.id
    JOIN sales_invoices inv ON si.invoice_id = inv.id
    WHERE si.settlement_id = ?
    ORDER BY si.id ASC
", [$id]);

$errors = [];

// Handle POST Edit Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $remarks   = sanitize_input($_POST['remarks'] ?? '');
    $itemsData = $_POST['items'] ?? [];

    $totalInvoiced = 0.0;
    $totalCash     = 0.0;
    $totalCredit   = 0.0;
    $totalReturns  = 0.0;

    foreach ($itemsData as $itemId => $row) {
        $billAmount = (float)($row['bill_amount'] ?? 0);
        $cash       = (float)($row['cash'] ?? 0);
        $credit     = (float)($row['credit'] ?? 0);
        $return     = (float)($row['return'] ?? 0);

        $totalInvoiced += $billAmount;
        $totalCash     += $cash;
        $totalCredit   += $credit;
        $totalReturns  += $return;
    }

    $netDifference = round($totalInvoiced - ($totalCash + $totalCredit + $totalReturns), 2);
    $status = (abs($netDifference) <= 0.01) ? 'finalized' : 'reconciled';
    $userId = current_user_id();

    try {
        db()->beginTransaction();

        // 1. Process items, adjust customer balances, update invoices
        $stmtUpdItem = db()->prepare("
            UPDATE settlement_items 
            SET cash_amount = ?, credit_amount = ?, return_amount = ?, difference_amount = ?, remarks = ?
            WHERE id = ?
        ");

        $stmtUpdInv = db()->prepare("
            UPDATE sales_invoices 
            SET paid_amount = ?, credit_amount = ?, return_amount = ?, payment_status = ?, updated_at = NOW()
            WHERE id = ?
        ");

        foreach ($items as $origItem) {
            $itemId = (int)$origItem['id'];
            if (!isset($itemsData[$itemId])) continue;

            $row = $itemsData[$itemId];
            $billAmt = (float)$origItem['bill_amount'];
            $newCash = (float)($row['cash'] ?? 0);
            $newCredit = (float)($row['credit'] ?? 0);
            $newReturn = (float)($row['return'] ?? 0);
            $rowRemarks = sanitize_input($row['remarks'] ?? '');
            $rowDiff = round($billAmt - ($newCash + $newCredit + $newReturn), 2);

            // Update item row
            $stmtUpdItem->execute([
                $newCash, $newCredit, $newReturn, $rowDiff, $rowRemarks, $itemId
            ]);

            // Adjust Customer Balance for credit changes
            $oldCredit = (float)$origItem['credit_amount'];
            $creditDelta = round($newCredit - $oldCredit, 2);
            if (abs($creditDelta) > 0.001) {
                if ($creditDelta > 0) {
                    update_customer_balance((int)$origItem['customer_id'], $creditDelta, 'increase');
                } else {
                    update_customer_balance((int)$origItem['customer_id'], abs($creditDelta), 'decrease');
                }
            }

            // Update sales_invoice
            $payStatus = 'unpaid';
            if ($newCash >= $billAmt) {
                $payStatus = 'paid';
            } elseif ($newCash > 0) {
                $payStatus = 'partial';
            }
            $stmtUpdInv->execute([$newCash, $newCredit, $newReturn, $payStatus, $origItem['invoice_id']]);
        }

        // 2. Update settlement header
        $stmtUpdHeader = db()->prepare("
            UPDATE settlements 
            SET total_cash_received = ?, total_credit_given = ?, total_returns_amount = ?, 
                difference_amount = ?, status = ?, remarks = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdHeader->execute([
            $totalCash, $totalCredit, $totalReturns, $netDifference, $status, $remarks, $id
        ]);

        // 3. Update cash_submissions amount
        db()->prepare("
            UPDATE cash_submissions 
            SET amount = ? 
            WHERE settlement_id = ?
        ")->execute([$totalCash, $id]);

        // 4. Reverse existing journal entry and re-post balanced entry
        $existingJE = db_fetch_one("SELECT id FROM journal_entries WHERE reference_type = 'settlement' AND reference_id = ?", [$id]);
        if ($existingJE) {
            $jeId = (int)$existingJE['id'];
            $lines = db_fetch_all("SELECT * FROM journal_entry_lines WHERE journal_entry_id = ?", [$jeId]);
            foreach ($lines as $line) {
                $accId = (int)$line['account_id'];
                $deb = (float)$line['debit'];
                $crd = (float)$line['credit'];
                $accType = db_fetch_val("SELECT account_type FROM accounts WHERE id = ?", [$accId]);
                $delta = in_array($accType, ['asset', 'expense'], true) ? -($deb - $crd) : -($crd - $deb);
                db()->prepare("UPDATE accounts SET current_balance = current_balance + ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$delta, $accId]);
            }
            db()->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?")->execute([$jeId]);
            db()->prepare("DELETE FROM journal_entries WHERE id = ?")->execute([$jeId]);
        }

        // Re-post balanced GL entry
        $journalLines = [];
        if ($totalCash > 0) {
            $journalLines[] = [
                'account_id'  => ACC_CASH_MAIN,
                'debit'       => $totalCash,
                'credit'      => 0.00,
                'description' => "Cash collected from route settlement #{$settlement['settlement_number']}"
            ];
        }
        if ($totalCredit > 0) {
            $journalLines[] = [
                'account_id'  => ACC_RECEIVABLES,
                'debit'       => $totalCredit,
                'credit'      => 0.00,
                'description' => "Customer credit from route settlement #{$settlement['settlement_number']}"
            ];
        }
        if ($totalReturns > 0) {
            $journalLines[] = [
                'account_id'  => ACC_RETURNS,
                'debit'       => $totalReturns,
                'credit'      => 0.00,
                'description' => "Goods returns from route settlement #{$settlement['settlement_number']}"
            ];
        }
        $creditedAmount = $totalCash + $totalCredit + $totalReturns;
        if ($creditedAmount > 0) {
            $journalLines[] = [
                'account_id'  => ACC_SALES,
                'debit'       => 0.00,
                'credit'      => $creditedAmount,
                'description' => "Sales realized from route settlement #{$settlement['settlement_number']}"
            ];

            post_journal_entry([
                'entry_date'     => $settlement['settlement_date'],
                'reference_type' => 'settlement',
                'reference_id'   => $id,
                'narration'      => "Daily Route Settlement #{$settlement['settlement_number']} (Adjusted)"
            ], $journalLines);
        }

        db()->commit();

        set_flash('success', "Settlement <strong>{$settlement['settlement_number']}</strong> updated successfully! Customer accounts and financial ledgers have been automatically updated.");
        redirect(APP_URL . '/modules/settlements/view.php?id=' . $id);

    } catch (Exception $e) {
        db()->rollBack();
        error_log("Error updating settlement: " . $e->getMessage());
        $errors[] = "Error updating settlement: " . $e->getMessage();
    }
}

$pageTitle = 'Edit Route Settlement: ' . $settlement['settlement_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settlements', 'url' => 'index.php'],
    ['label' => $settlement['settlement_number'], 'url' => 'view.php?id=' . $id],
    ['label' => 'Edit', 'url' => '']
];

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
                    <i class="bi bi-pencil-square me-2"></i>Edit Route Settlement: <?= htmlspecialchars($settlement['settlement_number']) ?>
                </h4>
                <p class="text-muted small mb-0">Adjust cash, credit, or returns. Ledger balances and customer accounts update automatically.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="view.php?id=<?= $settlement['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to View
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Unable to proceed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit.php?id=<?= $settlement['id'] ?>" id="settleEditForm">
            <!-- Parameters Overview -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-info-circle me-1"></i> Settlement Parameters</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Settlement Date</label>
                            <input type="text" class="form-control" value="<?= date('d-M-Y', strtotime($settlement['settlement_date'])) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Salesman</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($settlement['salesman_name']) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Run / Load Sheet</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($settlement['run_number'] ?: ($settlement['load_number'] ?: 'Direct')) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Settlement Remarks</label>
                            <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($settlement['remarks'] ?? '') ?>" placeholder="e.g. Evening route reconciliation">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reconciliation Grid -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0 fw-bold text-navy">
                            <i class="bi bi-table me-1"></i> Daily Sale Received + Credit (Photo 1)
                        </h6>
                        <small class="text-muted">Enter cash, credit, or returns — values auto-adjust so the difference stays balanced.</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-xs btn-outline-success" id="btnAutoBalance">
                            <i class="bi bi-magic me-1"></i> Auto-Balance Remaining into Credit
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0" id="settleEditTable">
                            <thead class="bg-navy text-white small text-uppercase text-center">
                                <tr>
                                    <th style="width: 50px;">Sr.</th>
                                    <th class="text-start" style="width: 250px;">Retailer / Shop Name</th>
                                    <th style="width: 140px;">Bill #</th>
                                    <th style="width: 130px;">Total Bill Amount</th>
                                    <th style="width: 140px;" class="bg-danger text-white">Party Credit</th>
                                    <th style="width: 130px;" class="bg-warning text-dark">Goods Return</th>
                                    <th style="width: 140px;" class="bg-success text-white">Cash Received</th>
                                    <th style="width: 120px;">Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $idx => $it): 
                                    $billAmt = (float)$it['bill_amount'];
                                    $cashAmt = (float)$it['cash_amount'];
                                    $creditAmt = (float)$it['credit_amount'];
                                    $returnAmt = (float)$it['return_amount'];
                                ?>
                                    <tr class="settle-row" data-index="<?= $idx ?>" data-amount="<?= $billAmt ?>">
                                        <td class="text-center fw-bold text-muted"><?= $idx + 1 ?></td>
                                        <td class="ps-3 fw-bold text-dark">
                                            <?= htmlspecialchars($it['shop_name']) ?>
                                            <input type="hidden" name="items[<?= $it['id'] ?>][bill_amount]" value="<?= $billAmt ?>">
                                        </td>
                                        <td class="text-center fw-semibold text-navy">
                                            <?= htmlspecialchars($it['invoice_number']) ?>
                                        </td>
                                        <td class="text-center fw-bold text-dark fs-6"><?= number_format($billAmt, 0) ?></td>
                                        <td>
                                            <input type="number" name="items[<?= $it['id'] ?>][credit]" class="form-control form-control-sm text-center credit-inp fw-bold text-danger" step="1" min="0" value="<?= $creditAmt > 0 ? $creditAmt : '' ?>" placeholder="0">
                                        </td>
                                        <td>
                                            <input type="number" name="items[<?= $it['id'] ?>][return]" class="form-control form-control-sm text-center return-inp" step="1" min="0" value="<?= $returnAmt > 0 ? $returnAmt : '' ?>" placeholder="0">
                                        </td>
                                        <td>
                                            <input type="number" name="items[<?= $it['id'] ?>][cash]" class="form-control form-control-sm text-center cash-inp fw-bold text-success" step="1" min="0" value="<?= $cashAmt > 0 ? $cashAmt : '' ?>" placeholder="0">
                                        </td>
                                        <td class="text-center fw-bold diff-cell text-muted">
                                            0
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-dark fw-bold fs-6">
                                <tr>
                                    <td colspan="3" class="text-end text-uppercase">TOTAL:</td>
                                    <td class="text-center" id="footTotalAmount">0</td>
                                    <td class="text-center text-danger" id="footCredit">0</td>
                                    <td class="text-center text-warning" id="footReturn">0</td>
                                    <td class="text-center text-success" id="footCash">0</td>
                                    <td class="text-center" id="footDiff">0</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-3 px-4 d-flex justify-content-between align-items-center">
                    <div id="reconciliationAlert" class="alert alert-secondary py-2 px-3 mb-0 flex-grow-1">
                        <i class="bi bi-hourglass-split me-1"></i> Verifying balances...
                    </div>
                    <div class="ms-3">
                        <button type="submit" class="btn btn-success shadow-sm px-4 fw-bold" id="btnSaveSettlement">
                            <i class="bi bi-check-circle me-1"></i> Save Settlement & Update Balances <kbd class="bg-navy ms-1">F9</kbd>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
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
    if (Math.abs(netDiff) < 0.01) {
        alertBox.className = 'alert alert-success py-2 px-3 mb-0 flex-grow-1 fw-bold';
        alertBox.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Reconciled! Difference is exactly 0.00. Ready to save.';
    } else {
        alertBox.className = 'alert alert-warning py-2 px-3 mb-0 flex-grow-1 fw-bold';
        alertBox.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Difference of Rs. ${netDiff.toFixed(2)} detected. Values will be updated upon save.`;
    }
}

document.addEventListener('DOMContentLoaded', function() {
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

    document.getElementById('btnAutoBalance').addEventListener('click', function() {
        document.querySelectorAll('.settle-row').forEach(row => {
            const billAmt = parseFloat(row.getAttribute('data-amount')) || 0;
            const cash = parseFloat(row.querySelector('.cash-inp').value) || 0;
            const ret = parseFloat(row.querySelector('.return-inp').value) || 0;
            const rem = billAmt - cash - ret;
            if (rem > 0) {
                row.querySelector('.credit-inp').value = rem;
            } else if (rem < 0) {
                row.querySelector('.cash-inp').value = billAmt - ret;
                row.querySelector('.credit-inp').value = '';
            }
            recalcRow(row);
        });
        recalcTotals();
    });

    document.querySelectorAll('.settle-row').forEach(row => recalcRow(row));
    recalcTotals();
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
