<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

echo "Testing Validator with customer_id = 0:\n";
$v = new Validator();
$v->positiveInt('customer_id', 0, 'Please choose a Retailer / Shop before saving the order.')
  ->exists('customer_id', 0, 'customers', 'id');
print_r($v->getErrors());

if (!$v->passes() && isset($v->getErrors()['customer_id'])) {
    echo "PASS: Validator correctly caught customer_id = 0!\n";
} else {
    echo "FAIL: Validator should have failed for customer_id = 0!\n";
}

echo "\nTesting Validator with customer_id = 15 (exists in DB):\n";
$v2 = new Validator();
$v2->positiveInt('customer_id', 15)
   ->exists('customer_id', 15, 'customers', 'id');

if ($v2->passes()) {
    echo "PASS: Validator correctly passed for existing customer ID 15!\n";
} else {
    echo "FAIL: Validator failed for valid customer 15: " . print_r($v2->getErrors(), true) . "\n";
}
