-- =====================================================================
-- DMS - Distribution Management System Database Schema
-- Designed strictly for Wholesale FMCG Distribution Business Workflow
-- Engine: InnoDB, Charset: utf8mb4, Collation: utf8mb4_unicode_ci
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `distribution_dms` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `distribution_dms`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. SYSTEM TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `user_roles`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) DEFAULT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(25) DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME DEFAULT NULL,
  `login_attempts` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_users_username` (`username`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(50) NOT NULL UNIQUE,
  `role_code` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module` VARCHAR(50) NOT NULL,
  `permission_code` VARCHAR(100) NOT NULL UNIQUE,
  `permission_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_perm_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `role_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `data_type` ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string',
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `record_id` VARCHAR(50) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_audit_module` (`module`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. MASTER TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `customer_prices`;
DROP TABLE IF EXISTS `price_list_items`;
DROP TABLE IF EXISTS `price_lists`;
DROP TABLE IF EXISTS `customer_contacts`;
DROP TABLE IF EXISTS `customer_addresses`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `warehouses`;
DROP TABLE IF EXISTS `product_unit_conversions`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `units`;
DROP TABLE IF EXISTS `brands`;
DROP TABLE IF EXISTS `product_categories`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `salesmen`;
DROP TABLE IF EXISTS `booker_areas`;
DROP TABLE IF EXISTS `bookers`;
DROP TABLE IF EXISTS `areas`;

CREATE TABLE `areas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_code` VARCHAR(20) NOT NULL UNIQUE,
  `area_name` VARCHAR(100) NOT NULL,
  `city` VARCHAR(100) NOT NULL DEFAULT 'Gujranwala',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_areas_name` (`area_name`),
  INDEX `idx_areas_active` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bookers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booker_code` VARCHAR(20) NOT NULL UNIQUE,
  `booker_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `cnic` VARCHAR(25) DEFAULT NULL,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_bookers_code` (`booker_code`),
  INDEX `idx_bookers_active` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `booker_areas` (
  `booker_id` INT UNSIGNED NOT NULL,
  `area_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`booker_id`, `area_id`),
  CONSTRAINT `fk_ba_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ba_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `salesmen` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_code` VARCHAR(20) NOT NULL UNIQUE,
  `salesman_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `vehicle_number` VARCHAR(50) DEFAULT NULL,
  `role` ENUM('delivery_man', 'van_driver', 'salesman') NOT NULL DEFAULT 'delivery_man',
  `assigned_area_id` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_salesmen_code` (`employee_code`),
  INDEX `idx_salesmen_active` (`is_active`, `is_deleted`),
  CONSTRAINT `fk_sm_area` FOREIGN KEY (`assigned_area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_code` VARCHAR(20) NOT NULL UNIQUE,
  `supplier_name` VARCHAR(150) NOT NULL,
  `company_name` VARCHAR(150) DEFAULT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_suppliers_code` (`supplier_code`),
  INDEX `idx_suppliers_active` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_code` VARCHAR(20) NOT NULL UNIQUE,
  `category_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_categories_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `brands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_code` VARCHAR(20) NOT NULL UNIQUE,
  `brand_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(20) NOT NULL UNIQUE,
  `unit_name` VARCHAR(50) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `warehouses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `warehouse_code` VARCHAR(20) NOT NULL UNIQUE,
  `warehouse_name` VARCHAR(100) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_code` VARCHAR(30) NOT NULL UNIQUE,
  `barcode` VARCHAR(50) DEFAULT NULL,
  `product_name` VARCHAR(200) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `brand_id` INT UNSIGNED DEFAULT NULL,
  `base_unit_id` INT UNSIGNED NOT NULL,
  `purchase_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `purchase_carton_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `purchase_piece_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `default_sale_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `wholesale_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `carton_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `piece_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `retail_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `retail_carton_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `retail_piece_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `packing_description` VARCHAR(100) DEFAULT NULL,
  `min_stock_level` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_products_code` (`product_code`),
  INDEX `idx_products_barcode` (`barcode`),
  INDEX `idx_products_name` (`product_name`),
  INDEX `idx_products_cat` (`category_id`),
  CONSTRAINT `fk_prod_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`),
  CONSTRAINT `fk_prod_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prod_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_unit_conversions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `from_unit_id` INT UNSIGNED NOT NULL,
  `to_unit_id` INT UNSIGNED NOT NULL,
  `multiplier` DECIMAL(12,4) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_puc_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_puc_from` FOREIGN KEY (`from_unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `fk_puc_to` FOREIGN KEY (`to_unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `price_lists` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `price_list_code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_code` VARCHAR(20) NOT NULL UNIQUE,
  `shop_name` VARCHAR(150) NOT NULL,
  `owner_name` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `cnic_or_ntn` VARCHAR(30) DEFAULT NULL,
  `address` TEXT NOT NULL,
  `area_id` INT UNSIGNED NOT NULL,
  `booker_id` INT UNSIGNED DEFAULT NULL,
  `credit_limit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `credit_days` INT NOT NULL DEFAULT 0,
  `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `default_discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `price_list_id` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_cust_code` (`customer_code`),
  INDEX `idx_cust_shop` (`shop_name`),
  INDEX `idx_cust_area` (`area_id`),
  INDEX `idx_cust_booker` (`booker_id`),
  INDEX `idx_cust_phone` (`phone`),
  CONSTRAINT `fk_cust_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_cust_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cust_plist` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `price_list_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `price_list_id` INT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `rate` DECIMAL(12,2) NOT NULL,
  `min_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plist_prod_unit` (`price_list_id`, `product_id`, `unit_id`),
  CONSTRAINT `fk_pli_list` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pli_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pli_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `special_rate` DECIMAL(12,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cust_prod_unit` (`customer_id`, `product_id`, `unit_id`),
  CONSTRAINT `fk_cp_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. ORDER TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;

CREATE TABLE `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(30) NOT NULL UNIQUE,
  `order_date` DATE NOT NULL,
  `delivery_date` DATE DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `booker_id` INT UNSIGNED NOT NULL,
  `area_id` INT UNSIGNED NOT NULL,
  `discount_mode` ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate',
  `subtotal_discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `salesman_id` INT UNSIGNED DEFAULT NULL,
  `gross_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_cartons` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_units` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'confirmed', 'partially_loaded', 'loaded', 'dispatched', 'delivered', 'cancelled') NOT NULL DEFAULT 'draft',
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `confirmed_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancel_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_orders_date` (`order_date`),
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_cust` (`customer_id`),
  INDEX `idx_orders_booker` (`booker_id`),
  INDEX `idx_orders_area` (`area_id`),
  CONSTRAINT `fk_ord_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_ord_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`),
  CONSTRAINT `fk_ord_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_ord_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ord_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(12,2) NOT NULL,
  `rate` DECIMAL(12,2) NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(15,2) NOT NULL,
  `loaded_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_oi_order` (`order_id`),
  INDEX `idx_oi_prod` (`product_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_oi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. WAREHOUSE LOADING TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `load_sheet_orders`;
DROP TABLE IF EXISTS `load_sheet_items`;
DROP TABLE IF EXISTS `load_sheets`;

CREATE TABLE `load_sheets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `load_number` VARCHAR(30) NOT NULL UNIQUE,
  `load_date` DATE NOT NULL,
  `delivery_date` DATE NOT NULL,
  `salesman_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `total_orders` INT NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'approved', 'issued', 'returned_closed', 'cancelled') NOT NULL DEFAULT 'draft',
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `issued_at` DATETIME DEFAULT NULL,
  `issued_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ls_date` (`load_date`),
  INDEX `idx_ls_salesman` (`salesman_id`),
  INDEX `idx_ls_status` (`status`),
  CONSTRAINT `fk_ls_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_ls_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_ls_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ls_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `load_sheet_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `load_sheet_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ls_order` (`load_sheet_id`, `order_id`),
  CONSTRAINT `fk_lso_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lso_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `load_sheet_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `load_sheet_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `ordered_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `required_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `loaded_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `short_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `returned_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_lsi_sheet` (`load_sheet_id`),
  INDEX `idx_lsi_prod` (`product_id`),
  CONSTRAINT `fk_lsi_sheet` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lsi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_lsi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. SALES INVOICE TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `invoice_discounts`;
DROP TABLE IF EXISTS `sales_invoice_items`;
DROP TABLE IF EXISTS `sales_invoices`;

CREATE TABLE `sales_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(30) NOT NULL UNIQUE,
  `invoice_date` DATE NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL,
  `load_sheet_id` BIGINT UNSIGNED DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `booker_id` INT UNSIGNED NOT NULL,
  `salesman_id` INT UNSIGNED DEFAULT NULL,
  `area_id` INT UNSIGNED NOT NULL,
  `discount_mode` ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate',
  `gross_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `item_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `extra_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `credit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'finalized', 'settled', 'cancelled') NOT NULL DEFAULT 'draft',
  `reconciliation_status` ENUM('pending', 'settled', 'difference') NOT NULL DEFAULT 'pending',
  `payment_status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `finalized_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancel_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_inv_date` (`invoice_date`),
  INDEX `idx_inv_status` (`status`),
  INDEX `idx_inv_cust` (`customer_id`),
  INDEX `idx_inv_booker` (`booker_id`),
  INDEX `idx_inv_salesman` (`salesman_id`),
  CONSTRAINT `fk_inv_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_inv_booker` FOREIGN KEY (`booker_id`) REFERENCES `bookers` (`id`),
  CONSTRAINT `fk_inv_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_inv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_invoice_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(12,2) NOT NULL,
  `rate` DECIMAL(12,2) NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sii_inv` (`invoice_id`),
  INDEX `idx_sii_prod` (`product_id`),
  CONSTRAINT `fk_sii_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sii_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sii_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_discounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `discount_type` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_id_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. DELIVERY RUN TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `delivery_run_bills`;
