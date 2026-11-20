<?php
/**
 * DMS - Distribution Management System
 * Warehouse Loading & Dispatch - Issue Stock Action
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

require_login();
require_permission('loading_issue');

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

if ($sheet['status'] !== 'draft') {
    set_flash('warning', 'Only draft load sheets can be issued.');
    redirect(APP_URL . '/modules/loading/view.php?id=' . $id);
}

try {
    db()->beginTransaction();
    $userId = current_user_id();

    // 1. Update load sheet header
    $stmt = db()->prepare("
        UPDATE load_sheets 
        SET status = 'issued', issued_at = NOW(), issued_by = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId, $id]);

    // 2. Fetch load sheet items
    $items = db_fetch_all("SELECT * FROM load_sheet_items WHERE load_sheet_id = ?", [$id]);
    $stmtUpdItem = db()->prepare("UPDATE load_sheet_items SET loaded_qty = required_qty WHERE id = ?");

    foreach ($items as $item) {
        $stmtUpdItem->execute([$item['id']]);

        // Record stock movement (sales_dispatch)
        record_stock_movement([
            'product_id'     => (int)$item['product_id'],
            'warehouse_id'   => (int)$sheet['warehouse_id'],
            'movement_type'  => 'sales_dispatch',
            'reference_type' => 'load_sheet',
            'reference_id'   => $id,
            'qty_in'         => 0,
            'qty_out'        => (float)$item['required_qty'],
            'unit_cost'      => (float)$item['rate'],
            'remarks'        => "Stock dispatch for Load Sheet #{$sheet['load_number']}",
            'condition_type' => 'saleable'
        ]);
    }

    // 3. Update orders and auto-generate Sales Invoices
    $orderLinks = db_fetch_all("SELECT order_id FROM load_sheet_orders WHERE load_sheet_id = ?", [$id]);
    $stmtUpdOrder = db()->prepare("UPDATE orders SET status = 'loaded', updated_at = NOW() WHERE id = ?");

    $stmtInv = db()->prepare("
        INSERT INTO sales_invoices (
            invoice_number, invoice_date, order_id, load_sheet_id, customer_id, 
            booker_id, salesman_id, area_id, gross_amount, item_discount_amount, 
            extra_discount_amount, net_amount, status, reconciliation_status, payment_status,
            created_by, finalized_at, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, 
            ?, ?, 'finalized', 'pending', 'unpaid',
            ?, NOW(), NOW()
        )
    ");

    $stmtInvItem = db()->prepare("
        INSERT INTO sales_invoice_items (
            invoice_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, 
            quantity, rate, discount_percent, discount_amount, line_total, created_at
        ) VALUES (
            ?, ?, ?, ?, 0.00, ?, 
            ?, ?, ?, ?, ?, NOW()
        )
    ");

    foreach ($orderLinks as $ol) {
        $ordId = (int)$ol['order_id'];
        $stmtUpdOrder->execute([$ordId]);

        // Check if invoice already exists
        $existingInv = db_fetch_val("SELECT id FROM sales_invoices WHERE order_id = ? AND load_sheet_id = ?", [$ordId, $id]);
        if (!$existingInv) {
            $ord = db_fetch_one("SELECT * FROM orders WHERE id = ?", [$ordId]);
            if ($ord) {
                $invNumber = generate_document_number(PREFIX_INVOICE, 'sales_invoices', 'invoice_number');
                $stmtInv->execute([
                    $invNumber, $sheet['delivery_date'], $ord['id'], $id, $ord['customer_id'],
                    $ord['booker_id'], $sheet['salesman_id'], $ord['area_id'], $ord['gross_amount'],
                    $ord['discount_amount'], 0.00, $ord['net_amount'],
                    $userId
                ]);
                $invoiceId = (int)db()->lastInsertId();

                $thisOrderItems = db_fetch_all("SELECT * FROM order_items WHERE order_id = ?", [$ord['id']]);
                foreach ($thisOrderItems as $toi) {
                    $stmtInvItem->execute([
                        $invoiceId, $toi['product_id'], $toi['unit_id'],
                        $toi['carton_qty'], $toi['pcs_qty'], $toi['quantity'],
                        $toi['rate'], $toi['discount_percent'], $toi['discount_amount'],
                        $toi['line_total']
                    ]);
                }
            }
        }
    }

    db()->commit();
    set_flash('success', "Load Sheet <strong>{$sheet['load_number']}</strong> has been issued and stock dispatched from warehouse!");
    redirect(APP_URL . '/modules/loading/view.php?id=' . $id);

} catch (Exception $e) {
    db()->rollBack();
    error_log("Error issuing load sheet: " . $e->getMessage());
    set_flash('danger', "Error issuing stock: " . $e->getMessage());
    redirect(APP_URL . '/modules/loading/view.php?id=' . $id);
}
