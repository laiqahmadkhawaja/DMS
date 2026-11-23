<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

echo "=== MIGRATION: Partners & Shared Expense Khatas ===\n";

try {
    // 1. Create partners table
    db()->exec("
        CREATE TABLE IF NOT EXISTS `partners` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `partner_code` varchar(30) NOT NULL UNIQUE,
            `partner_name` varchar(100) NOT NULL,
            `urdu_name` varchar(100) DEFAULT NULL,
            `role` enum('partner','managing_partner') NOT NULL DEFAULT 'partner',
            `supplier_id` int(10) unsigned DEFAULT NULL,
            `phone` varchar(30) DEFAULT NULL,
            `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Positive = Payable to partner, Negative = Partner owes',
            `notes` text DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `fk_partner_supplier` (`supplier_id`),
            CONSTRAINT `fk_partner_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "1. Table 'partners' created/verified.\n";

    // 2. Create partner_transactions table
    db()->exec("
        CREATE TABLE IF NOT EXISTS `partner_transactions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `transaction_number` varchar(40) NOT NULL UNIQUE,
            `transaction_date` date NOT NULL,
            `partner_id` int(10) unsigned NOT NULL,
            `transaction_type` enum('expense_paid','expense_share','cash_drawn','sales_profit','capital_added','inter_settlement','adjustment') NOT NULL,
            `reference_type` varchar(40) NOT NULL DEFAULT 'manual',
            `reference_id` bigint(20) unsigned DEFAULT NULL,
            `debit` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount charged to partner (drawings, share of expense)',
            `credit` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount credited to partner (paid expense, profit share, capital)',
            `running_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
            `description` text DEFAULT NULL,
            `created_by` bigint(20) unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_ptr_partner` (`partner_id`),
            KEY `idx_ptr_date` (`transaction_date`),
            KEY `idx_ptr_ref` (`reference_type`, `reference_id`),
            CONSTRAINT `fk_ptr_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "2. Table 'partner_transactions' created/verified.\n";

    // 3. Add columns to expenses table if not exist
    $columns = db_fetch_all("SHOW COLUMNS FROM expenses");
    $colNames = array_column($columns, 'Field');

    if (!in_array('paid_by_type', $colNames)) {
        db()->exec("ALTER TABLE expenses ADD COLUMN `paid_by_type` enum('central_cash','partner') NOT NULL DEFAULT 'central_cash' AFTER `payment_mode`");
        echo "   - Added 'paid_by_type' to expenses.\n";
    }
    if (!in_array('paid_by_partner_id', $colNames)) {
        db()->exec("ALTER TABLE expenses ADD COLUMN `paid_by_partner_id` int(10) unsigned DEFAULT NULL AFTER `paid_by_type`");
        echo "   - Added 'paid_by_partner_id' to expenses.\n";
    }
    if (!in_array('split_type', $colNames)) {
        db()->exec("ALTER TABLE expenses ADD COLUMN `split_type` enum('equal_50_50','only_moazzam','only_rehman','custom','none') NOT NULL DEFAULT 'equal_50_50' AFTER `paid_by_partner_id`");
        echo "   - Added 'split_type' to expenses.\n";
    }
    if (!in_array('split_details', $colNames)) {
        db()->exec("ALTER TABLE expenses ADD COLUMN `split_details` text DEFAULT NULL AFTER `split_type`");
        echo "   - Added 'split_details' to expenses.\n";
    }
    echo "3. Expenses table columns verified.\n";

    // 4. Seed initial 3 Partners
    // Check SMS Supplier ID & Ujab Supplier ID
    $smsSupId = (int)db_fetch_val("SELECT id FROM suppliers WHERE supplier_name LIKE '%SMS%' OR supplier_code LIKE '%SMS%' LIMIT 1") ?: 2;
    $ujabSupId = (int)db_fetch_val("SELECT id FROM suppliers WHERE supplier_name LIKE '%Ujab%' OR supplier_code LIKE '%UJAB%' LIMIT 1") ?: 1;

    $partnersData = [
        [
            'partner_code' => 'PTR-MOAZZAM',
            'partner_name' => 'Moazzam',
            'urdu_name'    => 'معظم (ایس ایم ایس)',
            'role'         => 'partner',
            'supplier_id'  => $smsSupId,
            'notes'        => 'Partner owning and managing SMS Agency & products.'
        ],
        [
            'partner_code' => 'PTR-REHMAN',
            'partner_name' => 'Abdur Rehman',
            'urdu_name'    => 'عبدالرحمن (اوچاب)',
            'role'         => 'partner',
            'supplier_id'  => $ujabSupId,
            'notes'        => 'Partner owning and managing Ujab Enterprises Agency & products.'
        ],
        [
            'partner_code' => 'PTR-CENTRAL',
            'partner_name' => 'Managing Partner (Central Sales Pool)',
            'urdu_name'    => 'تھرڈ پارٹنر (سیلز و کیش کسٹوڈین)',
            'role'         => 'managing_partner',
            'supplier_id'  => null,
            'notes'        => 'Managing partner overseeing total sales, recovery, and central operations cash pool.'
        ]
    ];

    $stmtInsertPartner = db()->prepare("
        INSERT INTO partners (partner_code, partner_name, urdu_name, role, supplier_id, notes, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            partner_name = VALUES(partner_name),
            urdu_name = VALUES(urdu_name),
            role = VALUES(role),
            supplier_id = VALUES(supplier_id),
            notes = VALUES(notes)
    ");

    foreach ($partnersData as $pd) {
        $stmtInsertPartner->execute([
            $pd['partner_code'],
            $pd['partner_name'],
            $pd['urdu_name'],
            $pd['role'],
            $pd['supplier_id'],
            $pd['notes']
        ]);
    }
    echo "4. 3 Initial Partners seeded successfully.\n";

    // 5. Ensure permissions
    $permExists = db_fetch_val("SELECT COUNT(*) FROM permissions WHERE module = 'partners'");
    if ($permExists == 0) {
        db()->exec("
            INSERT IGNORE INTO permissions (module, permission_code, permission_name, description) VALUES
            ('partners', 'partners_view', 'View Partner Khatas', 'View partner ledger statements and balances'),
            ('partners', 'partners_manage', 'Manage Partners & Drawings', 'Record partner drawings, settlements and expense splits')
        ");
        // Grant to role 1 (Admin)
        $adminRoleId = (int)db_fetch_val("SELECT id FROM roles WHERE role_code = 'admin' LIMIT 1") ?: 1;
        $newPerms = db_fetch_all("SELECT id FROM permissions WHERE module = 'partners'");
        foreach ($newPerms as $np) {
            db()->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ({$adminRoleId}, {$np['id']})");
        }
        echo "5. Partner permissions added.\n";
    }

    echo "=== MIGRATION COMPLETED SUCCESSFULLY! ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
