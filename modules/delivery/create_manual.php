<?php
/**
 * DMS - Distribution Management System
 * Delivery Runs - Create Manual Delivery & Recovery Run Sheet (مینول ادھار و ریکوری رن شیٹ)
 * Allows creating on-demand field recovery run sheets with live customer ledger balances.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('delivery_manage');

$pageTitle = 'Create Manual Delivery & Recovery Run Sheet';
$activeMenu = 'delivery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Delivery Runs', 'url' => 'index.php'],
    ['label' => 'Manual Run Sheet', 'url' => '']
];

$errors = [];

// Handle POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $runDate     = sanitize_input($_POST['run_date'] ?? date('Y-m-d'));
    $salesmanId  = (int)($_POST['salesman_id'] ?? 0);
    $areaId      = !empty($_POST['area_id']) ? (int)$_POST['area_id'] : null;
    $routeNotes  = sanitize_input($_POST['route_notes'] ?? '');
    $action      = sanitize_input($_POST['action'] ?? 'save');
    
    $selectedCustomers = $_POST['customer_ids'] ?? [];
    $billAmounts       = $_POST['bill_amounts'] ?? [];
    $billNotes         = $_POST['bill_notes'] ?? [];

    if ($salesmanId <= 0) {
        $errors[] = "Please select a delivery salesman / rider.";
    }
    if (empty($selectedCustomers) || !is_array($selectedCustomers)) {
        $errors[] = "Please select at least one customer / shop for this recovery run sheet.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $runNumber = generate_document_number(PREFIX_RUN, 'delivery_runs', 'run_number');

            // Fetch live current balances for selected customers
            $inPlaceholders = implode(',', array_fill(0, count($selectedCustomers), '?'));
            $custRows = db_fetch_all("
                SELECT id, shop_name, current_balance 
                FROM customers 
                WHERE id IN ({$inPlaceholders})
            ", $selectedCustomers);
            
            $custMap = [];
            foreach ($custRows as $cr) {
                $custMap[(int)$cr['id']] = (float)$cr['current_balance'];
            }

            $totalRunBillAmount = 0.0;
            $totalRunDueAmount  = 0.0;

            foreach ($selectedCustomers as $cid) {
                $cid = (int)$cid;
                $bAmt = isset($billAmounts[$cid]) ? (float)$billAmounts[$cid] : 0.0;
                $pBal = $custMap[$cid] ?? 0.0;
                $totalRunBillAmount += $bAmt;
                $totalRunDueAmount  += ($pBal + $bAmt);
            }

            // 1. Insert into delivery_runs
            $stmtRun = db()->prepare("
                INSERT INTO delivery_runs (
                    run_number, run_date, salesman_id, area_id, load_sheet_id, run_type,
                    route_notes, total_bills, total_amount, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, NULL, 'manual',
                    ?, ?, ?, 'assigned', ?, NOW()
                )
            ");
            $stmtRun->execute([
                $runNumber, $runDate, $salesmanId, $areaId,
                $routeNotes, count($selectedCustomers), $totalRunBillAmount, $userId
            ]);
            $runId = (int)db()->lastInsertId();

            // 2. Insert into delivery_run_bills
            $stmtBill = db()->prepare("
                INSERT INTO delivery_run_bills (
                    delivery_run_id, invoice_id, customer_id, bill_number,
                    prev_balance, bill_amount, total_due, stop_sequence,
                    delivery_status, created_at
                ) VALUES (
                    ?, NULL, ?, ?,
                    ?, ?, ?, ?,
                    'pending', NOW()
                )
            ");

            $seq = 1;
            foreach ($selectedCustomers as $cid) {
                $cid = (int)$cid;
                $bAmt = isset($billAmounts[$cid]) ? (float)$billAmounts[$cid] : 0.0;
                $pBal = $custMap[$cid] ?? 0.0;
                $tDue = $pBal + $bAmt;
                $bNote = !empty($billNotes[$cid]) ? sanitize_input($billNotes[$cid]) : 'Udhaar Recovery';

                $stmtBill->execute([
                    $runId, $cid, $bNote,
                    $pBal, $bAmt, $tDue, $seq++
                ]);
            }

            db()->commit();
            set_flash('success', "Manual Recovery Run Sheet <strong>{$runNumber}</strong> generated with " . count($selectedCustomers) . " shops.");

            if ($action === 'save_and_print') {
                redirect(APP_URL . '/modules/delivery/print.php?id=' . $runId);
            } else {
                redirect(APP_URL . '/modules/delivery/view.php?id=' . $runId);
            }

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error creating manual delivery run: " . $e->getMessage());
            $errors[] = "Error saving delivery run: " . $e->getMessage();
        }
    }
}

// Master Data for Pre-population
$salesmen = db_fetch_all("
    SELECT sm.id, sm.salesman_name, sm.assigned_area_id, a.area_name as assigned_area_name 
    FROM salesmen sm 
    LEFT JOIN areas a ON sm.assigned_area_id = a.id 
    WHERE sm.is_active = 1 
    ORDER BY sm.salesman_name ASC
");

$areas = db_fetch_all("
    SELECT a.id, a.area_code, a.area_name, a.city, COUNT(c.id) as customer_count 
    FROM areas a 
    LEFT JOIN customers c ON c.area_id = a.id AND c.is_active = 1 AND c.is_deleted = 0
    WHERE a.is_active = 1 AND a.is_deleted = 0 
    GROUP BY a.id, a.area_code, a.area_name, a.city 
    ORDER BY a.area_name ASC
");

// Preload active customers with current balance
$allCustomers = db_fetch_all("
    SELECT 
        c.id, c.customer_code, c.shop_name, c.owner_name, c.phone, 
        c.area_id, a.area_name, c.current_balance, c.credit_limit
    FROM customers c
    LEFT JOIN areas a ON c.area_id = a.id
    WHERE c.is_active = 1 AND c.is_deleted = 0
    ORDER BY a.area_name ASC, c.current_balance DESC, c.shop_name ASC
");

$preSelectedAreaId = (int)($_GET['area_id'] ?? 0);
$preSelectedSalesmanId = (int)($_GET['salesman_id'] ?? 0);

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-3">
        
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
            <div>
                <h4 class="mb-0 text-navy fw-bold">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Create Manual Delivery & Recovery Run Sheet (مینول رن شیٹ)
                </h4>
                <small class="text-muted">On-demand credit recovery run sheet with live customer ledger balances and custom bill targets</small>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Runs
                </a>
                <a href="create.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-truck-flatbed me-1"></i> Switch to Load Sheet Run
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4 alert-dismissible fade show">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Unable to proceed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="create_manual.php" id="manualDeliveryForm">
            
            <!-- Top Controls Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-navy text-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-sliders me-1 text-warning"></i> 1. Run Header & Route Assignment</span>
                    <span class="badge bg-warning text-dark font-monospace">Manual / Credit Run</span>
                </div>
                <div class="card-body p-3 bg-light">
                    <div class="row g-3">
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-bold text-dark">Run Date <span class="text-danger">*</span></label>
                            <input type="date" name="run_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-bold text-dark">Delivery Salesman / Rider <span class="text-danger">*</span></label>
                            <select name="salesman_id" id="manualSalesmanSelect" class="form-select form-select-sm" required onchange="onSalesmanChange(this)">
                                <option value="">-- Choose Salesman / Rider --</option>
                                <?php foreach ($salesmen as $sm): ?>
                                    <option value="<?= $sm['id'] ?>" 
                                            <?= ($preSelectedSalesmanId === (int)$sm['id']) ? 'selected' : '' ?> 
                                            data-area-id="<?= (int)($sm['assigned_area_id'] ?? 0) ?>">
                                        <?= htmlspecialchars($sm['salesman_name']) ?><?= !empty($sm['assigned_area_name']) ? ' [' . htmlspecialchars($sm['assigned_area_name']) . ']' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label small fw-bold text-dark mb-0">Market / Route Filter</label>
                                <button type="button" class="btn btn-link btn-xs p-0 text-decoration-none fw-semibold" onclick="openQuickAreaDrawer('manualAreaSelect')" title="Add New Route">
                                    <i class="bi bi-plus-circle"></i> New Route
                                </button>
                            </div>
                            <select name="area_id" id="manualAreaSelect" class="form-select form-select-sm" onchange="filterCustomerTable()">
                                <option value="0">-- All Routes / Whole Territory --</option>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id'] ?>" <?= ($preSelectedAreaId === (int)$a['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['area_name']) ?> (<?= (int)$a['customer_count'] ?> shops)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-bold text-dark">Route Notes / Purpose</label>
                            <input type="text" name="route_notes" class="form-control form-control-sm" placeholder="e.g. Weekly recovery run, credit collection...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Selection Section -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <h6 class="mb-0 fw-bold text-navy">
                            <i class="bi bi-people-fill text-primary me-2"></i>2. Select Shops & Verify Balances
                        </h6>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="filterOnlyWithBalance" checked onchange="filterCustomerTable()">
                            <label class="form-check-label small fw-semibold text-dark" for="filterOnlyWithBalance">
                                Only Show Shops With Udhaar / Balance (> 0)
                            </label>
                        </div>
                    </div>

                    <!-- Search within Route -->
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="width: 240px;">
                            <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                            <input type="text" id="custTableSearch" class="form-control" placeholder="Search shop name..." onkeyup="filterCustomerTable()">
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll(true)">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll(false)">Deselect All</button>
                    </div>
                </div>

                <!-- Add Single Customer Outside Route Bar -->
                <div class="p-2 bg-light border-bottom d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-bold text-dark"><i class="bi bi-plus-circle me-1"></i> Add Specific Shop:</span>
                    <div style="min-width: 300px; flex-grow: 1;">
                        <select id="singleShopAdderSelect" class="form-select form-select-sm">
                            <option value="">-- Choose any customer to add to this run sheet --</option>
                            <?php foreach ($allCustomers as $c): ?>
                                <option value="<?= $c['id'] ?>" 
                                        data-name="<?= htmlspecialchars($c['shop_name']) ?>"
                                        data-owner="<?= htmlspecialchars($c['owner_name'] ?? '') ?>"
                                        data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                        data-area="<?= htmlspecialchars($c['area_name'] ?? '-') ?>"
                                        data-area-id="<?= (int)$c['area_id'] ?>"
                                        data-bal="<?= (float)$c['current_balance'] ?>">
                                    <?= htmlspecialchars($c['shop_name']) ?> [<?= htmlspecialchars($c['area_name'] ?? '-') ?>] — Bal: Rs. <?= number_format((float)$c['current_balance'], 0) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addSingleShopFromDropdown()">
                        <i class="bi bi-plus-lg me-1"></i> Add Shop
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="openQuickCustomerDrawer('singleShopAdderSelect')">
                        <i class="bi bi-person-plus me-1"></i> Quick Register Shop
                    </button>
                </div>

                <!-- Table -->
                <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                    <table class="table table-hover table-bordered align-middle mb-0" id="manualRunTable">
                        <thead class="table-dark sticky-top small text-uppercase">
                            <tr>
                                <th style="width: 40px;" class="text-center">
                                    <input type="checkbox" id="masterCheckbox" class="form-check-input" checked onchange="toggleSelectAll(this.checked)">
                                </th>
                                <th style="width: 50px;" class="text-center">Sr.</th>
                                <th>Shop Name & Retailer</th>
                                <th style="width: 150px;">Route / Beat</th>
                                <th style="width: 130px;" class="text-end">Prev Balance (سابقہ)</th>
                                <th style="width: 150px;" class="text-end">Bill / Target Amt (بل)</th>
                                <th style="width: 140px;" class="text-end">Credit (کریڈٹ)</th>
                                <th>Notes / Ref</th>
                            </tr>
                        </thead>
                        <tbody id="manualRunTbody">
                            <?php 
                            $sr = 1;
                            foreach ($allCustomers as $c): 
                                $bal = (float)$c['current_balance'];
                                $hasBal = ($bal > 0);
                            ?>
                                <tr class="cust-row" 
                                    id="row-cust-<?= $c['id'] ?>"
                                    data-area-id="<?= (int)$c['area_id'] ?>" 
                                    data-bal="<?= $bal ?>"
                                    data-search="<?= strtolower(htmlspecialchars($c['shop_name'] . ' ' . $c['owner_name'] . ' ' . $c['phone'])) ?>">
                                    <td class="text-center">
                                        <input type="checkbox" 
                                               name="customer_ids[]" 
                                               value="<?= $c['id'] ?>" 
                                               class="form-check-input row-checkbox" 
                                               <?= $hasBal ? 'checked' : '' ?>
                                               onchange="recalcSummary()">
                                    </td>
                                    <td class="text-center text-muted small row-sr"><?= $sr++ ?></td>
                                    <td>
                                        <div class="fw-bold text-navy"><?= htmlspecialchars($c['shop_name']) ?></div>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($c['owner_name'] ?? '') ?> 
                                            <?= !empty($c['phone']) ? ' | ' . htmlspecialchars($c['phone']) : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-sans">
                                            <?= htmlspecialchars($c['area_name'] ?? '-') ?>
                                        </span>
                                    </td>
                                    <td class="text-end font-monospace fw-bold <?= $bal > 0 ? 'text-danger' : 'text-muted' ?>">
                                        <span class="prev-bal-val" data-val="<?= $bal ?>">
                                            <?= number_format($bal, 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               step="0.01" 
                                               min="0" 
                                               name="bill_amounts[<?= $c['id'] ?>]" 
                                               class="form-control form-control-sm text-end font-monospace bill-amt-input" 
                                               value="0.00" 
                                               oninput="onBillAmountChange(this, <?= $bal ?>)">
                                    </td>
                                    <td class="text-end font-monospace fw-bold text-dark total-due-cell">
                                        <?= number_format($bal, 2) ?>
                                    </td>
                                    <td>
                                        <input type="text" 
                                               name="bill_notes[<?= $c['id'] ?>]" 
                                               class="form-control form-control-sm" 
                                               placeholder="e.g. Purana Udhaar">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Summary Bar -->
                <div class="card-footer bg-light p-3 border-top">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-3 col-sm-6 text-center text-sm-start">
                            <div class="text-muted small fw-bold text-uppercase">Selected Shops</div>
                            <div class="h4 fw-bold text-primary mb-0 mt-1" id="statSelectedCount">0</div>
                        </div>
                        <div class="col-md-3 col-sm-6 text-center text-sm-start">
                            <div class="text-muted small fw-bold text-uppercase">Total Previous Udhaar</div>
                            <div class="h4 fw-bold text-danger mb-0 mt-1 font-monospace" id="statTotalPrevBal">Rs. 0.00</div>
                        </div>
                        <div class="col-md-3 col-sm-6 text-center text-sm-start">
                            <div class="text-muted small fw-bold text-uppercase">Total Assigned Bill Target</div>
                            <div class="h4 fw-bold text-navy mb-0 mt-1 font-monospace" id="statTotalBillAmt">Rs. 0.00</div>
                        </div>
                        <div class="col-md-3 col-sm-6 text-center text-sm-start">
                            <div class="text-muted small fw-bold text-uppercase">Grand Total Net Due</div>
                            <div class="h4 fw-bold text-success mb-0 mt-1 font-monospace" id="statGrandTotalDue">Rs. 0.00</div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Submission Actions -->
            <div class="d-flex justify-content-between align-items-center mb-5 pb-3">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle me-1"></i> Cancel
                </a>
                <div class="d-flex gap-2">
                    <button type="submit" name="action" value="save" class="btn btn-primary fw-bold px-4 shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Save Credit Sheet (کریڈٹ شیٹ محفوظ کریں - F9)
                    </button>
                    <button type="submit" name="action" value="save_and_print" class="btn btn-success fw-bold px-4 shadow-sm">
                        <i class="bi bi-printer me-1"></i> Save & Print Sheet (Photo 8)
                    </button>
                </div>
            </div>

        </form>

    </div>
</div>

<script>
// Filter table rows based on Area, Balance Toggle, and Search input
function filterCustomerTable() {
    const areaSelect = document.getElementById('manualAreaSelect');
    const selectedAreaId = parseInt(areaSelect.value, 10);
    const onlyWithBalance = document.getElementById('filterOnlyWithBalance').checked;
    const searchVal = document.getElementById('custTableSearch').value.trim().toLowerCase();

    const rows = document.querySelectorAll('#manualRunTbody tr.cust-row');
    let visibleSr = 1;

    rows.forEach(row => {
        const rowAreaId = parseInt(row.getAttribute('data-area-id') || 0, 10);
        const rowBal    = parseFloat(row.getAttribute('data-bal') || 0);
        const rowSearch = row.getAttribute('data-search') || '';

        let matchArea = (selectedAreaId === 0 || rowAreaId === selectedAreaId);
        let matchBal  = (!onlyWithBalance || rowBal > 0);
        let matchText = (!searchVal || rowSearch.includes(searchVal));

        if (matchArea && matchBal && matchText) {
            row.style.display = '';
            const srCell = row.querySelector('.row-sr');
            if (srCell) srCell.textContent = visibleSr++;
        } else {
            row.style.display = 'none';
        }
    });

    recalcSummary();
}

// When user changes bill amount input on a row
function onBillAmountChange(input, prevBal) {
    const bAmt = parseFloat(input.value || 0);
    const row = input.closest('tr');
    const dueCell = row.querySelector('.total-due-cell');
    const totalDue = prevBal + bAmt;
    if (dueCell) {
        dueCell.textContent = totalDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    recalcSummary();
}

// Toggle select all visible rows
function toggleSelectAll(checked) {
    const rows = document.querySelectorAll('#manualRunTbody tr.cust-row');
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const cb = row.querySelector('.row-checkbox');
            if (cb) cb.checked = checked;
        }
    });
    recalcSummary();
}

// Recalculate KPI summary counters
function recalcSummary() {
    let selectedCount = 0;
    let totalPrevBal = 0;
    let totalBillAmt = 0;
    let grandTotalDue = 0;

    const rows = document.querySelectorAll('#manualRunTbody tr.cust-row');
    rows.forEach(row => {
        const cb = row.querySelector('.row-checkbox');
        if (cb && cb.checked) {
            selectedCount++;
            const pBal = parseFloat(row.getAttribute('data-bal') || 0);
            const bInput = row.querySelector('.bill-amt-input');
            const bAmt = bInput ? parseFloat(bInput.value || 0) : 0;
            
            totalPrevBal += pBal;
            totalBillAmt += bAmt;
            grandTotalDue += (pBal + bAmt);
        }
    });

    document.getElementById('statSelectedCount').textContent = selectedCount;
    document.getElementById('statTotalPrevBal').textContent = 'Rs. ' + totalPrevBal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('statTotalBillAmt').textContent = 'Rs. ' + totalBillAmt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('statGrandTotalDue').textContent = 'Rs. ' + grandTotalDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Auto select area when salesman is selected
function onSalesmanChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const areaId = opt ? parseInt(opt.getAttribute('data-area-id') || 0, 10) : 0;
    if (areaId > 0) {
        const areaSelect = document.getElementById('manualAreaSelect');
        if (areaSelect) {
            areaSelect.value = String(areaId);
            filterCustomerTable();
        }
    }
}

// Add single shop from top dropdown into active selection
function addSingleShopFromDropdown() {
    const select = document.getElementById('singleShopAdderSelect');
    if (!select || !select.value) {
        alert("Please select a shop from the dropdown to add.");
        return;
    }

    const custId = select.value;
    const existingRow = document.getElementById('row-cust-cust-' + custId) || document.getElementById('row-cust-' + custId);
    
    if (existingRow) {
        existingRow.style.display = '';
        const cb = existingRow.querySelector('.row-checkbox');
        if (cb) cb.checked = true;
        existingRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        existingRow.classList.add('table-warning');
        setTimeout(() => existingRow.classList.remove('table-warning'), 1500);
        recalcSummary();
    } else {
        alert("Selected shop is already available in the list.");
    }
}

// Keyboard shortcuts (F9 to save)
document.addEventListener('keydown', function(e) {
    if (e.key === 'F9') {
        e.preventDefault();
        document.getElementById('manualDeliveryForm').submit();
    }
});

// Run filter on initial page load
document.addEventListener('DOMContentLoaded', function() {
    filterCustomerTable();
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
