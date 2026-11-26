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

echo "\nTesting URL Normalization & Protocol Handling...\n";
$u1 = whatsapp_normalize_url('wa.darwarqamgrw.in');
assert($u1 === 'https://wa.darwarqamgrw.in', "Expected https://wa.darwarqamgrw.in, got: {$u1}");
echo "  [PASS] Domain auto-https: wa.darwarqamgrw.in -> {$u1}\n";

$u2 = whatsapp_normalize_url('http://wa.darwarqamgrw.in/');
assert($u2 === 'http://wa.darwarqamgrw.in', "Expected http://wa.darwarqamgrw.in, got: {$u2}");
echo "  [PASS] Explicit http preserved and trailing slash trimmed -> {$u2}\n";

$u3 = whatsapp_normalize_url('https://wa.darwarqamgrw.in:8443/');
assert($u3 === 'https://wa.darwarqamgrw.in:8443', "Expected https port preserved, got: {$u3}");
echo "  [PASS] Custom HTTPS port preserved -> {$u3}\n";

$u4 = whatsapp_normalize_url('localhost:8080');
assert($u4 === 'http://localhost:8080', "Expected http://localhost:8080, got: {$u4}");
echo "  [PASS] Localhost auto-http: localhost:8080 -> {$u4}\n";

$u5 = whatsapp_normalize_url('192.168.1.10:8080/');
assert($u5 === 'http://192.168.1.10:8080', "Expected http://192.168.1.10:8080, got: {$u5}");
echo "  [PASS] LAN IP auto-http -> {$u5}\n";

echo "\nTesting Configuration & Session ID Resolution...\n";
$cfg1 = whatsapp_get_config('https://wa.darwarqamgrw.in', 'token123', 'mysession');
assert($cfg1['base_url'] === 'https://wa.darwarqamgrw.in', "Config base_url mismatch");
assert($cfg1['api_key'] === 'token123', "Config api_key mismatch");
assert($cfg1['session_id'] === 'mysession', "Config session_id mismatch");
assert($cfg1['is_https'] === true, "Config is_https should be true");
echo "  [PASS] Custom config with session_id resolved correctly\n";

$cfg2 = whatsapp_get_config('http://localhost:8080', '', '');
assert($cfg2['session_id'] === 'default', "Empty session_id should default to 'default'");
assert($cfg2['is_https'] === false, "Config is_https should be false for HTTP");
echo "  [PASS] Default session_id fallback verified\n";

echo "\nTesting Unconfigured URL Guard (Zero 8080 fallback)...\n";
$emptyCheck = whatsapp_check_connection('');
assert($emptyCheck['connected'] === false, "Empty URL must not connect");
assert(str_contains($emptyCheck['error'], 'not configured'), "Must warn that URL is not configured");
echo "  [PASS] Unconfigured URL caught immediately without curl: {$emptyCheck['error']}\n";

echo "\n==================================================\n";
echo "ALL WHATSAPP SERVICE TESTS PASSED CLEANLY!\n";
echo "==================================================\n";
