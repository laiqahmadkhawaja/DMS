<?php
/**
 * DMS - Create Supplier Action
 */

require_once __DIR__ . '/../../config/config.php';
require_permission('suppliers_create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/suppliers/index.php');
}

$supplierCode   = clean_input($_POST['supplier_code'] ?? '');
$supplierName   = clean_input($_POST['supplier_name'] ?? '');
$companyName    = clean_input($_POST['company_name'] ?? '');
$contactPerson  = clean_input($_POST['contact_person'] ?? '');
$phone          = clean_input($_POST['phone'] ?? '');
$email          = clean_input($_POST['email'] ?? '');
$address        = clean_input($_POST['address'] ?? '');
$city           = clean_input($_POST['city'] ?? '');
$openingBalance = (float)($_POST['opening_balance'] ?? 0);
$notes          = clean_input($_POST['notes'] ?? '');
$isActive       = isset($_POST['is_active']) ? 1 : 0;

$validator = new Validator();
$validator->required('supplier_code', $supplierCode)
          ->unique('supplier_code', $supplierCode, 'suppliers', 'supplier_code')
          ->required('supplier_name', $supplierName)
          ->required('phone', $phone)
          ->numeric('opening_balance', $openingBalance, 0);

if ($validator->fails()) {
    set_flash_message('danger', $validator->getFirstError());
    redirect(APP_URL . '/modules/suppliers/index.php');
}

try {
    db_transaction(function ($pdo) use (
        $supplierCode, $supplierName, $companyName, $contactPerson, $phone,
        $email, $address, $city, $openingBalance, $notes, $isActive
    ) {
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (
                supplier_code, supplier_name, company_name, contact_person, phone,
                email, address, city, opening_balance, current_balance, notes, is_active, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");
        $stmt->execute([
            $supplierCode, $supplierName, $companyName, $contactPerson, $phone,
            $email, $address, $city, $openingBalance, $openingBalance, $notes, $isActive
        ]);
        $supplierId = (int)$pdo->lastInsertId();

        // If opening balance > 0, post double-entry opening journal voucher
        if ($openingBalance > 0) {
            post_journal_entry([
                'entry_date'     => date('Y-m-d'),
                'reference_type' => 'opening',
                'reference_id'   => $supplierId,
                'narration'      => "Opening Payable Balance for Supplier: {$supplierName}"
            ], [
                [
                    'account_id'  => ACC_CAPITAL,
                    'debit'       => $openingBalance,
                    'credit'      => 0,
                    'description' => "Opening debit reserve for {$supplierName}",
                    'party_type'  => null,
                    'party_id'    => null
                ],
                [
                    'account_id'  => ACC_PAYABLES,
                    'debit'       => 0,
                    'credit'      => $openingBalance,
                    'description' => "Opening payable balance for {$supplierName}",
                    'party_type'  => 'supplier',
                    'party_id'    => $supplierId
                ]
            ]);
        }

        log_audit('create_supplier', 'suppliers', $supplierId, "Created supplier: {$supplierName} ({$supplierCode})");
    });

    set_flash_message('success', "Supplier '{$supplierName}' registered successfully.");
} catch (Exception $e) {
    set_flash_message('danger', "Failed to register supplier: " . $e->getMessage());
}

redirect(APP_URL . '/modules/suppliers/index.php');
