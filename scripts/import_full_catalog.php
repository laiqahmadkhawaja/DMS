<?php
/**
 * DMS - Distribution Management System
 * Import Full 2-Company Master Catalog (230 Items)
 * Supplier 1: Ujab Enterprises (103 items)
 * Supplier 2: SMS (127 items: 51 Kunafa + 76 Excel items)
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
    echo "IMPORTING FULL 2-COMPANY CATALOG (UJAB & SMS - 230 ITEMS)\n";
    echo "========================================================\n";

    db()->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. Configure Suppliers
    // Supplier 1: Ujab Enterprises
    // Supplier 2: SMS
    db()->exec("TRUNCATE TABLE suppliers");
    $stmtSup = db()->prepare("
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

    echo "[✓] Suppliers configured: 1 = Ujab Enterprises, 2 = SMS\n";

    // 2. Configure Categories
    $targetCategories = [
        ['id' => 1, 'code' => 'CAT-NIM', 'name' => 'Nimko & Savouries', 'desc' => 'Nimko twists, Moong Daal, Moth Daal, Party Packs'],
        ['id' => 2, 'code' => 'CAT-SNK', 'name' => 'Snacks & Puffs', 'desc' => 'Slanty, Zoro Ring Puffs, Popcorn, Nacho Chips'],
        ['id' => 3, 'code' => 'CAT-CND', 'name' => 'Confectionery', 'desc' => 'Lollipops, Candies, Toffees, Chocolates, Kunafa Gift Packs'],
        ['id' => 4, 'code' => 'CAT-BSC', 'name' => 'Biscuits & Wafers', 'desc' => 'Brunik Biscuits & Just Cream Wafers'],
        ['id' => 5, 'code' => 'CAT-GRC', 'name' => 'Grocery & Sauces', 'desc' => 'Mamas Sauces, Ketchups, Jams, Vinegar, Seeds, Spices'],
        ['id' => 6, 'code' => 'CAT-BAK', 'name' => 'Bakery & Dry Mixes', 'desc' => 'Baking Powder, Soda, Cocoa Powder, Cooking Chocolate'],
        ['id' => 7, 'code' => 'CAT-COS', 'name' => 'Cosmetics & Toiletries', 'desc' => '4ME Soaps & Air Fresheners']
    ];

    db()->exec("TRUNCATE TABLE product_categories");
    $stmtCat = db()->prepare("
        INSERT INTO product_categories (id, category_code, category_name, description, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, 1, NOW(), NOW())
    ");
    foreach ($targetCategories as $tc) {
        $stmtCat->execute([$tc['id'], $tc['code'], $tc['name'], $tc['desc']]);
    }
    echo "[✓] 7 Product Categories verified and configured.\n";

    // 3. Clean Products & Stock Balances
    db()->exec("TRUNCATE TABLE products");
    db()->exec("TRUNCATE TABLE stock_balances");

    // Load products from JSON
    $jsonFile = __DIR__ . '/../storage/imports/products_master_import.json';
    if (!file_exists($jsonFile)) {
        throw new Exception("Master JSON file not found: {$jsonFile}");
    }
    $products = json_decode(file_get_contents($jsonFile), true);

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
            1,
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

    $inserted = 0;
    foreach ($products as $p) {
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
        $prodId = (int)db()->lastInsertId();
        $stmtStock->execute([$prodId]);
        $inserted++;
    }

    db()->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "[✓] Successfully inserted {$inserted} products into the database!\n";
    echo "    - Ujab Enterprises: " . db_fetch_val("SELECT COUNT(*) FROM products WHERE supplier_id = 1") . "\n";
    echo "    - SMS: " . db_fetch_val("SELECT COUNT(*) FROM products WHERE supplier_id = 2") . "\n";
    echo "    - Stock Balances initialized: " . db_fetch_val("SELECT COUNT(*) FROM stock_balances WHERE saleable_qty = 0") . " (All 0.00)\n";

    // 4. Generate Master Excel (.xlsx) file
    echo "Generating styled Master Excel spreadsheet...\n";
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Products Master Catalog');

    // Headers
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
        'J1' => 'Net Purchase Rate / Ctn (Cost)',
        'K1' => 'Purchase Rate / Piece',
        'L1' => 'Wholesale Rate / Ctn (Sale)',
        'M1' => 'Wholesale Rate / Piece',
        'N1' => 'Opening Stock'
    ];

    foreach ($headers as $cell => $val) {
        $sheet->setCellValue($cell, $val);
    }

    // Header styling
    $headerRange = 'A1:N1';
    $sheet->getStyle($headerRange)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1E3A8A'] // Deep Navy
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Populate Data
    $rowIdx = 2;
    foreach ($products as $p) {
        $sheet->setCellValue('A' . $rowIdx, $p['product_code']);
        $sheet->setCellValue('B' . $rowIdx, $p['product_name']);
        $sheet->setCellValue('C' . $rowIdx, $p['supplier_name']);
        $sheet->setCellValue('D' . $rowIdx, $p['category_name']);
        $sheet->setCellValue('E' . $rowIdx, $p['packing_description']);
        $sheet->setCellValue('F' . $rowIdx, $p['carton_qty']);
        $sheet->setCellValue('G' . $rowIdx, $p['pack_qty']);
        $sheet->setCellValue('H' . $rowIdx, $p['total_pcs_per_ctn']);
        $sheet->setCellValue('I' . $rowIdx, $p['discount_percent']);
        $sheet->setCellValue('J' . $rowIdx, $p['purchase_carton_rate']);
        $sheet->setCellValue('K' . $rowIdx, $p['purchase_piece_rate']);
        $sheet->setCellValue('L' . $rowIdx, $p['carton_rate']);
        $sheet->setCellValue('M' . $rowIdx, $p['piece_rate']);
        $sheet->setCellValue('N' . $rowIdx, 0.00);

        // Alternate row fill
        if ($rowIdx % 2 === 0) {
            $sheet->getStyle("A{$rowIdx}:N{$rowIdx}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']]
            ]);
        }
        $rowIdx++;
    }

    // Auto-fit column widths
    foreach (range('A', 'N') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $xlsxPath = __DIR__ . '/../storage/imports/products_master_import.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($xlsxPath);

    echo "[✓] Master Excel generated successfully: {$xlsxPath}\n";
    echo "========================================================\n";
    echo "CATALOG IMPORT & SYNCHRONIZATION COMPLETE!\n";
    echo "========================================================\n";

} catch (Exception $e) {
    db()->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
