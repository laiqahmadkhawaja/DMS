<?php
/**
 * DMS - Update Master Catalog Files with Explicit Carton and Piece Columns
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

require_once __DIR__ . '/verify_ujab_data.php';

$jsonPath = __DIR__ . '/../storage/imports/products_master_import.json';
$rawItems = json_decode(file_get_contents($jsonPath), true);

foreach ($rawItems as &$it) {
    $code = $it['product_code'];
    if (isset($invoicesData[$code])) {
        $inv = $invoicesData[$code];
        $ctn = (float)$inv['ctn'];
        $pcs = (float)$inv['pcs'];
        $packing = (float)$inv['packing'];
        $totalBasePieces = ($ctn * $packing) + $pcs;
        
        $it['opening_ctn'] = $ctn;
        $it['opening_loose_pcs'] = $pcs;
        $it['opening_total_pcs'] = $totalBasePieces;
        
        $packsPerCtn = (float)$it['carton_qty'] > 0 ? (float)$it['carton_qty'] : 1;
        $pcsPerPack  = (float)$it['pack_qty'] > 0 ? (float)$it['pack_qty'] : 1;
        $bk = breakdown_base_pieces($totalBasePieces, $packsPerCtn, $pcsPerPack);
        $it['opening_stock_formatted'] = $bk['formatted'];
        // Default opening_stock field:
        $it['opening_stock'] = $ctn > 0 ? $ctn : $pcs;
    } else {
        $it['opening_ctn'] = 0;
        $it['opening_loose_pcs'] = 0;
        $it['opening_total_pcs'] = 0;
        $it['opening_stock_formatted'] = '0 Pcs';
        $it['opening_stock'] = 0;
    }
}
unset($it);

file_put_contents($jsonPath, json_encode($rawItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "[✓] products_master_import.json updated.\n";

// 2. Generate products_master_import.xlsx with clear, separate columns for Cartons and Loose Pieces
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
    'N1' => 'Opening Cartons (Ctn)',
    'O1' => 'Opening Loose (Pcs)',
    'P1' => 'Total Units (Base Pcs)',
    'Q1' => 'Opening Stock Breakdown'
];

foreach ($headers as $cell => $val) {
    $sheet->setCellValue($cell, $val);
}

$sheet->getStyle('A1:Q1')->applyFromArray([
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
$sheet->getStyle('N1:P1')->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '166534'] // Forest green for stock count columns
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
    $sheet->setCellValue('N' . $rowIdx, (float)($p['opening_ctn'] ?? 0));
    $sheet->setCellValue('O' . $rowIdx, (float)($p['opening_loose_pcs'] ?? 0));
    $sheet->setCellValue('P' . $rowIdx, (float)($p['opening_total_pcs'] ?? 0));
    $sheet->setCellValue('Q' . $rowIdx, $p['opening_stock_formatted'] ?? '0 Pcs');

    if ($rowIdx % 2 === 0) {
        $sheet->getStyle("A{$rowIdx}:Q{$rowIdx}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']]
        ]);
    }
    $rowIdx++;
}

foreach (range('A', 'Q') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$xlsxPath = __DIR__ . '/../storage/imports/products_master_import.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($xlsxPath);
echo "[✓] products_master_import.xlsx updated with explicit Cartons and Pieces columns.\n";

// 3. Update CSV
$csvPath = __DIR__ . '/../storage/imports/products_master_import.csv';
$fp = fopen($csvPath, 'w');
fputcsv($fp, [
    'product_code', 'product_name', 'supplier_name', 'category_name', 'packing_description',
    'carton_qty', 'pack_qty', 'purchase_rate', 'wholesale_rate', 'retail_rate',
    'opening_cartons', 'opening_loose_pcs', 'total_opening_pcs', 'opening_stock_breakdown'
]);
foreach ($rawItems as $p) {
    fputcsv($fp, [
        $p['product_code'],
        $p['product_name'],
        $p['supplier_name'],
        $p['category_name'],
        $p['packing_description'],
        $p['carton_qty'],
        $p['pack_qty'],
        $p['purchase_carton_rate'],
        $p['carton_rate'],
        $p['retail_rate'] ?? $p['carton_rate'],
        $p['opening_ctn'] ?? 0,
        $p['opening_loose_pcs'] ?? 0,
        $p['opening_total_pcs'] ?? 0,
        $p['opening_stock_formatted'] ?? '0 Pcs'
    ]);
}
fclose($fp);
echo "[✓] products_master_import.csv updated.\n";

// 4. Also generate standard Opening Stock Template file for modules/inventory/import_stock.php
$stockImportSpreadsheet = new Spreadsheet();
$sSheet = $stockImportSpreadsheet->getActiveSheet();
$sSheet->setTitle('Opening Stock');

$stockHeaders = [
    'A1' => 'Product Code*',
    'B1' => 'Product Name',
    'C1' => 'Cartons (Ctn)',
    'D1' => 'Packs (Pck)',
    'E1' => 'Loose Pieces (Pcs)',
    'F1' => 'Cost Rate / Carton (Optional)'
];

foreach ($stockHeaders as $cell => $t) {
    $sSheet->setCellValue($cell, $t);
}

$sSheet->getStyle('A1:F1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B3B6F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

$sRow = 2;
foreach ($rawItems as $p) {
    if (isset($invoicesData[$p['product_code']])) {
        $inv = $invoicesData[$p['product_code']];
        $sSheet->setCellValue('A' . $sRow, $p['product_code']);
        $sSheet->setCellValue('B' . $sRow, $p['product_name']);
        $sSheet->setCellValue('C' . $sRow, (float)$inv['ctn']);
        $sSheet->setCellValue('D' . $sRow, 0);
        $sSheet->setCellValue('E' . $sRow, (float)$inv['pcs']);
        $sSheet->setCellValue('F' . $sRow, (float)$p['purchase_carton_rate']);
        $sRow++;
    }
}

foreach (range('A', 'F') as $col) {
    $sSheet->getColumnDimension($col)->setAutoSize(true);
}

$stockImportPath = __DIR__ . '/../storage/imports/ujab_opening_stock_import.xlsx';
$stockWriter = new Xlsx($stockImportSpreadsheet);
$stockWriter->save($stockImportPath);
echo "[✓] Dedicated opening stock import template generated: storage/imports/ujab_opening_stock_import.xlsx\n";

// 5. Update stock_movements remarks in database to make carton and piece breakdown explicit
$pdo = db();
$pdo->beginTransaction();
$stmtUpdMov = $pdo->prepare("
    UPDATE stock_movements 
    SET remarks = ?
    WHERE product_id = ? AND warehouse_id = 1 AND movement_type = 'opening_stock'
");

$dbProds = db_fetch_all("SELECT id, product_code FROM products WHERE supplier_id = 1");
foreach ($dbProds as $dp) {
    $code = $dp['product_code'];
    if (isset($invoicesData[$code])) {
        $inv = $invoicesData[$code];
        $remarks = "Opening Stock from Ujab Invoices: {$inv['ctn']} Ctns, {$inv['pcs']} Loose Pcs (Total Base: " . (($inv['ctn'] * $inv['packing']) + $inv['pcs']) . " Pcs)";
        $stmtUpdMov->execute([$remarks, $dp['id']]);
    }
}
$pdo->commit();
echo "[✓] stock_movements remarks updated in database.\n";
