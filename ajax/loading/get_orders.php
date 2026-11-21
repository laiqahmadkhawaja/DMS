<?php
/**
 * DMS - Distribution Management System
 * AJAX: Fetch Confirmed Orders for Load Sheet
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/response.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_error('Unauthenticated', [], 401);
}

if (!has_permission('loading_create') && !has_permission('loading_view')) {
    json_error('Permission denied', [], 403);
}

$salesmanId   = isset($_GET['salesman_id']) ? (int)$_GET['salesman_id'] : 0;
$areaId       = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
$bookerId     = isset($_GET['booker_id']) ? (int)$_GET['booker_id'] : 0;
$deliveryDate = isset($_GET['delivery_date']) ? trim(sanitize_input($_GET['delivery_date'])) : '';

$where = ["o.status = 'confirmed'"];
$params = [];

// Exclude orders that are already assigned to an active draft or issued load sheet
$where[] = "o.id NOT IN (
    SELECT lso.order_id 
    FROM load_sheet_orders lso 
    JOIN load_sheets ls ON lso.load_sheet_id = ls.id 
    WHERE ls.status IN ('draft', 'issued')
)";

if ($areaId > 0) {
    $where[] = "o.area_id = ?";
    $params[] = $areaId;
}

if ($bookerId > 0) {
    $where[] = "o.booker_id = ?";
    $params[] = $bookerId;
}

// Delivery date filtering:
// If empty, 'all', or 'any', do not restrict by delivery date (returns all confirmed orders ready for loading)
if (!empty($deliveryDate) && !in_array($deliveryDate, ['all', 'any', 'all_dates'], true)) {
    if ($deliveryDate === 'today') {
        $where[] = "o.delivery_date = CURDATE()";
    } elseif ($deliveryDate === 'tomorrow') {
        $where[] = "o.delivery_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        $where[] = "o.delivery_date = ?";
        $params[] = $deliveryDate;
    }
}

$whereClause = implode(" AND ", $where);

try {
    $orders = db_fetch_all("
        SELECT 
            o.id,
            o.order_number,
            o.order_date,
            o.delivery_date,
            o.customer_id,
            c.shop_name as customer_name,
            c.customer_code,
            c.address as customer_address,
            o.area_id,
            a.area_name,
            o.booker_id,
            b.booker_name,
            o.total_cartons,
            o.total_units,
            o.net_amount
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN areas a ON o.area_id = a.id
        JOIN bookers b ON o.booker_id = b.id
        WHERE {$whereClause}
        ORDER BY o.delivery_date ASC, a.area_name ASC, c.shop_name ASC
    ", $params);

    // If orders found, also get aggregated items for each order or all selected orders
    $orderIds = array_column($orders, 'id');
    $itemsByOrder = [];

    if (!empty($orderIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        $items = db_fetch_all("
            SELECT 
                oi.order_id,
                oi.product_id,
                p.product_code,
                p.product_name,
                p.carton_qty as ctn_ratio,
                oi.unit_id,
                u.unit_code,
                u.unit_name,
                oi.carton_qty,
                oi.pcs_qty,
                oi.quantity,
                oi.rate,
                oi.line_total
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN units u ON oi.unit_id = u.id
            WHERE oi.order_id IN ({$inPlaceholders})
            ORDER BY p.product_name ASC
        ", $orderIds);

        foreach ($items as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }
    }

    foreach ($orders as &$ord) {
        $ord['items'] = $itemsByOrder[$ord['id']] ?? [];
    }
    unset($ord);

    json_success('Orders fetched successfully', [
        'count'  => count($orders),
        'orders' => $orders
    ]);

} catch (Exception $e) {
    error_log("Error in get_orders AJAX: " . $e->getMessage());
    json_error("Database error occurred while fetching orders.");
}
