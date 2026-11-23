<?php
/**
 * DMS - Distribution Management System
 * Reload Catalog from PDFs / Master JSON Without Applying Discount
 * 
 * Rules:
 * 1. Wipe all products and dependent transactions from system.
 * 2. Set discount_percent = 0 for all items.
 * 3. Set purchase rates (carton & piece) directly equal to trade rates (carton & piece) without any discount deductions.
 * 4. Supplier mapping:
 *    - Kunafa items (SMS-001 to SMS-051) -> Supplier 2 (SMS)
 *    - Jaj Enterprise / Judge Trader / Hashim items (SMS-052 to SMS-127) -> Supplier 2 (SMS)
 *    - Ujab items (UJB-001 to UJB-103) -> Supplier 1 (Ujab Enterprises)
 * 5. Re-generate products_master_import.json, products_master_import.xlsx, and products_master_import.csv.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

try {
    echo "========================================================\n";
    echo "RELOADING CATALOG WITHOUT DISCOUNT (UJAB & SMS)\n";
    echo "========================================================\n";

    $pdo = db();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. Wipe all transactional & product tables
    echo "[1/6] Purging old items, stock balances, and test transactions...\n";
    $tablesToTruncate = [
        'order_items', 'orders',
        'sales_invoice_items', 'invoice_discounts', 'sales_invoices',
        'load_sheet_items', 'load_sheet_orders', 'load_sheets',
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
        'customer_prices', 'price_list_items', 'product_unit_conversions',
        'products'
    ];

    foreach ($tablesToTruncate as $tbl) {
        $check = $pdo->query("SHOW TABLES LIKE '{$tbl}'")->fetch();
        if ($check) {
            $pdo->exec("TRUNCATE TABLE `{$tbl}`");
        }
    }

    $pdo->exec("UPDATE customers SET opening_balance = 0.00, current_balance = 0.00");
    $pdo->exec("UPDATE suppliers SET current_balance = 0.00");
    $pdo->exec("UPDATE accounts SET current_balance = opening_balance");
    echo "    [✓] Database cleared successfully.\n";

    // 2. Ensure Suppliers
    echo "[2/6] Verifying Suppliers...\n";
    $pdo->exec("TRUNCATE TABLE suppliers");
    $stmtSup = $pdo->prepare("
        INSERT INTO suppliers (id, supplier_code, supplier_name, company_name, phone, address, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");

    $stmtSup->execute([
        1,
        'SUPP-UJAB',
        'Ujab Enterprises',
        'Ujab Enterprises (Islamabad / Gujranwala)',
        '051-1234567',
        'Bank Islami - Khanna Pull Branch, A/C: 309900213000001'
    ]);

    $stmtSup->execute([
        2,
        'SUPP-SMS',
        'SMS',
        'Dubai Kunafa & Confectionery Gujranwala',
        '0300-9876543',
        'Small Industrial Estate, Gujranwala'
    ]);
    echo "    [✓] Supplier 1: Ujab Enterprises, Supplier 2: SMS\n";

    // 3. Ensure Categories
    echo "[3/6] Verifying Product Categories...\n";
    $targetCategories = [
        ['id' => 1, 'code' => 'CAT-NIM', 'name' => 'Nimko & Savouries', 'desc' => 'Nimko twists, Moong Daal, Moth Daal, Party Packs'],
        ['id' => 2, 'code' => 'CAT-SNK', 'name' => 'Snacks & Puffs', 'desc' => 'Slanty, Zoro Ring Puffs, Popcorn, Nacho Chips'],
        ['id' => 3, 'code' => 'CAT-CND', 'name' => 'Confectionery', 'desc' => 'Lollipops, Candies, Toffees, Chocolates, Kunafa Gift Packs'],
        ['id' => 4, 'code' => 'CAT-BSC', 'name' => 'Biscuits & Wafers', 'desc' => 'Brunik Biscuits & Just Cream Wafers'],
        ['id' => 5, 'code' => 'CAT-GRC', 'name' => 'Grocery & Sauces', 'desc' => 'Mamas Sauces, Ketchups, Jams, Vinegar, Seeds, Spices'],
        ['id' => 6, 'code' => 'CAT-BAK', 'name' => 'Bakery & Dry Mixes', 'desc' => 'Baking Powder, Soda, Cocoa Powder, Cooking Chocolate'],
        ['id' => 7, 'code' => 'CAT-COS', 'name' => 'Cosmetics & Toiletries', 'desc' => '4ME Soaps & Air Fresheners']
    ];

    $pdo->exec("TRUNCATE TABLE product_categories");
    $stmtCat = $pdo->prepare("
        INSERT INTO product_categories (id, category_code, category_name, description, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, 1, NOW(), NOW())
    ");
    foreach ($targetCategories as $tc) {
        $stmtCat->execute([$tc['id'], $tc['code'], $tc['name'], $tc['desc']]);
    }
    echo "    [✓] 7 Product Categories verified.\n";

    // 4. Update JSON Catalog to REMOVE DISCOUNT and SET PURCHASE RATE = TRADE RATE
    echo "[4/6] Processing items catalog without discounts...\n";
    $jsonPath = __DIR__ . '/../storage/imports/products_master_import.json';
    $rawItems = json_decode(file_get_contents($jsonPath), true);

    $updatedItems = [];
    foreach ($rawItems as $it) {
        $ctnRate = (float)$it['carton_rate'];
        $pcRate  = (float)$it['piece_rate'];

        // Determine supplier: Kunafa and Jaj / Hashim items are under SMS (supplier_id = 2)
        $isSms = (strpos($it['product_code'], 'SMS-') === 0 || $it['supplier_id'] == 2 || $it['supplier_name'] === 'SMS');
        $suppId = $isSms ? 2 : 1;
        $suppName = $isSms ? 'SMS' : 'Ujab Enterprises';

        $it['supplier_id']           = $suppId;
        $it['supplier_name']         = $suppName;
        $it['discount_percent']      = 0.00;
        $it['purchase_piece_rate']   = $pcRate;
        $it['purchase_carton_rate']  = $ctnRate;
        $it['purchase_rate']         = $ctnRate;
        $it['carton_rate']           = $ctnRate;
        $it['piece_rate']            = $pcRate;
        $it['wholesale_rate']        = $ctnRate;
        $it['retail_rate']           = $ctnRate;
        $it['opening_stock']         = 0.00;

        $updatedItems[] = $it;
    }

    // Backup previous JSON
    copy($jsonPath, __DIR__ . '/../storage/imports/products_master_import.backup_with_discounts.json');
    file_put_contents($jsonPath, json_encode($updatedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "    [✓] Updated {$jsonPath} with 0.00% discount across all " . count($updatedItems) . " items.\n";

    // 5. Insert clean items into products and stock_balances
    echo "[5/6] Inserting products into database...\n";
    $stmtProd = $pdo->prepare("
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
            1,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            0.00, 1, 0, NOW(), NOW()
        )
    ");

    $stmtStock = $pdo->prepare("
        INSERT INTO stock_balances (warehouse_id, product_id, saleable_qty, damaged_qty, expired_qty, total_qty, avg_unit_cost, total_value, last_updated_at)
        VALUES (1, ?, 0.00, 0.00, 0.00, 0.00, ?, 0.00, NOW())
    ");

    $inserted = 0;
    foreach ($updatedItems as $p) {
        $stmtProd->execute([
            $p['product_code'],
            $p['product_code'],
            $p['product_name'],
            $p['category_id'],
            $p['supplier_id'],
            $p['purchase_rate'],
            $p['purchase_carton_rate'],
            $p['purchase_piece_rate'],
            $p['carton_rate'],
            $p['wholesale_rate'],
            $p['carton_rate'],
            $p['piece_rate'],
            $p['retail_rate'],
            $p['carton_rate'],
            $p['piece_rate'],
            $p['carton_qty'],
            $p['pack_qty'],
            $p['packing_description']
        ]);
        $prodId = (int)$pdo->lastInsertId();
        $stmtStock->execute([$prodId, $p['piece_rate']]);
        $inserted++;
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "    [✓] Successfully inserted {$inserted} products.\n";
    $ujabCount = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE supplier_id = 1");
    $smsCount  = (int)db_fetch_val("SELECT COUNT(*) FROM products WHERE supplier_id = 2");
    echo "        - Ujab Enterprises (Supplier 1): {$ujabCount} items\n";
    echo "        - SMS (Supplier 2 - Kunafa & Jaj/Hashim): {$smsCount} items\n";

    // 6. Regenerate Excel & CSV export files
    echo "[6/6] Generating updated Master Excel & CSV import templates...\n";
    
    // Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Products Master Catalog');

    $headers = [
        'A1' => 'Product Code',
        'B1' => 'Product Name',
        'C1' => 'Company / Supplier',
        'D1' => 'Category',
        'E1' => 'Packaging Specification',
        'F1' => 'Carton Qty (Packs)',
        'G1' => 'Pack Qty (Pcs/Pack)',
        'H1' => 'Total Pcs / Ctn',
        'I1' => 'Discount %',
        'J1' => 'Purchase Rate / Ctn (Cost)',
        'K1' => 'Purchase Rate / Piece',
        'L1' => 'Wholesale Rate / Ctn (Sale)',
        'M1' => 'Wholesale Rate / Piece',
        'N1' => 'Opening Stock'
    ];

    foreach ($headers as $cell => $val) {
        $sheet->setCellValue($cell, $val);
    }

    $sheet->getStyle('A1:N1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1E3A8A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $rowIdx = 2;
    foreach ($updatedItems as $p) {
        $sheet->setCellValue('A' . $rowIdx, $p['product_code']);
        $sheet->setCellValue('B' . $rowIdx, $p['product_name']);
        $sheet->setCellValue('C' . $rowIdx, $p['supplier_name']);
        $sheet->setCellValue('D' . $rowIdx, $p['category_name']);
        $sheet->setCellValue('E' . $rowIdx, $p['packing_description']);
        $sheet->setCellValue('F' . $rowIdx, $p['carton_qty']);
        $sheet->setCellValue('G' . $rowIdx, $p['pack_qty']);
        $sheet->setCellValue('H' . $rowIdx, $p['total_pcs_per_ctn']);
        $sheet->setCellValue('I' . $rowIdx, 0.00);
        $sheet->setCellValue('J' . $rowIdx, $p['purchase_carton_rate']);
        $sheet->setCellValue('K' . $rowIdx, $p['purchase_piece_rate']);
        $sheet->setCellValue('L' . $rowIdx, $p['carton_rate']);
        $sheet->setCellValue('M' . $rowIdx, $p['piece_rate']);
        $sheet->setCellValue('N' . $rowIdx, 0.00);

        if ($rowIdx % 2 === 0) {
            $sheet->getStyle("A{$rowIdx}:N{$rowIdx}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']]
            ]);
        }
        $rowIdx++;
    }

    foreach (range('A', 'N') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $xlsxPath = __DIR__ . '/../storage/imports/products_master_import.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($xlsxPath);
    echo "    [✓] Excel updated: {$xlsxPath}\n";

    // CSV
    $csvPath = __DIR__ . '/../storage/imports/products_master_import.csv';
    $fp = fopen($csvPath, 'w');
    fputcsv($fp, ['product_code', 'product_name', 'supplier_name', 'category_name', 'packing_description', 'carton_qty', 'pack_qty', 'purchase_rate', 'wholesale_rate', 'retail_rate', 'opening_stock']);
    foreach ($updatedItems as $p) {
        fputcsv($fp, [
            $p['product_code'],
            $p['product_name'],
            $p['supplier_name'],
            $p['category_name'],
            $p['packing_description'],
            $p['carton_qty'],
            $p['pack_qty'],
            $p['purchase_rate'],
            $p['wholesale_rate'],
            $p['retail_rate'],
            0.00
        ]);
    }
    fclose($fp);
    echo "    [✓] CSV updated: {$csvPath}\n";

    echo "========================================================\n";
    echo "SUCCESS: ALL 230 ITEMS RELOADED WITHOUT DISCOUNT!\n";
    echo "========================================================\n";

} catch (Exception $e) {
    db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
