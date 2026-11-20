<?php
/**
 * DMS - Distribution Management System
 * Sales Returns - View Return Voucher
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('returns_view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid return voucher ID.');
    redirect(APP_URL . '/modules/returns/index.php');
}

$ret = db_fetch_one("
    SELECT 
        sr.*,
        c.shop_name as customer_name,
        c.customer_code,
        c.address as customer_address,
        inv.invoice_number,
        st.settlement_number,
        u.full_name as creator_name
    FROM sales_returns sr
    JOIN customers c ON sr.customer_id = c.id
    LEFT JOIN sales_invoices inv ON sr.invoice_id = inv.id
    LEFT JOIN settlements st ON sr.settlement_id = st.id
    LEFT JOIN users u ON sr.created_by = u.id
    WHERE sr.id = ?
", [$id]);

if (!$ret) {
    set_flash('danger', 'Return voucher not found.');
    redirect(APP_URL . '/modules/returns/index.php');
}

$items = db_fetch_all("
    SELECT 
        sri.*,
        p.product_code,
        p.product_name,
        u.unit_code,
        u.unit_name
    FROM sales_return_items sri
    JOIN products p ON sri.product_id = p.id
    JOIN units u ON sri.unit_id = u.id
    WHERE sri.sales_return_id = ?
    ORDER BY sri.id ASC
", [$id]);

$journalEntry = db_fetch_one("
    SELECT * FROM journal_entries 
    WHERE reference_type = 'sales_return' AND reference_id = ?
", [$id]);

$pageTitle = 'Return Voucher ' . $ret['return_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Sales Returns', 'url' => 'index.php'],
    ['label' => $ret['return_number'], 'url' => '']
];

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';

?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Top Action Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-arrow-return-left me-2"></i>Sales Return: <?= htmlspecialchars($ret['return_number']) ?>
                </h4>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-success"><?= htmlspecialchars($ret['status']) ?></span>
                    <span class="text-muted small">Date: <?= date('d-M-Y', strtotime($ret['return_date'])) ?></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Returns
                </a>
                <a href="print.php?id=<?= $ret['id'] ?>" class="btn btn-primary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print Credit Voucher
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 mb-4 pb-3 border-bottom">
                    <div class="col-md-6">
                        <div class="text-muted small text-uppercase fw-bold">Customer</div>
                        <div class="fs-5 fw-bold text-dark mt-1"><?= htmlspecialchars($ret['customer_name']) ?> (<?= htmlspecialchars($ret['customer_code']) ?>)</div>
                        <div class="small text-muted"><?= htmlspecialchars($ret['customer_address'] ?: 'N/A') ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small text-uppercase fw-bold">Return Classification</div>
                        <div class="mt-1">
                            <?php if ($ret['condition_type'] === 'saleable'): ?>
                                <span class="badge bg-success">Saleable (Restocked)</span>
                            <?php elseif ($ret['condition_type'] === 'damaged'): ?>
                                <span class="badge bg-danger">Damaged / Leaked</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Expired</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted mt-1">Reason: <?= htmlspecialchars($ret['reason'] ?: 'None specified') ?></div>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <div class="text-muted small text-uppercase fw-bold">Credit Total</div>
                        <div class="fs-4 fw-bold text-navy mt-1"><?= format_currency((float)$ret['total_amount']) ?></div>
                        <div class="small text-muted">Deducted from customer balance</div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase text-center">
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th class="text-start ps-3">Product Description</th>
                                <th style="width: 15%;">Quantity</th>
                                <th style="width: 15%;">Rate</th>
                                <th style="width: 20%;" class="text-end pe-3">Credit Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            foreach ($items as $item): 
                            ?>
                                <tr>
                                    <td class="text-center text-muted small"><?= $sr++ ?></td>
                                    <td class="ps-3 fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td class="text-center fw-semibold"><?= (float)$item['quantity'] ?> <?= htmlspecialchars($item['unit_code']) ?></td>
                                    <td class="text-center"><?= format_currency((float)$item['rate']) ?></td>
                                    <td class="text-end pe-3 fw-bold text-navy"><?= format_currency((float)$item['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold fs-6">
                            <tr>
                                <td colspan="4" class="text-end text-uppercase">Total Credit:</td>
                                <td class="text-end pe-3 text-navy"><?= format_currency((float)$ret['total_amount']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row pt-4 text-center small fw-bold">
                    <div class="col-6">
                        <div class="border-top border-dark pt-1">Warehouse Stock Receiver</div>
                    </div>
                    <div class="col-6">
                        <div class="border-top border-dark pt-1">Authorized Manager Approval</div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
