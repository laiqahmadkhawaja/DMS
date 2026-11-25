-- ==============================================================================
-- DMS Distribution Management System
-- Server Update & Schema Synchronization Migration Script
-- Safe, Idempotent, Zero Data Loss, No Foreign Key Errors
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ------------------------------------------------------------------------------
-- 1. Helper Procedure to Add Columns Idempotently (If Not Exists)
-- ------------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `dms_add_column_if_not_exists`;

DELIMITER $$
CREATE PROCEDURE `dms_add_column_if_not_exists`(
    IN in_table VARCHAR(64),
    IN in_column VARCHAR(64),
    IN in_col_definition TEXT
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO col_exists 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = in_table 
      AND COLUMN_NAME = in_column;
      
    IF col_exists = 0 THEN
        SET @query_sql = CONCAT('ALTER TABLE `', in_table, '` ADD COLUMN `', in_column, '` ', in_col_definition);
        PREPARE stmt FROM @query_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ------------------------------------------------------------------------------
-- 2. Update orders table (Dual Discount & Free Loose Pieces Scheme)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('orders', 'discount_mode', "ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate'");
CALL `dms_add_column_if_not_exists`('orders', 'subtotal_discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('orders', 'subtotal_discount_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('orders', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0");
ALTER TABLE `orders` MODIFY COLUMN `delivery_date` DATE NULL DEFAULT NULL;

-- ------------------------------------------------------------------------------
-- 2b. Standard Packaging Units in units table (Carton, Box, Packet/Pack, Piece)
-- ------------------------------------------------------------------------------
INSERT INTO `units` (`id`, `unit_code`, `unit_name`, `symbol`, `is_active`, `is_deleted`)
VALUES 
    (1, 'CTN', 'Carton', 'Ctn', 1, 0),
    (2, 'BOX', 'Box', 'Box', 1, 0),
    (3, 'PCK', 'Packet', 'Pck', 1, 0),
    (4, 'PCS', 'Piece', 'Pcs', 1, 0)
ON DUPLICATE KEY UPDATE 
    `unit_code` = VALUES(`unit_code`),
    `unit_name` = VALUES(`unit_name`),
    `symbol`    = VALUES(`symbol`),
    `is_active` = 1,
    `is_deleted` = 0;

-- ------------------------------------------------------------------------------
-- 2c. Correct Legacy Carton Items Saved With Piece Unit ID (4 -> 1)
-- ------------------------------------------------------------------------------
UPDATE `order_items` 
SET `unit_id` = 1 
WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;

UPDATE `sales_invoice_items` 
SET `unit_id` = 1 
WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;

UPDATE `purchase_order_items` 
SET `unit_id` = 1 
WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;

UPDATE `sales_return_items` 
SET `unit_id` = 1 
WHERE `carton_qty` > 0 AND `pack_qty` = 0 AND `pcs_qty` = 0 AND `unit_id` = 4;

-- ------------------------------------------------------------------------------
-- 3. Update sales_invoices table (Dual Discount & Free Loose Pieces Scheme)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('sales_invoices', 'discount_mode', "ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate'");
CALL `dms_add_column_if_not_exists`('sales_invoices', 'subtotal_discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('sales_invoices', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0");

-- ------------------------------------------------------------------------------
-- 4. Update purchase_orders table
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('purchase_orders', 'discount_mode', "ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate'");
CALL `dms_add_column_if_not_exists`('purchase_orders', 'subtotal_discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_orders', 'subtotal_discount_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_orders', 'total_cartons', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_orders', 'total_units', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_orders', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0");

-- ------------------------------------------------------------------------------
-- 5. Update purchase_order_items table
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00");

-- ------------------------------------------------------------------------------
-- 6. Update sales_returns & sales_return_items tables
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('sales_returns', 'stock_settled', "TINYINT(1) NOT NULL DEFAULT 1");
CALL `dms_add_column_if_not_exists`('sales_returns', 'free_loose_pieces', "TINYINT(1) NOT NULL DEFAULT 0");

CALL `dms_add_column_if_not_exists`('sales_return_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('sales_return_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('sales_return_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('sales_return_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00");

-- ------------------------------------------------------------------------------
-- 7. Update products table (Carton and Piece Rate Dual Pricing)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('products', 'carton_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('products', 'piece_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('products', 'purchase_carton_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('products', 'purchase_piece_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('products', 'retail_carton_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
CALL `dms_add_column_if_not_exists`('products', 'retail_piece_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");

-- Backfill product carton and piece rates if they were 0 without overwriting customized values
UPDATE `products` 
SET `carton_rate` = `wholesale_rate` 
WHERE (`carton_rate` IS NULL OR `carton_rate` = 0) AND `wholesale_rate` > 0;

UPDATE `products` 
SET `piece_rate` = ROUND(`carton_rate` / GREATEST(1, `carton_qty` * `pack_qty`), 2) 
WHERE (`piece_rate` IS NULL OR `piece_rate` = 0) AND `carton_rate` > 0;

UPDATE `products` 
SET `purchase_carton_rate` = `purchase_rate` 
WHERE (`purchase_carton_rate` IS NULL OR `purchase_carton_rate` = 0) AND `purchase_rate` > 0;

UPDATE `products` 
SET `purchase_piece_rate` = ROUND(`purchase_carton_rate` / GREATEST(1, `carton_qty` * `pack_qty`), 2) 
WHERE (`purchase_piece_rate` IS NULL OR `purchase_piece_rate` = 0) AND `purchase_carton_rate` > 0;

-- ------------------------------------------------------------------------------
-- 8. Partners & Shared Expense Khatas System
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `partners` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `partner_code` varchar(30) NOT NULL,
    `partner_name` varchar(100) NOT NULL,
    `urdu_name` varchar(100) DEFAULT NULL,
    `role` enum('partner','managing_partner') NOT NULL DEFAULT 'partner',
    `supplier_id` int(10) unsigned DEFAULT NULL,
    `phone` varchar(30) DEFAULT NULL,
    `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Positive = Payable to partner, Negative = Partner owes',
    `notes` text DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_partner_code` (`partner_code`),
    KEY `idx_partner_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partner_transactions` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `transaction_number` varchar(40) NOT NULL,
    `transaction_date` date NOT NULL,
    `partner_id` int(10) unsigned NOT NULL,
    `transaction_type` enum('expense_paid','expense_share','cash_drawn','cash_returned','sales_cash_handover','sales_profit','capital_added','inter_settlement','adjustment') NOT NULL,
    `reference_type` varchar(40) NOT NULL DEFAULT 'manual',
    `reference_id` bigint(20) unsigned DEFAULT NULL,
    `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
    `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
    `running_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
    `description` text DEFAULT NULL,
    `created_by` bigint(20) unsigned DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ptr_trans_no` (`transaction_number`),
    KEY `idx_ptr_partner` (`partner_id`),
    KEY `idx_ptr_date` (`transaction_date`),
    KEY `idx_ptr_ref` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update transaction_type enum in partner_transactions if table already existed previously
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
) NOT NULL;

-- ------------------------------------------------------------------------------
-- 9. Update expenses table for Partner Sharing & Pocket Payments
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('expenses', 'paid_by_type', "ENUM('central_cash','partner') NOT NULL DEFAULT 'central_cash'");
CALL `dms_add_column_if_not_exists`('expenses', 'paid_by_partner_id', "INT(10) UNSIGNED DEFAULT NULL");
CALL `dms_add_column_if_not_exists`('expenses', 'split_type', "ENUM('equal_50_50','only_moazzam','only_rehman','custom','none') NOT NULL DEFAULT 'equal_50_50'");
CALL `dms_add_column_if_not_exists`('expenses', 'split_details', "TEXT DEFAULT NULL");

-- ------------------------------------------------------------------------------
-- 10. Update General Ledger Reference Types for Partner Movements
-- ------------------------------------------------------------------------------
ALTER TABLE `journal_entries` 
MODIFY COLUMN `reference_type` ENUM(
    'opening',
    'sales_invoice',
    'settlement',
    'cash_receipt',
    'supplier_payment',
    'grn',
    'expense',
    'manual_journal',
    'partner_drawing',
    'sales_cash_handover',
    'partner_cash_received'
) NOT NULL DEFAULT 'manual_journal';

ALTER TABLE `journal_entry_lines` 
MODIFY COLUMN `party_type` ENUM(
    'customer',
    'supplier',
    'salesman',
    'partner'
) DEFAULT NULL;

-- ------------------------------------------------------------------------------
-- 11. Seed Initial Default Partners (If Missing)
-- ------------------------------------------------------------------------------
INSERT INTO `partners` (`partner_code`, `partner_name`, `urdu_name`, `role`, `supplier_id`, `notes`, `is_active`)
VALUES 
    ('PTR-MOAZZAM', 'Moazzam', 'معظم (ایس ایم ایس)', 'partner', 2, 'Partner owning and managing SMS Agency & products.', 1),
    ('PTR-REHMAN', 'Abdur Rehman', 'عبدالرحمن (اوچاب)', 'partner', 1, 'Partner owning and managing Ujab Enterprises Agency & products.', 1),
    ('PTR-CENTRAL', 'Managing Partner (Central Sales Pool)', 'تھرڈ پارٹنر (سیلز و کیش کسٹوڈین)', 'managing_partner', NULL, 'Managing partner overseeing total sales, recovery, and central operations cash pool.', 1)
ON DUPLICATE KEY UPDATE 
    `partner_name` = VALUES(`partner_name`),
    `urdu_name` = VALUES(`urdu_name`),
    `role` = VALUES(`role`);

-- Ensure Admin Role has partner permissions
INSERT IGNORE INTO `permissions` (`module`, `permission_code`, `permission_name`, `description`) 
VALUES
    ('partners', 'partners_view', 'View Partner Khatas', 'View partner ledger statements and balances'),
    ('partners', 'partners_manage', 'Manage Partners & Drawings', 'Record partner drawings, settlements and expense splits');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id` FROM `permissions` p WHERE p.`module` = 'partners';

-- ------------------------------------------------------------------------------
-- 12. Update delivery_runs & delivery_run_bills (Route Delivery & Recovery Sheets)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('delivery_runs', 'area_id', "INT(10) UNSIGNED DEFAULT NULL AFTER `salesman_id`");
CALL `dms_add_column_if_not_exists`('delivery_runs', 'run_type', "VARCHAR(20) NOT NULL DEFAULT 'load_sheet' AFTER `load_sheet_id`");

-- Allow null invoice_id in delivery_run_bills for manual customer balance recovery sheets
ALTER TABLE `delivery_run_bills` MODIFY COLUMN `invoice_id` BIGINT(20) UNSIGNED DEFAULT NULL;

CALL `dms_add_column_if_not_exists`('delivery_run_bills', 'customer_id', "INT(10) UNSIGNED DEFAULT NULL AFTER `invoice_id`");
CALL `dms_add_column_if_not_exists`('delivery_run_bills', 'bill_number', "VARCHAR(50) DEFAULT NULL AFTER `customer_id`");
CALL `dms_add_column_if_not_exists`('delivery_run_bills', 'prev_balance', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `bill_number`");
CALL `dms_add_column_if_not_exists`('delivery_run_bills', 'bill_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `prev_balance`");
CALL `dms_add_column_if_not_exists`('delivery_run_bills', 'total_due', "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `bill_amount`");

-- ------------------------------------------------------------------------------
-- Clean up helper procedure
-- ------------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `dms_add_column_if_not_exists`;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- MIGRATION COMPLETED SUCCESSFULLY WITHOUT ERRORS
-- ==============================================================================
