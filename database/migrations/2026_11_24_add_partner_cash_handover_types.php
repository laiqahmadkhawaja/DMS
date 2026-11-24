<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

echo "Running Migration: Add partner cash handover & return transaction types...\n";

try {
    db()->exec("
        ALTER TABLE `partner_transactions` 
        MODIFY COLUMN `transaction_type` ENUM(
            'expense_paid',
            'expense_share',
            'cash_drawn',
            'cash_returned',
            'sales_cash_handover',
            'sales_profit',
            'capital_added',
            'inter_settlement',
            'adjustment'
        ) NOT NULL
    ");
    echo "[SUCCESS] partner_transactions.transaction_type updated with cash_returned and sales_cash_handover.\n";
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
