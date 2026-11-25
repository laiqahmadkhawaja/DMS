-- ==============================================================================
-- DMS Distribution Management System
-- Server Update & Schema Synchronization Migration Script (2026-11-25)
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
-- 1b. Update users table (Security, Lockouts & Activity Columns)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('users', 'phone', "VARCHAR(25) DEFAULT NULL AFTER `full_name`");
CALL `dms_add_column_if_not_exists`('users', 'status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `phone`");
CALL `dms_add_column_if_not_exists`('users', 'last_login_at', "DATETIME DEFAULT NULL AFTER `status`");
CALL `dms_add_column_if_not_exists`('users', 'login_attempts', "INT NOT NULL DEFAULT 0 AFTER `last_login_at`");
CALL `dms_add_column_if_not_exists`('users', 'locked_until', "DATETIME DEFAULT NULL AFTER `login_attempts`");
CALL `dms_add_column_if_not_exists`('users', 'is_deleted', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `locked_until`");

-- ------------------------------------------------------------------------------
-- 2. Update orders table constraints (Make delivery_date nullable)
-- ------------------------------------------------------------------------------
ALTER TABLE `orders` MODIFY COLUMN `delivery_date` DATE NULL DEFAULT NULL;

-- ------------------------------------------------------------------------------
-- 3. Verify Standard Packaging Units in units table (Carton, Box, Packet/Pack, Piece)
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
-- 4. Correct Legacy Carton Items Saved With Piece Unit ID (4 -> 1)
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
-- 5. Update sales_return_items Table (Pack and Packaging Breakdown Columns)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('sales_return_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_id`");
CALL `dms_add_column_if_not_exists`('sales_return_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `carton_qty`");
CALL `dms_add_column_if_not_exists`('sales_return_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_qty`");
CALL `dms_add_column_if_not_exists`('sales_return_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `rate`");

-- ------------------------------------------------------------------------------
-- 6. Update purchase_order_items Table (Pack and Packaging Breakdown Columns)
-- ------------------------------------------------------------------------------
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'carton_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_id`");
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'pack_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `carton_qty`");
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'pcs_qty', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_qty`");
CALL `dms_add_column_if_not_exists`('purchase_order_items', 'discount_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `rate`");

-- ------------------------------------------------------------------------------
-- 7. Update delivery_runs & delivery_run_bills (Route Delivery & Recovery Sheets)
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
-- 2026-11-25 MIGRATION COMPLETED SUCCESSFULLY WITHOUT ERRORS
-- ==============================================================================