DROP TABLE IF EXISTS `delivery_runs`;

CREATE TABLE `delivery_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_number` VARCHAR(30) NOT NULL UNIQUE,
  `run_date` DATE NOT NULL,
  `salesman_id` INT UNSIGNED NOT NULL,
  `load_sheet_id` BIGINT UNSIGNED DEFAULT NULL,
  `route_notes` TEXT DEFAULT NULL,
  `total_bills` INT NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('assigned', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'assigned',
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_dr_date` (`run_date`),
  INDEX `idx_dr_salesman` (`salesman_id`),
  CONSTRAINT `fk_dr_sm` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_dr_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_run_bills` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_run_id` BIGINT UNSIGNED NOT NULL,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `stop_sequence` INT NOT NULL DEFAULT 0,
  `delivery_status` ENUM('pending', 'delivered', 'partial', 'refused', 'not_delivered') NOT NULL DEFAULT 'pending',
  `cash_collected` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `credit_given` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `return_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_drb_run_inv` (`delivery_run_id`, `invoice_id`),
  CONSTRAINT `fk_drb_run` FOREIGN KEY (`delivery_run_id`) REFERENCES `delivery_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drb_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. SETTLEMENT & CASH SUBMISSION TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cash_submissions`;
DROP TABLE IF EXISTS `settlement_items`;
DROP TABLE IF EXISTS `settlements`;

CREATE TABLE `settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_number` VARCHAR(30) NOT NULL UNIQUE,
  `settlement_date` DATE NOT NULL,
  `delivery_run_id` BIGINT UNSIGNED DEFAULT NULL,
  `salesman_id` INT UNSIGNED NOT NULL,
  `load_sheet_id` BIGINT UNSIGNED DEFAULT NULL,
  `total_invoices_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_cash_received` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_credit_given` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_returns_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `difference_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'submitted', 'reconciled', 'finalized') NOT NULL DEFAULT 'draft',
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `reconciled_by` BIGINT UNSIGNED DEFAULT NULL,
  `finalized_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_set_date` (`settlement_date`),
  INDEX `idx_set_salesman` (`salesman_id`),
  INDEX `idx_set_status` (`status`),
  CONSTRAINT `fk_set_run` FOREIGN KEY (`delivery_run_id`) REFERENCES `delivery_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_sm` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_set_ls` FOREIGN KEY (`load_sheet_id`) REFERENCES `load_sheets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_set_reconciler` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settlement_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `bill_amount` DECIMAL(15,2) NOT NULL,
  `cash_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `credit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `difference_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_si_settlement` (`settlement_id`),
  INDEX `idx_si_inv` (`invoice_id`),
  CONSTRAINT `fk_si_set` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_si_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`),
  CONSTRAINT `fk_si_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_number` VARCHAR(30) NOT NULL UNIQUE,
  `submission_date` DATE NOT NULL,
  `salesman_id` INT UNSIGNED NOT NULL,
  `settlement_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `cash_account_id` INT UNSIGNED DEFAULT NULL,
  `received_by` BIGINT UNSIGNED NOT NULL,
  `shortage_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `excess_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_csub_date` (`submission_date`),
  INDEX `idx_csub_sm` (`salesman_id`),
  CONSTRAINT `fk_csub_sm` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`),
  CONSTRAINT `fk_csub_set` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_csub_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. RETURNS TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `purchase_return_items`;
