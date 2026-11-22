<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

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
    'F1' => 'Packs / Carton',
    'G1' => 'Pcs / Pack',
    'H1' => 'Total Pcs / Ctn',
    'I1' => 'Gross Trade Price (Rs)',
    'J1' => 'Discount %',
    'K1' => 'Net Purchase Pc Rate (Rs)',
    'L1' => 'Net Purchase Ctn Rate (Rs)',
    'M1' => 'Sale Pc Rate (Rs)',
    'N1' => 'Sale Ctn Rate (Rs)',
    'O1' => 'Opening Stock'
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
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];
$sheet->getStyle('A1:O1')->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(32);

$rowNum = 2;
foreach ($items as $item) {
    $sheet->setCellValue('A' . $rowNum, $item['product_code']);
    $sheet->setCellValue('B' . $rowNum, $item['product_name']);
    $sheet->setCellValue('C' . $rowNum, $item['supplier_name']);
    $sheet->setCellValue('D' . $rowNum, $item['category_name']);
    $sheet->setCellValue('E' . $rowNum, $item['packing_description']);
    $sheet->setCellValue('F' . $rowNum, (float)$item['carton_qty']);
    $sheet->setCellValue('G' . $rowNum, (float)$item['pack_qty']);
    $sheet->setCellValue('H' . $rowNum, (float)$item['total_pcs_per_ctn']);
    $sheet->setCellValue('I' . $rowNum, (float)$item['gross_trade_price']);
    $sheet->setCellValue('J' . $rowNum, (float)$item['discount_percent']);
    $sheet->setCellValue('K' . $rowNum, (float)$item['purchase_piece_rate']);
    $sheet->setCellValue('L' . $rowNum, (float)$item['purchase_carton_rate']);
    $sheet->setCellValue('M' . $rowNum, (float)$item['piece_rate']);
    $sheet->setCellValue('N' . $rowNum, (float)$item['carton_rate']);
    $sheet->setCellValue('O' . $rowNum, 0.0);

    // Light zebra striping
    if ($rowNum % 2 == 0) {
        $sheet->getStyle('A' . $rowNum . ':O' . $rowNum)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }

    // Number formats
    $sheet->getStyle('F' . $rowNum . ':H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('I' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('J' . $rowNum)->getNumberFormat()->setFormatCode('0.0"%"');
    $sheet->getStyle('K' . $rowNum . ':N' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('O' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');

    // Alignments
    $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('E' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('F' . $rowNum . ':O' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $rowNum++;
}

// Auto-size columns
foreach (range('A', 'O') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Borders for all data cells
$sheet->getStyle('A1:O' . ($rowNum - 1))->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN)
    ->getColor()->setRGB('D0D5DD');

$outFile = __DIR__ . '/storage/imports/products_master_import.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($outFile);

echo "Successfully created master spreadsheet: {$outFile} with " . count($items) . " products.\n";
