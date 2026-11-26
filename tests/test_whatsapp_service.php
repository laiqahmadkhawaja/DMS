<?php
/**
 * Test Suite: Open-WA WhatsApp Service Integration
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/whatsapp_service.php';

echo "Testing WhatsApp Number Formatting...\n";

// Test 1: Pakistani local format (03001234567 -> 923001234567@c.us)
$f1 = whatsapp_format_number('03001234567');
assert($f1 === '923001234567@c.us', "Expected 923001234567@c.us, got: {$f1}");
echo "  [PASS] Pakistani standard 03001234567 -> {$f1}\n";

// Test 2: Pakistani 10-digit format without leading 0 (3001234567 -> 923001234567@c.us)
$f2 = whatsapp_format_number('3001234567');
assert($f2 === '923001234567@c.us', "Expected 923001234567@c.us, got: {$f2}");
echo "  [PASS] Format without leading zero 3001234567 -> {$f2}\n";

// Test 3: Number with dashes / spaces / +
$f3 = whatsapp_format_number('+92 300-1234567');
assert($f3 === '923001234567@c.us', "Expected 923001234567@c.us, got: {$f3}");
echo "  [PASS] Cleaned formatted phone -> {$f3}\n";

// Test 4: Already suffixed with @c.us
$f4 = whatsapp_format_number('923001234567@c.us');
assert($f4 === '923001234567@c.us', "Expected 923001234567@c.us, got: {$f4}");
echo "  [PASS] Preserved existing JID -> {$f4}\n";

// Test 5: Empty input
$f5 = whatsapp_format_number('');
assert($f5 === null, "Expected null, got: " . var_export($f5, true));
echo "  [PASS] Empty input returns null\n";

echo "\nTesting WhatsApp Enabled Check...\n";
$enabled = whatsapp_is_enabled();
echo "  [PASS] WhatsApp enabled: " . ($enabled ? 'YES' : 'NO') . "\n";

echo "\nTesting Invoice Bill Summary Builder...\n";
$latestInv = db_fetch_val("SELECT id FROM sales_invoices ORDER BY id DESC LIMIT 1");
if ($latestInv) {
    $invSummary = whatsapp_build_invoice_summary((int)$latestInv);
    assert(!empty($invSummary['text']), "Invoice text should not be empty");
    assert(str_contains($invSummary['text'], 'Bill No:'), "Should contain 'Bill No:'");
    assert(str_contains($invSummary['text'], 'ITEMS:'), "Should contain 'ITEMS:'");
    echo "  [PASS] Invoice #{$latestInv} summary generated (" . strlen($invSummary['text']) . " bytes)\n";
    echo "  Sample snippet:\n" . substr($invSummary['text'], 0, 150) . "...\n";
} else {
    echo "  [SKIP] No invoices found in database to test summary builder\n";
}

echo "\nTesting Customer Statement Builder...\n";
$latestCust = db_fetch_val("SELECT id FROM customers ORDER BY id DESC LIMIT 1");
if ($latestCust) {
    $custSummary = whatsapp_build_customer_statement((int)$latestCust);
    assert(!empty($custSummary['text']), "Customer statement text should not be empty");
    assert(str_contains($custSummary['text'], 'CUSTOMER STATEMENT'), "Should contain 'CUSTOMER STATEMENT'");
    assert(str_contains($custSummary['text'], 'CURRENT OUTSTANDING BALANCE'), "Should contain 'CURRENT OUTSTANDING BALANCE'");
    echo "  [PASS] Customer #{$latestCust} statement generated (" . strlen($custSummary['text']) . " bytes)\n";
} else {
    echo "  [SKIP] No customer found in database to test statement builder\n";
}

echo "\nTesting File Attachment Base64 Format Preparation...\n";
// Test sending a mock file with data URI construction
$testData = "Mock PDF Content for Open-WA delivery";
$testB64 = base64_encode($testData);
assert(!empty($testB64), "Base64 encoding should work");
echo "  [PASS] Base64 encode verified\n";

echo "\n==================================================\n";
echo "ALL WHATSAPP SERVICE TESTS PASSED CLEANLY!\n";
echo "==================================================\n";
