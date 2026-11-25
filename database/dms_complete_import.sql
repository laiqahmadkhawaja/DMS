-- ==============================================================================
-- DMS Distribution Management System - Complete Clean Database Dump
-- Ready to import directly into phpMyAdmin or MySQL CLI with ZERO errors
-- Generated: 2026-11-25 14:53:08
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- -----------------------------------------------------------------------------
-- Table structure for `accounts`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_code` varchar(30) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `sub_type` enum('cash','bank','wallet','accounts_receivable','accounts_payable','inventory','sales','sales_discount','sales_return','cost_of_goods_sold','expense','equity') NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `branch_code` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_code` (`account_code`),
  KEY `idx_acc_code` (`account_code`),
  KEY `idx_acc_type` (`account_type`,`sub_type`)
) ENGINE=InnoDB AUTO_INCREMENT=6051 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `accounts` (16 rows)
INSERT INTO `accounts` (`id`, `account_code`, `account_name`, `account_type`, `sub_type`, `bank_name`, `account_number`, `branch_code`, `notes`, `opening_balance`, `current_balance`, `is_system`, `is_active`, `created_at`, `updated_at`) VALUES
('1001', '1001', 'Cash in Hand (Office Main)', 'asset', 'cash', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('1002', '1002', 'Petty Cash', 'asset', 'cash', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('1010', '1010', 'Bank Al-Habib / MCB Current Account', 'asset', 'bank', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('1020', '1020', 'Accounts Receivable (Trade Debtors)', 'asset', 'accounts_receivable', NULL, NULL, NULL, NULL, '0.00', '493359.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:50:18'),
('1030', '1030', 'Inventory Asset (Finished Goods Stock)', 'asset', 'inventory', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('2010', '2010', 'Accounts Payable (Trade Creditors)', 'liability', 'accounts_payable', NULL, NULL, NULL, NULL, '0.00', '430000.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:50:18'),
('3010', '3010', 'Owner Capital / Equity', 'equity', 'equity', NULL, NULL, NULL, NULL, '0.00', '63359.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:50:18'),
('4010', '4010', 'Sales Revenue', 'revenue', 'sales', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('4020', '4020', 'Sales Discounts Allowed', 'revenue', 'sales_discount', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('4030', '4030', 'Sales Returns & Allowances', 'revenue', 'sales_return', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('5010', '5010', 'Cost of Goods Sold (COGS)', 'expense', 'cost_of_goods_sold', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('6010', '6010', 'Operating Expenses - Fuel & Van Transport', 'expense', 'expense', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('6020', '6020', 'Operating Expenses - Staff Salaries & Daily Wages', 'expense', 'expense', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('6030', '6030', 'Operating Expenses - Warehouse Rent & Utilities', 'expense', 'expense', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('6040', '6040', 'Operating Expenses - Vehicle Maintenance', 'expense', 'expense', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('6050', '6050', 'Operating Expenses - General Office & Tea', 'expense', 'expense', NULL, NULL, NULL, NULL, '0.00', '0.00', '1', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `areas`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `areas`;
CREATE TABLE `areas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `area_code` varchar(20) NOT NULL,
  `area_name` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL DEFAULT 'Gujranwala',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `area_code` (`area_code`),
  KEY `idx_areas_name` (`area_name`),
  KEY `idx_areas_active` (`is_active`,`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `areas` (6 rows)
INSERT INTO `areas` (`id`, `area_code`, `area_name`, `city`, `description`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'AREA-001', 'Jandiala Bhagwala', 'Gujranwala', 'G.T. Road Main Route', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('2', 'AREA-002', 'Daal Bazar', 'Gujranwala', 'Old City Wholesale Market', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('3', 'AREA-003', 'Rahwali', 'Gujranwala', 'Cantonment / Highway Route', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('4', 'AREA-004', 'Satellite Town', 'Gujranwala', 'Residential & Commercial Hub', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('5', 'AREA-005', 'Rana Bhatti Bhango', 'Gujranwala', 'Rural Outer Delivery Beat', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('6', 'AREA-006', 'Dholay / Peoples Colony', 'Ghakar', 'Ghakar Mandi Beat', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17');

-- -----------------------------------------------------------------------------
-- Table structure for `audit_logs`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` varchar(50) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_module` (`module`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `booker_areas`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `booker_areas`;
CREATE TABLE `booker_areas` (
  `booker_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`booker_id`,`area_id`),
  KEY `fk_ba_area` (`area_id`),
  CONSTRAINT `fk_ba_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ba_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `bookers`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `bookers`;
CREATE TABLE `bookers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booker_code` varchar(20) NOT NULL,
  `booker_name` varchar(100) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `cnic` varchar(25) DEFAULT NULL,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booker_code` (`booker_code`),
  KEY `idx_bookers_code` (`booker_code`),
  KEY `idx_bookers_active` (`is_active`,`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `bookers` (2 rows)
INSERT INTO `bookers` (`id`, `booker_code`, `booker_name`, `phone`, `cnic`, `commission_rate`, `notes`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'BKR-001', 'Imran Butt', '0300-6732761', NULL, '1.50', 'Senior Market Booker for Daal Bazar & Jandiala', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('2', 'BKR-002', 'Abdur Rehman', '0301-7654321', NULL, '1.50', 'Route Booker for Rahwali & Satellite Town', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17');

-- -----------------------------------------------------------------------------
-- Table structure for `brands`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `brands`;
CREATE TABLE `brands` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `brand_code` varchar(20) NOT NULL,
  `brand_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `brand_code` (`brand_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `cash_accounts`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cash_accounts`;
CREATE TABLE `cash_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `custodian_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_ca_acc` (`account_id`),
  CONSTRAINT `fk_ca_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `cash_accounts` (2 rows)
INSERT INTO `cash_accounts` (`id`, `account_id`, `account_name`, `custodian_name`, `is_active`, `created_at`) VALUES
('1', '1001', 'Main Office Cash Drawer', 'Cashier / Admin', '1', '2026-11-25 18:45:48'),
('2', '1002', 'Daily Route Delivery Cash Buffer', 'Sales Manager', '1', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `cash_submissions`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cash_submissions`;
CREATE TABLE `cash_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_number` varchar(30) NOT NULL,
  `submission_date` date NOT NULL,
  `salesman_id` int(10) unsigned NOT NULL,
  `settlement_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `cash_account_id` int(10) unsigned DEFAULT NULL,
  `received_by` bigint(20) unsigned NOT NULL,
  `shortage_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `excess_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `submission_number` (`submission_number`),
  KEY `idx_csub_date` (`submission_date`),
  KEY `idx_csub_sm` (`salesman_id`),
  KEY `fk_csub_set` (`settlement_id`),
  KEY `fk_csub_user` (`received_by`),
  CONSTRAINT `fk_csub_set` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_csub_sm` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_csub_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `customer_payments`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customer_payments`;
CREATE TABLE `customer_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(30) NOT NULL,
  `payment_date` date NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `salesman_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_mode` enum('cash','cheque','bank_transfer','online') NOT NULL DEFAULT 'cash',
  `account_id` int(10) unsigned NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `idx_cp_date` (`payment_date`),
  KEY `idx_cp_cust` (`customer_id`),
  KEY `fk_cp_salesman` (`salesman_id`),
  KEY `fk_cp_account` (`account_id`),
  KEY `fk_cp_user` (`created_by`),
  CONSTRAINT `fk_cp_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_cp_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `customer_prices`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customer_prices`;
CREATE TABLE `customer_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `special_rate` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cust_prod_unit` (`customer_id`,`product_id`,`unit_id`),
  KEY `fk_cp_prod` (`product_id`),
  KEY `fk_cp_unit` (`unit_id`),
  CONSTRAINT `fk_cp_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `customers`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(20) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `owner_name` varchar(100) DEFAULT NULL,
  `phone` varchar(25) NOT NULL,
  `cnic_or_ntn` varchar(30) DEFAULT NULL,
  `address` text NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `booker_id` int(10) unsigned DEFAULT NULL,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_days` int(11) NOT NULL DEFAULT 0,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `default_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `price_list_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_code` (`customer_code`),
  KEY `idx_cust_code` (`customer_code`),
  KEY `idx_cust_shop` (`shop_name`),
  KEY `idx_cust_area` (`area_id`),
  KEY `idx_cust_booker` (`booker_id`),
  KEY `idx_cust_phone` (`phone`),
  KEY `fk_cust_plist` (`price_list_id`),
  CONSTRAINT `fk_cust_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_cust_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cust_plist` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `customers` (14 rows)
INSERT INTO `customers` (`id`, `customer_code`, `shop_name`, `owner_name`, `phone`, `cnic_or_ntn`, `address`, `area_id`, `booker_id`, `credit_limit`, `credit_days`, `opening_balance`, `current_balance`, `default_discount_percent`, `price_list_id`, `notes`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'CUST-00001', 'Prince Grocery', 'Mian Prince', '0300-1111111', NULL, 'Jandiala Bhagwala G.T. Road', '1', '1', '150000.00', '7', '9600.00', '9600.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('2', 'CUST-00002', 'Ramzan Sweets', 'Mian Ramzan', '0300-2222222', NULL, 'Daal Bazar Gujranwala', '2', '1', '150000.00', '7', '35000.00', '35000.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('3', 'CUST-00003', 'Usman J.S', 'Usman', '0300-3333333', NULL, 'Daal Bazar', '2', '1', '150000.00', '7', '2160.00', '2160.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('4', 'CUST-00004', 'Waqas Super Store', 'Waqas', '0300-4444444', NULL, 'Near Chungi Daal Bazar', '2', '1', '150000.00', '7', '0.00', '0.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('5', 'CUST-00005', 'Hafiz Super Store', 'Hafiz Sb', '0300-5555555', NULL, 'Main Market Satellite Town', '4', '2', '150000.00', '7', '15390.00', '15390.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('6', 'CUST-00006', 'Rehman Super Store', 'Abdul Rehman', '0300-6666666', NULL, 'Block C Satellite Town', '4', '2', '150000.00', '7', '0.00', '0.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('7', 'CUST-00007', 'Akram Super Store', 'Mian Akram', '0300-7777777', NULL, 'Circular Road Daal Bazar', '2', '1', '150000.00', '7', '140000.00', '140000.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('8', 'CUST-00008', 'Mamu Gee Super Store', 'Mamu Gee', '0300-8888888', NULL, 'Main Bazar Rahwali Cantt', '3', '2', '150000.00', '7', '1640.00', '1640.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('9', 'CUST-00009', 'Latif Cash & Carry', 'Chaudhry Latif', '0300-9999999', NULL, 'G.T. Road Rahwali', '3', '2', '150000.00', '7', '14310.00', '14310.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('10', 'CUST-00010', 'Muzzum Shah', 'Muzzum Shah', '0300-0001111', NULL, 'Rana Bhatti Bhango', '5', '1', '150000.00', '7', '138408.00', '138408.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('11', 'CUST-00011', 'Mujahid Shah', 'Mujahid Shah', '0300-0002222', NULL, 'Satellite Town', '4', '2', '150000.00', '7', '21546.00', '21546.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('12', 'CUST-00012', 'Ali Traders', 'Ali', '0300-0003333', NULL, 'Daal Bazar', '2', '1', '150000.00', '7', '14000.00', '14000.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('13', 'CUST-00013', 'Moeen', 'Moeen Sb', '0300-0004444', NULL, 'Dholay, Peoples Colony, Ghakar', '6', '2', '150000.00', '7', '17940.00', '17940.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('14', 'CUST-00014', 'Ahmed Super Store', 'Ahmed', '0300-0005555', NULL, 'Rahwali', '3', '2', '150000.00', '7', '83365.00', '83365.00', '0.00', NULL, NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18');

-- -----------------------------------------------------------------------------
-- Table structure for `delivery_run_bills`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `delivery_run_bills`;
CREATE TABLE `delivery_run_bills` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_run_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `bill_number` varchar(50) DEFAULT NULL,
  `prev_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bill_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_due` decimal(15,2) NOT NULL DEFAULT 0.00,
  `stop_sequence` int(11) NOT NULL DEFAULT 0,
  `delivery_status` enum('pending','delivered','partial','refused','not_delivered') NOT NULL DEFAULT 'pending',
  `cash_collected` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_given` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_drb_run` (`delivery_run_id`),
  KEY `idx_drb_inv` (`invoice_id`),
  KEY `idx_drb_cust` (`customer_id`),
  CONSTRAINT `fk_drb_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_drb_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_drb_run` FOREIGN KEY (`delivery_run_id`) REFERENCES `delivery_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `delivery_runs`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `delivery_runs`;
CREATE TABLE `delivery_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_number` varchar(30) NOT NULL,
  `run_date` date NOT NULL,
  `area_id` int(10) unsigned DEFAULT NULL,
  `salesman_id` int(10) unsigned NOT NULL,
  `load_sheet_id` bigint(20) unsigned DEFAULT NULL,
  `route_notes` text DEFAULT NULL,
  `total_bills` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `run_type` enum('order_delivery','credit_recovery') NOT NULL DEFAULT 'order_delivery',
  `status` enum('assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'assigned',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_number` (`run_number`),
  KEY `idx_dr_date` (`run_date`),
  KEY `idx_dr_area` (`area_id`),
  KEY `idx_dr_salesman` (`salesman_id`),
  KEY `fk_dr_ls` (`load_sheet_id`),
  KEY `fk_dr_user` (`created_by`),
  CONSTRAINT `fk_dr_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dr_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dr_sm` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_dr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `expense_categories`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_code` varchar(20) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_code` (`category_code`),
  KEY `fk_ec_acc` (`account_id`),
  CONSTRAINT `fk_ec_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `expense_categories` (6 rows)
INSERT INTO `expense_categories` (`id`, `category_code`, `category_name`, `account_id`, `description`, `is_active`, `created_at`) VALUES
('1', 'EXP-FUEL', 'Fuel & Transport', '6010', 'Van petrol, diesel, and freight costs', '1', '2026-11-25 18:45:48'),
('2', 'EXP-SALARY', 'Salaries & Allowances', '6020', 'Sales staff, bookers, and warehouse daily wages', '1', '2026-11-25 18:45:48'),
('3', 'EXP-RENT', 'Rent & Electricity', '6030', 'Warehouse and shop premises rent and power', '1', '2026-11-25 18:45:48'),
('4', 'EXP-MAINT', 'Vehicle Maintenance', '6040', 'Van repair, bike maintenance, tires, and oil', '1', '2026-11-25 18:45:48'),
('5', 'EXP-LOAD', 'Loading & Unloading', '6010', 'Labour charges for wagon/truck loading', '1', '2026-11-25 18:45:48'),
('6', 'EXP-OFFICE', 'Office & Tea Expense', '6050', 'Daily office refreshments, stationery, printing', '1', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `expenses`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expense_number` varchar(30) NOT NULL,
  `expense_date` date NOT NULL,
  `expense_category_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `paid_from_account_id` int(10) unsigned NOT NULL,
  `payment_mode` enum('cash','bank') NOT NULL DEFAULT 'cash',
  `paid_by_type` enum('central_cash','partner') NOT NULL DEFAULT 'central_cash',
  `paid_by_partner_id` int(10) unsigned DEFAULT NULL,
  `split_type` enum('equal_50_50','only_moazzam','only_rehman','custom','none') NOT NULL DEFAULT 'equal_50_50',
  `split_details` text DEFAULT NULL,
  `payee_name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `reference_voucher` varchar(50) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `expense_number` (`expense_number`),
  KEY `idx_exp_date` (`expense_date`),
  KEY `idx_exp_cat` (`expense_category_id`),
  KEY `idx_exp_partner` (`paid_by_partner_id`),
  KEY `fk_exp_acc` (`paid_from_account_id`),
  KEY `fk_exp_user` (`created_by`),
  CONSTRAINT `fk_exp_acc` FOREIGN KEY (`paid_from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_exp_cat` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `fk_exp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `goods_receipt_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `goods_receipt_items`;
CREATE TABLE `goods_receipt_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `goods_receipt_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ordered_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `received_qty` decimal(12,2) NOT NULL,
  `short_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `damaged_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_rate` decimal(12,2) NOT NULL,
  `line_total` decimal(15,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_gri_grn` (`goods_receipt_id`),
  KEY `fk_gri_prod` (`product_id`),
  KEY `fk_gri_unit` (`unit_id`),
  CONSTRAINT `fk_gri_grn` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_gri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `goods_receipts`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `goods_receipts`;
CREATE TABLE `goods_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_number` varchar(30) NOT NULL,
  `purchase_order_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `supplier_bill_no` varchar(50) DEFAULT NULL,
  `grn_date` date NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','verified','posted') NOT NULL DEFAULT 'posted',
  `remarks` text DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `grn_number` (`grn_number`),
  KEY `idx_grn_date` (`grn_date`),
  KEY `idx_grn_supp` (`supplier_id`),
  KEY `fk_grn_po` (`purchase_order_id`),
  KEY `fk_grn_wh` (`warehouse_id`),
  KEY `fk_grn_user` (`received_by`),
  CONSTRAINT `fk_grn_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grn_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_grn_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grn_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `invoice_discounts`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `invoice_discounts`;
CREATE TABLE `invoice_discounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `discount_type` varchar(50) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_id_inv` (`invoice_id`),
  CONSTRAINT `fk_id_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `journal_entries`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `journal_entries`;
CREATE TABLE `journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_number` varchar(30) NOT NULL,
  `entry_date` date NOT NULL,
  `reference_type` enum('opening','sales_invoice','settlement','cash_receipt','supplier_payment','grn','expense','manual_journal','partner_drawing','sales_cash_handover','partner_cash_received') NOT NULL DEFAULT 'manual_journal',
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `total_debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `narration` text DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_number` (`entry_number`),
  KEY `idx_je_date` (`entry_date`),
  KEY `idx_je_ref` (`reference_type`,`reference_id`),
  KEY `fk_je_user` (`created_by`),
  CONSTRAINT `fk_je_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `journal_entries` (14 rows)
INSERT INTO `journal_entries` (`id`, `entry_number`, `entry_date`, `reference_type`, `reference_id`, `total_debit`, `total_credit`, `narration`, `is_posted`, `created_by`, `created_at`) VALUES
('1', 'JV-2026-00001', '2026-11-25', 'opening', '1', '9600.00', '9600.00', 'Opening Receivable Balance for Retailer: Prince Grocery', '1', NULL, '2026-11-25 18:50:18'),
('2', 'JV-2026-00002', '2026-11-25', 'opening', '2', '35000.00', '35000.00', 'Opening Receivable Balance for Retailer: Ramzan Sweets', '1', NULL, '2026-11-25 18:50:18'),
('3', 'JV-2026-00003', '2026-11-25', 'opening', '3', '2160.00', '2160.00', 'Opening Receivable Balance for Retailer: Usman J.S', '1', NULL, '2026-11-25 18:50:18'),
('4', 'JV-2026-00004', '2026-11-25', 'opening', '5', '15390.00', '15390.00', 'Opening Receivable Balance for Retailer: Hafiz Super Store', '1', NULL, '2026-11-25 18:50:18'),
('5', 'JV-2026-00005', '2026-11-25', 'opening', '7', '140000.00', '140000.00', 'Opening Receivable Balance for Retailer: Akram Super Store', '1', NULL, '2026-11-25 18:50:18'),
('6', 'JV-2026-00006', '2026-11-25', 'opening', '8', '1640.00', '1640.00', 'Opening Receivable Balance for Retailer: Mamu Gee Super Store', '1', NULL, '2026-11-25 18:50:18'),
('7', 'JV-2026-00007', '2026-11-25', 'opening', '9', '14310.00', '14310.00', 'Opening Receivable Balance for Retailer: Latif Cash & Carry', '1', NULL, '2026-11-25 18:50:18'),
('8', 'JV-2026-00008', '2026-11-25', 'opening', '10', '138408.00', '138408.00', 'Opening Receivable Balance for Retailer: Muzzum Shah', '1', NULL, '2026-11-25 18:50:18'),
('9', 'JV-2026-00009', '2026-11-25', 'opening', '11', '21546.00', '21546.00', 'Opening Receivable Balance for Retailer: Mujahid Shah', '1', NULL, '2026-11-25 18:50:18'),
('10', 'JV-2026-00010', '2026-11-25', 'opening', '12', '14000.00', '14000.00', 'Opening Receivable Balance for Retailer: Ali Traders', '1', NULL, '2026-11-25 18:50:18'),
('11', 'JV-2026-00011', '2026-11-25', 'opening', '13', '17940.00', '17940.00', 'Opening Receivable Balance for Retailer: Moeen', '1', NULL, '2026-11-25 18:50:18'),
('12', 'JV-2026-00012', '2026-11-25', 'opening', '14', '83365.00', '83365.00', 'Opening Receivable Balance for Retailer: Ahmed Super Store', '1', NULL, '2026-11-25 18:50:18'),
('13', 'JV-2026-00013', '2026-11-25', 'opening', '1', '250000.00', '250000.00', 'Opening Payable Balance for Supplier: Al-Hafiz Nimko & Sweets Factory', '1', NULL, '2026-11-25 18:50:18'),
('14', 'JV-2026-00014', '2026-11-25', 'opening', '2', '180000.00', '180000.00', 'Opening Payable Balance for Supplier: Dawn Snack Foods Ltd', '1', NULL, '2026-11-25 18:50:18');

-- -----------------------------------------------------------------------------
-- Table structure for `journal_entry_lines`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `journal_entry_lines`;
CREATE TABLE `journal_entry_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint(20) unsigned NOT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `party_type` enum('customer','supplier','salesman','partner') DEFAULT NULL,
  `party_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_jel_entry` (`journal_entry_id`),
  KEY `idx_jel_account` (`account_id`),
  KEY `idx_jel_party` (`party_type`,`party_id`),
  CONSTRAINT `fk_jel_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_jel_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `journal_entry_lines` (28 rows)
INSERT INTO `journal_entry_lines` (`id`, `journal_entry_id`, `account_id`, `debit`, `credit`, `description`, `party_type`, `party_id`, `created_at`) VALUES
('1', '1', '1020', '9600.00', '0.00', 'Opening debit for Prince Grocery', 'customer', '1', '2026-11-25 18:50:18'),
('2', '1', '3010', '0.00', '9600.00', 'Opening capital reserve for Prince Grocery', NULL, NULL, '2026-11-25 18:50:18'),
('3', '2', '1020', '35000.00', '0.00', 'Opening debit for Ramzan Sweets', 'customer', '2', '2026-11-25 18:50:18'),
('4', '2', '3010', '0.00', '35000.00', 'Opening capital reserve for Ramzan Sweets', NULL, NULL, '2026-11-25 18:50:18'),
('5', '3', '1020', '2160.00', '0.00', 'Opening debit for Usman J.S', 'customer', '3', '2026-11-25 18:50:18'),
('6', '3', '3010', '0.00', '2160.00', 'Opening capital reserve for Usman J.S', NULL, NULL, '2026-11-25 18:50:18'),
('7', '4', '1020', '15390.00', '0.00', 'Opening debit for Hafiz Super Store', 'customer', '5', '2026-11-25 18:50:18'),
('8', '4', '3010', '0.00', '15390.00', 'Opening capital reserve for Hafiz Super Store', NULL, NULL, '2026-11-25 18:50:18'),
('9', '5', '1020', '140000.00', '0.00', 'Opening debit for Akram Super Store', 'customer', '7', '2026-11-25 18:50:18'),
('10', '5', '3010', '0.00', '140000.00', 'Opening capital reserve for Akram Super Store', NULL, NULL, '2026-11-25 18:50:18'),
('11', '6', '1020', '1640.00', '0.00', 'Opening debit for Mamu Gee Super Store', 'customer', '8', '2026-11-25 18:50:18'),
('12', '6', '3010', '0.00', '1640.00', 'Opening capital reserve for Mamu Gee Super Store', NULL, NULL, '2026-11-25 18:50:18'),
('13', '7', '1020', '14310.00', '0.00', 'Opening debit for Latif Cash & Carry', 'customer', '9', '2026-11-25 18:50:18'),
('14', '7', '3010', '0.00', '14310.00', 'Opening capital reserve for Latif Cash & Carry', NULL, NULL, '2026-11-25 18:50:18'),
('15', '8', '1020', '138408.00', '0.00', 'Opening debit for Muzzum Shah', 'customer', '10', '2026-11-25 18:50:18'),
('16', '8', '3010', '0.00', '138408.00', 'Opening capital reserve for Muzzum Shah', NULL, NULL, '2026-11-25 18:50:18'),
('17', '9', '1020', '21546.00', '0.00', 'Opening debit for Mujahid Shah', 'customer', '11', '2026-11-25 18:50:18'),
('18', '9', '3010', '0.00', '21546.00', 'Opening capital reserve for Mujahid Shah', NULL, NULL, '2026-11-25 18:50:18'),
('19', '10', '1020', '14000.00', '0.00', 'Opening debit for Ali Traders', 'customer', '12', '2026-11-25 18:50:18'),
('20', '10', '3010', '0.00', '14000.00', 'Opening capital reserve for Ali Traders', NULL, NULL, '2026-11-25 18:50:18'),
('21', '11', '1020', '17940.00', '0.00', 'Opening debit for Moeen', 'customer', '13', '2026-11-25 18:50:18'),
('22', '11', '3010', '0.00', '17940.00', 'Opening capital reserve for Moeen', NULL, NULL, '2026-11-25 18:50:18'),
('23', '12', '1020', '83365.00', '0.00', 'Opening debit for Ahmed Super Store', 'customer', '14', '2026-11-25 18:50:18'),
('24', '12', '3010', '0.00', '83365.00', 'Opening capital reserve for Ahmed Super Store', NULL, NULL, '2026-11-25 18:50:18'),
('25', '13', '3010', '250000.00', '0.00', 'Opening debit reserve for Al-Hafiz Nimko & Sweets Factory', NULL, NULL, '2026-11-25 18:50:18'),
('26', '13', '2010', '0.00', '250000.00', 'Opening payable balance for Al-Hafiz Nimko & Sweets Factory', 'supplier', '1', '2026-11-25 18:50:18'),
('27', '14', '3010', '180000.00', '0.00', 'Opening debit reserve for Dawn Snack Foods Ltd', NULL, NULL, '2026-11-25 18:50:18'),
('28', '14', '2010', '0.00', '180000.00', 'Opening payable balance for Dawn Snack Foods Ltd', 'supplier', '2', '2026-11-25 18:50:18');

-- -----------------------------------------------------------------------------
-- Table structure for `load_sheet_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `load_sheet_items`;
CREATE TABLE `load_sheet_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `load_sheet_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ordered_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `required_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loaded_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `short_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `returned_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lsi_sheet` (`load_sheet_id`),
  KEY `idx_lsi_prod` (`product_id`),
  KEY `fk_lsi_unit` (`unit_id`),
  CONSTRAINT `fk_lsi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_lsi_sheet` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lsi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `load_sheet_orders`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `load_sheet_orders`;
CREATE TABLE `load_sheet_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `load_sheet_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ls_order` (`load_sheet_id`,`order_id`),
  KEY `fk_lso_order` (`order_id`),
  CONSTRAINT `fk_lso_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lso_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `load_sheets`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `load_sheets`;
CREATE TABLE `load_sheets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `load_number` varchar(30) NOT NULL,
  `load_date` date NOT NULL,
  `delivery_date` date NOT NULL,
  `salesman_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','approved','issued','returned_closed','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `load_number` (`load_number`),
  KEY `idx_ls_date` (`load_date`),
  KEY `idx_ls_salesman` (`salesman_id`),
  KEY `idx_ls_status` (`status`),
  KEY `fk_ls_warehouse` (`warehouse_id`),
  KEY `fk_ls_creator` (`created_by`),
  KEY `fk_ls_issuer` (`issued_by`),
  CONSTRAINT `fk_ls_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ls_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ls_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_ls_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `order_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(12,2) NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL,
  `loaded_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_prod` (`product_id`),
  KEY `fk_oi_unit` (`unit_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_oi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `orders`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(30) NOT NULL,
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `booker_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `discount_mode` enum('item_rate','subtotal') NOT NULL DEFAULT 'item_rate',
  `subtotal_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `free_loose_pieces` tinyint(1) NOT NULL DEFAULT 0,
  `salesman_id` int(10) unsigned DEFAULT NULL,
  `gross_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cartons` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_units` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','confirmed','partially_loaded','loaded','dispatched','delivered','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_orders_date` (`order_date`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_cust` (`customer_id`),
  KEY `idx_orders_booker` (`booker_id`),
  KEY `idx_orders_area` (`area_id`),
  KEY `fk_ord_salesman` (`salesman_id`),
  KEY `fk_ord_user` (`created_by`),
  CONSTRAINT `fk_ord_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_ord_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`),
  CONSTRAINT `fk_ord_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_ord_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ord_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `partner_transactions`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `partner_transactions`;
CREATE TABLE `partner_transactions` (
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
  UNIQUE KEY `transaction_number` (`transaction_number`),
  KEY `idx_ptr_partner` (`partner_id`),
  KEY `idx_ptr_date` (`transaction_date`),
  KEY `idx_ptr_ref` (`reference_type`,`reference_id`),
  KEY `fk_ptr_user` (`created_by`),
  CONSTRAINT `fk_ptr_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `partners`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `partners`;
CREATE TABLE `partners` (
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
  UNIQUE KEY `partner_code` (`partner_code`),
  KEY `idx_partner_supplier` (`supplier_id`),
  CONSTRAINT `fk_partner_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `partners` (3 rows)
INSERT INTO `partners` (`id`, `partner_code`, `partner_name`, `urdu_name`, `role`, `supplier_id`, `phone`, `current_balance`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
('1', 'PTR-MOAZZAM', 'Moazzam', 'معظم (ایس ایم ایس)', 'partner', '2', NULL, '0.00', 'Partner owning and managing SMS Agency & products.', '1', '2026-11-25 18:50:33', '2026-11-25 18:50:33'),
('2', 'PTR-REHMAN', 'Abdur Rehman', 'عبدالرحمن (اوچاب)', 'partner', '1', NULL, '0.00', 'Partner owning and managing Ujab Enterprises Agency & products.', '1', '2026-11-25 18:50:33', '2026-11-25 18:50:33'),
('3', 'PTR-CENTRAL', 'Managing Partner (Central Sales Pool)', 'تھرڈ پارٹنر (سیلز و کیش کسٹوڈین)', 'managing_partner', NULL, NULL, '0.00', 'Managing partner overseeing total sales, recovery, and central operations cash pool.', '1', '2026-11-25 18:50:33', '2026-11-25 18:50:33');

-- -----------------------------------------------------------------------------
-- Table structure for `permissions`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(50) NOT NULL,
  `permission_code` varchar(100) NOT NULL,
  `permission_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_code` (`permission_code`),
  KEY `idx_perm_module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `permissions` (60 rows)
INSERT INTO `permissions` (`id`, `module`, `permission_code`, `permission_name`, `description`, `created_at`) VALUES
('1', 'customers', 'customers_view', 'View Customers', 'Access customer list, details, and balance', '2026-11-25 18:45:48'),
('2', 'customers', 'customers_create', 'Create Customer', 'Register new retail shops/customers', '2026-11-25 18:45:48'),
('3', 'customers', 'customers_edit', 'Edit Customer', 'Update customer profile and credit limit', '2026-11-25 18:45:48'),
('4', 'customers', 'customers_delete', 'Delete Customer', 'Soft delete customer records', '2026-11-25 18:45:48'),
('5', 'customers', 'customers_statement', 'View Customer Ledger', 'View customer debit/credit statement and aging', '2026-11-25 18:45:48'),
('6', 'suppliers', 'suppliers_view', 'View Suppliers', 'Access supplier list and balances', '2026-11-25 18:45:48'),
('7', 'suppliers', 'suppliers_create', 'Create Supplier', 'Register new wholesale suppliers', '2026-11-25 18:45:48'),
('8', 'suppliers', 'suppliers_edit', 'Edit Supplier', 'Update supplier details', '2026-11-25 18:45:48'),
('9', 'suppliers', 'suppliers_delete', 'Delete Supplier', 'Soft delete supplier records', '2026-11-25 18:45:48'),
('10', 'suppliers', 'suppliers_ledger', 'View Supplier Ledger', 'View supplier ledger and payment history', '2026-11-25 18:45:48'),
('11', 'products', 'products_view', 'View Products', 'Browse catalog, prices, and stock units', '2026-11-25 18:45:48'),
('12', 'products', 'products_create', 'Create Product', 'Add new products and packaging units', '2026-11-25 18:45:48'),
('13', 'products', 'products_edit', 'Edit Product', 'Update product details and default rates', '2026-11-25 18:45:48'),
('14', 'products', 'products_delete', 'Delete Product', 'Soft delete product records', '2026-11-25 18:45:48'),
('15', 'masters', 'masters_manage', 'Manage Masters', 'Manage Areas, Bookers, Salesmen, Categories, Units, Brands', '2026-11-25 18:45:48'),
('16', 'orders', 'orders_view', 'View Orders', 'View order booking list and status', '2026-11-25 18:45:48'),
('17', 'orders', 'orders_create', 'Create Order', 'Enter market booked orders', '2026-11-25 18:45:48'),
('18', 'orders', 'orders_edit', 'Edit Order', 'Modify draft orders', '2026-11-25 18:45:48'),
('19', 'orders', 'orders_confirm', 'Confirm Order', 'Confirm orders for warehouse loading', '2026-11-25 18:45:48'),
('20', 'orders', 'orders_cancel', 'Cancel Order', 'Cancel pending or draft orders', '2026-11-25 18:45:48'),
('21', 'loading', 'loading_view', 'View Loading Sheets', 'Browse van loading sheets and dispatch orders', '2026-11-25 18:45:48'),
('22', 'loading', 'loading_create', 'Create Loading Sheet', 'Consolidate orders and generate load sheet', '2026-11-25 18:45:48'),
('23', 'loading', 'loading_issue', 'Issue Stock / Dispatch', 'Confirm warehouse issue and deduct stock', '2026-11-25 18:45:48'),
('24', 'loading', 'loading_cancel', 'Cancel Loading Sheet', 'Cancel and reverse unissued or issued loads', '2026-11-25 18:45:48'),
('25', 'invoices', 'invoices_view', 'View Sales Invoices', 'Access customer bills and invoices', '2026-11-25 18:45:48'),
('26', 'invoices', 'invoices_create', 'Create Sales Invoice', 'Generate direct bills or order invoices', '2026-11-25 18:45:48'),
('27', 'invoices', 'invoices_finalize', 'Finalize Invoice', 'Finalize bill and post financial/delivery status', '2026-11-25 18:45:48'),
('28', 'invoices', 'invoices_print', 'Print Customer Invoices', 'Single or multi-invoice printing on A4', '2026-11-25 18:45:48'),
('29', 'invoices', 'invoices_cancel', 'Cancel Invoice', 'Cancel finalized bills with audit reason', '2026-11-25 18:45:48'),
('30', 'delivery', 'delivery_view', 'View Delivery Runs', 'View assigned routes and delivery run sheets', '2026-11-25 18:45:48'),
('31', 'delivery', 'delivery_manage', 'Manage Delivery Runs', 'Create delivery run sheets and assign salesmen', '2026-11-25 18:45:48'),
('32', 'delivery', 'delivery_update_status', 'Update Delivery Status', 'Mark bills delivered, partial, or refused', '2026-11-25 18:45:48'),
('33', 'settlements', 'settlements_view', 'View Settlements', 'View daily route reconciliation sheets', '2026-11-25 18:45:48'),
('34', 'settlements', 'settlements_create', 'Create Settlement', 'Enter cash, credit, return per bill', '2026-11-25 18:45:48'),
('35', 'settlements', 'settlements_reconcile', 'Reconcile Settlement', 'Verify zero difference or manager override', '2026-11-25 18:45:48'),
('36', 'settlements', 'settlements_finalize', 'Finalize Settlement', 'Post cash, customer credit, and return stock', '2026-11-25 18:45:48'),
('37', 'settlements', 'cash_submission', 'Manage Cash Submissions', 'Record physical cash handed over to office', '2026-11-25 18:45:48'),
('38', 'returns', 'returns_view', 'View Returns', 'View customer sales returns and conditions', '2026-11-25 18:45:48'),
('39', 'returns', 'returns_create', 'Create Sales Return', 'Record saleable, damaged, or expired returns', '2026-11-25 18:45:48'),
('40', 'returns', 'returns_process', 'Process Returns', 'Move stock to saleable or damaged locations', '2026-11-25 18:45:48'),
('41', 'inventory', 'inventory_view', 'View Stock Balances', 'View real-time inventory and valuation', '2026-11-25 18:45:48'),
('42', 'inventory', 'inventory_ledger', 'View Stock Ledger', 'Audit trail of every stock in/out movement', '2026-11-25 18:45:48'),
('43', 'inventory', 'inventory_adjust', 'Stock Adjustment', 'Perform physical audit adjustments', '2026-11-25 18:45:48'),
('44', 'purchases', 'purchases_view', 'View Purchases', 'View purchase orders and bills', '2026-11-25 18:45:48'),
('45', 'purchases', 'purchases_create', 'Create Purchase', 'Record supplier purchases', '2026-11-25 18:45:48'),
('46', 'purchases', 'purchases_receive', 'Receive Goods (GRN)', 'Inspect and receive ordered stock into warehouse', '2026-11-25 18:45:48'),
('47', 'accounts', 'accounts_view', 'View Chart of Accounts', 'View account balances and ledger', '2026-11-25 18:45:48'),
('48', 'accounts', 'expenses_manage', 'Manage Expenses', 'Record daily operating expenses and vouchers', '2026-11-25 18:45:48'),
('49', 'accounts', 'journal_entries', 'Journal Entries', 'Post manual vouchers and view transaction journals', '2026-11-25 18:45:48'),
('50', 'accounts', 'payments_manage', 'Manage Payments', 'Record customer receipts and supplier payments', '2026-11-25 18:45:48'),
('51', 'reports', 'reports_daily', 'Daily Reports', 'View daily sales, recovery, loading, settlement registers', '2026-11-25 18:45:48'),
('52', 'reports', 'reports_management', 'Management Reports', 'View monthly, yearly, product, booker, salesman performance', '2026-11-25 18:45:48'),
('53', 'reports', 'reports_financial', 'Financial Reports', 'View Trial Balance, P&L, Balance Sheet, Ledger', '2026-11-25 18:45:48'),
('54', 'excel', 'excel_import', 'Excel Data Import', 'Import master files and opening balances from XLSX', '2026-11-25 18:45:48'),
('55', 'excel', 'excel_export', 'Excel Data Export', 'Export real transaction registers and reports to XLSX', '2026-11-25 18:45:48'),
('56', 'settings', 'settings_manage', 'Manage Settings', 'Update business profile, invoice print templates, numbering rules', '2026-11-25 18:45:48'),
('57', 'users', 'users_manage', 'Manage Users & Permissions', 'Create users, assign roles and configure access rights', '2026-11-25 18:45:48'),
('58', 'audit', 'audit_view', 'View Audit Logs', 'Inspect system security and transaction audit logs', '2026-11-25 18:45:48'),
('59', 'partners', 'partners_view', 'View Partner Khatas', 'View partner ledger statements and balances', '2026-11-25 18:50:33'),
('60', 'partners', 'partners_manage', 'Manage Partners & Drawings', 'Record partner drawings, settlements and expense splits', '2026-11-25 18:50:33');

-- -----------------------------------------------------------------------------
-- Table structure for `price_list_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `price_list_items`;
CREATE TABLE `price_list_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `price_list_id` int(10) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `min_qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plist_prod_unit` (`price_list_id`,`product_id`,`unit_id`),
  KEY `fk_pli_prod` (`product_id`),
  KEY `fk_pli_unit` (`unit_id`),
  CONSTRAINT `fk_pli_list` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pli_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pli_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `price_lists`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `price_lists`;
CREATE TABLE `price_lists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `price_list_code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_list_code` (`price_list_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `product_categories`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE `product_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_code` varchar(20) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_code` (`category_code`),
  KEY `idx_categories_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `product_categories` (4 rows)
INSERT INTO `product_categories` (`id`, `category_code`, `category_name`, `description`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'CAT-NIM', 'Nimko & Savouries', 'Daal moth, mix nimko, salted snacks', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('2', 'CAT-SNK', 'Snacks & Puffs', 'Ring puffs, chips, extruded snacks', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('3', 'CAT-CND', 'Confectionery', 'Candies, toffees, sweets', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('4', 'CAT-GRC', 'Grocery & Pickles', 'Vermicelli, mixed pickles in oil', '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17');

-- -----------------------------------------------------------------------------
-- Table structure for `product_unit_conversions`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `product_unit_conversions`;
CREATE TABLE `product_unit_conversions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `from_unit_id` int(10) unsigned NOT NULL,
  `to_unit_id` int(10) unsigned NOT NULL,
  `multiplier` decimal(12,4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_puc_prod` (`product_id`),
  KEY `fk_puc_from` (`from_unit_id`),
  KEY `fk_puc_to` (`to_unit_id`),
  CONSTRAINT `fk_puc_from` FOREIGN KEY (`from_unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `fk_puc_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_puc_to` FOREIGN KEY (`to_unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `products`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(30) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `brand_id` int(10) unsigned DEFAULT NULL,
  `base_unit_id` int(10) unsigned NOT NULL,
  `purchase_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_carton_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_piece_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `default_sale_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wholesale_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `carton_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `piece_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `retail_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `retail_carton_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `retail_piece_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `packing_description` varchar(100) DEFAULT NULL,
  `min_stock_level` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `idx_products_code` (`product_code`),
  KEY `idx_products_barcode` (`barcode`),
  KEY `idx_products_name` (`product_name`),
  KEY `idx_products_cat` (`category_id`),
  KEY `fk_prod_brand` (`brand_id`),
  KEY `fk_prod_unit` (`base_unit_id`),
  CONSTRAINT `fk_prod_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prod_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`),
  CONSTRAINT `fk_prod_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `products` (8 rows)
INSERT INTO `products` (`id`, `product_code`, `barcode`, `product_name`, `category_id`, `supplier_id`, `brand_id`, `base_unit_id`, `purchase_rate`, `purchase_carton_rate`, `purchase_piece_rate`, `default_sale_rate`, `wholesale_rate`, `carton_rate`, `piece_rate`, `retail_rate`, `retail_carton_rate`, `retail_piece_rate`, `carton_qty`, `pack_qty`, `packing_description`, `min_stock_level`, `description`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'PRD-0001', NULL, 'Nimko Daal Moth (1*36)', '1', NULL, NULL, '4', '1350.00', '1350.00', '37.50', '1512.00', '1512.00', '1512.00', '42.00', '1512.00', '0.00', '0.00', '36.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:33'),
('2', 'PRD-0002', NULL, 'Nimko Daal Moth (1*24)', '1', NULL, NULL, '4', '1980.00', '1980.00', '82.50', '2208.00', '2208.00', '2208.00', '92.00', '2208.00', '0.00', '0.00', '24.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33'),
('3', 'PRD-0003', NULL, 'Nimko Mix (1*36)', '1', NULL, NULL, '4', '1350.00', '1350.00', '37.50', '1512.00', '1512.00', '1512.00', '42.00', '1512.00', '0.00', '0.00', '36.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33'),
('4', 'PRD-0004', NULL, 'Nimko Mix (1*24)', '1', NULL, NULL, '4', '1980.00', '1980.00', '82.50', '2208.00', '2208.00', '2208.00', '92.00', '2208.00', '0.00', '0.00', '24.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33'),
('5', 'PRD-0005', NULL, 'Zoro Ring Puff BBQ (1*12)', '2', NULL, NULL, '4', '640.00', '640.00', '53.33', '720.00', '720.00', '720.00', '60.00', '720.00', '0.00', '0.00', '12.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33'),
('6', 'PRD-0006', NULL, 'Booster Candy', '3', NULL, NULL, '4', '6200.00', '6200.00', '620.00', '7000.00', '7000.00', '7000.00', '700.00', '7000.00', '0.00', '0.00', '10.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33'),
('7', 'PRD-0007', NULL, 'Color Vermicelli', '4', NULL, NULL, '4', '1800.00', '1800.00', '90.00', '2100.00', '2100.00', '2100.00', '105.00', '2100.00', '0.00', '0.00', '20.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33'),
('8', 'PRD-0008', NULL, 'Mix Pickle in Oil Small', '4', NULL, NULL, '4', '2400.00', '2400.00', '200.00', '2800.00', '2800.00', '2800.00', '233.33', '2800.00', '0.00', '0.00', '12.00', '1.00', NULL, '10.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:33');

-- -----------------------------------------------------------------------------
-- Table structure for `purchase_order_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_order_items`;
CREATE TABLE `purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(12,2) NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL,
  `received_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_poi_po` (`purchase_order_id`),
  KEY `fk_poi_prod` (`product_id`),
  KEY `fk_poi_unit` (`unit_id`),
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_poi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `purchase_orders`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_orders`;
CREATE TABLE `purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(30) NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `supplier_invoice_no` varchar(50) DEFAULT NULL,
  `discount_mode` enum('item_rate','subtotal') NOT NULL DEFAULT 'item_rate',
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cartons` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_units` decimal(12,2) NOT NULL DEFAULT 0.00,
  `free_loose_pieces` tinyint(1) NOT NULL DEFAULT 0,
  `net_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_number` (`purchase_number`),
  KEY `idx_po_date` (`order_date`),
  KEY `idx_po_supp` (`supplier_id`),
  KEY `fk_po_user` (`created_by`),
  CONSTRAINT `fk_po_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `purchase_return_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_return_items`;
CREATE TABLE `purchase_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_return_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(12,2) NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_pri_pr` (`purchase_return_id`),
  KEY `fk_pri_prod` (`product_id`),
  KEY `fk_pri_unit` (`unit_id`),
  CONSTRAINT `fk_pri_pr` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_pri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `purchase_returns`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE `purchase_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_number` varchar(30) NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('draft','approved','processed') NOT NULL DEFAULT 'processed',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `idx_pr_date` (`return_date`),
  KEY `idx_pr_supp` (`supplier_id`),
  KEY `fk_pr_user` (`created_by`),
  CONSTRAINT `fk_pr_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `role_permissions`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `role_permissions` (175 rows)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`) VALUES
('1', '1', '2026-11-25 18:45:48'),
('1', '2', '2026-11-25 18:45:48'),
('1', '3', '2026-11-25 18:45:48'),
('1', '4', '2026-11-25 18:45:48'),
('1', '5', '2026-11-25 18:45:48'),
('1', '6', '2026-11-25 18:45:48'),
('1', '7', '2026-11-25 18:45:48'),
('1', '8', '2026-11-25 18:45:48'),
('1', '9', '2026-11-25 18:45:48'),
('1', '10', '2026-11-25 18:45:48'),
('1', '11', '2026-11-25 18:45:48'),
('1', '12', '2026-11-25 18:45:48'),
('1', '13', '2026-11-25 18:45:48'),
('1', '14', '2026-11-25 18:45:48'),
('1', '15', '2026-11-25 18:45:48'),
('1', '16', '2026-11-25 18:45:48'),
('1', '17', '2026-11-25 18:45:48'),
('1', '18', '2026-11-25 18:45:48'),
('1', '19', '2026-11-25 18:45:48'),
('1', '20', '2026-11-25 18:45:48'),
('1', '21', '2026-11-25 18:45:48'),
('1', '22', '2026-11-25 18:45:48'),
('1', '23', '2026-11-25 18:45:48'),
('1', '24', '2026-11-25 18:45:48'),
('1', '25', '2026-11-25 18:45:48'),
('1', '26', '2026-11-25 18:45:48'),
('1', '27', '2026-11-25 18:45:48'),
('1', '28', '2026-11-25 18:45:48'),
('1', '29', '2026-11-25 18:45:48'),
('1', '30', '2026-11-25 18:45:48'),
('1', '31', '2026-11-25 18:45:48'),
('1', '32', '2026-11-25 18:45:48'),
('1', '33', '2026-11-25 18:45:48'),
('1', '34', '2026-11-25 18:45:48'),
('1', '35', '2026-11-25 18:45:48'),
('1', '36', '2026-11-25 18:45:48'),
('1', '37', '2026-11-25 18:45:48'),
('1', '38', '2026-11-25 18:45:48'),
('1', '39', '2026-11-25 18:45:48'),
('1', '40', '2026-11-25 18:45:48'),
('1', '41', '2026-11-25 18:45:48'),
('1', '42', '2026-11-25 18:45:48'),
('1', '43', '2026-11-25 18:45:48'),
('1', '44', '2026-11-25 18:45:48'),
('1', '45', '2026-11-25 18:45:48'),
('1', '46', '2026-11-25 18:45:48'),
('1', '47', '2026-11-25 18:45:48'),
('1', '48', '2026-11-25 18:45:48'),
('1', '49', '2026-11-25 18:45:48'),
('1', '50', '2026-11-25 18:45:48'),
('1', '51', '2026-11-25 18:45:48'),
('1', '52', '2026-11-25 18:45:48'),
('1', '53', '2026-11-25 18:45:48'),
('1', '54', '2026-11-25 18:45:48'),
('1', '55', '2026-11-25 18:45:48'),
('1', '56', '2026-11-25 18:45:48'),
('1', '57', '2026-11-25 18:45:48'),
('1', '58', '2026-11-25 18:45:48'),
('1', '59', '2026-11-25 18:50:33'),
('1', '60', '2026-11-25 18:50:33'),
('2', '1', '2026-11-25 18:45:48'),
('2', '2', '2026-11-25 18:45:48'),
('2', '3', '2026-11-25 18:45:48'),
('2', '4', '2026-11-25 18:45:48'),
('2', '5', '2026-11-25 18:45:48'),
('2', '6', '2026-11-25 18:45:48'),
('2', '7', '2026-11-25 18:45:48'),
('2', '8', '2026-11-25 18:45:48'),
('2', '9', '2026-11-25 18:45:48'),
('2', '10', '2026-11-25 18:45:48'),
('2', '11', '2026-11-25 18:45:48'),
('2', '12', '2026-11-25 18:45:48'),
('2', '13', '2026-11-25 18:45:48'),
('2', '14', '2026-11-25 18:45:48'),
('2', '15', '2026-11-25 18:45:48'),
('2', '16', '2026-11-25 18:45:48'),
('2', '17', '2026-11-25 18:45:48'),
('2', '18', '2026-11-25 18:45:48'),
('2', '19', '2026-11-25 18:45:48'),
('2', '20', '2026-11-25 18:45:48'),
('2', '21', '2026-11-25 18:45:48'),
('2', '22', '2026-11-25 18:45:48'),
('2', '23', '2026-11-25 18:45:48'),
('2', '24', '2026-11-25 18:45:48'),
('2', '25', '2026-11-25 18:45:48'),
('2', '26', '2026-11-25 18:45:48'),
('2', '27', '2026-11-25 18:45:48'),
('2', '28', '2026-11-25 18:45:48'),
('2', '29', '2026-11-25 18:45:48'),
('2', '30', '2026-11-25 18:45:48'),
('2', '31', '2026-11-25 18:45:48'),
('2', '32', '2026-11-25 18:45:48'),
('2', '33', '2026-11-25 18:45:48'),
('2', '34', '2026-11-25 18:45:48'),
('2', '35', '2026-11-25 18:45:48'),
('2', '36', '2026-11-25 18:45:48'),
('2', '37', '2026-11-25 18:45:48'),
('2', '38', '2026-11-25 18:45:48'),
('2', '39', '2026-11-25 18:45:48'),
('2', '40', '2026-11-25 18:45:48');
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`) VALUES
('2', '41', '2026-11-25 18:45:48'),
('2', '42', '2026-11-25 18:45:48'),
('2', '43', '2026-11-25 18:45:48'),
('2', '44', '2026-11-25 18:45:48'),
('2', '45', '2026-11-25 18:45:48'),
('2', '46', '2026-11-25 18:45:48'),
('2', '47', '2026-11-25 18:45:48'),
('2', '48', '2026-11-25 18:45:48'),
('2', '49', '2026-11-25 18:45:48'),
('2', '50', '2026-11-25 18:45:48'),
('2', '51', '2026-11-25 18:45:48'),
('2', '52', '2026-11-25 18:45:48'),
('2', '53', '2026-11-25 18:45:48'),
('2', '54', '2026-11-25 18:45:48'),
('2', '55', '2026-11-25 18:45:48'),
('3', '1', '2026-11-25 18:45:48'),
('3', '2', '2026-11-25 18:45:48'),
('3', '3', '2026-11-25 18:45:48'),
('3', '5', '2026-11-25 18:45:48'),
('3', '11', '2026-11-25 18:45:48'),
('3', '16', '2026-11-25 18:45:48'),
('3', '17', '2026-11-25 18:45:48'),
('3', '18', '2026-11-25 18:45:48'),
('3', '25', '2026-11-25 18:45:48'),
('3', '26', '2026-11-25 18:45:48'),
('3', '28', '2026-11-25 18:45:48'),
('3', '33', '2026-11-25 18:45:48'),
('3', '34', '2026-11-25 18:45:48'),
('3', '51', '2026-11-25 18:45:48'),
('3', '55', '2026-11-25 18:45:48'),
('5', '11', '2026-11-25 18:45:48'),
('5', '21', '2026-11-25 18:45:48'),
('5', '23', '2026-11-25 18:45:48'),
('5', '38', '2026-11-25 18:45:48'),
('5', '40', '2026-11-25 18:45:48'),
('5', '41', '2026-11-25 18:45:48'),
('5', '42', '2026-11-25 18:45:48'),
('5', '44', '2026-11-25 18:45:48'),
('5', '46', '2026-11-25 18:45:48'),
('6', '25', '2026-11-25 18:45:48'),
('6', '28', '2026-11-25 18:45:48'),
('6', '30', '2026-11-25 18:45:48'),
('6', '32', '2026-11-25 18:45:48'),
('7', '5', '2026-11-25 18:45:48'),
('7', '10', '2026-11-25 18:45:48'),
('7', '33', '2026-11-25 18:45:48'),
('7', '34', '2026-11-25 18:45:48'),
('7', '35', '2026-11-25 18:45:48'),
('7', '36', '2026-11-25 18:45:48'),
('7', '37', '2026-11-25 18:45:48'),
('7', '47', '2026-11-25 18:45:48'),
('7', '48', '2026-11-25 18:45:48'),
('7', '49', '2026-11-25 18:45:48'),
('7', '50', '2026-11-25 18:45:48'),
('7', '51', '2026-11-25 18:45:48'),
('7', '52', '2026-11-25 18:45:48'),
('7', '53', '2026-11-25 18:45:48'),
('7', '54', '2026-11-25 18:45:48'),
('7', '55', '2026-11-25 18:45:48'),
('8', '1', '2026-11-25 18:45:48'),
('8', '6', '2026-11-25 18:45:48'),
('8', '11', '2026-11-25 18:45:48'),
('8', '16', '2026-11-25 18:45:48'),
('8', '21', '2026-11-25 18:45:48'),
('8', '25', '2026-11-25 18:45:48'),
('8', '30', '2026-11-25 18:45:48'),
('8', '33', '2026-11-25 18:45:48'),
('8', '38', '2026-11-25 18:45:48'),
('8', '41', '2026-11-25 18:45:48'),
('8', '44', '2026-11-25 18:45:48'),
('8', '47', '2026-11-25 18:45:48'),
('8', '51', '2026-11-25 18:45:48'),
('8', '52', '2026-11-25 18:45:48'),
('8', '53', '2026-11-25 18:45:48'),
('8', '58', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `roles`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `role_code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`),
  UNIQUE KEY `role_code` (`role_code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `roles` (8 rows)
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system`, `created_at`, `updated_at`) VALUES
('1', 'Administrator', 'admin', 'Full system access with all administrative privileges', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('2', 'Operations Manager', 'manager', 'Oversees order confirmations, loading, delivery, and settlements', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('3', 'Office Operator', 'operator', 'Enters market orders, customer bills, and daily entries', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('4', 'Order Booker', 'booker', 'Collects retailer orders from market routes', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('5', 'Warehouse Storekeeper', 'storekeeper', 'Handles load sheet dispatch, receiving, and stock custody', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('6', 'Delivery Salesman', 'salesman', 'Delivers customer orders and brings cash/returns for settlement', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('7', 'Accountant', 'accountant', 'Manages cash flow, vouchers, customer credit, and ledgers', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('8', 'Report Viewer', 'viewer', 'Read-only access to operational and management reports', '1', '2026-11-25 18:45:48', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `sales_invoice_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_invoice_items`;
CREATE TABLE `sales_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(12,2) NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sii_inv` (`invoice_id`),
  KEY `idx_sii_prod` (`product_id`),
  KEY `fk_sii_unit` (`unit_id`),
  CONSTRAINT `fk_sii_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sii_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sii_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `sales_invoices`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_invoices`;
CREATE TABLE `sales_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(30) NOT NULL,
  `invoice_date` date NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `load_sheet_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `booker_id` int(10) unsigned NOT NULL,
  `salesman_id` int(10) unsigned DEFAULT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `discount_mode` enum('item_rate','subtotal') NOT NULL DEFAULT 'item_rate',
  `gross_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `extra_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `free_loose_pieces` tinyint(1) NOT NULL DEFAULT 0,
  `net_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','finalized','settled','cancelled') NOT NULL DEFAULT 'draft',
  `reconciliation_status` enum('pending','settled','difference') NOT NULL DEFAULT 'pending',
  `payment_status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_inv_date` (`invoice_date`),
  KEY `idx_inv_status` (`status`),
  KEY `idx_inv_cust` (`customer_id`),
  KEY `idx_inv_booker` (`booker_id`),
  KEY `idx_inv_salesman` (`salesman_id`),
  KEY `fk_inv_order` (`order_id`),
  KEY `fk_inv_ls` (`load_sheet_id`),
  KEY `fk_inv_area` (`area_id`),
  KEY `fk_inv_creator` (`created_by`),
  CONSTRAINT `fk_inv_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_inv_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`),
  CONSTRAINT `fk_inv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_inv_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `sales_return_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_return_items`;
CREATE TABLE `sales_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_return_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(12,2) NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(15,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `condition_type` enum('saleable','damaged','expired') NOT NULL DEFAULT 'saleable',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sri_return` (`sales_return_id`),
  KEY `fk_sri_prod` (`product_id`),
  KEY `fk_sri_unit` (`unit_id`),
  CONSTRAINT `fk_sri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sri_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `sales_returns`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_returns`;
CREATE TABLE `sales_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_number` varchar(30) NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `settlement_id` bigint(20) unsigned DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `condition_type` enum('saleable','damaged','expired') NOT NULL DEFAULT 'saleable',
  `status` enum('draft','approved','processed') NOT NULL DEFAULT 'processed',
  `stock_settled` tinyint(1) NOT NULL DEFAULT 1,
  `free_loose_pieces` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `idx_sr_date` (`return_date`),
  KEY `idx_sr_cust` (`customer_id`),
  KEY `fk_sr_inv` (`invoice_id`),
  KEY `fk_sr_set` (`settlement_id`),
  KEY `fk_sr_user` (`created_by`),
  CONSTRAINT `fk_sr_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sr_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sr_set` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `salesmen`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `salesmen`;
CREATE TABLE `salesmen` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) NOT NULL,
  `salesman_name` varchar(100) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `role` enum('delivery_man','van_driver','salesman') NOT NULL DEFAULT 'delivery_man',
  `assigned_area_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_code` (`employee_code`),
  KEY `idx_salesmen_code` (`employee_code`),
  KEY `idx_salesmen_active` (`is_active`,`is_deleted`),
  KEY `fk_sm_area` (`assigned_area_id`),
  CONSTRAINT `fk_sm_area` FOREIGN KEY (`assigned_area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `salesmen` (2 rows)
INSERT INTO `salesmen` (`id`, `employee_code`, `salesman_name`, `phone`, `vehicle_number`, `role`, `assigned_area_id`, `notes`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'EMP-001', 'Saghar', '0302-1122334', 'GAK-4580 (Suzuki)', 'delivery_man', NULL, NULL, '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17'),
('2', 'EMP-002', 'Muzamal Shah', '0303-9988776', 'GAK-9821 (Van)', 'delivery_man', NULL, NULL, '1', '0', '2026-11-25 18:50:17', '2026-11-25 18:50:17');

-- -----------------------------------------------------------------------------
-- Table structure for `settings`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_group` varchar(50) NOT NULL DEFAULT 'general',
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `data_type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `settings` (20 rows)
INSERT INTO `settings` (`id`, `setting_group`, `setting_key`, `setting_value`, `data_type`, `description`, `updated_at`) VALUES
('1', 'general', 'distributor_name', 'Muzzam Enterprises', 'string', 'Official trade/distributor business name', '2026-11-25 18:45:48'),
('2', 'general', 'distributor_phone', '03000673276', 'string', 'Primary contact phone number', '2026-11-25 18:45:48'),
('3', 'general', 'distributor_address', 'Jandiala Bhagwala G.T. Road Gujranwala', 'string', 'Head office and warehouse location', '2026-11-25 18:45:48'),
('4', 'general', 'city', 'Gujranwala', 'string', 'Operating headquarters city', '2026-11-25 18:45:48'),
('5', 'general', 'currency_symbol', 'Rs.', 'string', 'Display currency symbol', '2026-11-25 18:45:48'),
('6', 'general', 'date_format', 'd-m-Y', 'string', 'Standard display date format', '2026-11-25 18:45:48'),
('7', 'numbering', 'order_prefix', 'ORD', 'string', 'Prefix for market booking orders', '2026-11-25 18:45:48'),
('8', 'numbering', 'invoice_prefix', 'INV', 'string', 'Prefix for sales bills and invoices', '2026-11-25 18:45:48'),
('9', 'numbering', 'load_prefix', 'LOAD', 'string', 'Prefix for warehouse load sheets', '2026-11-25 18:45:48'),
('10', 'numbering', 'run_prefix', 'RUN', 'string', 'Prefix for delivery run sheets', '2026-11-25 18:45:48'),
('11', 'numbering', 'settlement_prefix', 'SET', 'string', 'Prefix for route settlement vouchers', '2026-11-25 18:45:48'),
('12', 'numbering', 'grn_prefix', 'GRN', 'string', 'Prefix for goods receipt notes', '2026-11-25 18:45:48'),
('13', 'numbering', 'purchase_prefix', 'PUR', 'string', 'Prefix for supplier purchase orders', '2026-11-25 18:45:48'),
('14', 'numbering', 'return_prefix', 'RET', 'string', 'Prefix for sales returns', '2026-11-25 18:45:48'),
('15', 'print', 'invoice_paper_size', 'A4', 'string', 'Default paper size for invoices', '2026-11-25 18:45:48'),
('16', 'print', 'multi_invoice_fit_mode', 'auto_pack', 'string', 'Algorithm for packing multiple bills on A4 page', '2026-11-25 18:45:48'),
('17', 'print', 'show_signature_boxes', '1', 'boolean', 'Print receiver and salesman signature lines on bills', '2026-11-25 18:45:48'),
('18', 'distribution', 'route_assignment_mode', 'realtime', 'string', 'Route assignment policy: realtime allows dynamic area creation on all forms; fixed enforces strict salesman route assignment.', '2026-11-25 18:45:48'),
('19', 'distribution', 'allow_realtime_routes', '1', 'boolean', 'Allow adding new delivery areas / routes on-the-fly via slide drawer without reloading page', '2026-11-25 18:45:48'),
('20', 'distribution', 'enforce_fixed_routes', '0', 'boolean', 'Enforce drivers and salesmen to operate strictly within their pre-assigned delivery routes', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `settlement_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `settlement_items`;
CREATE TABLE `settlement_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `bill_amount` decimal(15,2) NOT NULL,
  `cash_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `difference_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_si_settlement` (`settlement_id`),
  KEY `idx_si_inv` (`invoice_id`),
  KEY `fk_si_cust` (`customer_id`),
  CONSTRAINT `fk_si_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_si_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`),
  CONSTRAINT `fk_si_set` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `settlements`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `settlements`;
CREATE TABLE `settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_number` varchar(30) NOT NULL,
  `settlement_date` date NOT NULL,
  `delivery_run_id` bigint(20) unsigned DEFAULT NULL,
  `salesman_id` int(10) unsigned NOT NULL,
  `load_sheet_id` bigint(20) unsigned DEFAULT NULL,
  `total_invoices_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cash_received` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_credit_given` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_returns_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `difference_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','reconciled','finalized') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `reconciled_by` bigint(20) unsigned DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `settlement_number` (`settlement_number`),
  KEY `idx_set_date` (`settlement_date`),
  KEY `idx_set_salesman` (`salesman_id`),
  KEY `idx_set_status` (`status`),
  KEY `fk_set_run` (`delivery_run_id`),
  KEY `fk_set_ls` (`load_sheet_id`),
  KEY `fk_set_creator` (`created_by`),
  KEY `fk_set_reconciler` (`reconciled_by`),
  CONSTRAINT `fk_set_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_reconciler` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_run` FOREIGN KEY (`delivery_run_id`) REFERENCES `delivery_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_sm` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `stock_adjustment_items`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustment_items`;
CREATE TABLE `stock_adjustment_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_adjustment_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `carton_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `adjustment_type` enum('add','subtract') NOT NULL,
  `condition_type` enum('saleable','damaged','expired') NOT NULL DEFAULT 'saleable',
  `quantity` decimal(12,2) NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sai_adj` (`stock_adjustment_id`),
  KEY `fk_sai_prod` (`product_id`),
  CONSTRAINT `fk_sai_adj` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sai_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `stock_adjustments`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `adjustment_number` varchar(30) NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `adjustment_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('draft','approved') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `adjustment_number` (`adjustment_number`),
  KEY `idx_sa_date` (`adjustment_date`),
  KEY `fk_sa_wh` (`warehouse_id`),
  KEY `fk_sa_user` (`created_by`),
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `stock_balances`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_balances`;
CREATE TABLE `stock_balances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(10) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `saleable_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `damaged_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expired_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `avg_unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `last_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sb_wh_prod` (`warehouse_id`,`product_id`),
  KEY `fk_sb_prod` (`product_id`),
  CONSTRAINT `fk_sb_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sb_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `stock_balances` (8 rows)
INSERT INTO `stock_balances` (`id`, `warehouse_id`, `product_id`, `saleable_qty`, `damaged_qty`, `expired_qty`, `total_qty`, `avg_unit_cost`, `total_value`, `last_updated_at`) VALUES
('1', '1', '1', '120.00', '0.00', '0.00', '120.00', '37.50', '4500.00', '2026-11-25 18:50:18'),
('2', '1', '2', '150.00', '0.00', '0.00', '150.00', '82.50', '12375.00', '2026-11-25 18:50:18'),
('3', '1', '3', '100.00', '0.00', '0.00', '100.00', '37.50', '3750.00', '2026-11-25 18:50:18'),
('4', '1', '4', '120.00', '0.00', '0.00', '120.00', '82.50', '9900.00', '2026-11-25 18:50:18'),
('5', '1', '5', '200.00', '0.00', '0.00', '200.00', '53.33', '10666.67', '2026-11-25 18:50:18'),
('6', '1', '6', '50.00', '0.00', '0.00', '50.00', '620.00', '31000.00', '2026-11-25 18:50:18'),
('7', '1', '7', '80.00', '0.00', '0.00', '80.00', '90.00', '7200.00', '2026-11-25 18:50:18'),
('8', '1', '8', '60.00', '0.00', '0.00', '60.00', '200.00', '12000.00', '2026-11-25 18:50:18');

-- -----------------------------------------------------------------------------
-- Table structure for `stock_movements`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `movement_date` datetime NOT NULL,
  `movement_type` enum('opening_stock','purchase_grn','sales_dispatch','sales_return_saleable','sales_return_damaged','purchase_return','stock_adjustment_add','stock_adjustment_sub') NOT NULL,
  `reference_type` enum('opening','grn','load_sheet','sales_return','purchase_return','adjustment','transfer') NOT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `qty_in` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty_out` decimal(12,2) NOT NULL DEFAULT 0.00,
  `base_unit_qty` decimal(12,2) NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sm_prod` (`product_id`),
  KEY `idx_sm_warehouse` (`warehouse_id`),
  KEY `idx_sm_date` (`movement_date`),
  KEY `idx_sm_ref` (`reference_type`,`reference_id`),
  KEY `fk_sm_user` (`created_by`),
  CONSTRAINT `fk_sm_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sm_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sm_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `stock_movements` (8 rows)
INSERT INTO `stock_movements` (`id`, `product_id`, `warehouse_id`, `movement_date`, `movement_type`, `reference_type`, `reference_id`, `qty_in`, `qty_out`, `base_unit_qty`, `unit_cost`, `total_cost`, `created_by`, `remarks`, `created_at`) VALUES
('1', '1', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '1', '120.00', '0.00', '120.00', '37.50', '4500.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('2', '2', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '2', '150.00', '0.00', '150.00', '82.50', '12375.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('3', '3', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '3', '100.00', '0.00', '100.00', '37.50', '3750.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('4', '4', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '4', '120.00', '0.00', '120.00', '82.50', '9900.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('5', '5', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '5', '200.00', '0.00', '200.00', '53.33', '10666.67', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('6', '6', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '6', '50.00', '0.00', '50.00', '620.00', '31000.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('7', '7', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '7', '80.00', '0.00', '80.00', '90.00', '7200.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18'),
('8', '8', '1', '2026-11-25 18:50:18', 'opening_stock', 'opening', '8', '60.00', '0.00', '60.00', '200.00', '12000.00', NULL, 'Master opening inventory', '2026-11-25 18:50:18');

-- -----------------------------------------------------------------------------
-- Table structure for `supplier_payments`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `supplier_payments`;
CREATE TABLE `supplier_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(30) NOT NULL,
  `payment_date` date NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_mode` enum('cash','cheque','bank_transfer','online') NOT NULL DEFAULT 'cash',
  `account_id` int(10) unsigned NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `idx_sp_date` (`payment_date`),
  KEY `idx_sp_supp` (`supplier_id`),
  KEY `fk_sp_account` (`account_id`),
  KEY `fk_sp_user` (`created_by`),
  CONSTRAINT `fk_sp_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_sp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_sp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table structure for `suppliers`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(20) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(25) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `idx_suppliers_code` (`supplier_code`),
  KEY `idx_suppliers_active` (`is_active`,`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `suppliers` (2 rows)
INSERT INTO `suppliers` (`id`, `supplier_code`, `supplier_name`, `company_name`, `contact_person`, `phone`, `email`, `address`, `city`, `opening_balance`, `current_balance`, `notes`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'SUPP-001', 'Al-Hafiz Nimko & Sweets Factory', 'Al-Hafiz Confectionery', NULL, '0300-8765432', NULL, NULL, 'Gujranwala', '250000.00', '250000.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18'),
('2', 'SUPP-002', 'Dawn Snack Foods Ltd', 'Dawn Foods', NULL, '0321-4567890', NULL, NULL, 'Lahore', '180000.00', '180000.00', NULL, '1', '0', '2026-11-25 18:50:18', '2026-11-25 18:50:18');

-- -----------------------------------------------------------------------------
-- Table structure for `units`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(20) NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unit_code` (`unit_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `units` (4 rows)
INSERT INTO `units` (`id`, `unit_code`, `unit_name`, `symbol`, `is_active`, `is_deleted`, `created_at`) VALUES
('1', 'CTN', 'Carton', 'Ctn', '1', '0', '2026-11-25 18:45:48'),
('2', 'BOX', 'Box', 'Box', '1', '0', '2026-11-25 18:45:48'),
('3', 'PCK', 'Packet', 'Pck', '1', '0', '2026-11-25 18:45:48'),
('4', 'PCS', 'Piece', 'Pcs', '1', '0', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `user_roles`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `fk_ur_role` (`role_id`),
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `user_roles` (3 rows)
INSERT INTO `user_roles` (`user_id`, `role_id`, `created_at`) VALUES
('1', '1', '2026-11-25 18:45:48'),
('2', '2', '2026-11-25 18:45:48'),
('3', '3', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `users`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(25) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_username` (`username`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `users` (3 rows)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `phone`, `status`, `last_login_at`, `login_attempts`, `locked_until`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'admin', 'admin@muzzam.pk', '$2y$10$/ZAdAND2ZCmJoD7qWXdNs.q58gYcyzzzvFQ000w0qPWE5Ean8il/6', 'System Administrator', '03000673276', 'active', NULL, '0', NULL, '0', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('2', 'manager', 'manager@muzzam.pk', '$2y$10$/ZAdAND2ZCmJoD7qWXdNs.q58gYcyzzzvFQ000w0qPWE5Ean8il/6', 'Operations Manager', '03001234567', 'active', NULL, '0', NULL, '0', '2026-11-25 18:45:48', '2026-11-25 18:45:48'),
('3', 'operator', 'operator@muzzam.pk', '$2y$10$/ZAdAND2ZCmJoD7qWXdNs.q58gYcyzzzvFQ000w0qPWE5Ean8il/6', 'Office Operator', '03007654321', 'active', NULL, '0', NULL, '0', '2026-11-25 18:45:48', '2026-11-25 18:45:48');

-- -----------------------------------------------------------------------------
-- Table structure for `warehouses`
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_code` varchar(20) NOT NULL,
  `warehouse_name` varchar(100) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouse_code` (`warehouse_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for `warehouses` (1 rows)
INSERT INTO `warehouses` (`id`, `warehouse_code`, `warehouse_name`, `location`, `is_primary`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
('1', 'WH-01', 'Main Central Warehouse', 'Jandiala Bhagwala G.T. Road Gujranwala', '1', '1', '0', '2026-11-25 18:45:48', '2026-11-25 18:45:48');

SET FOREIGN_KEY_CHECKS = 1;
-- ===================== END OF DMS COMPLETE DATABASE DUMP =====================
