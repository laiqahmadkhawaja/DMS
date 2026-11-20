<?php
/**
 * DMS - Distribution Management System
 * Multi-Bill Layout Orchestrator (1-up, 2-up, 3-up, Duplicate mode)
 * 
 * Required variables:
 * $invoices - Array of invoices
 * $itemsByInvoice - Map of invoice_id => array of invoice line items
 * 
 * Optional parameters:
 * $layoutMode - '1-up' | '2-up' | '3-up' (default '2-up')
 * $duplicateCopies - boolean (default false; if true, each bill generates Customer Copy + Office Copy)
 * $showCutLine - boolean (default true)
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$layoutMode = $layoutMode ?? '2-up';
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

// Layout slot settings (Tight Excel-like congested layout to avoid paper wastage)
if ($layoutMode === '1-up') {
    $billsPerPage = 1;
    $compactMode = false;
    $slotClass = 'bill-slot-1up';
} elseif ($layoutMode === '3-up') {
    $billsPerPage = 3;
    $compactMode = true;
    $slotClass = 'bill-slot-3up';
} else {
    // Default 2-up
    $layoutMode = '2-up';
    $billsPerPage = 2;
    $compactMode = true;
    $slotClass = 'bill-slot-2up';
}

$totalBills = count($printableQueue);
?>

<div class="print-container layout-<?= $layoutMode ?>">
    <?php 
    for ($billIdx = 0; $billIdx < $totalBills; $billIdx++): 
        $slotData = $printableQueue[$billIdx];
        $invoice = $slotData['invoice'];
        $invoiceItems = $slotData['items'];
        $copyLabel = $slotData['copyLabel'];
        
        // Calibrate row padding per layout slot
        if ($layoutMode === '1-up') {
            $padRows = max(8, count($invoiceItems));
        } elseif ($layoutMode === '3-up') {
            $padRows = max(2, count($invoiceItems));
        } else {
            $padRows = max(4, count($invoiceItems));
        }
        
        $positionInPage = $billIdx % $billsPerPage;
        $isPageStart = ($positionInPage === 0);
        $isPageEnd = ($positionInPage === ($billsPerPage - 1)) || ($billIdx === ($totalBills - 1));
    ?>
        <?php if ($isPageStart): ?>
            <div class="a4-sheet <?= $isPageEnd ? 'page-break-auto' : '' ?>">
        <?php endif; ?>

        <div class="bill-slot <?= $slotClass ?>">
            <?php require __DIR__ . '/bill_print.php'; ?>
        </div>

        <?php if (!$isPageEnd && $showCutLine): ?>
            <div class="cut-line">
                <span class="cut-scissors">✁</span>
                <span class="cut-text">------------------------------------ Cut Here ------------------------------------</span>
                <span class="cut-scissors">✁</span>
            </div>
        <?php endif; ?>

        <?php if ($isPageEnd): ?>
            </div><!-- /.a4-sheet -->
        <?php endif; ?>
    <?php endfor; ?>
</div>
