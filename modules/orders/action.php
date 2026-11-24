<?php
/**
 * DMS - Process Order Actions (Confirm, Cancel)
 */

require_once __DIR__ . '/../../config/config.php';
require_login();

$id     = (int)($_GET['id'] ?? 0);
$action = clean_input($_GET['action'] ?? '');

$order = db_fetch_one("SELECT * FROM orders WHERE id = ?", [$id]);

if (!$order) {
    set_flash_message('danger', 'Order not found.');
    redirect(APP_URL . '/modules/orders/index.php');
}

if ($action === 'confirm') {
    require_permission('orders_confirm');
    if ($order['status'] !== 'draft') {
        set_flash_message('warning', "Only draft orders can be confirmed.");
        redirect(APP_URL . '/modules/orders/view.php?id=' . $id);
    }

    db_execute("UPDATE orders SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('confirm_order', 'orders', $id, "Confirmed order #{$order['order_number']}");
    set_flash_message('success', "Order #{$order['order_number']} confirmed. It is now ready for Load Form (لوڈ فارم).");
} elseif ($action === 'cancel') {
    require_permission('orders_cancel');
    if (in_array($order['status'], ['loaded', 'delivered'])) {
        set_flash_message('danger', "Orders that have already been loaded or delivered cannot be cancelled directly.");
        redirect(APP_URL . '/modules/orders/view.php?id=' . $id);
    }

    db_execute("UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = 'Cancelled by operator', updated_at = NOW() WHERE id = ?", [$id]);
    log_audit('cancel_order', 'orders', $id, "Cancelled order #{$order['order_number']}");
    set_flash_message('warning', "Order #{$order['order_number']} has been cancelled.");
}

redirect(APP_URL . '/modules/orders/view.php?id=' . $id);
