<?php
/**
 * DMS - Distribution Management System
 * Delivery Runs - Create Delivery Run Sheet
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('delivery_manage');

$pageTitle = 'Create Delivery Run';
$activeMenu = 'delivery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Delivery Runs', 'url' => 'index.php'],
    ['label' => 'New Run Sheet', 'url' => '']
];

$preLoadSheetId = isset($_GET['load_sheet_id']) ? (int)$_GET['load_sheet_id'] : 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $runDate     = sanitize_input($_POST['run_date'] ?? date('Y-m-d'));
    $salesmanId  = (int)($_POST['salesman_id'] ?? 0);
    $loadSheetId = !empty($_POST['load_sheet_id']) ? (int)$_POST['load_sheet_id'] : null;
    $routeNotes  = sanitize_input($_POST['route_notes'] ?? '');
    $selectedInvoices = $_POST['invoice_ids'] ?? [];

    if ($salesmanId <= 0) {
        $errors[] = "Please select a delivery salesman.";
    }
    if (empty($selectedInvoices) || !is_array($selectedInvoices)) {
        $errors[] = "Please select at least one sales invoice for this delivery run.";
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $userId = current_user_id();
            $runNumber = generate_document_number(PREFIX_RUN, 'delivery_runs', 'run_number');

            $inPlaceholders = implode(',', array_fill(0, count($selectedInvoices), '?'));
            $bills = db_fetch_all("
                SELECT id, net_amount 
                FROM sales_invoices 
                WHERE id IN ({$inPlaceholders})
            ", $selectedInvoices);

            $totalRunAmount = array_sum(array_column($bills, 'net_amount'));

            // 1. Insert delivery_runs
            $stmtRun = db()->prepare("
                INSERT INTO delivery_runs (
                    run_number, run_date, salesman_id, load_sheet_id, route_notes, 
                    total_bills, total_amount, status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, 'assigned', ?, NOW()
                )
            ");
            $stmtRun->execute([
                $runNumber, $runDate, $salesmanId, $loadSheetId, $routeNotes,
                count($selectedInvoices), $totalRunAmount, $userId
            ]);
            $runId = (int)db()->lastInsertId();

            // 2. Insert delivery_run_bills
            $stmtBill = db()->prepare("
                INSERT INTO delivery_run_bills (
                    delivery_run_id, invoice_id, stop_sequence, delivery_status, created_at
                ) VALUES (
                    ?, ?, ?, 'pending', NOW()
                )
            ");

            $seq = 1;
            foreach ($selectedInvoices as $invId) {
                $stmtBill->execute([$runId, (int)$invId, $seq++]);
            }

            db()->commit();
            set_flash('success', "Delivery Run <strong>{$runNumber}</strong> created with " . count($selectedInvoices) . " customer bills.");
            redirect(APP_URL . '/modules/delivery/view.php?id=' . $runId);

        } catch (Exception $e) {
            db()->rollBack();
            error_log("Error creating delivery run: " . $e->getMessage());
            $errors[] = "Error saving delivery run: " . $e->getMessage();
        }
    }
}

// Pre-fill from load sheet if provided
$defaultSalesmanId = 0;
if ($preLoadSheetId > 0) {
    $preSheet = db_fetch_one("SELECT * FROM load_sheets WHERE id = ?", [$preLoadSheetId]);
    if ($preSheet) {
        $defaultSalesmanId = (int)$preSheet['salesman_id'];
    }
}

$salesmen   = db_fetch_all("
    SELECT sm.id, sm.salesman_name, sm.assigned_area_id, a.area_name as assigned_area_name 
    FROM salesmen sm 
    LEFT JOIN areas a ON sm.assigned_area_id = a.id 
    WHERE sm.is_active = 1 
    ORDER BY sm.salesman_name ASC
");
$loadSheets = db_fetch_all("SELECT id, load_number, delivery_date FROM load_sheets WHERE status = 'issued' ORDER BY id DESC LIMIT 20");

// Available finalized invoices not yet in an active run
$availableInvoices = db_fetch_all("
    SELECT 
        inv.id,
        inv.invoice_number,
        inv.invoice_date,
        inv.load_sheet_id,
        c.shop_name,
        a.area_name,
        b.booker_name,
        inv.net_amount
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    JOIN areas a ON inv.area_id = a.id
    JOIN bookers b ON inv.booker_id = b.id
    WHERE inv.status = 'finalized'
    ORDER BY a.area_name ASC, c.shop_name ASC
");

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
                    <i class="bi bi-geo-alt-fill me-2"></i>Create Delivery Run Sheet
                </h4>
                <p class="text-muted small mb-0">Assign customer bills to delivery salesman and generate field recovery sheet</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Runs
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

        <form method="POST" action="create.php" id="deliveryForm">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-navy"><i class="bi bi-sliders me-1"></i> Delivery Run Details</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Run Date <span class="text-danger">*</span></label>
                            <input type="date" name="run_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Delivery Salesman <span class="text-danger">*</span></label>
                            <select name="salesman_id" class="form-select" required>
                                <option value="">-- Select Salesman --</option>
                                <?php foreach ($salesmen as $sm): ?>
                                    <option value="<?= $sm['id'] ?>" <?= ($defaultSalesmanId === (int)$sm['id']) ? 'selected' : '' ?> data-area-id="<?= (int)($sm['assigned_area_id'] ?? 0) ?>">
                                        <?= htmlspecialchars($sm['salesman_name']) ?><?= !empty($sm['assigned_area_name']) ? ' [Beat: ' . htmlspecialchars($sm['assigned_area_name']) . ']' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Associated Load Sheet</label>
                            <select name="load_sheet_id" id="loadSheetSelect" class="form-select">
                                <option value="">-- Direct Delivery Run / None --</option>
                                <?php foreach ($loadSheets as $ls): ?>
                                    <option value="<?= $ls['id'] ?>" <?= ($preLoadSheetId === (int)$ls['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ls['load_number']) ?> (<?= date('d-M', strtotime($ls['delivery_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Route Notes</label>
                            <input type="text" name="route_notes" class="form-control" placeholder="e.g. Morning van 1 (Satellite Town route)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoices to include -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-navy">
                        <i class="bi bi-receipt me-1"></i> Select Customer Bills for this Run (<?= count($availableInvoices) ?> available)
                    </h6>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-xs btn-outline-secondary" id="btnSelectAll">Select All</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" id="btnDeselectAll">Deselect All</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase sticky-top">
                                <tr>
                                    <th class="ps-3" style="width: 40px;">
                                        <input type="checkbox" class="form-check-input" id="checkMaster">
                                    </th>
                                    <th>Bill #</th>
                                    <th>Customer / Retail Shop</th>
                                    <th>Route / Area</th>
                                    <th>Booker</th>
                                    <th class="text-end pe-3">Bill Amount (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($availableInvoices)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            No active customer bills available. First create or issue a load sheet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($availableInvoices as $inv): 
                                        $isChecked = ($preLoadSheetId > 0 && (int)$inv['load_sheet_id'] === $preLoadSheetId);
                                    ?>
                                        <tr class="inv-row" data-sheet="<?= (int)$inv['load_sheet_id'] ?>">
                                            <td class="ps-3">
                                                <input type="checkbox" name="invoice_ids[]" value="<?= $inv['id'] ?>" class="form-check-input inv-checkbox" <?= $isChecked ? 'checked' : '' ?>>
                                            </td>
                                            <td class="fw-bold text-navy"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($inv['shop_name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($inv['area_name']) ?></span></td>
                                            <td><?= htmlspecialchars($inv['booker_name']) ?></td>
                                            <td class="text-end pe-3 fw-bold text-dark"><?= format_currency((float)$inv['net_amount']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light py-2 text-end">
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Generate Delivery Run Sheet
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const master = document.getElementById('checkMaster');
    master.addEventListener('change', function() {
        document.querySelectorAll('.inv-checkbox').forEach(cb => cb.checked = master.checked);
    });

    document.getElementById('btnSelectAll').addEventListener('click', function() {
        document.querySelectorAll('.inv-checkbox').forEach(cb => cb.checked = true);
        master.checked = true;
    });

    document.getElementById('btnDeselectAll').addEventListener('click', function() {
        document.querySelectorAll('.inv-checkbox').forEach(cb => cb.checked = false);
        master.checked = false;
    });

    // When load sheet dropdown changes, highlight/check invoices matching that load sheet
    document.getElementById('loadSheetSelect').addEventListener('change', function() {
        const selectedSheet = this.value;
        if (!selectedSheet) return;
        document.querySelectorAll('.inv-row').forEach(row => {
            const rowSheet = row.getAttribute('data-sheet');
            const cb = row.querySelector('.inv-checkbox');
            if (rowSheet === selectedSheet) {
                cb.checked = true;
            } else {
                cb.checked = false;
            }
        });
    });
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
