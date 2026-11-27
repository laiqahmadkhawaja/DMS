<?php
/**
 * Migration: Mobile App & Field Delivery Returns Support
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    echo "Running migration: Mobile App & Field Delivery Returns...\n";

    // 1. Add return_items_json to delivery_run_bills if not exists
    $chkCol = db_fetch_all("SHOW COLUMNS FROM delivery_run_bills LIKE 'return_items_json'");
    if (empty($chkCol)) {
        db()->exec("ALTER TABLE delivery_run_bills ADD COLUMN return_items_json LONGTEXT NULL AFTER return_value");
        echo "  [+] Added return_items_json column to delivery_run_bills\n";
    } else {
        echo "  [.] return_items_json column already exists in delivery_run_bills\n";
    }

    // 2. Create field_return_items table if not exists
    db()->exec("
        CREATE TABLE IF NOT EXISTS field_return_items (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          delivery_run_bill_id BIGINT UNSIGNED NOT NULL,
          delivery_run_id BIGINT UNSIGNED NOT NULL,
          invoice_id BIGINT UNSIGNED DEFAULT NULL,
          customer_id BIGINT UNSIGNED NOT NULL,
          salesman_id INT UNSIGNED NOT NULL,
          product_id BIGINT UNSIGNED NOT NULL,
          unit_id INT UNSIGNED NOT NULL,
          carton_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          pack_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          pcs_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          total_pieces DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
          reason VARCHAR(255) DEFAULT 'Shop Return',
          condition_type ENUM('saleable', 'damaged', 'expired') NOT NULL DEFAULT 'saleable',
          warehouse_verified TINYINT(1) NOT NULL DEFAULT 0,
          verified_by BIGINT UNSIGNED DEFAULT NULL,
          verified_at DATETIME DEFAULT NULL,
          sales_return_id BIGINT UNSIGNED DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_fri_run_bill (delivery_run_bill_id),
          INDEX idx_fri_run (delivery_run_id),
          INDEX idx_fri_prod (product_id),
          INDEX idx_fri_verified (warehouse_verified)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  [+] Checked/Created table field_return_items\n";

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
