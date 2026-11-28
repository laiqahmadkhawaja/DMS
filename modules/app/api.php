<?php
/**
 * DMS - Mobile App Backend API
 * Handles Order Booking, Delivery Run Entries, Field Returns, Warehouse Verification, and Cashier Handover
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/response.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';
require_once INCLUDES_PATH . '/inventory_functions.php';
require_once INCLUDES_PATH . '/accounting_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_error('Session expired. Please log in again.', [], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. INITIAL APP DATA
        // -------------------------------------------------------------
        case 'init':
            $userId = current_user_id();
            $currentUser = db_fetch_one("SELECT id, username, full_name, phone FROM users WHERE id = ?", [$userId]);

            $salesmen = db_fetch_all("
                SELECT id, employee_code, salesman_name, phone, vehicle_number, assigned_area_id 
                FROM salesmen 
                WHERE is_deleted = 0 AND is_active = 1 
                ORDER BY salesman_name ASC
            ");

            $bookers = db_fetch_all("
                SELECT id, booker_code, booker_name, phone 
                FROM bookers 
                WHERE is_deleted = 0 AND is_active = 1 
                ORDER BY booker_name ASC
            ");

            $areas = db_fetch_all("
                SELECT id, area_code, area_name 
                FROM areas 
                WHERE is_deleted = 0 AND is_active = 1 
                ORDER BY area_name ASC
            ");

            $categories = db_fetch_all("
                SELECT id, category_name 
                FROM product_categories 
                WHERE is_active = 1 
                ORDER BY category_name ASC
            ");

            $products = db_fetch_all("
                SELECT 
                    p.id, p.product_name, p.product_code, p.barcode, p.category_id, p.brand_id,
                    cat.category_name,
                    p.carton_rate, p.piece_rate, p.wholesale_rate, p.retail_rate, p.retail_carton_rate, p.retail_piece_rate, p.default_sale_rate,
                    p.carton_qty, p.pack_qty, p.packing_description, p.base_unit_id,
                    u.symbol as unit_symbol, u.unit_name,
                    COALESCE(sb.saleable_qty, 0) as saleable_qty
                FROM products p
                JOIN units u ON u.id = p.base_unit_id
                LEFT JOIN product_categories cat ON cat.id = p.category_id
                LEFT JOIN stock_balances sb ON p.id = sb.product_id AND sb.warehouse_id = 1
                WHERE p.is_deleted = 0 AND p.is_active = 1
                ORDER BY p.product_name ASC
            ");

            // Format packaging ratios and Wholesale / MRP pricing tiers for fast mobile cart calculation
            foreach ($products as &$p) {
                $ratios = get_product_packaging_ratios($p);
                $packsPerCtn = (float)$ratios['packs_per_ctn'];
                $pcsPerPack  = (float)$ratios['pcs_per_pack'];
                $pcsPerCtn   = (float)$ratios['pcs_per_ctn'];

                $p['packs_per_ctn'] = $packsPerCtn;
                $p['pcs_per_pack']  = $pcsPerPack;
                $p['pcs_per_ctn']   = $pcsPerCtn;

                // Wholesale rates
                $wsCarton = (float)($p['carton_rate'] > 0 ? $p['carton_rate'] : ($p['wholesale_rate'] ?: 0));
                $wsPack   = $packsPerCtn > 0 ? round($wsCarton / $packsPerCtn, 2) : $wsCarton;
                $wsPiece  = (float)($p['piece_rate'] > 0 ? $p['piece_rate'] : ($pcsPerCtn > 0 ? round($wsCarton / $pcsPerCtn, 2) : $wsCarton));

                // Retail / MRP rates
                $mrpCarton = (float)($p['retail_carton_rate'] > 0 ? $p['retail_carton_rate'] : ($p['retail_rate'] > 0 ? $p['retail_rate'] : $wsCarton));
                $mrpPack   = $packsPerCtn > 0 ? round($mrpCarton / $packsPerCtn, 2) : $mrpCarton;
                $mrpPiece  = (float)($p['retail_piece_rate'] > 0 ? $p['retail_piece_rate'] : ($pcsPerCtn > 0 ? round($mrpCarton / $pcsPerCtn, 2) : $mrpCarton));

                $p['wholesale_carton_rate'] = $wsCarton;
                $p['wholesale_pack_rate']   = $wsPack;
                $p['wholesale_piece_rate']  = $wsPiece;

                $p['mrp_carton_rate']       = $mrpCarton;
                $p['mrp_pack_rate']         = $mrpPack;
                $p['mrp_piece_rate']        = $mrpPiece;

                // Backwards-compatible aliases
                $p['carton_rate']  = $wsCarton;
                $p['pack_rate']    = $wsPack;
                $p['piece_rate']   = $wsPiece;
                $p['saleable_qty'] = (float)$p['saleable_qty'];
            }
            unset($p);

            // Resolve staff identity & linked salesman/booker records
            $linkedSalesman = function_exists('get_current_salesman') ? get_current_salesman() : null;
            $linkedBooker   = function_exists('get_current_booker') ? get_current_booker() : null;

            $assignedSalesmanId = $linkedSalesman ? (int)$linkedSalesman['id'] : $defaultSalesmanId;
            $assignedBookerId   = $linkedBooker ? (int)$linkedBooker['id'] : $defaultBookerId;

            $isField = function_exists('is_field_staff') ? is_field_staff() : false;
            $staffRole = 'Administrator';
            $staffName = $currentUser['full_name'] ?? 'Staff';
            $staffVehicle = '';

            if (in_array('salesman', $_SESSION['roles'] ?? [], true)) {
                $staffRole = 'Delivery Salesman';
                if ($linkedSalesman) {
                    $staffName = $linkedSalesman['salesman_name'];
                    $staffVehicle = $linkedSalesman['vehicle_number'] ?? '';
                }
            } elseif (in_array('booker', $_SESSION['roles'] ?? [], true)) {
                $staffRole = 'Order Booker';
                if ($linkedBooker) {
                    $staffName = $linkedBooker['booker_name'];
                }
            } elseif ($linkedSalesman) {
                $staffName = $linkedSalesman['salesman_name'];
                $staffVehicle = $linkedSalesman['vehicle_number'] ?? '';
            }

            json_success('Initial app data loaded', [
                'user'                 => $currentUser,
                'is_field_staff'       => $isField,
                'staff_name'           => $staffName,
                'staff_role'           => $staffRole,
                'staff_vehicle'        => $staffVehicle,
                'assigned_salesman_id' => $assignedSalesmanId,
                'assigned_booker_id'   => $assignedBookerId,
                'salesmen'             => $isField ? [] : $salesmen,
                'bookers'              => $isField ? [] : $bookers,
                'areas'                => $areas,
                'categories'           => $categories,
                'products'             => $products,
                'default_salesman_id'  => $assignedSalesmanId,
                'default_booker_id'    => $assignedBookerId,
                'distributor_name'     => get_setting('distributor_name', CLIENT_NAME),
                'currency'             => get_setting('currency_symbol', 'Rs.')
            ]);
            break;

        // -------------------------------------------------------------
        // 2. FETCH CUSTOMERS (WITH LIVE OUTSTANDING BALANCE)
        // -------------------------------------------------------------
        case 'customers':
            $areaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
            $search = isset($_GET['q']) ? trim($_GET['q']) : '';

            $sql = "
                SELECT 
                    c.id, c.customer_code, c.shop_name, c.owner_name, c.phone, c.address,
                    c.area_id, a.area_name, c.booker_id, b.booker_name,
                    c.credit_limit, c.current_balance, c.default_discount_percent
                FROM customers c
                JOIN areas a ON a.id = c.area_id
                LEFT JOIN bookers b ON b.id = c.booker_id
                WHERE c.is_deleted = 0 AND c.is_active = 1
            ";
            $params = [];

            if ($areaId > 0) {
                $sql .= " AND c.area_id = ?";
                $params[] = $areaId;
            }
            if (!empty($search)) {
                $sql .= " AND (c.shop_name LIKE ? OR c.owner_name LIKE ? OR c.customer_code LIKE ? OR c.phone LIKE ?)";
                $like = "%{$search}%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $sql .= " ORDER BY c.shop_name ASC";
            $customers = db_fetch_all($sql, $params);

            json_success('Customers retrieved', $customers);
            break;

        // -------------------------------------------------------------
        // 3. BOOK ORDER (DIRECT APP ORDER PLACEMENT)
        // -------------------------------------------------------------
        case 'book_order':
            if (!has_permission('orders_create')) {
                json_error('Permission denied to book orders.', [], 403);
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            $customerId   = (int)($input['customer_id'] ?? 0);
            $bookerId     = (int)($input['booker_id'] ?? 0);
            $salesmanId   = !empty($input['salesman_id']) ? (int)$input['salesman_id'] : null;
            $orderDate    = !empty($input['order_date']) ? clean_input($input['order_date']) : date('Y-m-d');
            $deliveryDate = !empty($input['delivery_date']) ? clean_input($input['delivery_date']) : date('Y-m-d', strtotime('+1 day'));
            $remarks      = clean_input($input['remarks'] ?? 'Booked via Mobile App');
            $orderStatus  = !empty($input['confirm_now']) ? 'confirmed' : 'confirmed'; // Default confirmed for fast workflow

            $pricingMode = clean_input($input['pricing_mode'] ?? 'wholesale');
            if ($pricingMode !== 'mrp') {
                $pricingMode = 'wholesale';
            }

            $discountMode = clean_input($input['discount_mode'] ?? 'item_rate');
            if ($discountMode !== 'subtotal') {
                $discountMode = 'item_rate';
            }
            $freeLoosePieces = !empty($input['free_loose_pieces']) ? 1 : 0;
            $subtotalDiscountPercent = (float)($input['subtotal_discount_percent'] ?? 0);
            $subtotalDiscountAmount  = (float)($input['subtotal_discount_amount'] ?? 0);

            $items = $input['items'] ?? [];
            if ($customerId <= 0) {
                json_error('Please select a customer / shop.');
            }
            if (empty($items) || !is_array($items)) {
                json_error('Please add at least one product to the order cart.');
            }

            $customer = db_fetch_one("SELECT id, area_id, booker_id FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                json_error('Selected customer does not exist.');
            }

            $areaId = (int)$customer['area_id'];
            if ($bookerId <= 0) {
                $bookerId = (int)($customer['booker_id'] ?: 1);
            }

            // Strictly lock salesman and booker IDs for field staff accounts
            if (function_exists('is_field_staff') && is_field_staff()) {
                $mySm = function_exists('get_current_salesman') ? get_current_salesman() : null;
                $myBk = function_exists('get_current_booker') ? get_current_booker() : null;
                if ($myBk) {
                    $bookerId = (int)$myBk['id'];
                }
                if ($mySm) {
                    $salesmanId = (int)$mySm['id'];
                }
            }

            db()->beginTransaction();

            $orderNumber = generate_document_number(PREFIX_ORDER, 'orders', 'order_number');
            $userId = current_user_id();

            $grossAmount   = 0.0;
            $discountTotal = 0.0;
            $totalCartons  = 0.0;
            $totalUnits    = 0.0;
            $validItems    = [];

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $cartonQty = (float)($item['carton_qty'] ?? 0);
                $packQty   = (float)($item['pack_qty'] ?? 0);
                $pcsQty    = (float)($item['pcs_qty'] ?? 0);
                $rate      = (float)($item['rate'] ?? 0);
                $discPct   = (float)($item['discount_percent'] ?? 0);
                $unitCode  = strtolower(trim((string)($item['unit'] ?? 'ctn')));

                if ($productId <= 0) continue;
                if ($cartonQty <= 0 && $packQty <= 0 && $pcsQty <= 0) continue;

                $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
                if (!$prod) continue;

                $ratios = get_product_packaging_ratios($prod);
                $packsPerCtn = (float)$ratios['packs_per_ctn'];
                $pcsPerPack  = (float)$ratios['pcs_per_pack'];
                $pcsPerCtn   = (float)$ratios['pcs_per_ctn'];

                $totalPieces = calculate_base_pieces($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);
                $cartonEquiv = calculate_carton_equivalent($cartonQty, $packQty, $pcsQty, $packsPerCtn, $pcsPerPack);

                // If Free Loose Pieces scheme is active, loose pieces are not charged
                $billablePcs = $freeLoosePieces ? 0 : $pcsQty;
                $billableBasePieces = calculate_base_pieces($cartonQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);
                $billableCartons    = calculate_carton_equivalent($cartonQty, $packQty, $billablePcs, $packsPerCtn, $pcsPerPack);

                // Determine effective rate if not provided or zero
                if ($rate <= 0) {
                    if ($pricingMode === 'mrp') {
                        $cRate = (float)($prod['retail_carton_rate'] > 0 ? $prod['retail_carton_rate'] : ($prod['retail_rate'] > 0 ? $prod['retail_rate'] : ($prod['carton_rate'] ?: $prod['wholesale_rate'])));
                    } else {
                        $cRate = (float)($prod['carton_rate'] > 0 ? $prod['carton_rate'] : ($prod['wholesale_rate'] ?: 0));
                    }
                    if ($unitCode === 'pcs') {
                        $rate = (float)($pricingMode === 'mrp' && $prod['retail_piece_rate'] > 0 ? $prod['retail_piece_rate'] : ($prod['piece_rate'] > 0 ? $prod['piece_rate'] : ($pcsPerCtn > 0 ? round($cRate / $pcsPerCtn, 2) : $cRate)));
                    } elseif ($unitCode === 'pack' || $unitCode === 'pck') {
                        $rate = $packsPerCtn > 0 ? round($cRate / $packsPerCtn, 2) : $cRate;
                    } else {
                        $rate = $cRate;
                    }
                }

                if ($unitCode === 'pcs') {
                    $lineGross = round($billableBasePieces * $rate, 2);
                    $unitId = 4; // Piece unit
                } elseif ($unitCode === 'pack' || $unitCode === 'pck') {
                    $billablePacks = ($cartonQty * $packsPerCtn) + $packQty + ($pcsPerPack > 0 ? ($billablePcs / $pcsPerPack) : 0);
                    $lineGross = round($billablePacks * $rate, 2);
                    $unitId = 3; // Packet unit
                } else {
                    $lineGross = round($billableCartons * $rate, 2);
                    $unitId = 1; // Carton unit
                }

                if ($discountMode === 'subtotal') {
                    $lineDisc = 0.0;
                    $discPct = 0.0;
                    $lineNet = $lineGross;
                } else {
                    $lineDisc = round($lineGross * ($discPct / 100), 2);
                    $lineNet  = round($lineGross - $lineDisc, 2);
                    $discountTotal += $lineDisc;
                }

                $grossAmount   += $lineGross;
                $totalCartons  += $cartonQty;
                $totalPieces   += $pcsQty;
                $totalUnits    += $totalPieces;

                $validItems[] = [
                    'product_id'       => $productId,
                    'unit_id'          => $unitId,
                    'carton_qty'       => $cartonQty,
                    'pack_qty'         => $packQty,
                    'pcs_qty'          => $pcsQty,
                    'quantity'         => $totalPieces,
                    'rate'             => $rate,
                    'discount_percent' => $discPct,
                    'discount_amount'  => $lineDisc,
                    'line_total'       => $lineNet
                ];
            }

            if (empty($validItems)) {
                db()->rollBack();
                json_error('Order has no valid products with positive quantities.');
            }

            // Apply subtotal discount if subtotal discount mode is chosen
            if ($discountMode === 'subtotal') {
                if ($subtotalDiscountAmount > 0) {
                    $discountTotal = min($grossAmount, $subtotalDiscountAmount);
                    $subtotalDiscountPercent = $grossAmount > 0 ? round(($discountTotal / $grossAmount) * 100, 2) : 0;
                } elseif ($subtotalDiscountPercent > 0) {
                    $discountTotal = round($grossAmount * ($subtotalDiscountPercent / 100), 2);
                    $subtotalDiscountAmount = $discountTotal;
                }
            }

            $netAmount = round(max(0, $grossAmount - $discountTotal), 2);

            $stmtOrd = db()->prepare("
                INSERT INTO orders (
                    order_number, order_date, delivery_date, customer_id, booker_id, 
                    area_id, salesman_id, pricing_mode, discount_mode, free_loose_pieces,
                    subtotal_discount_percent, subtotal_discount_amount,
                    gross_amount, discount_amount, net_amount, total_cartons, total_units,
                    status, remarks, created_by, confirmed_at, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, NOW(), NOW()
                )
            ");
            $stmtOrd->execute([
                $orderNumber, $orderDate, $deliveryDate, $customerId, $bookerId,
                $areaId, $salesmanId, $pricingMode, $discountMode, $freeLoosePieces,
                $subtotalDiscountPercent, $subtotalDiscountAmount,
                $grossAmount, $discountTotal, $netAmount, $totalCartons, $totalUnits,
                $orderStatus, $remarks, $userId
            ]);
            $orderId = (int)db()->lastInsertId();

            $stmtItem = db()->prepare("
                INSERT INTO order_items (
                    order_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty, 
                    quantity, rate, discount_percent, discount_amount, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($validItems as $vi) {
                $stmtItem->execute([
                    $orderId, $vi['product_id'], $vi['unit_id'], $vi['carton_qty'], $vi['pack_qty'], $vi['pcs_qty'],
                    $vi['quantity'], $vi['rate'], $vi['discount_percent'], $vi['discount_amount'], $vi['line_total']
                ]);
            }

            db()->commit();

            json_success("Order #{$orderNumber} placed successfully!", [
                'order_id'      => $orderId,
                'order_number'  => $orderNumber,
                'pricing_mode'  => $pricingMode,
                'gross_amount'  => $grossAmount,
                'discount'      => $discountTotal,
                'net_amount'    => $netAmount,
                'total_cartons' => round($totalCartons, 2),
                'total_units'   => round($totalUnits, 0),
                'items_count'   => count($validItems)
            ]);
            break;

        // -------------------------------------------------------------
        // 4. FETCH DELIVERY RUNS & SHOP BILLS
        // -------------------------------------------------------------
        case 'delivery_runs':
            $salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
            if (function_exists('is_field_staff') && is_field_staff()) {
                $mySm = function_exists('get_current_salesman') ? get_current_salesman() : null;
                if ($mySm) {
                    $salesmanId = (int)$mySm['id'];
                }
            }
            $runDate = isset($_GET['run_date']) ? clean_input($_GET['run_date']) : date('Y-m-d');

            $sql = "
                SELECT 
                    dr.id, dr.run_number, dr.run_date, dr.salesman_id, dr.total_bills, 
                    dr.total_amount, dr.status, dr.route_notes,
                    s.salesman_name, s.phone as salesman_phone,
                    ls.load_number
                FROM delivery_runs dr
                JOIN salesmen s ON s.id = dr.salesman_id
                LEFT JOIN load_sheets ls ON ls.id = dr.load_sheet_id
                WHERE 1=1
            ";
            $params = [];
            if ($salesmanId > 0) {
                $sql .= " AND dr.salesman_id = ?";
                $params[] = $salesmanId;
            }
            if (!empty($runDate)) {
                $sql .= " AND dr.run_date = ?";
                $params[] = $runDate;
            }
            $sql .= " ORDER BY dr.id DESC";

            $runs = db_fetch_all($sql, $params);

            // Fetch bills for each run
            foreach ($runs as &$run) {
                $bills = db_fetch_all("
                    SELECT 
                        drb.id as run_bill_id,
                        drb.delivery_run_id,
                        drb.invoice_id,
                        drb.customer_id,
                        drb.stop_sequence,
                        drb.delivery_status,
                        drb.cash_collected,
                        drb.credit_given,
                        drb.return_value,
                        drb.return_items_json,
                        drb.remarks,
                        drb.delivered_at,
                        COALESCE(inv.invoice_number, drb.bill_number) as invoice_number,
                        COALESCE(drb.bill_amount, inv.net_amount, 0) as bill_amount,
                        COALESCE(c.shop_name, c_inv.shop_name) as shop_name,
                        COALESCE(c.owner_name, c_inv.owner_name) as owner_name,
                        COALESCE(c.phone, c_inv.phone) as customer_phone,
                        COALESCE(c.address, c_inv.address) as customer_address,
                        COALESCE(c.current_balance, c_inv.current_balance, 0) as customer_current_balance,
                        COALESCE(a.area_name, a_inv.area_name, '-') as area_name
                    FROM delivery_run_bills drb
                    LEFT JOIN sales_invoices inv ON inv.id = drb.invoice_id
                    LEFT JOIN customers c ON c.id = drb.customer_id
                    LEFT JOIN customers c_inv ON c_inv.id = inv.customer_id
                    LEFT JOIN areas a ON a.id = c.area_id
                    LEFT JOIN areas a_inv ON a_inv.id = inv.area_id
                    WHERE drb.delivery_run_id = ?
                    ORDER BY drb.stop_sequence ASC, drb.id ASC
                ", [$run['id']]);

                // Calculate total due & remaining for each bill
                foreach ($bills as &$b) {
                    $billAmt  = (float)$b['bill_amount'];
                    $prevBal  = (float)$b['customer_current_balance'];
                    $cashColl = (float)$b['cash_collected'];
                    $retVal   = (float)$b['return_value'];
                    $credGiv  = (float)$b['credit_given'];

                    $b['bill_amount']    = $billAmt;
                    $b['cash_collected'] = $cashColl;
                    $b['credit_given']   = $credGiv;
                    $b['return_value']   = $retVal;
                    $b['total_due']      = $billAmt + $prevBal;

                    // If bill has been processed, compute remaining
                    $b['remaining_bill_due'] = max(0, round($billAmt - ($cashColl + $retVal), 2));
                    $b['return_items'] = !empty($b['return_items_json']) ? json_decode($b['return_items_json'], true) : [];
                }
                unset($b);

                $run['bills'] = $bills;
                $run['delivered_count'] = count(array_filter($bills, fn($b) => $b['delivery_status'] === 'delivered'));
                $run['total_cash_collected'] = array_sum(array_column($bills, 'cash_collected'));
                $run['total_credit_given']   = array_sum(array_column($bills, 'credit_given'));
                $run['total_returns_value']  = array_sum(array_column($bills, 'return_value'));
            }
            unset($run);

            json_success('Delivery runs fetched', $runs);
            break;

        // -------------------------------------------------------------
        // 5. RECORD DELIVERY & CASH COLLECTION FOR A BILL
        // -------------------------------------------------------------
        case 'save_delivery_entry':
            if (!has_permission('delivery_manage') && !has_permission('orders_view')) {
                json_error('Permission denied to update delivery entry.', [], 403);
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $runBillId     = (int)($input['run_bill_id'] ?? 0);
            $cashCollected = (float)($input['cash_collected'] ?? 0);
            $creditGiven   = (float)($input['credit_given'] ?? 0);
            $returnValue   = (float)($input['return_value'] ?? 0);
            $deliveryStatus= clean_input($input['delivery_status'] ?? 'delivered');
            $remarks       = clean_input($input['remarks'] ?? '');

            if ($runBillId <= 0) {
                json_error('Invalid delivery run bill ID.');
            }

            $bill = db_fetch_one("SELECT * FROM delivery_run_bills WHERE id = ?", [$runBillId]);
            if (!$bill) {
                json_error('Delivery run bill not found.');
            }

            $billAmount = (float)$bill['bill_amount'];

            // Auto-calculate credit if not supplied explicitly:
            // Remaining bill = Bill Amount - (Cash + Return)
            if (!isset($input['credit_given'])) {
                $creditGiven = max(0, round($billAmount - ($cashCollected + $returnValue), 2));
            }

            $stmt = db()->prepare("
                UPDATE delivery_run_bills 
                SET cash_collected = ?, credit_given = ?, return_value = ?, 
                    delivery_status = ?, remarks = ?, delivered_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$cashCollected, $creditGiven, $returnValue, $deliveryStatus, $remarks, $runBillId]);

            // Update parent delivery run status to in_progress
            db()->prepare("UPDATE delivery_runs SET status = 'in_progress', updated_at = NOW() WHERE id = ? AND status = 'assigned'")
                ->execute([(int)$bill['delivery_run_id']]);

            json_success('Delivery and cash collection saved successfully!', [
                'run_bill_id'    => $runBillId,
                'cash_collected' => $cashCollected,
                'credit_given'   => $creditGiven,
                'return_value'   => $returnValue,
                'delivery_status'=> $deliveryStatus
            ]);
            break;

        // -------------------------------------------------------------
        // 6. RECORD ON-THE-SPOT FIELD RETURN OF GOODS
        // -------------------------------------------------------------
        case 'save_field_return':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $runBillId   = (int)($input['run_bill_id'] ?? 0);
            $returnItems = $input['items'] ?? [];

            if ($runBillId <= 0) {
                json_error('Delivery bill ID is required.');
            }
            if (empty($returnItems) || !is_array($returnItems)) {
                json_error('Please add at least one returned item.');
            }

            $bill = db_fetch_one("
                SELECT drb.*, dr.salesman_id, inv.invoice_number, inv.customer_id as inv_customer_id 
                FROM delivery_run_bills drb
                JOIN delivery_runs dr ON dr.id = drb.delivery_run_id
                LEFT JOIN sales_invoices inv ON inv.id = drb.invoice_id
                WHERE drb.id = ?
            ", [$runBillId]);

            if (!$bill) {
                json_error('Delivery bill record not found.');
            }

            $customerId = (int)($bill['customer_id'] ?: $bill['inv_customer_id']);
            $salesmanId = (int)$bill['salesman_id'];
            $runId      = (int)$bill['delivery_run_id'];
            $invoiceId  = !empty($bill['invoice_id']) ? (int)$bill['invoice_id'] : null;

            db()->beginTransaction();

            $totalReturnValue = 0.0;
            $savedReturnItems = [];

            $stmtFri = db()->prepare("
                INSERT INTO field_return_items (
                    delivery_run_bill_id, delivery_run_id, invoice_id, customer_id, salesman_id,
                    product_id, unit_id, carton_qty, pack_qty, pcs_qty, total_pieces, rate, amount,
                    reason, condition_type, warehouse_verified, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, ?, ?, 
                    ?, ?, 0, NOW()
                )
            ");

            foreach ($returnItems as $ri) {
                $productId = (int)($ri['product_id'] ?? 0);
                $cartonQty = (float)($ri['carton_qty'] ?? 0);
                $packQty   = (float)($ri['pack_qty'] ?? 0);
                $pcsQty    = (float)($ri['pcs_qty'] ?? 0);
                $rate      = (float)($ri['rate'] ?? 0);
                $condition = in_array($ri['condition_type'] ?? '', ['saleable', 'damaged', 'expired']) ? $ri['condition_type'] : 'saleable';
                $reason    = clean_input($ri['reason'] ?? 'Customer Returned on Route');

                if ($productId <= 0) continue;
                if ($cartonQty <= 0 && $packQty <= 0 && $pcsQty <= 0) continue;

                $prod = db_fetch_one("SELECT * FROM products WHERE id = ?", [$productId]);
                if (!$prod) continue;

                $ratios = get_product_packaging_ratios($prod);
                $totalPieces = calculate_base_pieces($cartonQty, $packQty, $pcsQty, $ratios['packs_per_ctn'], $ratios['pcs_per_pack']);

                if ($rate <= 0) {
                    $rate = (float)($prod['piece_rate'] > 0 ? $prod['piece_rate'] : ($prod['carton_rate'] / max(1, $ratios['pcs_per_ctn'])));
                }

                $itemAmount = round($totalPieces * $rate, 2);
                $totalReturnValue += $itemAmount;

                $stmtFri->execute([
                    $runBillId, $runId, $invoiceId, $customerId, $salesmanId,
                    $productId, (int)$prod['base_unit_id'], $cartonQty, $packQty, $pcsQty,
                    $totalPieces, $rate, $itemAmount,
                    $reason, $condition
                ]);

                $savedReturnItems[] = [
                    'product_id'     => $productId,
                    'product_name'   => $prod['product_name'],
                    'carton_qty'     => $cartonQty,
                    'pack_qty'       => $packQty,
                    'pcs_qty'        => $pcsQty,
                    'total_pieces'   => $totalPieces,
                    'rate'           => $rate,
                    'amount'         => $itemAmount,
                    'condition_type' => $condition,
                    'reason'         => $reason
                ];
            }

            // Update delivery_run_bills return_value & json
            $existingReturnVal = (float)$bill['return_value'];
            $newTotalReturnVal = round($existingReturnVal + $totalReturnValue, 2);

            // Re-adjust credit given
            $billAmt = (float)$bill['bill_amount'];
            $cash    = (float)$bill['cash_collected'];
            $newCredit = max(0, round($billAmt - ($cash + $newTotalReturnVal), 2));

            $jsonString = json_encode($savedReturnItems, JSON_UNESCAPED_UNICODE);

            $stmtUpdBill = db()->prepare("
                UPDATE delivery_run_bills 
                SET return_value = ?, credit_given = ?, return_items_json = ?, delivered_at = NOW()
                WHERE id = ?
            ");
            $stmtUpdBill->execute([$newTotalReturnVal, $newCredit, $jsonString, $runBillId]);

            db()->commit();

            json_success('Field return recorded successfully! Awaiting warehouse inspection upon return.', [
                'run_bill_id'        => $runBillId,
                'items_count'        => count($savedReturnItems),
                'new_return_value'   => $newTotalReturnVal,
                'new_credit_given'   => $newCredit,
                'items'              => $savedReturnItems
            ]);
            break;

        // -------------------------------------------------------------
        // 7. GET FIELD RETURNS LIST FOR WAREHOUSE & CASHIER
        // -------------------------------------------------------------
        case 'get_field_returns':
            $salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
            $runId      = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
            $verified   = isset($_GET['verified']) ? (int)$_GET['verified'] : null;

            $sql = "
                SELECT 
                    fri.*,
                    p.product_name, p.product_code,
                    u.symbol as unit_symbol,
                    c.shop_name, c.customer_code,
                    sm.salesman_name,
                    dr.run_number,
                    inv.invoice_number,
                    uv.full_name as verified_by_name
                FROM field_return_items fri
                JOIN products p ON p.id = fri.product_id
                JOIN units u ON u.id = fri.unit_id
                JOIN customers c ON c.id = fri.customer_id
                JOIN salesmen sm ON sm.id = fri.salesman_id
                JOIN delivery_runs dr ON dr.id = fri.delivery_run_id
                LEFT JOIN sales_invoices inv ON inv.id = fri.invoice_id
                LEFT JOIN users uv ON uv.id = fri.verified_by
                WHERE 1=1
            ";
            $params = [];

            if ($salesmanId > 0) {
                $sql .= " AND fri.salesman_id = ?";
                $params[] = $salesmanId;
            }
            if ($runId > 0) {
                $sql .= " AND fri.delivery_run_id = ?";
                $params[] = $runId;
            }
            if ($verified !== null) {
                $sql .= " AND fri.warehouse_verified = ?";
                $params[] = $verified;
            }

            $sql .= " ORDER BY fri.id DESC";

            $fieldReturns = db_fetch_all($sql, $params);

            json_success('Field returns fetched', $fieldReturns);
            break;

        // -------------------------------------------------------------
        // 8. WAREHOUSE CONFIRMATION OF RETURNED STOCK
        // -------------------------------------------------------------
        case 'verify_warehouse_return':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $itemIds = $input['item_ids'] ?? [];

            if (empty($itemIds) || !is_array($itemIds)) {
                json_error('Please select at least one returned item to confirm into warehouse.');
            }

            db()->beginTransaction();
            $userId = current_user_id();

            $inPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
            $items = db_fetch_all("
                SELECT fri.*, p.product_name, p.purchase_rate, p.carton_rate, p.piece_rate
                FROM field_return_items fri
                JOIN products p ON p.id = fri.product_id
                WHERE fri.id IN ({$inPlaceholders}) AND fri.warehouse_verified = 0
            ", $itemIds);

            if (empty($items)) {
                db()->rollBack();
                json_error('Selected items have already been verified or do not exist.');
            }

            // Group verified items by customer / run to create sales_returns voucher
            $groupedByRunCustomer = [];
            foreach ($items as $it) {
                $key = $it['delivery_run_id'] . '_' . $it['customer_id'];
                $groupedByRunCustomer[$key][] = $it;
            }

            $verifiedCount = 0;
            $totalStockValue = 0.0;

            foreach ($groupedByRunCustomer as $group) {
                $first = $group[0];
                $groupTotal = array_sum(array_column($group, 'amount'));
                $totalStockValue += $groupTotal;

                $returnNumber = generate_document_number(PREFIX_RETURN, 'sales_returns', 'return_number');

                // Insert sales_returns header
                $stmtRet = db()->prepare("
                    INSERT INTO sales_returns (
                        return_number, invoice_id, customer_id, return_date, 
                        total_amount, reason, condition_type, status, stock_settled, 
                        created_by, created_at
                    ) VALUES (
                        ?, ?, ?, CURDATE(), 
                        ?, 'Field Return Verified by Warehouse', ?, 'processed', 1, 
                        ?, NOW()
                    )
                ");
                $primaryCondition = $first['condition_type'] ?? 'saleable';
                $stmtRet->execute([
                    $returnNumber, $first['invoice_id'], $first['customer_id'],
                    $groupTotal, $primaryCondition, $userId
                ]);
                $salesReturnId = (int)db()->lastInsertId();

                $stmtRetItem = db()->prepare("
                    INSERT INTO sales_return_items (
                        sales_return_id, product_id, unit_id, carton_qty, pack_qty, pcs_qty,
                        quantity, rate, discount_percent, amount, reason, condition_type, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, 
                        ?, ?, 0.00, ?, ?, ?, NOW()
                    )
                ");

                $stmtUpdFri = db()->prepare("
                    UPDATE field_return_items 
                    SET warehouse_verified = 1, verified_by = ?, verified_at = NOW(), sales_return_id = ?
                    WHERE id = ?
                ");

                foreach ($group as $gi) {
                    $stmtRetItem->execute([
                        $salesReturnId, $gi['product_id'], $gi['unit_id'],
                        $gi['carton_qty'], $gi['pack_qty'], $gi['pcs_qty'],
                        $gi['total_pieces'], $gi['rate'], $gi['amount'],
                        $gi['reason'], $gi['condition_type']
                    ]);

                    // Atomically increment warehouse inventory
                    $pieceCost = (float)$gi['rate'];
                    record_stock_movement([
                        'product_id'     => (int)$gi['product_id'],
                        'warehouse_id'   => 1, // Main Warehouse
                        'movement_type'  => 'sales_return',
                        'reference_type' => 'sales_return',
                        'reference_id'   => $salesReturnId,
                        'qty_in'         => (float)$gi['total_pieces'],
                        'qty_out'        => 0,
                        'unit_cost'      => $pieceCost,
                        'total_cost'     => (float)$gi['amount'],
                        'remarks'        => "Stock returned from field by Salesman #{$gi['salesman_id']} (Shop ID: {$gi['customer_id']})",
                        'condition_type' => $gi['condition_type']
                    ]);

                    $stmtUpdFri->execute([$userId, $salesReturnId, $gi['id']]);
                    $verifiedCount++;
                }
            }

            db()->commit();

            json_success("Warehouse verified {$verifiedCount} returned items and restocked inventory successfully!", [
                'verified_count'    => $verifiedCount,
                'total_stock_value' => $totalStockValue
            ]);
            break;

        // -------------------------------------------------------------
        // 9. CASHIER CASH HANDOVER VERIFICATION & SETTLEMENT
        // -------------------------------------------------------------
        case 'verify_cashier_handover':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $runId        = (int)($input['delivery_run_id'] ?? 0);
            $cashReceived = (float)($input['cash_received'] ?? 0);
            $remarks      = clean_input($input['remarks'] ?? 'Cashier Handover Verified via Mobile App');

            if ($runId <= 0) {
                json_error('Delivery run ID is required.');
            }

            $run = db_fetch_one("SELECT * FROM delivery_runs WHERE id = ?", [$runId]);
            if (!$run) {
                json_error('Delivery run not found.');
            }

            $bills = db_fetch_all("
                SELECT drb.*, inv.net_amount as inv_net_amount
                FROM delivery_run_bills drb
                LEFT JOIN sales_invoices inv ON inv.id = drb.invoice_id
                WHERE drb.delivery_run_id = ?
            ", [$runId]);

            if (empty($bills)) {
                json_error('No bills found in this delivery run.');
            }

            $totalInvoiced = array_sum(array_map(fn($b) => (float)($b['bill_amount'] ?: $b['inv_net_amount']), $bills));
            $totalCash     = array_sum(array_column($bills, 'cash_collected'));
            $totalCredit   = array_sum(array_column($bills, 'credit_given'));
            $totalReturns  = array_sum(array_column($bills, 'return_value'));

            $diff = round($totalInvoiced - ($totalCash + $totalCredit + $totalReturns), 2);

            db()->beginTransaction();
            $userId = current_user_id();

            // 1. Create or update settlements record
            $existingSet = db_fetch_one("SELECT id FROM settlements WHERE delivery_run_id = ?", [$runId]);
            $settlementNumber = generate_document_number(PREFIX_SETTLEMENT, 'settlements', 'settlement_number');
            $status = (abs($diff) <= 0.01) ? 'finalized' : 'reconciled';

            if ($existingSet) {
                $settlementId = (int)$existingSet['id'];
                $stmtUpdSet = db()->prepare("
                    UPDATE settlements 
                    SET total_invoices_amount = ?, total_cash_received = ?, total_credit_given = ?, 
                        total_returns_amount = ?, difference_amount = ?, status = ?, 
                        remarks = ?, reconciled_by = ?, finalized_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdSet->execute([
                    $totalInvoiced, $totalCash, $totalCredit, $totalReturns,
                    $diff, $status, $remarks, $userId, $settlementId
                ]);
            } else {
                $stmtSet = db()->prepare("
                    INSERT INTO settlements (
                        settlement_number, settlement_date, delivery_run_id, salesman_id, load_sheet_id,
                        total_invoices_amount, total_cash_received, total_credit_given, total_returns_amount,
                        difference_amount, status, remarks, created_by, reconciled_by, finalized_at, created_at
                    ) VALUES (
                        ?, CURDATE(), ?, ?, ?, 
                        ?, ?, ?, ?, 
                        ?, ?, ?, ?, ?, NOW(), NOW()
                    )
                ");
                $stmtSet->execute([
                    $settlementNumber, $runId, $run['salesman_id'], $run['load_sheet_id'],
                    $totalInvoiced, $totalCash, $totalCredit, $totalReturns,
                    $diff, $status, $remarks, $userId, $userId
                ]);
                $settlementId = (int)db()->lastInsertId();
            }

            // 2. Record cash submission if positive cash
            if ($cashReceived > 0) {
                $subNumber = generate_document_number('SUB', 'cash_submissions', 'submission_number');
                $stmtCash = db()->prepare("
                    INSERT INTO cash_submissions (
                        submission_number, submission_date, salesman_id, settlement_id, amount,
                        cash_account_id, received_by, remarks, created_at
                    ) VALUES (
                        ?, CURDATE(), ?, ?, ?, 
                        1, ?, ?, NOW()
                    )
                ");
                $stmtCash->execute([
                    $subNumber, $run['salesman_id'], $settlementId, $cashReceived,
                    $userId, $remarks
                ]);
            }

            // 3. Mark delivery run as completed
            db()->prepare("UPDATE delivery_runs SET status = 'completed', updated_at = NOW() WHERE id = ?")
                ->execute([$runId]);

            db()->commit();

            json_success("Cashier handover confirmed and route closed successfully!", [
                'settlement_id'     => $settlementId,
                'total_invoiced'    => $totalInvoiced,
                'total_cash'        => $totalCash,
                'total_credit'      => $totalCredit,
                'total_returns'     => $totalReturns,
                'difference'        => $diff,
                'status'            => $status
            ]);
            break;

        // -------------------------------------------------------------
        // 10. DAILY SUMMARY KPIS
        // -------------------------------------------------------------
        case 'daily_summary':
            $salesmanId = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
            $bookerId   = isset($_GET['booker_id']) ? (int)$_GET['booker_id'] : 0;
            if (function_exists('is_field_staff') && is_field_staff()) {
                $mySm = function_exists('get_current_salesman') ? get_current_salesman() : null;
                if ($mySm) $salesmanId = (int)$mySm['id'];
                $myBk = function_exists('get_current_booker') ? get_current_booker() : null;
                if ($myBk) $bookerId = (int)$myBk['id'];
            }
            $date = isset($_GET['date']) ? clean_input($_GET['date']) : date('Y-m-d');

            // Orders booked today
            $ordSql = "SELECT COUNT(*) as cnt, COALESCE(SUM(net_amount), 0) as total FROM orders WHERE order_date = ?";
            $ordParams = [$date];
            if ($bookerId > 0) {
                $ordSql .= " AND booker_id = ?";
                $ordParams[] = $bookerId;
            } elseif ($salesmanId > 0) {
                $ordSql .= " AND (salesman_id = ? OR created_by = ?)";
                $ordParams[] = $salesmanId;
                $ordParams[] = current_user_id();
            }
            $ordRes = db_fetch_one($ordSql, $ordParams);

            // Active delivery runs today
            $delSql = "
                SELECT 
                    COUNT(DISTINCT dr.id) as runs_count,
                    COUNT(drb.id) as total_bills,
                    SUM(CASE WHEN drb.delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_bills,
                    COALESCE(SUM(drb.cash_collected), 0) as cash_collected,
                    COALESCE(SUM(drb.credit_given), 0) as credit_given,
                    COALESCE(SUM(drb.return_value), 0) as return_value
                FROM delivery_runs dr
                LEFT JOIN delivery_run_bills drb ON drb.delivery_run_id = dr.id
                WHERE dr.run_date = ?
            ";
            $delParams = [$date];
            if ($salesmanId > 0) {
                $delSql .= " AND dr.salesman_id = ?";
                $delParams[] = $salesmanId;
            }
            $delRes = db_fetch_one($delSql, $delParams);

            // Field returns pending verification
            $retSql = "
                SELECT 
                    COUNT(*) as items_count,
                    COALESCE(SUM(amount), 0) as total_val,
                    SUM(CASE WHEN warehouse_verified = 1 THEN 1 ELSE 0 END) as verified_items,
                    SUM(CASE WHEN warehouse_verified = 0 THEN 1 ELSE 0 END) as pending_items
                FROM field_return_items
                WHERE DATE(created_at) = ?
            ";
            $retParams = [$date];
            if ($salesmanId > 0) {
                $retSql .= " AND salesman_id = ?";
                $retParams[] = $salesmanId;
            }
            $retRes = db_fetch_one($retSql, $retParams);

            json_success('Daily summary KPIs', [
                'orders_count'     => (int)($ordRes['cnt'] ?? 0),
                'orders_amount'    => (float)($ordRes['total'] ?? 0),
                'runs_count'       => (int)($delRes['runs_count'] ?? 0),
                'total_bills'      => (int)($delRes['total_bills'] ?? 0),
                'delivered_bills'  => (int)($delRes['delivered_bills'] ?? 0),
                'cash_collected'   => (float)($delRes['cash_collected'] ?? 0),
                'credit_given'     => (float)($delRes['credit_given'] ?? 0),
                'returns_value'    => (float)($delRes['return_value'] ?? 0),
                'pending_returns'  => (int)($retRes['pending_items'] ?? 0),
                'verified_returns' => (int)($retRes['verified_items'] ?? 0)
            ]);
            break;

        default:
            json_error("Invalid or missing action parameter: '{$action}'", [], 400);
    }
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log("Error in Mobile App API ({$action}): " . $e->getMessage());
    json_error("Server error: " . $e->getMessage(), [], 500);
}
