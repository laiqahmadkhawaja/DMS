<?php
/**
 * DMS - Delete Customer Price Override
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('masters_manage');

$id = (int)($_GET['id'] ?? 0);
$cp = db_fetch_one("SELECT * FROM customer_prices WHERE id = ?", [$id]);

if ($cp) {
    db_execute("DELETE FROM customer_prices WHERE id = ?", [$id]);
    log_audit('delete_customer_price', 'price_lists', $id, "Removed special rate override for customer ID {$cp['customer_id']}");
    set_flash_message('success', 'Customer special rate removed. Default catalog wholesale rate restored.');
} else {
    set_flash_message('danger', 'Rate record not found.');
}

redirect(APP_URL . '/modules/price_lists/index.php');
