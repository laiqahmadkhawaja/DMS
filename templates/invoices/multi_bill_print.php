<?php
/**
 * DMS - Distribution Management System
 * Multi-Bill Dynamic Layout Orchestrator (Auto-Fit, 1-up, 2-up, 3-up, Duplicate mode)
 * 
 * Automatically packs the maximum number of invoices per A4 page based on their
 * actual item count and height, minimizing paper waste (2, 3, or 4 bills per page).
 *
 * Required variables:
 * $invoices - Array of invoices
 * $itemsByInvoice - Map of invoice_id => array of invoice line items
 * 
 * Optional parameters:
 * $layoutMode - 'auto' | '1-up' | '2-up' | '3-up' (default 'auto')
 * $duplicateCopies - boolean (default false)
 * $showCutLine - boolean (default true)
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$layoutMode = isset($layoutMode) && in_array($layoutMode, ['auto', '1-up', '2-up', '3-up']) ? $layoutMode : 'auto';
$duplicateCopies = !empty($duplicateCopies);
$showCutLine = $showCutLine ?? true;

// Build the flattened bill list (accounting for duplicate copies if enabled)
$printableQueue = [];
foreach ($invoices as $inv) {
    $invId = $inv['id'];
    $items = $itemsByInvoice[$invId] ?? [];
    
    if ($duplicateCopies) {
        $printableQueue[] = [
            'invoice' => $inv,
            'items' => $items,
            'copyLabel' => 'Customer Copy'
        ];
        $printableQueue[] = [
            'invoice' => $inv,
            'items' => $items,
            'copyLabel' => 'Office Copy'
        ];
    } else {
        $printableQueue[] = [
            'invoice' => $inv,
            'items' => $items,
            'copyLabel' => ''
        ];
    }
}

// Function to estimate bill height in printable units (pixels)
// Standard A4 printable height is ~1020px with 5-6mm margins.
if (!function_exists('dms_estimate_bill_height')) {
    function dms_estimate_bill_height($itemsCount, $hasDiscount = false) {
        // Base height: header + address (~36px), metadata (~40px), thead (~22px), total row (~24px), signatures (~24px), cut line & margin (~16px)
        $base = $hasDiscount ? 190 : 162;
        // Each line item in compact mode takes ~19px
        $itemHeight = 19 * max(1, $itemsCount);
        return $base + $itemHeight;
    }
}

$pages = [];

if ($layoutMode === 'auto') {
    // Dynamic Packing: pack maximum bills per page up to safe A4 height
    $pageCapacity = 1000;
    $currentPage = [];
    $currentHeight = 0;
    
    foreach ($printableQueue as $slot) {
        $itemCount = count($slot['items']);
        $hasDiscount = ((float)($slot['invoice']['extra_discount_amount'] ?? 0) + (float)($slot['invoice']['item_discount_amount'] ?? 0)) > 0;
        $billHeight = dms_estimate_bill_height($itemCount, $hasDiscount);
        
        // If adding this bill exceeds capacity and current page already has bills:
        if (!empty($currentPage) && ($currentHeight + $billHeight > $pageCapacity)) {
            $pages[] = $currentPage;
            $currentPage = [];
            $currentHeight = 0;
        }
        
        $currentPage[] = $slot;
        $currentHeight += $billHeight;
    }
    
    if (!empty($currentPage)) {
        $pages[] = $currentPage;
    }
} elseif ($layoutMode === '1-up') {
    foreach ($printableQueue as $slot) {
        $pages[] = [$slot];
    }
} elseif ($layoutMode === '3-up') {
    $pages = array_chunk($printableQueue, 3);
} else {
    // 2-up
    $pages = array_chunk($printableQueue, 2);
}

$totalPages = count($pages);
?>

<div class="print-container layout-<?= htmlspecialchars($layoutMode) ?>">
    <?php foreach ($pages as $pIdx => $pageBills): 
        $isLastPage = ($pIdx === ($totalPages - 1));
        $billsInPage = count($pageBills);
    ?>
        <div class="a4-sheet <?= $isLastPage ? 'last-sheet' : '' ?>" data-bills-count="<?= $billsInPage ?>">
            <?php foreach ($pageBills as $bIdx => $slotData): 
                $invoice = $slotData['invoice'];
                $invoiceItems = $slotData['items'];
                $copyLabel = $slotData['copyLabel'];
                $isLastBillInPage = ($bIdx === ($billsInPage - 1));
                
                // Calibration for compact mode and row padding
                if ($layoutMode === 'auto') {
                    $compactMode = true;
                    // In auto mode, zero empty padding so small bills stay compact and allow more on page
                    $padRows = count($invoiceItems);
                } elseif ($layoutMode === '1-up') {
                    $compactMode = false;
                    $padRows = max(8, count($invoiceItems));
                } elseif ($layoutMode === '3-up') {
                    $compactMode = true;
                    $padRows = max(2, count($invoiceItems));
                } else {
                    // 2-up
                    $compactMode = true;
                    $padRows = max(4, count($invoiceItems));
                }
                $slotSuffix = str_replace('-', '', $layoutMode);
                $slotClass = ($layoutMode === 'auto') ? "bill-slot-{$billsInPage}up" : "bill-slot-{$slotSuffix}";
            ?>
                <div class="bill-slot <?= $slotClass ?>">
                    <?php require __DIR__ . '/bill_print.php'; ?>
                </div>

                <?php if (!$isLastBillInPage && $showCutLine): ?>
                    <div class="cut-line">
                        <span class="cut-scissors">✁</span>
                        <span class="cut-text">------------------------------------ Cut Here (یہاں سے کاٹیں) ------------------------------------</span>
                        <span class="cut-scissors">✁</span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