DROP TABLE IF EXISTS `purchase_returns`;
DROP TABLE IF EXISTS `sales_return_items`;
DROP TABLE IF EXISTS `sales_returns`;

CREATE TABLE `sales_returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_number` VARCHAR(30) NOT NULL UNIQUE,
  `invoice_id` BIGINT UNSIGNED DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `settlement_id` BIGINT UNSIGNED DEFAULT NULL,
  `return_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `reason` VARCHAR(255) DEFAULT NULL,
  `condition_type` ENUM('saleable', 'damaged', 'expired') NOT NULL DEFAULT 'saleable',
  `status` ENUM('draft', 'approved', 'processed') NOT NULL DEFAULT 'processed',
  `stock_settled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sr_date` (`return_date`),
  INDEX `idx_sr_cust` (`customer_id`),
  CONSTRAINT `fk_sr_inv` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sr_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sr_set` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_return_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_return_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(12,2) NOT NULL,
  `rate` DECIMAL(12,2) NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(15,2) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `condition_type` ENUM('saleable', 'damaged', 'expired') NOT NULL DEFAULT 'saleable',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sri_return` (`sales_return_id`),
  CONSTRAINT `fk_sri_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_number` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `return_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `reason` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft', 'approved', 'processed') NOT NULL DEFAULT 'processed',
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pr_date` (`return_date`),
  INDEX `idx_pr_supp` (`supplier_id`),
  CONSTRAINT `fk_pr_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_return_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_return_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(12,2) NOT NULL,
  `rate` DECIMAL(12,2) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_pri_pr` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_pri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. INVENTORY TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `stock_adjustment_items`;
DROP TABLE IF EXISTS `stock_adjustments`;
DROP TABLE IF EXISTS `stock_balances`;
DROP TABLE IF EXISTS `stock_movements`;

CREATE TABLE `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `movement_date` DATETIME NOT NULL,
  `movement_type` ENUM(
    'opening_stock',
    'purchase_grn',
    'sales_dispatch',
    'sales_return_saleable',
    'sales_return_damaged',
    'purchase_return',
    'stock_adjustment_add',
    'stock_adjustment_sub'
  ) NOT NULL,
  `reference_type` ENUM('opening', 'grn', 'load_sheet', 'sales_return', 'purchase_return', 'adjustment', 'transfer') NOT NULL,
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `qty_in` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `qty_out` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `base_unit_qty` DECIMAL(12,2) NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sm_prod` (`product_id`),
  INDEX `idx_sm_warehouse` (`warehouse_id`),
  INDEX `idx_sm_date` (`movement_date`),
  INDEX `idx_sm_ref` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_sm_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sm_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_sm_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_balances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `saleable_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `damaged_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expired_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `avg_unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `last_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sb_wh_prod` (`warehouse_id`, `product_id`),
  CONSTRAINT `fk_sb_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_sb_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `adjustment_number` VARCHAR(30) NOT NULL UNIQUE,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `adjustment_date` DATE NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('draft', 'approved') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sa_date` (`adjustment_date`),
  CONSTRAINT `fk_sa_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_adjustment_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_adjustment_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `adjustment_type` ENUM('add', 'subtract') NOT NULL,
  `condition_type` ENUM('saleable', 'damaged', 'expired') NOT NULL DEFAULT 'saleable',
  `quantity` DECIMAL(12,2) NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_sai_adj` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sai_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. PURCHASING & GOODS RECEIVING TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `goods_receipt_items`;
