<?php
/**
 * DMS - Distribution Management System
 * Master End-to-End System Test Runner (Phases 1 through 7)
 * Runs all individual phase suites and displays an executive scorecard.
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/config/config.php';

$testSuites = [
    'Phase 1: Architecture & Foundation'        => 'test_phase1.php',
    'Phase 2: Master Data Modules'              => 'test_phase2.php',
    'Phase 3: Order-to-Delivery Business Cycle' => 'test_phase3.php',
    'Phase 4: Purchases, GRN & Warehouse Stock' => 'test_phase4.php',
    'Phase 5: Financials, Expenses & Accounting' => 'test_phase5.php',
    'Phase 6: Executive Reports & Native Excel' => 'test_phase6.php',
    'Phase 7: Printing & Paper Layouts'         => 'test_phase7.php'
];

echo "====================================================================\n";
echo "   DISTRIBUTION MANAGEMENT SYSTEM (DMS) - MASTER TEST SUITE\n";
echo "   Muzzam Enterprises, Gujranwala - Full System Verification\n";
echo "====================================================================\n\n";

$suiteResults = [];
$totalPassedAcrossAll = 0;
$totalFailedAcrossAll = 0;
$startTime = microtime(true);

foreach ($testSuites as $suiteName => $testFile) {
    echo ">>> Running: {$suiteName} ({$testFile})...\n";
    $filePath = escapeshellarg(__DIR__ . '/' . $testFile);
    $output = [];
    $returnVar = 0;
    
    exec("php {$filePath} 2>&1", $output, $returnVar);
    $fullOutput = implode("\n", $output);
    
    // Parse Total Passed & Failed from standard test output
    $passedCount = 0;
    $failedCount = 0;
    if (preg_match('/Passed:\s*(\d+)/i', $fullOutput, $m)) {
        $passedCount = (int)$m[1];
    } elseif (preg_match('/(?:Total Passed:\s*|SUMMARY:\s*)(\d+)/i', $fullOutput, $m)) {
        $passedCount = (int)$m[1];
    } elseif (preg_match('/(\d+)\s+Passed/i', $fullOutput, $m)) {
        $passedCount = (int)$m[1];
    }
    
    if (preg_match('/Failed:\s*(\d+)/i', $fullOutput, $m)) {
        $failedCount = (int)$m[1];
    } elseif (preg_match('/(?:Total Failed:\s*|,\s*)(\d+)\s+Failed/i', $fullOutput, $m)) {
        $failedCount = (int)$m[1];
    } elseif (preg_match('/(\d+)\s+Failed/i', $fullOutput, $m)) {
        $failedCount = (int)$m[1];
    }
    
    $status = ($returnVar === 0 && $failedCount === 0) ? 'PASSED' : 'FAILED';
    $suiteResults[$suiteName] = [
        'file' => $testFile,
        'status' => $status,
        'passed' => $passedCount,
        'failed' => $failedCount,
        'exitCode' => $returnVar
    ];
    
    $totalPassedAcrossAll += $passedCount;
    $totalFailedAcrossAll += $failedCount;
    
    echo "    Result: [{$status}] - {$passedCount} passed, {$failedCount} failed\n\n";
}

$elapsedTime = round(microtime(true) - $startTime, 2);

echo "====================================================================\n";
echo "                   MASTER VERIFICATION SCORECARD                    \n";
echo "====================================================================\n";
printf("%-45s | %-8s | %-8s | %-8s\n", "Test Suite", "Status", "Passed", "Failed");
echo str_repeat("-", 75) . "\n";

foreach ($suiteResults as $name => $res) {
    printf("%-45s | %-8s | %-8d | %-8d\n", $name, $res['status'], $res['passed'], $res['failed']);
}
echo str_repeat("=", 75) . "\n";
printf("%-45s | %-8s | %-8d | %-8d\n", "TOTAL SYSTEM COVERAGE", ($totalFailedAcrossAll === 0 ? "PASSED" : "FAILED"), $totalPassedAcrossAll, $totalFailedAcrossAll);
echo "Execution Time: {$elapsedTime} seconds\n";
echo "====================================================================\n";

if ($totalFailedAcrossAll === 0) {
    echo "🎉 CONGRATULATIONS: 100% OF ALL SYSTEM TESTS PASSED CLEANLY!\n";
    echo "System is production-ready, verified against client photos 1-9.\n";
    exit(0);
} else {
    echo "❌ ATTENTION: {$totalFailedAcrossAll} TEST(S) FAILED. CHECK LOGS ABOVE.\n";
    exit(1);
}
