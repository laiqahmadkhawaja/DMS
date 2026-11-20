<?php
/**
 * DMS - Distribution Management System
 * Native XLSX Data Export Handler (PhpSpreadsheet)
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/database_helpers.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('excel_export');

$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    die("PhpSpreadsheet library is not installed. Please run composer install.");
}
require_once $vendorAutoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$exportType = clean_input($_GET['type'] ?? 'daily_sales');
$fromDate   = clean_input($_GET['from_date'] ?? date('Y-m-01'));
$toDate     = clean_input($_GET['to_date'] ?? date('Y-m-d'));

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Header Styling Preset
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1E3A8A'] // Navy Blue
    ],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

$yellowHeaderStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFC107'] // Yellow
    ],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

$totalRowStyle = [
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F3F4F6']
    ],
    'borders' => [
        'top' => ['borderStyle' => Border::BORDER_THIN],
        'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]
    ]
];

$filename = "dms_export_" . date('Ymd_His') . ".xlsx";

switch ($exportType) {
    case 'daily_sales':
        $filename = "Muzzam_Daily_Sales_" . $fromDate . "_to_" . $toDate . ".xlsx";
        $sheet->setTitle("Daily Sales");

        // Title Block
        $sheet->setCellValue('A1', CLIENT_NAME . ' - DAILY SALES REGISTER');
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', "Period: " . format_date($fromDate) . " to " . format_date($toDate));
        $sheet->mergeCells('A2:G2');

        // Headers
        $headers = ['Sr.', 'Date', 'Bills', 'Invoices (Total Amount)', 'Cash Recived', 'Credit', 'Return'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        foreach ($headers as $idx => $h) {
            $sheet->setCellValue($cols[$idx] . '4', $h);
        }
        $sheet->getStyle('A4:G4')->applyFromArray($yellowHeaderStyle);

        // Fetch Data
        $rows = db_fetch_all("
            SELECT 
                invoice_date,
                COUNT(id) as bills,
                COALESCE(SUM(net_amount), 0) as invoices,
                COALESCE(SUM(paid_amount), 0) as cash,
                COALESCE(SUM(credit_amount), 0) as credit,
                COALESCE(SUM(return_amount), 0) as returns
            FROM sales_invoices
            WHERE invoice_date >= ? AND invoice_date <= ? AND status != 'cancelled'
            GROUP BY invoice_date
            ORDER BY invoice_date ASC
        ", [$fromDate, $toDate]);

        $lineNum = 5;
        $sr = 1;
        $sumInvoices = 0; $sumCash = 0; $sumCredit = 0; $sumReturns = 0; $sumBills = 0;

        foreach ($rows as $r) {
            $sheet->setCellValue('A' . $lineNum, $sr++);
            $sheet->setCellValue('B' . $lineNum, date('d.m.y', strtotime($r['invoice_date'])));
            $sheet->setCellValue('C' . $lineNum, (int)$r['bills']);
            $sheet->setCellValue('D' . $lineNum, (float)$r['invoices']);
            $sheet->setCellValue('E' . $lineNum, (float)$r['cash']);
            $sheet->setCellValue('F' . $lineNum, (float)$r['credit']);
            $sheet->setCellValue('G' . $lineNum, (float)$r['returns']);

            $sumBills += (int)$r['bills'];
            $sumInvoices += (float)$r['invoices'];
            $sumCash += (float)$r['cash'];
            $sumCredit += (float)$r['credit'];
            $sumReturns += (float)$r['returns'];

            $lineNum++;
        }

        // Totals Row
        $sheet->setCellValue('A' . $lineNum, 'Total');
        $sheet->setCellValue('B' . $lineNum, '');
        $sheet->setCellValue('C' . $lineNum, $sumBills);
        $sheet->setCellValue('D' . $lineNum, $sumInvoices);
        $sheet->setCellValue('E' . $lineNum, $sumCash);
        $sheet->setCellValue('F' . $lineNum, $sumCredit);
        $sheet->setCellValue('G' . $lineNum, $sumReturns);
        $sheet->getStyle('A' . $lineNum . ':G' . $lineNum)->applyFromArray($totalRowStyle);

        foreach ($cols as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        break;

    case 'customer_balances':
        $filename = "Parties_Credit_" . date('Y-m-d') . ".xlsx";
        $sheet->setTitle("Parties Credit");

        $sheet->setCellValue('A1', CLIENT_NAME . ' - PARTIES CREDIT REGISTER');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', "Report Date: " . date('d-m-Y'));
        $sheet->mergeCells('A2:F2');

        $headers = ['Sr.', 'Customer Code', 'Shop Name', 'Owner Name', 'Address / Route', 'Outstanding Amount (Rs.)'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($headers as $idx => $h) {
            $sheet->setCellValue($cols[$idx] . '4', $h);
        }
        $sheet->getStyle('A4:F4')->applyFromArray($yellowHeaderStyle);

        $rows = db_fetch_all("
            SELECT c.customer_code, c.shop_name, c.owner_name, c.address, a.area_name, c.current_balance
            FROM customers c
            LEFT JOIN areas a ON c.area_id = a.id
            WHERE c.is_deleted = 0 AND c.current_balance > 0
            ORDER BY c.current_balance DESC
        ");

        $lineNum = 5;
        $sr = 1;
        $sumBal = 0;

        foreach ($rows as $r) {
            $sheet->setCellValue('A' . $lineNum, $sr++);
            $sheet->setCellValue('B' . $lineNum, $r['customer_code']);
            $sheet->setCellValue('C' . $lineNum, $r['shop_name']);
            $sheet->setCellValue('D' . $lineNum, $r['owner_name']);
            $sheet->setCellValue('E' . $lineNum, $r['address'] ?: $r['area_name']);
            $sheet->setCellValue('F' . $lineNum, (float)$r['current_balance']);

            $sumBal += (float)$r['current_balance'];
            $lineNum++;
        }

        $sheet->setCellValue('A' . $lineNum, 'Total Parties Credit');
        $sheet->mergeCells('A' . $lineNum . ':E' . $lineNum);
        $sheet->setCellValue('F' . $lineNum, $sumBal);
        $sheet->getStyle('A' . $lineNum . ':F' . $lineNum)->applyFromArray($totalRowStyle);

        foreach ($cols as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        break;

    case 'products_inventory':
        $filename = "Products_Inventory_" . date('Y-m-d') . ".xlsx";
        $sheet->setTitle("Products & Stock");

        $sheet->setCellValue('A1', CLIENT_NAME . ' - PRODUCTS & WAREHOUSE INVENTORY');
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Item Code', 'Product Name', 'Category', 'Carton Ratio', 'Purchase Rate', 'Wholesale Rate', 'Saleable Stock (Pcs)', 'Stock in Cartons', 'Stock Value (Rs.)'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        foreach ($headers as $idx => $h) {
            $sheet->setCellValue($cols[$idx] . '3', $h);
        }
        $sheet->getStyle('A3:I3')->applyFromArray($headerStyle);

        $rows = db_fetch_all("
            SELECT 
                p.product_code, p.product_name, pc.category_name, p.carton_qty,
                p.purchase_rate, p.wholesale_rate,
                COALESCE(sb.saleable_qty, 0) as saleable_qty,
                COALESCE(sb.total_value, 0) as total_value
            FROM products p
            JOIN product_categories pc ON p.category_id = pc.id
            LEFT JOIN stock_balances sb ON p.id = sb.product_id
            WHERE p.is_deleted = 0
            ORDER BY pc.category_name ASC, p.product_name ASC
        ");

        $lineNum = 4;
        $totalVal = 0;

        foreach ($rows as $r) {
            $ratio = max(1, (float)$r['carton_qty']);
            $saleable = (float)$r['saleable_qty'];
            $ctns = floor($saleable / $ratio);
            $rem = $saleable % $ratio;
            $ctnStr = $ctns . " Ctns" . ($rem > 0 ? " + {$rem} Pcs" : "");

            $sheet->setCellValue('A' . $lineNum, $r['product_code']);
            $sheet->setCellValue('B' . $lineNum, $r['product_name']);
            $sheet->setCellValue('C' . $lineNum, $r['category_name']);
            $sheet->setCellValue('D' . $lineNum, (int)$ratio);
            $sheet->setCellValue('E' . $lineNum, (float)$r['purchase_rate']);
            $sheet->setCellValue('F' . $lineNum, (float)$r['wholesale_rate']);
            $sheet->setCellValue('G' . $lineNum, $saleable);
            $sheet->setCellValue('H' . $lineNum, $ctnStr);
            $sheet->setCellValue('I' . $lineNum, (float)$r['total_value']);

            $totalVal += (float)$r['total_value'];
            $lineNum++;
        }

        $sheet->setCellValue('A' . $lineNum, 'Total Inventory Valuation');
        $sheet->mergeCells('A' . $lineNum . ':H' . $lineNum);
        $sheet->setCellValue('I' . $lineNum, $totalVal);
        $sheet->getStyle('A' . $lineNum . ':I' . $lineNum)->applyFromArray($totalRowStyle);

        foreach ($cols as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        break;

    case 'general_ledger':
        $filename = "General_Ledger_" . $fromDate . "_to_" . $toDate . ".xlsx";
        $sheet->setTitle("General Ledger");

        $sheet->setCellValue('A1', CLIENT_NAME . ' - GENERAL LEDGER TRANSACTIONS');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Date', 'JV Number', 'Source', 'Account Code', 'Account Name', 'Narration / Description', 'Debit (Rs.)', 'Credit (Rs.)'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($headers as $idx => $h) {
            $sheet->setCellValue($cols[$idx] . '3', $h);
        }
        $sheet->getStyle('A3:H3')->applyFromArray($headerStyle);

        $rows = db_fetch_all("
            SELECT 
                je.entry_date, je.entry_number, je.reference_type,
                a.account_code, a.account_name,
                jel.description, jel.debit, jel.credit
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            JOIN accounts a ON jel.account_id = a.id
            WHERE je.entry_date >= ? AND je.entry_date <= ? AND je.is_posted = 1
            ORDER BY je.entry_date ASC, je.id ASC, jel.id ASC
        ", [$fromDate, $toDate]);

        $lineNum = 4;
        $totalD = 0; $totalC = 0;

        foreach ($rows as $r) {
            $sheet->setCellValue('A' . $lineNum, date('d-m-Y', strtotime($r['entry_date'])));
            $sheet->setCellValue('B' . $lineNum, $r['entry_number']);
            $sheet->setCellValue('C' . $lineNum, strtoupper($r['reference_type']));
            $sheet->setCellValue('D' . $lineNum, $r['account_code']);
            $sheet->setCellValue('E' . $lineNum, $r['account_name']);
            $sheet->setCellValue('F' . $lineNum, $r['description']);
            $sheet->setCellValue('G' . $lineNum, (float)$r['debit']);
            $sheet->setCellValue('H' . $lineNum, (float)$r['credit']);

            $totalD += (float)$r['debit'];
            $totalC += (float)$r['credit'];
            $lineNum++;
        }

        $sheet->setCellValue('A' . $lineNum, 'Total');
        $sheet->mergeCells('A' . $lineNum . ':F' . $lineNum);
        $sheet->setCellValue('G' . $lineNum, $totalD);
        $sheet->setCellValue('H' . $lineNum, $totalC);
        $sheet->getStyle('A' . $lineNum . ':H' . $lineNum)->applyFromArray($totalRowStyle);

        foreach ($cols as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        break;

    default:
        die("Invalid export type.");
}

// Clean output buffer before sending headers
if (ob_get_length()) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
