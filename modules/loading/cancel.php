<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - Cancel Load Sheet Action
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('loading_cancel');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash('danger', 'Invalid load sheet ID specified.');
    redirect(APP_URL . '/modules/loading/index.php');
}

$sheet = db_fetch_one("SELECT * FROM load_sheets WHERE id = ?", [$id]);
if (!$sheet) {
    set_flash('danger', 'Load sheet not found.');
    redirect(APP_URL . '/modules/loading/index.php');
}

if ($sheet['status'] === 'returned_closed') {
    set_flash('danger', 'Closed / reconciled load sheets cannot be cancelled.');
    redirect(APP_URL . '/modules/loading/view.php?id=' . $id);
}

try {
    db()->beginTransaction();
    $userId = current_user_id();

    // If issued, reverse stock movements
    if ($sheet['status'] === 'issued') {
        $items = db_fetch_all("SELECT * FROM load_sheet_items WHERE load_sheet_id = ?", [$id]);
        foreach ($items as $item) {
            $loadedQty = (float)$item['loaded_qty'];
            if ($loadedQty > 0) {
                $totAmt = (float)$item['total_amount'];
                $pieceCost = round($totAmt / $loadedQty, 6);
                record_stock_movement([
                    'product_id'     => (int)$item['product_id'],
                    'warehouse_id'   => (int)$sheet['warehouse_id'],
                    'movement_type'  => 'stock_adjustment_add',
                    'reference_type' => 'load_sheet',
                    'reference_id'   => $id,
                    'qty_in'         => $loadedQty,
                    'qty_out'        => 0,
                    'unit_cost'      => $pieceCost,
                    'total_cost'     => $totAmt,
                    'remarks'        => "Stock reversal for cancelled Load Sheet #{$sheet['load_number']}",
                    'condition_type' => 'saleable'
                ]);
            }
        }

        // Cancel generated invoices if not settled
        $invoices = db_fetch_all("SELECT id, status FROM sales_invoices WHERE load_sheet_id = ?", [$id]);
        foreach ($invoices as $inv) {
            if ($inv['status'] === 'settled') {
                throw new Exception("Cannot cancel load sheet: invoice #{$inv['id']} has already been settled on route.");
            }
            db()->prepare("UPDATE sales_invoices SET status = 'cancelled', cancel_reason = 'Load sheet cancelled', cancelled_at = NOW() WHERE id = ?")
                ->execute([$inv['id']]);
        }
    }

    // Restore orders to confirmed
    $orderLinks = db_fetch_all("SELECT order_id FROM load_sheet_orders WHERE load_sheet_id = ?", [$id]);
    $stmtUpdOrder = db()->prepare("UPDATE orders SET status = 'confirmed', updated_at = NOW() WHERE id = ?");
    foreach ($orderLinks as $ol) {
        $stmtUpdOrder->execute([(int)$ol['order_id']]);
    }

    // Update load sheet status
    db()->prepare("UPDATE load_sheets SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
        ->execute([$id]);

    db()->commit();
    set_flash('success', "Load sheet <strong>{$sheet['load_number']}</strong> has been cancelled and inventory/orders restored.");
    redirect(APP_URL . '/modules/loading/index.php');

} catch (Exception $e) {
    db()->rollBack();
    error_log("Error cancelling load sheet: " . $e->getMessage());
    set_flash('danger', "Error cancelling load sheet: " . $e->getMessage());
    redirect(APP_URL . '/modules/loading/view.php?id=' . $id);
}