DROP TABLE IF EXISTS `goods_receipts`;
DROP TABLE IF EXISTS `purchase_order_items`;
DROP TABLE IF EXISTS `purchase_orders`;

CREATE TABLE `purchase_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_number` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `supplier_invoice_no` VARCHAR(50) DEFAULT NULL,
  `discount_mode` ENUM('item_rate', 'subtotal') NOT NULL DEFAULT 'item_rate',
  `order_date` DATE NOT NULL,
  `expected_delivery_date` DATE DEFAULT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `subtotal_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_cartons` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_units` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'ordered', 'partially_received', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_po_date` (`order_date`),
  INDEX `idx_po_supp` (`supplier_id`),
  CONSTRAINT `fk_po_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(12,2) NOT NULL,
  `rate` DECIMAL(12,2) NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(15,2) NOT NULL,
  `received_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_poi_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `goods_receipts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grn_number` VARCHAR(30) NOT NULL UNIQUE,
  `purchase_order_id` BIGINT UNSIGNED DEFAULT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `supplier_bill_no` VARCHAR(50) DEFAULT NULL,
  `grn_date` DATE NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'verified', 'posted') NOT NULL DEFAULT 'posted',
  `remarks` TEXT DEFAULT NULL,
  `received_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_grn_date` (`grn_date`),
  INDEX `idx_grn_supp` (`supplier_id`),
  CONSTRAINT `fk_grn_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grn_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_grn_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_grn_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `goods_receipt_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `goods_receipt_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `carton_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pack_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `ordered_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `received_qty` DECIMAL(12,2) NOT NULL,
  `short_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `damaged_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit_rate` DECIMAL(12,2) NOT NULL,
  `line_total` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_gri_grn` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gri_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_gri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. FINANCE & ACCOUNTING TABLES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `expenses`;
DROP TABLE IF EXISTS `expense_categories`;
DROP TABLE IF EXISTS `supplier_payments`;
DROP TABLE IF EXISTS `customer_payments`;
DROP TABLE IF EXISTS `journal_entry_lines`;
DROP TABLE IF EXISTS `journal_entries`;
DROP TABLE IF EXISTS `cash_accounts`;
DROP TABLE IF EXISTS `accounts`;

CREATE TABLE `accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_code` VARCHAR(30) NOT NULL UNIQUE,
  `account_name` VARCHAR(150) NOT NULL,
  `account_type` ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
  `sub_type` ENUM(
    'cash',
    'bank',
    'wallet',
    'accounts_receivable',
    'accounts_payable',
    'inventory',
    'sales',
    'sales_discount',
    'sales_return',
    'cost_of_goods_sold',
    'expense',
    'equity'
  ) NOT NULL,
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `account_number` VARCHAR(100) DEFAULT NULL,
  `branch_code` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_acc_code` (`account_code`),
  INDEX `idx_acc_type` (`account_type`, `sub_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `account_name` VARCHAR(100) NOT NULL,
  `custodian_name` VARCHAR(100) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ca_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_number` VARCHAR(30) NOT NULL UNIQUE,
  `entry_date` DATE NOT NULL,
  `reference_type` ENUM('opening', 'sales_invoice', 'settlement', 'cash_receipt', 'supplier_payment', 'grn', 'expense', 'manual_journal') NOT NULL,
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `total_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `narration` TEXT DEFAULT NULL,
  `is_posted` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_je_date` (`entry_date`),
  INDEX `idx_je_ref` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_je_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_entry_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `journal_entry_id` BIGINT UNSIGNED NOT NULL,
  `account_id` INT UNSIGNED NOT NULL,
  `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `description` VARCHAR(255) DEFAULT NULL,
  `party_type` ENUM('customer', 'supplier', 'salesman') DEFAULT NULL,
  `party_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_jel_entry` (`journal_entry_id`),
  INDEX `idx_jel_account` (`account_id`),
  INDEX `idx_jel_party` (`party_type`, `party_id`),
  CONSTRAINT `fk_jel_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jel_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_number` VARCHAR(30) NOT NULL UNIQUE,
  `payment_date` DATE NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `salesman_id` INT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_mode` ENUM('cash', 'cheque', 'bank_transfer', 'online') NOT NULL DEFAULT 'cash',
  `account_id` INT UNSIGNED NOT NULL,
  `reference_no` VARCHAR(50) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_cp_date` (`payment_date`),
  INDEX `idx_cp_cust` (`customer_id`),
  CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_cp_salesman` FOREIGN KEY (`salesman_id`) REFERENCES `salesmen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cp_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_number` VARCHAR(30) NOT NULL UNIQUE,
  `payment_date` DATE NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_mode` ENUM('cash', 'cheque', 'bank_transfer', 'online') NOT NULL DEFAULT 'cash',
  `account_id` INT UNSIGNED NOT NULL,
  `reference_no` VARCHAR(50) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sp_date` (`payment_date`),
  INDEX `idx_sp_supp` (`supplier_id`),
  CONSTRAINT `fk_sp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_sp_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_sp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expense_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_code` VARCHAR(20) NOT NULL UNIQUE,
  `category_name` VARCHAR(100) NOT NULL,
  `account_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ec_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_number` VARCHAR(30) NOT NULL UNIQUE,
  `expense_date` DATE NOT NULL,
  `expense_category_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `paid_from_account_id` INT UNSIGNED NOT NULL,
  `payment_mode` ENUM('cash', 'bank') NOT NULL DEFAULT 'cash',
  `payee_name` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `reference_voucher` VARCHAR(50) DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_exp_date` (`expense_date`),
  INDEX `idx_exp_cat` (`expense_category_id`),
  CONSTRAINT `fk_exp_cat` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `fk_exp_acc` FOREIGN KEY (`paid_from_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_exp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
