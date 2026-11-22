<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$jsonFile = __DIR__ . '/storage/imports/products_master_import.json';
$items = json_decode(file_get_contents($jsonFile), true);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Products Catalog');

// Header formatting
$headers = [
    'A1' => 'Product Code (SKU)',
    'B1' => 'Product Name',
    'C1' => 'Supplier / Company',
    'D1' => 'Category',
    'E1' => 'Packing Description',
    'F1' => 'Carton Qty (Pcs/Ctn)',
    'G1' => 'Pack Qty',
    'H1' => 'Base Unit',
    'I1' => 'Purchase Rate (Rs)',
    'J1' => 'Wholesale Rate (Rs)',
    'K1' => 'Retail Rate (Rs)',
    'L1' => 'Opening Stock (Qty)'
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// Styling the header row
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1B365D'] // Navy blue
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];
$sheet->getStyle('A1:L1')->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(28);

$rowNum = 2;
foreach ($items as $item) {
    $sheet->setCellValue('A' . $rowNum, $item['product_code']);
    $sheet->setCellValue('B' . $rowNum, $item['product_name']);
    $sheet->setCellValue('C' . $rowNum, $item['supplier_name']);
    $sheet->setCellValue('D' . $rowNum, $item['category_name']);
    $sheet->setCellValue('E' . $rowNum, $item['packing_description']);
    $sheet->setCellValue('F' . $rowNum, $item['carton_qty']);
    $sheet->setCellValue('G' . $rowNum, $item['pack_qty']);
    $sheet->setCellValue('H' . $rowNum, $item['unit']);
    $sheet->setCellValue('I' . $rowNum, $item['purchase_rate']);
    $sheet->setCellValue('J' . $rowNum, $item['wholesale_rate']);
    $sheet->setCellValue('K' . $rowNum, $item['retail_rate']);
    $sheet->setCellValue('L' . $rowNum, $item['opening_stock']);

    // Light zebra striping
    if ($rowNum % 2 == 0) {
        $sheet->getStyle("A{$rowNum}:L{$rowNum}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }

    $rowNum++;
}

// Format numbers
$sheet->getStyle('F2:G' . ($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0');
$sheet->getStyle('I2:K' . ($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('L2:L' . ($rowNum - 1))->getNumberFormat()->setFormatCode('0');

// Borders on data
$sheet->getStyle('A2:L' . ($rowNum - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E0E0E0');

// Auto size columns
foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$xlsxPath = __DIR__ . '/storage/imports/products_master_import.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($xlsxPath);

echo "Successfully created XLSX: {$xlsxPath} with " . count($items) . " products." . PHP_EOL;
