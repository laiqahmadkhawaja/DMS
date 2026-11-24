<?php
/**
 * Migration: Add partner transaction reference types to journal_entries ENUM and party_type in journal_entry_lines
 */
define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    echo "Updating journal_entries.reference_type enum...\n";
    db()->exec("
        ALTER TABLE journal_entries 
        MODIFY COLUMN reference_type ENUM(
            'opening',
            'sales_invoice',
            'settlement',
            'cash_receipt',
            'supplier_payment',
            'grn',
            'expense',
            'manual_journal',
            'partner_drawing',
            'sales_cash_handover',
            'partner_cash_received'
        ) NOT NULL DEFAULT 'manual_journal'
    ");
    echo "Updating journal_entry_lines.party_type enum...\n";
    db()->exec("
        ALTER TABLE journal_entry_lines 
        MODIFY COLUMN party_type ENUM(
            'customer',
            'supplier',
            'salesman',
            'partner'
        ) DEFAULT NULL
    ");
    echo "Successfully updated journal_entries and journal_entry_lines ENUMs.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
