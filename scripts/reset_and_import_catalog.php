<?php
/**
 * DMS - Distribution Management System
 * System Reset & Real Products Catalog Importer
 * Purges old testing transactions and sets up the 2 real companies and 154 products.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

try {
    echo "======================================================" . PHP_EOL;
    echo "STARTING SYSTEM DATA PURGE & 2-COMPANY CATALOG IMPORT" . PHP_EOL;
    echo "======================================================" . PHP_EOL;

    db()->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. Truncate all transactional and catalog tables
    $transactionTables = [
        'order_items', 'orders',
        'load_sheet_items', 'load_sheet_orders', 'load_sheets',
        'sales_invoice_items', 'sales_invoices', 'invoice_discounts',
        'delivery_run_bills', 'delivery_runs',
        'settlement_items', 'settlements',
        'cash_submissions',
        'customer_payments',
        'supplier_payments',
        'expenses',
        'purchase_order_items', 'purchase_orders',
        'goods_receipt_items', 'goods_receipts',
        'purchase_return_items', 'purchase_returns',
        'sales_return_items', 'sales_returns',
        'stock_movements', 'stock_balances', 'stock_adjustments', 'stock_adjustment_items',
        'journal_entry_lines', 'journal_entries',
        'customer_prices', 'price_list_items', 'price_lists', 'product_unit_conversions',
        'products',
        'audit_logs'
    ];

    foreach ($transactionTables as $tbl) {
        db()->exec("TRUNCATE TABLE `{$tbl}`");
        echo " - Truncated: {$tbl}" . PHP_EOL;
    }

    // 2. Reset balances
    db()->exec("UPDATE customers SET opening_balance = 0.00, current_balance = 0.00");
    db()->exec("UPDATE accounts SET opening_balance = 0.00, current_balance = 0.00");
    echo " - Balances reset for customers and financial accounts." . PHP_EOL;

    // 3. Ensure ONLY the 2 real suppliers: Ujab Enterprises and Kunafa Foods
    $targetSuppliers = [
        [
            'code' => 'SUPP-UJAB',
            'name' => 'Ujab Enterprises',
            'company' => 'Ujab Enterprises (Islamabad / Gujranwala)',
            'phone' => '051-1234567',
            'address' => 'Bank Islami - Khanna Pull Branch, A/C: 309900213000001'
        ],
        [
            'code' => 'SUPP-KUN',
            'name' => 'Kunafa Foods',
            'company' => 'Dubai Kunafa & Confectionery Gujranwala',
            'phone' => '0300-9876543',
            'address' => 'Small Industrial Estate, Gujranwala'
        ]
    ];

    db()->exec("TRUNCATE TABLE suppliers");
    $stmtSup = db()->prepare("
        INSERT INTO suppliers (supplier_code, supplier_name, company_name, phone, address, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");

    $supplierMap = [];
    foreach ($targetSuppliers as $ts) {
        $stmtSup->execute([$ts['code'], $ts['name'], $ts['company'], $ts['phone'], $ts['address']]);
        $sId = (int)db()->lastInsertId();
        $supplierMap[$ts['name']] = $sId;
        echo " - Configured Supplier: {$ts['name']} (ID: {$sId})" . PHP_EOL;
    }

    // 4. Ensure Categories
    $targetCategories = [
        ['code' => 'CAT-NIM', 'name' => 'Nimko & Savouries', 'desc' => 'Nimko twists, Moong Daal, Moth Daal, Party Packs'],
        ['code' => 'CAT-SNK', 'name' => 'Snacks & Puffs', 'desc' => 'Slanty, Zoro Ring Puffs, Popcorn, Nacho Chips'],
        ['code' => 'CAT-CND', 'name' => 'Confectionery', 'desc' => 'Lollipops, Candies, Toffees, Chocolates, Kunafa Gift Packs'],
        ['code' => 'CAT-BSC', 'name' => 'Biscuits & Wafers', 'desc' => 'Biscuits & Wafers'],
        ['code' => 'CAT-GRC', 'name' => 'Grocery & Sauces', 'desc' => 'Sauces, Vinegar, Pickles, Jams, Seeds, Spices, Bin Rolls'],
        ['code' => 'CAT-BAK', 'name' => 'Bakery & Dry Mixes', 'desc' => 'Baking Powder, Soda, Cocoa Powder, Cooking Chocolate, Pasta'],
        ['code' => 'CAT-COS', 'name' => 'Cosmetics & Toiletries', 'desc' => 'Toiletries & Cleaning Essentials']
    ];

    db()->exec("TRUNCATE TABLE product_categories");
    $stmtCat = db()->prepare("
        INSERT INTO product_categories (category_code, category_name, description, is_active, created_at, updated_at)
        VALUES (?, ?, ?, 1, NOW(), NOW())
    ");

    $categoryMap = [];
    foreach ($targetCategories as $tc) {
        $stmtCat->execute([$tc['code'], $tc['name'], $tc['desc']]);
        $cId = (int)db()->lastInsertId();
        $categoryMap[$tc['name']] = $cId;
        echo " - Configured Category: {$tc['name']} (ID: {$cId})" . PHP_EOL;
    }

    // Units map
    $units = db_fetch_all("SELECT id, unit_code FROM units");
    $unitMap = [];
    foreach ($units as $u) {
        $unitMap[$u['unit_code']] = $u['id'];
    }

    // 5. Read products from products_master_import.json
    $jsonPath = __DIR__ . '/../storage/imports/products_master_import.json';
    if (!file_exists($jsonPath)) {
        throw new Exception("Master JSON not found at: {$jsonPath}");
    }
    $products = json_decode(file_get_contents($jsonPath), true);

    $stmtProd = db()->prepare("
        INSERT INTO products (
            product_code, barcode, product_name, category_id, supplier_id,
            base_unit_id,
            purchase_rate, purchase_carton_rate, purchase_piece_rate,
            default_sale_rate, wholesale_rate, carton_rate, piece_rate,
            retail_rate, retail_carton_rate, retail_piece_rate,
            carton_qty, pack_qty, packing_description,
            min_stock_level, is_active, is_deleted, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            0.00, 1, 0, NOW(), NOW()
        )
    ");

    $stmtStock = db()->prepare("
        INSERT INTO stock_balances (warehouse_id, product_id, saleable_qty, damaged_qty, expired_qty, total_qty, avg_unit_cost, total_value, last_updated_at)
        VALUES (1, ?, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NOW())
    ");

    $importedCount = 0;
    foreach ($products as $p) {
        $supId = $supplierMap[$p['supplier_name']] ?? 1;
        $catId = $categoryMap[$p['category_name']] ?? 3;
        $unitId = 1; // Pcs / base unit

        $stmtProd->execute([
            $p['product_code'],
            $p['product_code'],
            $p['product_name'],
            $catId,
            $supId,
            $unitId,
            $p['purchase_rate'],
            $p['purchase_carton_rate'],
            $p['purchase_piece_rate'],
            $p['carton_rate'],
            $p['wholesale_rate'],
            $p['carton_rate'],
            $p['piece_rate'],
            $p['carton_rate'],
            $p['carton_rate'],
            $p['piece_rate'],
            $p['carton_qty'],
            $p['pack_qty'],
            $p['packing_description']
        ]);
        $prodId = (int)db()->lastInsertId();

        // Initialize zero stock balance in warehouse 1
        $stmtStock->execute([$prodId]);
        $importedCount++;
    }

    db()->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "======================================================" . PHP_EOL;
    echo "SUCCESS: Cleaned all test transactions & imported {$importedCount} unique products for 2 suppliers!" . PHP_EOL;
    echo "======================================================" . PHP_EOL;

} catch (Exception $e) {
    db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}
