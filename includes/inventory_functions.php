<?php
/**
 * DMS - Distribution Management System
 * Core Stock Ledger & Inventory Functions
 * 
 * Strict Principle: Never modify stock balances without recording a stock_movement row.
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Get standard packaging conversion ratios for a product
 * 1 Carton = X Packs (carton_qty)
 * 1 Pack = Y Pieces (pack_qty)
 * 1 Carton = X * Y Pieces (pcs_per_ctn)
 */
function get_product_packaging_ratios($product): array {
    if (!is_array($product)) {
        $product = db_fetch_one("SELECT carton_qty, pack_qty FROM products WHERE id = ?", [(int)$product]) ?: [];
    }
    $packsPerCtn = (float)($product['carton_qty'] ?? 1) > 0 ? (float)$product['carton_qty'] : 1.0;
    $pcsPerPack  = (float)($product['pack_qty'] ?? 1) > 0 ? (float)$product['pack_qty'] : 1.0;
    $pcsPerCtn   = $packsPerCtn * $pcsPerPack;

    return [
        'packs_per_ctn' => $packsPerCtn,
        'pcs_per_pack'  => $pcsPerPack,
        'pcs_per_ctn'   => $pcsPerCtn
    ];
}

/**
 * Calculate total base pieces from cartons, packs, and loose pieces
 */
function calculate_base_pieces(float $cartons, float $packs, float $pieces, float $packsPerCtn, float $pcsPerPack): float {
    $packsPerCtn = $packsPerCtn > 0 ? $packsPerCtn : 1.0;
    $pcsPerPack  = $pcsPerPack > 0 ? $pcsPerPack : 1.0;
    return round(($cartons * $packsPerCtn * $pcsPerPack) + ($packs * $pcsPerPack) + $pieces, 4);
}

/**
 * Calculate carton equivalent from cartons, packs, and loose pieces
 */
function calculate_carton_equivalent(float $cartons, float $packs, float $pieces, float $packsPerCtn, float $pcsPerPack): float {
    $packsPerCtn = $packsPerCtn > 0 ? $packsPerCtn : 1.0;
    $pcsPerPack  = $pcsPerPack > 0 ? $pcsPerPack : 1.0;
    return round($cartons + ($packs / $packsPerCtn) + ($pieces / ($packsPerCtn * $pcsPerPack)), 6);
}

/**
 * Helper to pluralize unit labels nicely
 */
function pluralize_unit(string $unit, float $qty): string {
    if (abs($qty) == 1) return $unit;
    $lower = strtolower($unit);
    if (str_ends_with($lower, 's')) return $unit;
    if (str_ends_with($lower, 'x') || str_ends_with($lower, 'ch') || str_ends_with($lower, 'sh')) {
        return $unit . 'es';
    }
    return $unit . 's';
}

/**
 * Break down a total quantity in pieces into formatted Cartons, Packs, and Loose Pieces (or by assigned major unit)
 */
function breakdown_base_pieces(float $totalPieces, float $packsPerCtn, float $pcsPerPack, ?string $majorUnit = 'Ctn', ?string $mediumUnit = 'Pck', ?string $minorUnit = 'Pcs'): array {
    $packsPerCtn = $packsPerCtn > 0 ? $packsPerCtn : 1.0;
    $pcsPerPack  = $pcsPerPack > 0 ? $pcsPerPack : 1.0;
    $pcsPerCtn   = $packsPerCtn * $pcsPerPack;

    $isNegative = ($totalPieces < 0);
    $absPieces = abs($totalPieces);

    $cartons = floor($absPieces / $pcsPerCtn);
    $rem = fmod($absPieces, $pcsPerCtn);
    if ($pcsPerPack <= 1.0) {
        $packs = 0;
        $pieces = $rem;
    } else {
        $packs = floor($rem / $pcsPerPack);
        $pieces = fmod($rem, $pcsPerPack);
    }

    $majorLabel  = !empty($majorUnit) ? trim($majorUnit) : 'Ctn';
    $mediumLabel = !empty($mediumUnit) ? trim($mediumUnit) : 'Pck';
    $minorLabel  = !empty($minorUnit) ? trim($minorUnit) : 'Pcs';

    $parts = [];
    if ($cartons > 0) {
        $parts[] = "{$cartons} " . pluralize_unit($majorLabel, $cartons);
    }
    if ($packs > 0) {
        $parts[] = "{$packs} " . pluralize_unit($mediumLabel, $packs);
    }
    if ($pieces > 0 || empty($parts)) {
        $parts[] = "{$pieces} {$minorLabel}";
    }

    $sign = $isNegative ? '-' : '';

    return [
        'cartons'   => (float)($isNegative ? -$cartons : $cartons),
        'packs'     => (float)($isNegative ? -$packs : $packs),
        'pieces'    => (float)($isNegative ? -$pieces : $pieces),
        'formatted' => $sign . implode(' + ', $parts)
    ];
}

/**
 * Format movement quantity with major unit breakdown and base unit detail
 */
