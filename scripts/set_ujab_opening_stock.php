<?php
/**
 * DMS - Distribution Management System
 * Save Ujab Opening Stock from Invoices #7178, #7177, and #7212
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once INCLUDES_PATH . '/inventory_functions.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

try {
    echo "========================================================\n";
    echo "SAVING UJAB OPENING STOCK FROM PDF INVOICES (103 ITEMS)\n";
    echo "========================================================\n";

    $pdo = db();
    $pdo->beginTransaction();

    // Exact purchase quantities from Ujab Invoices 7178, 7177, 7212
    $ujabOpeningStock = [
        // Invoice 7178 (Snacks)
        'UJB-001' => ['ctn' => 120, 'pcs' => 0, 'sale_pcs' => 4320], // UJAB NACHO CHIPS Maxican Chilli
        'UJB-002' => ['ctn' => 192, 'pcs' => 0, 'sale_pcs' => 6912], // UJAB NACHO CHIPS Tangy Cheese
        'UJB-003' => ['ctn' => 29,  'pcs' => 0, 'sale_pcs' => 1044], // UJAB NACHOS Chips SMOKEY BBQ
        'UJB-004' => ['ctn' => 106, 'pcs' => 0, 'sale_pcs' => 2544], // OTTO Vegetable SLANTY
        'UJB-005' => ['ctn' => 100, 'pcs' => 0, 'sale_pcs' => 2400], // OTTO Jalapeno SLANTY
        'UJB-006' => ['ctn' => 51,  'pcs' => 0, 'sale_pcs' => 1224], // OTTO Salted SLANTY
        'UJB-007' => ['ctn' => 85,  'pcs' => 0, 'sale_pcs' => 2040], // POPCORN CLASSIC SALT (59 in 7178 + 26 in 7212 = 85 Ctn)
        'UJB-008' => ['ctn' => 130, 'pcs' => 0, 'sale_pcs' => 1560], // ZORO Ring puff CHILLI STORM (100 in 7178 + 30 in 7212 = 130 Ctn)
        'UJB-009' => ['ctn' => 114, 'pcs' => 0, 'sale_pcs' => 1368], // ZORO Ring puff Butter Cheese (84 in 7178 + 30 in 7212 = 114 Ctn)
        'UJB-010' => ['ctn' => 130, 'pcs' => 0, 'sale_pcs' => 1560], // ZORO Ring Puff Urban BBQ (100 in 7178 + 30 in 7212 = 130 Ctn)
        'UJB-011' => ['ctn' => 130, 'pcs' => 0, 'sale_pcs' => 1560], // ZORO Ring puff LEMON PEPPER (100 in 7178 + 30 in 7212 = 130 Ctn)

        // Invoice 7212 (Nimko & Popcorn New Items)
        'UJB-012' => ['ctn' => 50,  'pcs' => 0, 'sale_pcs' => 1200], // POPCORN TOFFEE TWIST
        'UJB-013' => ['ctn' => 100, 'pcs' => 0, 'sale_pcs' => 2400], // POPCORN Caramel Crunch
        'UJB-014' => ['ctn' => 30,  'pcs' => 0, 'sale_pcs' => 1080], // UJAB Nimko twist MIX 45g
        'UJB-015' => ['ctn' => 30,  'pcs' => 0, 'sale_pcs' => 1080], // UJAB Nimko twist DAAL MO
        'UJB-016' => ['ctn' => 30,  'pcs' => 0, 'sale_pcs' => 720],  // UJAB Nimko twist MIX 95g
        'UJB-017' => ['ctn' => 30,  'pcs' => 0, 'sale_pcs' => 720],  // UJAB Nimko twist DAAL MO 95g

        // Invoice 7177 (General Products, Spices, Bakery, Jams, Sweets)
        'UJB-018' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 216],  // Ujab Baking Powder 50gm
        'UJB-019' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 216],  // Ujab Baking Powder 100gm
        'UJB-020' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Baking Soda 100gm
        'UJB-021' => ['ctn' => 5,   'pcs' => 0, 'sale_pcs' => 340],  // Ujab Barley Porridge 100gm
        'UJB-022' => ['ctn' => 5,   'pcs' => 0, 'sale_pcs' => 340],  // Ujab Wheat Porridge 100gm
        'UJB-023' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 68],   // Ujab Brown Sugar 250gm
        'UJB-024' => ['ctn' => 3,   'pcs' => 0, 'sale_pcs' => 324],  // Ujab Chickern Powder 70gm
        'UJB-025' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 116],  // Ujab Brown Chocolate Chip 85gm
        'UJB-026' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 216],  // Ujab Cocoa Powder 50gm
        'UJB-027' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 216],  // Ujab Cocoa Powder 100gm
        'UJB-028' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 40],   // Ujab Cocoa Powder 200gm
        'UJB-029' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 136],  // Ujab Color vermicilli 155gm
        'UJB-030' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 116],  // Ujab Cooking Chocolate Dark 200gm
        'UJB-031' => ['ctn' => 0,   'pcs' => 17,'sale_pcs' => 17],   // Ujab Cooking Chocolate Dark 500gm
        'UJB-032' => ['ctn' => 3,   'pcs' => 0, 'sale_pcs' => 120],  // Ujab Corn Flour 250gm
        'UJB-033' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 68],   // Ujab Sher Khurma 125gm
        'UJB-034' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 132],  // Ujab SPRINKLES 85GM
        'UJB-035' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 68],   // Ujab Chia Seed Premium Box 180gm
        'UJB-036' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 68],   // Ujab Flax Seed Premium 180gm Box
        'UJB-037' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Yeast 3in1 Sachet Box 33gm
        'UJB-038' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 116],  // Ujab White Chocolate chip 80gm
        'UJB-039' => ['ctn' => 50,  'pcs' => 0, 'sale_pcs' => 50],   // Catalogues of Ujab Enterprises
        'UJB-040' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 12],   // UJAB Mango Jam Balty 1kg
        'UJB-041' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 12],   // UJAB Mixed Fruit Jam Balty 1kg
        'UJB-042' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // Ujab Chaat Masala JAR 60gm
        'UJB-043' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // Ujab French Fry JAR 60gm
        'UJB-044' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // Ujab Moringa Powder JAR 60gm
        'UJB-045' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // Ujab Paprika Powder JAR 60gm
        'UJB-046' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // Ujab Black Pepper powder JAR 60gm
        'UJB-047' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // Ujab Black Salt JAR 120gm
        'UJB-048' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 48],   // Ujab Bread Crumb Plain 200gm
        'UJB-049' => ['ctn' => 5,   'pcs' => 0, 'sale_pcs' => 80],   // Ujab White Vinegar 750ml
        'UJB-050' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 48],   // Ujab Chilli Sauce 300ml
        'UJB-051' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 48],   // Ujab Soya Sauce 300ml
        'UJB-052' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 48],   // Ujab White Vinegar 300ml
        'UJB-053' => ['ctn' => 5,   'pcs' => 0, 'sale_pcs' => 120],  // UJAB Triopack Small 3x120ml
        'UJB-054' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // UJAB Garlic Powder BOX 50gm
        'UJB-055' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Ginger Powder BOX 50gm
        'UJB-056' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Onion powder BOX 50gm
        'UJB-057' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Chaat Masala BOX 50gm
        'UJB-058' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab French Fry BOX 50gm
        'UJB-059' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Kashmiri Chilli BOX 50gm
        'UJB-060' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Paprika Powder BOX 50gm
        'UJB-061' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab Black Pepper BOX 30gm
        'UJB-062' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 108],  // Ujab White Pepper BOX 30gm
        'UJB-063' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // Ujab Pink Salt Jar 120gm
        'UJB-064' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 32],   // UJAB SOYA SAUCE 800ml Pure Bottle
        'UJB-065' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 32],   // UJAB CHILLI SAUCE 800ML Pure Bottle
        'UJB-066' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 60],   // UJAB Mixed Pickles Jar 400gm
        'UJB-067' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 48],   // UJAB Mixed Pickles Jar 1000gm
        'UJB-068' => ['ctn' => 0,   'pcs' => 60,'sale_pcs' => 60],   // Ujab KEWRA essence 25ml
        'UJB-069' => ['ctn' => 0,   'pcs' => 60,'sale_pcs' => 60],   // Ujab Vanilla Essence 25ml
        'UJB-070' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Once organic 200gm Chia seeds
        'UJB-071' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Once Organic 200gm Flex seed
        'UJB-072' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Once Organic 200gm Pumpkin Seeds
        'UJB-073' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Once organic 100gm Moringa Powder
        'UJB-074' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Once Organic 25gm Oregano Leaves
        'UJB-075' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Once Organic 50gm Oregano Leaves
        'UJB-076' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 80],   // UJAB PASTA Big Elbow BOX 250gm
        'UJB-077' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 80],   // Ujab Pasta Medium Elbow Box 250gm
        'UJB-078' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 80],   // Ujab Pasta pennie BOX 250gm
        'UJB-079' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 50],   // Ujab SUPER Black Binroll 16x22
        'UJB-080' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 50],   // Ujab SUPER Black Binroll 18x20
        'UJB-081' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 50],   // Ujab SUPER Black Binroll 20x21
        'UJB-082' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 50],   // Ujab SUPER Black Binroll 20x24
        'UJB-083' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 50],   // Ujab SUPER Black Binroll 24x30
        'UJB-084' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 50],   // Ujab SUPER Black Binroll 25x35
        'UJB-085' => ['ctn' => 2,   'pcs' => 0, 'sale_pcs' => 24],   // UJAB COCOLADA 13% Hazelnut Spread 350gm
        'UJB-086' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 12],   // UJAB COCOLADA Chocolate Spread 180gm
        'UJB-087' => ['ctn' => 1,   'pcs' => 0, 'sale_pcs' => 12],   // UJAB COCOLADA CHocolate Spread 350gm
        'UJB-088' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Surprise Kids Egg Avengers
        'UJB-089' => ['ctn' => 0,   'pcs' => 36,'sale_pcs' => 36],   // UJAB Surprise Kids Egg Barbie
        'UJB-090' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Surprise Kids Egg Lion King
        'UJB-091' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Surprise Kids Egg Masha & Bear
        'UJB-092' => ['ctn' => 0,   'pcs' => 36,'sale_pcs' => 36],   // UJAB Surprise Kids Egg Pink Panther
        'UJB-093' => ['ctn' => 0,   'pcs' => 24,'sale_pcs' => 24],   // UJAB Surprise kKds Egg Cindrella
        'UJB-094' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // UJAB-Surprise Kids Egg BEN 10 50gm
        'UJB-095' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // UJB-Surprise Kids Egg BatMan 50GM
        'UJB-096' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // UJB-Surprise Kids Egg MICKY Mouse 50g
        'UJB-097' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // UJB-Surprise Kids Egg Motu Patlu 50gm
        'UJB-098' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // UJB-Surprise Kids Egg Pinkie pie 50gm
        'UJB-099' => ['ctn' => 0,   'pcs' => 48,'sale_pcs' => 48],   // UJB-Surprise Kids Egg Princess 50gm
        'UJB-100' => ['ctn' => 0,   'pcs' => 15,'sale_pcs' => 15],   // Ujab LMT Giant Lollypop Blue-AVENGERS
        'UJB-101' => ['ctn' => 0,   'pcs' => 15,'sale_pcs' => 15],   // Ujab LMT Giant Lollypop Red-FROZEN
        'UJB-102' => ['ctn' => 0,   'pcs' => 15,'sale_pcs' => 15],   // Ujab LMT Giant Lollypop Yellow-LOONEY T
        'UJB-103' => ['ctn' => 0,   'pcs' => 15,'sale_pcs' => 15],   // Ujab LMT Giant Lollypop pink-PRINCESS
    ];

    // Load Ujab products from DB
    $dbProds = db_fetch_all("
        SELECT id, product_code, product_name, purchase_piece_rate, purchase_carton_rate, carton_qty, pack_qty 
        FROM products 
        WHERE supplier_id = 1
    ");

    $dbByCode = [];
    foreach ($dbProds as $dp) {
        $dbByCode[$dp['product_code']] = $dp;
    }

    $warehouseId = 1;
    $totalUnits = 0;
    $totalValuation = 0.0;
    $updatedCount = 0;

    $stmtUpdStock = $pdo->prepare("
        UPDATE stock_balances 
        SET saleable_qty = ?, total_qty = ?, avg_unit_cost = ?, total_value = ?, last_updated_at = NOW()
        WHERE warehouse_id = ? AND product_id = ?
    ");

    $stmtDelMov = $pdo->prepare("
        DELETE FROM stock_movements 
        WHERE warehouse_id = ? AND product_id = ? AND movement_type = 'opening_stock'
    ");

    $stmtInsMov = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id, warehouse_id, movement_date, movement_type, reference_type, reference_id,
            qty_in, qty_out, base_unit_qty, unit_cost, total_cost, created_by, remarks, created_at
        ) VALUES (
            ?, ?, NOW(), 'opening_stock', 'opening', NULL,
            ?, 0.00, ?, ?, ?, 1, ?, NOW()
        )
    ");

    foreach ($ujabOpeningStock as $code => $stk) {
        if (!isset($dbByCode[$code])) {
            throw new Exception("Product code {$code} not found in database!");
        }

        $prod = $dbByCode[$code];
        $prodId = (int)$prod['id'];
        $basePieces = (float)$stk['sale_pcs'];
        $pieceCost = (float)$prod['purchase_piece_rate'];
        $lineVal = round($basePieces * $pieceCost, 2);

        // 1. Update stock_balances
        $stmtUpdStock->execute([$basePieces, $basePieces, $pieceCost, $lineVal, $warehouseId, $prodId]);

        // 2. Clear old opening movement if exists
        $stmtDelMov->execute([$warehouseId, $prodId]);

        // 3. Insert stock movement record
        $remarks = "Opening Stock from Ujab Invoices #7178/#7177/#7212 [{$stk['ctn']} Ctn, {$stk['pcs']} Pcs]";
        $stmtInsMov->execute([$prodId, $warehouseId, $basePieces, $basePieces, $pieceCost, $lineVal, $remarks]);

        $totalUnits += $basePieces;
        $totalValuation += $lineVal;
        $updatedCount++;
    }

    $pdo->commit();

    echo "[✓] Successfully updated stock balances and ledger movements for {$updatedCount} Ujab products!\n";
    echo "    - Total Opening Units (Pieces): " . number_format($totalUnits) . "\n";
    echo "    - Total Stock Valuation: Rs. " . number_format($totalValuation, 2) . "\n";

    // 4. Update JSON Catalog File with Opening Stock
    echo "Updating JSON, Excel, and CSV catalog files with opening stock...\n";
    $jsonPath = __DIR__ . '/../storage/imports/products_master_import.json';
    $rawItems = json_decode(file_get_contents($jsonPath), true);

    foreach ($rawItems as &$it) {
        if (isset($ujabOpeningStock[$it['product_code']])) {
            $it['opening_stock'] = (float)$ujabOpeningStock[$it['product_code']]['sale_pcs'];
        }
    }
    unset($it);

    file_put_contents($jsonPath, json_encode($rawItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "    [✓] JSON updated: {$jsonPath}\n";

    // 5. Update Excel Spreadsheet
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
    foreach ($rawItems as $p) {
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
        $sheet->setCellValue('N' . $rowIdx, (float)($p['opening_stock'] ?? 0));

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

    // 6. Update CSV
    $csvPath = __DIR__ . '/../storage/imports/products_master_import.csv';
    $fp = fopen($csvPath, 'w');
    fputcsv($fp, ['product_code', 'product_name', 'supplier_name', 'category_name', 'packing_description', 'carton_qty', 'pack_qty', 'purchase_rate', 'wholesale_rate', 'retail_rate', 'opening_stock']);
    foreach ($rawItems as $p) {
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
            (float)($p['opening_stock'] ?? 0)
        ]);
    }
    fclose($fp);
    echo "    [✓] CSV updated: {$csvPath}\n";

    echo "========================================================\n";
    echo "ALL 103 UJAB OPENING STOCK QUANTITIES SAVED SUCCESSFULLY!\n";
    echo "========================================================\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
