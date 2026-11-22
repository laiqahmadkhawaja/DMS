<?php
/**
 * DMS - Distribution Management System
 * Sales Invoices - Actions Handler (Finalize, Cancel)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

require_login();

$action = sanitize_input($_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0 || empty($action)) {
    set_flash('danger', 'Invalid invoice action request.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

$invoice = db_fetch_one("SELECT * FROM sales_invoices WHERE id = ?", [$id]);
if (!$invoice) {
    set_flash('danger', 'Invoice not found.');
    redirect(APP_URL . '/modules/invoices/index.php');
}

if ($action === 'cancel') {
    require_permission('invoices_cancel');

    if ($invoice['status'] === 'settled') {
        set_flash('danger', 'Settled bills cannot be cancelled directly. Settle corrections through returns or vouchers.');
        redirect(APP_URL . '/modules/invoices/view.php?id=' . $id);
    }

    try {
        db()->beginTransaction();
        $userId = current_user_id();

        // If direct invoice (load_sheet_id is null), reverse stock movement
        if (empty($invoice['load_sheet_id'])) {
            $items = db_fetch_all("SELECT * FROM sales_invoice_items WHERE invoice_id = ?", [$id]);
            foreach ($items as $item) {
                $revQty = (float)$item['quantity'];
                $revTot = (float)$item['line_total'];
                $pieceCost = $revQty > 0 ? round($revTot / $revQty, 6) : 0;
                record_stock_movement([
                    'product_id'     => (int)$item['product_id'],
                    'warehouse_id'   => 1,
                    'movement_type'  => 'stock_adjustment_add',
                    'reference_type' => 'adjustment',
                    'reference_id'   => $id,
                    'qty_in'         => $revQty,
                    'qty_out'        => 0,
                    'unit_cost'      => $pieceCost,
                    'total_cost'     => $revTot,
                    'remarks'        => "Stock reversal for cancelled invoice #{$invoice['invoice_number']}",
                    'condition_type' => 'saleable'
                ]);
            }

            // If credit was added to customer balance, reverse it
            if ((float)$invoice['credit_amount'] > 0) {
                update_customer_balance($invoice['customer_id'], (float)$invoice['credit_amount'], 'decrease');
            }
        }

        db()->prepare("
            UPDATE sales_invoices 
            SET status = 'cancelled', cancel_reason = 'Cancelled by user', cancelled_at = NOW(), updated_at = NOW() 
            WHERE id = ?
        ")->execute([$id]);

        db()->commit();
        set_flash('success', "Invoice #{$invoice['invoice_number']} successfully cancelled.");
        redirect(APP_URL . '/modules/invoices/index.php');

    } catch (Exception $e) {
        db()->rollBack();
        error_log("Error cancelling invoice: " . $e->getMessage());
        set_flash('danger', "Error cancelling invoice: " . $e->getMessage());
        redirect(APP_URL . '/modules/invoices/view.php?id=' . $id);
    }

} elseif ($action === 'finalize') {
    require_permission('invoices_finalize');

    db()->prepare("UPDATE sales_invoices SET status = 'finalized', finalized_at = NOW(), updated_at = NOW() WHERE id = ?")
        ->execute([$id]);

    set_flash('success', "Invoice #{$invoice['invoice_number']} finalized.");
    redirect(APP_URL . '/modules/invoices/view.php?id=' . $id);
} else {
    set_flash('danger', 'Unknown action.');
    redirect(APP_URL . '/modules/invoices/index.php');
}
