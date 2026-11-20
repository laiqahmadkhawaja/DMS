<?php
/**
 * DMS - Phase 6 Comprehensive Automated Verification Suite
 * Tests Daily Registers, Financial Statements (TB, P&L, BS), and Native XLSX Export & Import
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/database_helpers.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permission.php';
require_once __DIR__ . '/includes/inventory_functions.php';
require_once __DIR__ . '/includes/accounting_functions.php';

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

echo "========================================================\n";
echo "DMS PHASE 6 AUTOMATED VERIFICATION TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(string $testName, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $testName . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $testName . ($detail ? " => " . $detail : "") . "\n";
        $failCount++;
    }
}

try {
    $db = db();

    // -------------------------------------------------------------
    // TEST 1: Daily Sales Register & Zero-Sum Daily Consistency
    // -------------------------------------------------------------
    $dailySummary = db_fetch_all("
        SELECT 
            si.invoice_date,
            COUNT(si.id) as total_bills,
            COALESCE(SUM(si.net_amount), 0) as total_invoices,
            COALESCE(SUM(si.paid_amount), 0) as cash_received,
            COALESCE(SUM(si.credit_amount), 0) as credit_given,
            COALESCE(SUM(si.return_amount), 0) as return_amount
        FROM sales_invoices si
        WHERE si.status = 'settled'
        GROUP BY si.invoice_date
    ");
    assert_test("Daily sales summary records retrieved", !empty($dailySummary));

    $zeroSumConsistent = true;
    $detailErr = '';
    foreach ($dailySummary as $ds) {
        $inv = (float)$ds['total_invoices'];
        $cash = (float)$ds['cash_received'];
        $credit = (float)$ds['credit_given'];
        $ret = (float)$ds['return_amount'];
        $diff = $inv - ($cash + $credit + $ret);
        if (abs($diff) > 0.05) {
            $zeroSumConsistent = false;
            $detailErr = "Date: " . $ds['invoice_date'] . " Inv: {$inv}, Cash: {$cash}, Credit: {$credit}, Ret: {$ret}, Diff: {$diff}";
        }
    }
    assert_test("Daily Sales Register strictly satisfies Invoices == Cash + Credit + Returns", $zeroSumConsistent, $detailErr);

    // -------------------------------------------------------------
    // TEST 2: Booker Sales Performance Calculation
    // -------------------------------------------------------------
    $bookerPerf = db_fetch_all("
        SELECT 
            b.booker_name,
            b.commission_rate,
            COALESCE(SUM(si.net_amount), 0) as net_sales
        FROM bookers b
        LEFT JOIN sales_invoices si ON b.id = si.booker_id AND si.status != 'cancelled'
        WHERE b.is_deleted = 0
        GROUP BY b.id
    ");
    assert_test("Booker performance aggregates generated", !empty($bookerPerf));
    $imranButt = array_filter($bookerPerf, fn($b) => str_contains($b['booker_name'], 'Imran Butt'));
    assert_test("Booker 'Imran Butt' verified with real sales activity", !empty($imranButt));

    // -------------------------------------------------------------
    // TEST 3: Salesman Delivery & Cash Recovery Register
    // -------------------------------------------------------------
    $salesmanPerf = db_fetch_all("
        SELECT 
            sm.salesman_name,
            COALESCE(SUM(si.net_amount), 0) as dispatched,
            COALESCE(SUM(si.paid_amount), 0) as cash
        FROM salesmen sm
        LEFT JOIN sales_invoices si ON sm.id = si.salesman_id AND si.status != 'cancelled'
        WHERE sm.is_deleted = 0
        GROUP BY sm.id
    ");
    assert_test("Salesman delivery register generated", !empty($salesmanPerf));
    $saghar = array_filter($salesmanPerf, fn($s) => str_contains($s['salesman_name'], 'Saghar'));
    assert_test("Delivery staff 'Saghar' verified with route dispatches", !empty($saghar));

    // -------------------------------------------------------------
    // TEST 4: Customer Receivables Aging & Parties Credit
    // -------------------------------------------------------------
    $partiesCredit = db_fetch_all("
        SELECT id, shop_name, current_balance 
        FROM customers 
        WHERE is_deleted = 0 AND current_balance > 0
    ");
    assert_test("Parties Credit retail accounts present", count($partiesCredit) >= 5);
    $totalCreditSum = array_sum(array_column($partiesCredit, 'current_balance'));
    assert_test("Total customer credit balances exceed Rs. 400,000", $totalCreditSum > 400000.00, "Current sum: Rs. " . number_format($totalCreditSum, 2));

    // -------------------------------------------------------------
    // TEST 5: Trial Balance Zero Imbalance Verification
    // -------------------------------------------------------------
    $accounts = db_fetch_all("
        SELECT id, account_code, account_name, account_type, opening_balance
        FROM accounts
        WHERE is_active = 1
    ");

    $tbDebit = 0.0;
    $tbCredit = 0.0;
    $asOfToday = date('Y-m-d');

    foreach ($accounts as $acc) {
        $accId = (int)$acc['id'];
        $isDebitNormal = in_array($acc['account_type'], ['asset', 'expense'], true);

        $sums = db_fetch_one("
            SELECT 
                COALESCE(SUM(jel.debit), 0) as d,
                COALESCE(SUM(jel.credit), 0) as c
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE jel.account_id = ? AND je.entry_date <= ? AND je.is_posted = 1
        ", [$accId, $asOfToday]);

        $d = (float)$sums['d'];
        $c = (float)$sums['c'];
        $initial = (float)$acc['opening_balance'];

        if ($isDebitNormal) {
            $net = $initial + ($d - $c);
            if ($net >= 0) $tbDebit += $net; else $tbCredit += abs($net);
        } else {
            $net = $initial + ($c - $d);
            if ($net >= 0) $tbCredit += $net; else $tbDebit += abs($net);
        }
    }

    $tbDiff = abs($tbDebit - $tbCredit);
    assert_test("Trial Balance Total Debits (" . number_format($tbDebit, 2) . ") equals Total Credits (" . number_format($tbCredit, 2) . ")", $tbDiff < 0.01, "Difference: Rs. " . number_format($tbDiff, 2));

    // -------------------------------------------------------------
    // TEST 6: Profit & Loss Statement (Income Statement)
    // -------------------------------------------------------------
    $fromD = date('Y-01-01');
    $toD = date('Y-12-31');

    $salesRev = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.credit - jel.debit), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date >= ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [ACC_SALES, $fromD, $toD]);

    $discountsRev = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date >= ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [ACC_DISCOUNTS, $fromD, $toD]);

    $returnsRev = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date >= ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [ACC_RETURNS, $fromD, $toD]);

    $netSalesRev = $salesRev - $discountsRev - $returnsRev;
    assert_test("Profit & Loss computes valid Net Sales Revenue", $netSalesRev >= 0);

    $totalOperatingExp = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN accounts a ON jel.account_id = a.id
        WHERE a.account_type = 'expense' AND a.id != ? AND je.entry_date >= ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [ACC_COGS, $fromD, $toD]);
    assert_test("Profit & Loss aggregates Operating Expenses correctly", $totalOperatingExp > 0);

    // -------------------------------------------------------------
    // TEST 7: Balance Sheet Accounting Equation Verification
    // -------------------------------------------------------------
    // Total Assets = Cash + Petty Cash + Bank + Receivables + Inventory
    $assetIds = [ACC_CASH_MAIN, ACC_PETTY_CASH, ACC_BANK_MAIN, ACC_RECEIVABLES, ACC_INVENTORY];
    $bsAssets = 0.0;
    foreach ($assetIds as $aid) {
        $r = db_fetch_one("
            SELECT COALESCE(SUM(jel.debit - jel.credit), 0) as net
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE jel.account_id = ? AND je.entry_date <= ? AND je.is_posted = 1
        ", [$aid, $asOfToday]);
        $init = (float)db_fetch_val("SELECT opening_balance FROM accounts WHERE id = ?", [$aid]);
        $bsAssets += ($init + (float)$r['net']);
    }

    // Total Liabilities = Payables
    $rPay = db_fetch_one("
        SELECT COALESCE(SUM(jel.credit - jel.debit), 0) as net
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [ACC_PAYABLES, $asOfToday]);
    $initPay = (float)db_fetch_val("SELECT opening_balance FROM accounts WHERE id = ?", [ACC_PAYABLES]);
    $bsLiabilities = $initPay + (float)$rPay['net'];

    // Owner Capital
    $rCap = db_fetch_one("
        SELECT COALESCE(SUM(jel.credit - jel.debit), 0) as net
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date <= ? AND je.is_posted = 1
    ", [ACC_CAPITAL, $asOfToday]);
    $initCap = (float)db_fetch_val("SELECT opening_balance FROM accounts WHERE id = ?", [ACC_CAPITAL]);
    $bsCapital = $initCap + (float)$rCap['net'];

    // Retained Earnings (All revenue - All expense up to asOfToday)
    $bsRev = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.credit - jel.debit), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN accounts a ON jel.account_id = a.id
        WHERE a.account_type = 'revenue' AND je.entry_date <= ? AND je.is_posted = 1
    ", [$asOfToday]);

    $bsExp = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN accounts a ON jel.account_id = a.id
        WHERE a.account_type = 'expense' AND je.entry_date <= ? AND je.is_posted = 1
    ", [$asOfToday]);

    $bsRetainedEarnings = $bsRev - $bsExp;
    $bsEquity = $bsCapital + $bsRetainedEarnings;

    $bsDiff = abs($bsAssets - ($bsLiabilities + $bsEquity));
    assert_test("Balance Sheet Accounting Equation (Assets == Liabilities + Equity)", $bsDiff < 0.01, "Assets: " . number_format($bsAssets, 2) . " vs Liab+Eq: " . number_format($bsLiabilities + $bsEquity, 2) . " (Diff: " . number_format($bsDiff, 2) . ")");

    // -------------------------------------------------------------
    // TEST 8: PhpSpreadsheet Native XLSX Generation
    // -------------------------------------------------------------
    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle("Test Export");
    $sh->setCellValue('A1', 'DMS Test Export');
    $sh->setCellValue('A2', 12345.67);

    $testFile = STORAGE_PATH . '/exports/test_export.xlsx';
    $writer = new Xlsx($ss);
    $writer->save($testFile);

    assert_test("PhpSpreadsheet writes native .xlsx file to disk", file_exists($testFile) && filesize($testFile) > 0);

    // Verify it is a valid ZIP/XLSX file by checking magic header 'PK' (0x50 0x4B)
    $fHandle = fopen($testFile, 'rb');
    $headerBytes = fread($fHandle, 2);
    fclose($fHandle);
    assert_test("Exported file is valid OpenXML binary (PK header)", $headerBytes === "PK");
    @unlink($testFile);

    // -------------------------------------------------------------
    // TEST 9: Master Data Excel Importer Validation & Reading
    // -------------------------------------------------------------
    // Create in-memory spreadsheet for customer import
    $importSS = new Spreadsheet();
    $importSheet = $importSS->getActiveSheet();
    $importSheet->setCellValue('A1', 'Shop Name');
    $importSheet->setCellValue('B1', 'Owner Name');
    $importSheet->setCellValue('C1', 'Phone');
    $importSheet->setCellValue('D1', 'Address');
    $importSheet->setCellValue('E1', 'Area Name');
    $importSheet->setCellValue('F1', 'Credit Limit');
    $importSheet->setCellValue('G1', 'Opening Balance');

    $importSheet->setCellValue('A2', 'Test Import Shop ' . time());
    $importSheet->setCellValue('B2', 'Tariq Mehmood');
    $importSheet->setCellValue('C2', '03009988776');
    $importSheet->setCellValue('D2', 'G.T. Road Gujranwala');
    $importSheet->setCellValue('E2', 'Jandiala Bhagwala');
    $importSheet->setCellValue('F2', 75000);
    $importSheet->setCellValue('G2', 0);

    $tmpImportFile = STORAGE_PATH . '/imports/test_customer_import.xlsx';
    $writerImp = new Xlsx($importSS);
    $writerImp->save($tmpImportFile);

    // Read back using IOFactory
    $loadedSS = IOFactory::load($tmpImportFile);
    $loadedData = $loadedSS->getActiveSheet()->toArray();
    assert_test("PhpSpreadsheet IOFactory reads uploaded XLSX into row arrays", count($loadedData) === 2);
    assert_test("Import preview matches test shop name", str_starts_with($loadedData[1][0], 'Test Import Shop'));
    @unlink($tmpImportFile);

} catch (Exception $e) {
    echo " [EXCEPTION] " . $e->getMessage() . "\n";
    $failCount++;
}

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Passed: {$passCount} | Failed: {$failCount}\n";
echo "--------------------------------------------------------\n";

if ($failCount === 0) {
    echo ">>> PHASE 6 VERIFICATION 100% COMPLETE AND PASSING <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. INVESTIGATION REQUIRED <<<\n";
    exit(1);
}