function format_movement_quantity(float $pieces, float $packsPerCtn, float $pcsPerPack, ?string $majorUnit = 'Ctn', bool $showSign = false): array {
    $breakdown = breakdown_base_pieces($pieces, $packsPerCtn, $pcsPerPack, $majorUnit);
    $absPieces = abs($pieces);
    $sign = ($pieces > 0 && $showSign) ? '+' : '';
    
    return [
        'cartons'   => $breakdown['cartons'],
        'packs'     => $breakdown['packs'],
        'pieces'    => $breakdown['pieces'],
        'breakdown' => $sign . $breakdown['formatted'],
        'units'     => $sign . number_format($pieces, 0) . ' units'
    ];
}

/**
 * Convert quantity from any packaging unit (Carton, Box, Pack) to product's base unit (Piece/Base)
 *
 * @param int $productId
 * @param int $unitId
 * @param float $quantity
 * @return float
 */
function convert_to_base_unit(int $productId, int $unitId, float $quantity): float {
    // Check if unit is already base unit
    $prod = db_fetch_one("SELECT base_unit_id, carton_qty, pack_qty FROM products WHERE id = ?", [$productId]);
    if (!$prod) {
        return $quantity;
    }

    if ((int)$prod['base_unit_id'] === $unitId) {
        return $quantity;
    }

    // Check specific conversion table
    $conv = db_fetch_val("
        SELECT multiplier 
        FROM product_unit_conversions 
        WHERE product_id = ? AND from_unit_id = ? AND to_unit_id = ?
    ", [$productId, $unitId, $prod['base_unit_id']]);

    if ($conv !== null && (float)$conv > 0) {
        return $quantity * (float)$conv;
    }

    // Default fallback based on unit code with 3-tier hierarchy
    $unitCode = db_fetch_val("SELECT unit_code FROM units WHERE id = ?", [$unitId]);
    $ratios = get_product_packaging_ratios($prod);

    if ($unitCode === 'CTN') {
        return $quantity * $ratios['pcs_per_ctn'];
    }
    if ($unitCode === 'PCK' || $unitCode === 'BOX') {
        return $quantity * $ratios['pcs_per_pack'];
    }

    return $quantity;
}

/**
 * Record an atomic stock movement and update stock_balances
 *
 * @param array $data [
 *   'product_id'     => int,
 *   'warehouse_id'   => int,
 *   'movement_type'  => string,
 *   'reference_type' => string,
 *   'reference_id'   => int|null,
 *   'qty_in'         => float,
 *   'qty_out'        => float,
 *   'unit_cost'      => float,
 *   'remarks'        => string|null,
 *   'condition_type' => string ('saleable'|'damaged'|'expired')
 * ]
 * @return int Movement ID
 * @throws Exception
 */
function record_stock_movement(array $data): int {
    $productId     = (int)($data['product_id'] ?? 0);
    $warehouseId   = (int)($data['warehouse_id'] ?? 1);
    $movementType  = (string)($data['movement_type'] ?? 'opening_stock');
    $referenceType = (string)($data['reference_type'] ?? 'opening');
    $referenceId   = !empty($data['reference_id']) ? (int)$data['reference_id'] : null;
    $qtyIn         = (float)($data['qty_in'] ?? 0);
    $qtyOut        = (float)($data['qty_out'] ?? 0);
    $unitCost      = (float)($data['unit_cost'] ?? 0);
    $remarks       = (string)($data['remarks'] ?? '');
    $condition     = (string)($data['condition_type'] ?? 'saleable');
    $userId        = current_user_id();

    if ($productId <= 0) {
        throw new InvalidArgumentException("Valid Product ID is required for stock movement.");
    }
    if ($qtyIn <= 0 && $qtyOut <= 0) {
        throw new InvalidArgumentException("Stock movement must have either qty_in or qty_out greater than zero.");
    }

    $netQty = $qtyIn - $qtyOut;
    $movementQty = $qtyIn > 0 ? $qtyIn : $qtyOut;

    // Normalize unit cost to piece / base unit cost:
    // If total_cost is passed explicitly, calculate unit piece cost from total_cost.
    $ratios = get_product_packaging_ratios($productId);
    $pcsPerCtn = (float)($ratios['pcs_per_ctn'] ?? 1);
    if ($pcsPerCtn <= 0) $pcsPerCtn = 1.0;

    if (isset($data['total_cost']) && (float)$data['total_cost'] > 0) {
        $totalCost = round((float)$data['total_cost'], 2);
        $unitCost  = $movementQty > 0 ? round($totalCost / $movementQty, 6) : $unitCost;
    } else {
        // If caller passed carton rate as unitCost when pcs_per_ctn > 1, normalize to piece cost
        $prod = db_fetch_one("SELECT purchase_rate, purchase_piece_rate FROM products WHERE id = ?", [$productId]);
        $pieceRate  = (float)($prod['purchase_piece_rate'] ?? 0);
        $cartonRate = (float)($prod['purchase_rate'] ?? 0);

        if ($pcsPerCtn > 1 && $unitCost > 0) {
            if ($pieceRate > 0 && $unitCost > ($pieceRate * 1.5)) {
                $unitCost = round($unitCost / $pcsPerCtn, 6);
            } elseif ($cartonRate > 0 && $unitCost >= ($cartonRate * 0.4)) {
                $unitCost = round($unitCost / $pcsPerCtn, 6);
            }
        } elseif ($unitCost <= 0 && $pieceRate > 0) {
            $unitCost = $pieceRate;
        }

        $totalCost = round($movementQty * $unitCost, 2);
    }

    // 1. Insert Movement Record
    $stmt = db()->prepare("
        INSERT INTO stock_movements (
            product_id, warehouse_id, movement_date, movement_type, 
            reference_type, reference_id, qty_in, qty_out, base_unit_qty, 
            unit_cost, total_cost, created_by, remarks, created_at
        ) VALUES (
            ?, ?, NOW(), ?, 
            ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $productId, $warehouseId, $movementType,
        $referenceType, $referenceId, $qtyIn, $qtyOut, $netQty,
        $unitCost, $totalCost, $userId, $remarks
    ]);
    $movementId = (int)db()->lastInsertId();

    // 2. Atomically update or insert stock_balances
    $colToUpdate = 'saleable_qty';
    if ($condition === 'damaged') {
        $colToUpdate = 'damaged_qty';
    } elseif ($condition === 'expired') {
        $colToUpdate = 'expired_qty';
    }

    $sqlBalance = "
        INSERT INTO stock_balances (warehouse_id, product_id, {$colToUpdate}, total_qty, avg_unit_cost, total_value, last_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            {$colToUpdate} = {$colToUpdate} + VALUES({$colToUpdate}),
            total_qty = total_qty + VALUES({$colToUpdate}),
            avg_unit_cost = IF(VALUES(avg_unit_cost) > 0, VALUES(avg_unit_cost), avg_unit_cost),
            total_value = total_qty * avg_unit_cost,
            last_updated_at = NOW()
    ";
    $stmtBal = db()->prepare($sqlBalance);
    $stmtBal->execute([$warehouseId, $productId, $netQty, $netQty, $unitCost, $totalCost]);

    return $movementId;
}

/**
 * Get current stock balance for a product
 *
 * @param int $productId
 * @param int $warehouseId
 * @return array
 */
function get_stock_balance(int $productId, int $warehouseId = 1): array {
    $bal = db_fetch_one("
        SELECT saleable_qty, damaged_qty, expired_qty, total_qty, avg_unit_cost, total_value 
        FROM stock_balances 
        WHERE product_id = ? AND warehouse_id = ?
    ", [$productId, $warehouseId]);

    if (!$bal) {
        return [
            'saleable_qty'  => 0.0,
            'damaged_qty'   => 0.0,
            'expired_qty'   => 0.0,
            'total_qty'     => 0.0,
            'avg_unit_cost' => 0.0,
            'total_value'   => 0.0
        ];
    }

    return [
        'saleable_qty'  => (float)$bal['saleable_qty'],
        'damaged_qty'   => (float)$bal['damaged_qty'],
        'expired_qty'   => (float)$bal['expired_qty'],
        'total_qty'     => (float)$bal['total_qty'],
        'avg_unit_cost' => (float)$bal['avg_unit_cost'],
        'total_value'   => (float)$bal['total_value']
    ];
}

/**
 * Rebuild stock_balances from stock_movements ledger (Audit source of truth)
 *
 * @param int $productId
 * @param int $warehouseId
 * @return void
 */
function recalculate_stock_balance(int $productId, int $warehouseId = 1): void {
    $sql = "
        SELECT 
            SUM(qty_in - qty_out) as calculated_total,
            SUM(CASE 
                WHEN movement_type LIKE '%damaged%' THEN (qty_in - qty_out) 
                ELSE 0 
            END) as calculated_damaged,
            SUM(CASE 
                WHEN movement_type LIKE '%expired%' THEN (qty_in - qty_out) 
                ELSE 0 
            END) as calculated_expired
        FROM stock_movements 
        WHERE product_id = ? AND warehouse_id = ?
    ";
    $res = db_fetch_one($sql, [$productId, $warehouseId]);

    $total   = (float)($res['calculated_total'] ?? 0);
    $damaged = (float)($res['calculated_damaged'] ?? 0);
    $expired = (float)($res['calculated_expired'] ?? 0);
    $saleable = $total - $damaged - $expired;

    $stmt = db()->prepare("
        INSERT INTO stock_balances (warehouse_id, product_id, saleable_qty, damaged_qty, expired_qty, total_qty, last_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            saleable_qty = VALUES(saleable_qty),
            damaged_qty = VALUES(damaged_qty),
            expired_qty = VALUES(expired_qty),
            total_qty = VALUES(total_qty),
            last_updated_at = NOW()
    ");
    $stmt->execute([$warehouseId, $productId, $saleable, $damaged, $expired, $total]);
}
