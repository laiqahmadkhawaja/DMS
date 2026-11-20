<?php
/**
 * DMS - Seed Real-World Test Masters
 * Populates exact shops, products, bookers, salesmen, and routes from client sheets
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';

echo "Seeding Real Distribution Master Data...\n";

// 1. Areas / Routes
$areasData = [
    ['area_code' => 'AREA-001', 'area_name' => 'Jandiala Bhagwala', 'city' => 'Gujranwala', 'description' => 'G.T. Road Main Route'],
    ['area_code' => 'AREA-002', 'area_name' => 'Daal Bazar', 'city' => 'Gujranwala', 'description' => 'Old City Wholesale Market'],
    ['area_code' => 'AREA-003', 'area_name' => 'Rahwali', 'city' => 'Gujranwala', 'description' => 'Cantonment / Highway Route'],
    ['area_code' => 'AREA-004', 'area_name' => 'Satellite Town', 'city' => 'Gujranwala', 'description' => 'Residential & Commercial Hub'],
    ['area_code' => 'AREA-005', 'area_name' => 'Rana Bhatti Bhango', 'city' => 'Gujranwala', 'description' => 'Rural Outer Delivery Beat'],
    ['area_code' => 'AREA-006', 'area_name' => 'Dholay / Peoples Colony', 'city' => 'Ghakar', 'description' => 'Ghakar Mandi Beat']
];

$areaIdMap = [];
$stmtArea = db()->prepare("
    INSERT INTO areas (area_code, area_name, city, description, is_active, created_at)
    VALUES (?, ?, ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE area_name = VALUES(area_name), city = VALUES(city)
");

foreach ($areasData as $a) {
    $stmtArea->execute([$a['area_code'], $a['area_name'], $a['city'], $a['description']]);
    $id = (int)db_fetch_val("SELECT id FROM areas WHERE area_code = ?", [$a['area_code']]);
    $areaIdMap[$a['area_name']] = $id;
}
echo "  [OK] Seeded 6 Areas / Routes.\n";

// 2. Bookers
$bookersData = [
    ['booker_code' => 'BKR-001', 'booker_name' => 'Imran Butt', 'phone' => '0300-6732761', 'commission_rate' => 1.5, 'notes' => 'Senior Market Booker for Daal Bazar & Jandiala'],
    ['booker_code' => 'BKR-002', 'booker_name' => 'Abdur Rehman', 'phone' => '0301-7654321', 'commission_rate' => 1.5, 'notes' => 'Route Booker for Rahwali & Satellite Town']
];

$bookerIdMap = [];
$stmtBooker = db()->prepare("
    INSERT INTO bookers (booker_code, booker_name, phone, commission_rate, notes, is_active, created_at)
    VALUES (?, ?, ?, ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE booker_name = VALUES(booker_name), phone = VALUES(phone)
");

foreach ($bookersData as $b) {
    $stmtBooker->execute([$b['booker_code'], $b['booker_name'], $b['phone'], $b['commission_rate'], $b['notes']]);
    $id = (int)db_fetch_val("SELECT id FROM bookers WHERE booker_code = ?", [$b['booker_code']]);
    $bookerIdMap[$b['booker_name']] = $id;
}
echo "  [OK] Seeded 2 Order Bookers (Imran Butt, Abdur Rehman).\n";

// 3. Salesmen / Delivery Staff
$salesmenData = [
    ['employee_code' => 'EMP-001', 'salesman_name' => 'Saghar', 'phone' => '0302-1122334', 'vehicle_number' => 'GAK-4580 (Suzuki)', 'role' => 'delivery_man'],
    ['employee_code' => 'EMP-002', 'salesman_name' => 'Muzamal Shah', 'phone' => '0303-9988776', 'vehicle_number' => 'GAK-9821 (Van)', 'role' => 'delivery_man']
];

$salesmanIdMap = [];
$stmtSm = db()->prepare("
    INSERT INTO salesmen (employee_code, salesman_name, phone, vehicle_number, role, is_active, created_at)
    VALUES (?, ?, ?, ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE salesman_name = VALUES(salesman_name), vehicle_number = VALUES(vehicle_number)
");

foreach ($salesmenData as $sm) {
    $stmtSm->execute([$sm['employee_code'], $sm['salesman_name'], $sm['phone'], $sm['vehicle_number'], $sm['role']]);
    $id = (int)db_fetch_val("SELECT id FROM salesmen WHERE employee_code = ?", [$sm['employee_code']]);
    $salesmanIdMap[$sm['salesman_name']] = $id;
}
echo "  [OK] Seeded 2 Delivery Salesmen (Saghar, Muzamal Shah).\n";

// 4. Product Categories
$catData = [
    ['category_code' => 'CAT-NIM', 'category_name' => 'Nimko & Savouries', 'description' => 'Daal moth, mix nimko, salted snacks'],
    ['category_code' => 'CAT-SNK', 'category_name' => 'Snacks & Puffs', 'description' => 'Ring puffs, chips, extruded snacks'],
    ['category_code' => 'CAT-CND', 'category_name' => 'Confectionery', 'description' => 'Candies, toffees, sweets'],
    ['category_code' => 'CAT-GRC', 'category_name' => 'Grocery & Pickles', 'description' => 'Vermicelli, mixed pickles in oil']
];

$catIdMap = [];
$stmtCat = db()->prepare("
    INSERT INTO product_categories (category_code, category_name, description, is_active, created_at)
    VALUES (?, ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE category_name = VALUES(category_name)
");

foreach ($catData as $c) {
    $stmtCat->execute([$c['category_code'], $c['category_name'], $c['description']]);
    $id = (int)db_fetch_val("SELECT id FROM product_categories WHERE category_code = ?", [$c['category_code']]);
    $catIdMap[$c['category_code']] = $id;
}
echo "  [OK] Seeded 4 Product Categories.\n";

// 5. Products Catalog
$productsData = [
    [
        'product_code' => 'PRD-0001', 'product_name' => 'Nimko Daal Moth (1*36)',
        'cat_code' => 'CAT-NIM', 'carton_qty' => 36, 'purchase_rate' => 1350, 'wholesale_rate' => 1512, 'default_sale_rate' => 1512,
        'stock' => 120
    ],
    [
        'product_code' => 'PRD-0002', 'product_name' => 'Nimko Daal Moth (1*24)',
        'cat_code' => 'CAT-NIM', 'carton_qty' => 24, 'purchase_rate' => 1980, 'wholesale_rate' => 2208, 'default_sale_rate' => 2208,
        'stock' => 150
    ],
    [
        'product_code' => 'PRD-0003', 'product_name' => 'Nimko Mix (1*36)',
        'cat_code' => 'CAT-NIM', 'carton_qty' => 36, 'purchase_rate' => 1350, 'wholesale_rate' => 1512, 'default_sale_rate' => 1512,
        'stock' => 100
    ],
    [
        'product_code' => 'PRD-0004', 'product_name' => 'Nimko Mix (1*24)',
        'cat_code' => 'CAT-NIM', 'carton_qty' => 24, 'purchase_rate' => 1980, 'wholesale_rate' => 2208, 'default_sale_rate' => 2208,
        'stock' => 120
    ],
    [
        'product_code' => 'PRD-0005', 'product_name' => 'Zoro Ring Puff BBQ (1*12)',
        'cat_code' => 'CAT-SNK', 'carton_qty' => 12, 'purchase_rate' => 640, 'wholesale_rate' => 720, 'default_sale_rate' => 720,
        'stock' => 200
    ],
    [
        'product_code' => 'PRD-0006', 'product_name' => 'Booster Candy',
        'cat_code' => 'CAT-CND', 'carton_qty' => 10, 'purchase_rate' => 6200, 'wholesale_rate' => 7000, 'default_sale_rate' => 7000,
        'stock' => 50
    ],
    [
        'product_code' => 'PRD-0007', 'product_name' => 'Color Vermicelli',
        'cat_code' => 'CAT-GRC', 'carton_qty' => 20, 'purchase_rate' => 1800, 'wholesale_rate' => 2100, 'default_sale_rate' => 2100,
        'stock' => 80
    ],
    [
        'product_code' => 'PRD-0008', 'product_name' => 'Mix Pickle in Oil Small',
        'cat_code' => 'CAT-GRC', 'carton_qty' => 12, 'purchase_rate' => 2400, 'wholesale_rate' => 2800, 'default_sale_rate' => 2800,
        'stock' => 60
    ]
];

$unitCtn = (int)db_fetch_val("SELECT id FROM units WHERE unit_code = 'CTN'");

foreach ($productsData as $pd) {
    $existing = db_fetch_val("SELECT id FROM products WHERE product_code = ?", [$pd['product_code']]);
    if ($existing) {
        $prodId = (int)$existing;
        db()->prepare("UPDATE products SET wholesale_rate = ?, default_sale_rate = ?, purchase_rate = ? WHERE id = ?")
            ->execute([$pd['wholesale_rate'], $pd['default_sale_rate'], $pd['purchase_rate'], $prodId]);
            
        $curQty = (float)db_fetch_val("SELECT saleable_qty FROM stock_balances WHERE product_id = ? AND warehouse_id = 1", [$prodId]);
        if ($curQty < $pd['stock']) {
            $diff = $pd['stock'] - $curQty;
            record_stock_movement([
                'product_id'     => $prodId,
                'warehouse_id'   => 1,
                'movement_type'  => 'opening_stock',
                'reference_type' => 'opening',
                'reference_id'   => $prodId,
                'qty_in'         => $diff,
                'qty_out'        => 0,
                'unit_cost'      => $pd['purchase_rate'],
                'remarks'        => 'Restored master opening inventory'
            ]);
        }
    } else {
        $stmt = db()->prepare("
            INSERT INTO products (
                product_code, product_name, category_id, base_unit_id,
                purchase_rate, default_sale_rate, wholesale_rate, retail_rate,
                carton_qty, pack_qty, min_stock_level, is_active, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, 1, 10, 1, NOW()
            )
        ");
        $stmt->execute([
            $pd['product_code'], $pd['product_name'], $catIdMap[$pd['cat_code']], $unitCtn,
            $pd['purchase_rate'], $pd['default_sale_rate'], $pd['wholesale_rate'], $pd['default_sale_rate'],
            $pd['carton_qty']
        ]);
        $prodId = (int)db()->lastInsertId();

        // Record opening stock
        record_stock_movement([
            'product_id'     => $prodId,
            'warehouse_id'   => 1,
            'movement_type'  => 'opening_stock',
            'reference_type' => 'opening',
            'reference_id'   => $prodId,
            'qty_in'         => $pd['stock'],
            'qty_out'        => 0,
            'unit_cost'      => $pd['purchase_rate'],
            'remarks'        => 'Master opening inventory'
        ]);
    }
}
echo "  [OK] Seeded 8 Products with Opening Stock & Packaging Ratios.\n";

// 6. Customers / Retailers with Initial Balances
$customersData = [
    ['code' => 'CUST-00001', 'shop' => 'Prince Grocery', 'owner' => 'Mian Prince', 'area' => 'Jandiala Bhagwala', 'booker' => 'Imran Butt', 'phone' => '0300-1111111', 'address' => 'Jandiala Bhagwala G.T. Road', 'opening_bal' => 9600.00],
    ['code' => 'CUST-00002', 'shop' => 'Ramzan Sweets', 'owner' => 'Mian Ramzan', 'area' => 'Daal Bazar', 'booker' => 'Imran Butt', 'phone' => '0300-2222222', 'address' => 'Daal Bazar Gujranwala', 'opening_bal' => 35000.00],
    ['code' => 'CUST-00003', 'shop' => 'Usman J.S', 'owner' => 'Usman', 'area' => 'Daal Bazar', 'booker' => 'Imran Butt', 'phone' => '0300-3333333', 'address' => 'Daal Bazar', 'opening_bal' => 2160.00],
    ['code' => 'CUST-00004', 'shop' => 'Waqas Super Store', 'owner' => 'Waqas', 'area' => 'Daal Bazar', 'booker' => 'Imran Butt', 'phone' => '0300-4444444', 'address' => 'Near Chungi Daal Bazar', 'opening_bal' => 0.00],
    ['code' => 'CUST-00005', 'shop' => 'Hafiz Super Store', 'owner' => 'Hafiz Sb', 'area' => 'Satellite Town', 'booker' => 'Abdur Rehman', 'phone' => '0300-5555555', 'address' => 'Main Market Satellite Town', 'opening_bal' => 15390.00],
    ['code' => 'CUST-00006', 'shop' => 'Rehman Super Store', 'owner' => 'Abdul Rehman', 'area' => 'Satellite Town', 'booker' => 'Abdur Rehman', 'phone' => '0300-6666666', 'address' => 'Block C Satellite Town', 'opening_bal' => 0.00],
    ['code' => 'CUST-00007', 'shop' => 'Akram Super Store', 'owner' => 'Mian Akram', 'area' => 'Daal Bazar', 'booker' => 'Imran Butt', 'phone' => '0300-7777777', 'address' => 'Circular Road Daal Bazar', 'opening_bal' => 140000.00],
    ['code' => 'CUST-00008', 'shop' => 'Mamu Gee Super Store', 'owner' => 'Mamu Gee', 'area' => 'Rahwali', 'booker' => 'Abdur Rehman', 'phone' => '0300-8888888', 'address' => 'Main Bazar Rahwali Cantt', 'opening_bal' => 1640.00],
    ['code' => 'CUST-00009', 'shop' => 'Latif Cash & Carry', 'owner' => 'Chaudhry Latif', 'area' => 'Rahwali', 'booker' => 'Abdur Rehman', 'phone' => '0300-9999999', 'address' => 'G.T. Road Rahwali', 'opening_bal' => 14310.00],
    ['code' => 'CUST-00010', 'shop' => 'Muzzum Shah', 'owner' => 'Muzzum Shah', 'area' => 'Rana Bhatti Bhango', 'booker' => 'Imran Butt', 'phone' => '0300-0001111', 'address' => 'Rana Bhatti Bhango', 'opening_bal' => 138408.00],
    ['code' => 'CUST-00011', 'shop' => 'Mujahid Shah', 'owner' => 'Mujahid Shah', 'area' => 'Satellite Town', 'booker' => 'Abdur Rehman', 'phone' => '0300-0002222', 'address' => 'Satellite Town', 'opening_bal' => 21546.00],
    ['code' => 'CUST-00012', 'shop' => 'Ali Traders', 'owner' => 'Ali', 'area' => 'Daal Bazar', 'booker' => 'Imran Butt', 'phone' => '0300-0003333', 'address' => 'Daal Bazar', 'opening_bal' => 14000.00],
    ['code' => 'CUST-00013', 'shop' => 'Moeen', 'owner' => 'Moeen Sb', 'area' => 'Dholay / Peoples Colony', 'booker' => 'Abdur Rehman', 'phone' => '0300-0004444', 'address' => 'Dholay, Peoples Colony, Ghakar', 'opening_bal' => 17940.00],
    ['code' => 'CUST-00014', 'shop' => 'Ahmed Super Store', 'owner' => 'Ahmed', 'area' => 'Rahwali', 'booker' => 'Abdur Rehman', 'phone' => '0300-0005555', 'address' => 'Rahwali', 'opening_bal' => 83365.00]
];

foreach ($customersData as $cd) {
    $existing = db_fetch_val("SELECT id FROM customers WHERE customer_code = ?", [$cd['code']]);
    if (!$existing) {
        $areaId = $areaIdMap[$cd['area']] ?? 1;
        $bookerId = $bookerIdMap[$cd['booker']] ?? null;

        $stmt = db()->prepare("
            INSERT INTO customers (
                customer_code, shop_name, owner_name, phone, address,
                area_id, booker_id, credit_limit, credit_days, opening_balance,
                current_balance, default_discount_percent, is_active, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, 150000, 7, ?,
                ?, 0, 1, NOW()
            )
        ");
        $stmt->execute([
            $cd['code'], $cd['shop'], $cd['owner'], $cd['phone'], $cd['address'],
            $areaId, $bookerId, $cd['opening_bal'], $cd['opening_bal']
        ]);
        $custId = (int)db()->lastInsertId();

        // If opening balance > 0, post double-entry opening journal entry
        if ($cd['opening_bal'] > 0) {
            post_journal_entry([
                'entry_date'     => date('Y-m-d'),
                'reference_type' => 'opening',
                'reference_id'   => $custId,
                'narration'      => "Opening Receivable Balance for Retailer: {$cd['shop']}"
            ], [
                [
                    'account_id'  => ACC_RECEIVABLES,
                    'debit'       => $cd['opening_bal'],
                    'credit'      => 0,
                    'description' => "Opening debit for {$cd['shop']}",
                    'party_type'  => 'customer',
                    'party_id'    => $custId
                ],
                [
                    'account_id'  => ACC_CAPITAL,
                    'debit'       => 0,
                    'credit'      => $cd['opening_bal'],
                    'description' => "Opening capital reserve for {$cd['shop']}",
                    'party_type'  => null,
                    'party_id'    => null
                ]
            ]);
        }
    }
}
echo "  [OK] Seeded 14 Retail Customers with Real Market Opening Balances.\n";

// 7. Suppliers
$suppliersData = [
    ['code' => 'SUPP-001', 'name' => 'Al-Hafiz Nimko & Sweets Factory', 'company' => 'Al-Hafiz Confectionery', 'phone' => '0300-8765432', 'city' => 'Gujranwala', 'opening_bal' => 250000.00],
    ['code' => 'SUPP-002', 'name' => 'Dawn Snack Foods Ltd', 'company' => 'Dawn Foods', 'phone' => '0321-4567890', 'city' => 'Lahore', 'opening_bal' => 180000.00]
];

foreach ($suppliersData as $sd) {
    $existing = db_fetch_val("SELECT id FROM suppliers WHERE supplier_code = ?", [$sd['code']]);
    if (!$existing) {
        $stmt = db()->prepare("
            INSERT INTO suppliers (
                supplier_code, supplier_name, company_name, phone, city,
                opening_balance, current_balance, is_active, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, 1, NOW()
            )
        ");
        $stmt->execute([
            $sd['code'], $sd['name'], $sd['company'], $sd['phone'], $sd['city'],
            $sd['opening_bal'], $sd['opening_bal']
        ]);
        $suppId = (int)db()->lastInsertId();

        if ($sd['opening_bal'] > 0) {
            post_journal_entry([
                'entry_date'     => date('Y-m-d'),
                'reference_type' => 'opening',
                'reference_id'   => $suppId,
                'narration'      => "Opening Payable Balance for Supplier: {$sd['name']}"
            ], [
                [
                    'account_id'  => ACC_CAPITAL,
                    'debit'       => $sd['opening_bal'],
                    'credit'      => 0,
                    'description' => "Opening debit reserve for {$sd['name']}",
                    'party_type'  => null,
                    'party_id'    => null
                ],
                [
                    'account_id'  => ACC_PAYABLES,
                    'debit'       => 0,
                    'credit'      => $sd['opening_bal'],
                    'description' => "Opening payable balance for {$sd['name']}",
                    'party_type'  => 'supplier',
                    'party_id'    => $suppId
                ]
            ]);
        }
    }
}
echo "  [OK] Seeded 2 Wholesale Suppliers with Opening Balances.\n";

echo "Master Data Seeding Complete!\n";
