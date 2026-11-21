<?php
/**
 * DMS - Distribution Management System
 * Core Double-Entry Accounting Functions
 * 
 * Strict Principle: Total Debit MUST Equal Total Credit on Every Financial Entry.
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Post a balanced double-entry journal voucher
 *
 * @param array $header [
 *   'entry_date'     => 'Y-m-d',
 *   'reference_type' => 'opening'|'sales_invoice'|'settlement'|'cash_receipt'|'supplier_payment'|'grn'|'expense'|'manual_journal',
 *   'reference_id'   => int|null,
 *   'narration'      => string
 * ]
 * @param array $lines Array of [
 *   'account_id'  => int,
 *   'debit'       => float,
 *   'credit'      => float,
 *   'description' => string|null,
 *   'party_type'  => 'customer'|'supplier'|'salesman'|null,
 *   'party_id'    => int|null
 * ]
 * @return int Journal Entry ID
 * @throws Exception
 */
function post_journal_entry(array $header, array $lines): int {
    $entryDate     = $header['entry_date'] ?? date('Y-m-d');
    $referenceType = $header['reference_type'] ?? 'manual_journal';
    $referenceId   = !empty($header['reference_id']) ? (int)$header['reference_id'] : null;
    $narration     = (string)($header['narration'] ?? '');
    $userId        = current_user_id();

    // Prevent duplicate posting for the same transactional reference (e.g. invoice, settlement, grn)
    if ($referenceId !== null && !in_array($referenceType, ['manual_journal', 'opening'], true)) {
        $existing = db_fetch_val("
            SELECT id FROM journal_entries 
            WHERE reference_type = ? AND reference_id = ?
        ", [$referenceType, $referenceId]);
        if ($existing) {
            throw new Exception("Transaction already posted to general ledger. Duplicate posting prevented.");
        }
    }

    if (empty($lines)) {
        throw new InvalidArgumentException("Journal entry must have at least two line items.");
    }

    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach ($lines as $line) {
        $debit  = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);
        if ($debit < 0 || $credit < 0) {
            throw new InvalidArgumentException("Debit and Credit amounts must be non-negative.");
        }
        $totalDebit  += $debit;
        $totalCredit += $credit;
    }

    // Double-entry validation: Total Debit MUST equal Total Credit
    if (abs($totalDebit - $totalCredit) > 0.01) {
        throw new Exception("Unbalanced Journal Entry: Total Debit (" . format_currency($totalDebit) . ") does not equal Total Credit (" . format_currency($totalCredit) . ").");
    }

    $entryNumber = generate_document_number(PREFIX_JOURNAL, 'journal_entries', 'entry_number');

    // 1. Insert header
    $stmt = db()->prepare("
        INSERT INTO journal_entries (
            entry_number, entry_date, reference_type, reference_id, 
            total_debit, total_credit, narration, is_posted, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?, 
            ?, ?, ?, 1, ?, NOW()
        )
    ");
    $stmt->execute([
        $entryNumber, $entryDate, $referenceType, $referenceId,
        $totalDebit, $totalCredit, $narration, $userId
    ]);
    $entryId = (int)db()->lastInsertId();

    // 2. Insert lines and update account current balances
    $stmtLine = db()->prepare("
        INSERT INTO journal_entry_lines (
            journal_entry_id, account_id, debit, credit, description, party_type, party_id, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");

    $stmtAccBal = db()->prepare("
        UPDATE accounts 
        SET current_balance = current_balance + ?, updated_at = NOW() 
        WHERE id = ?
    ");

    foreach ($lines as $line) {
        $accId = (int)$line['account_id'];
        $debit = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);
        $desc = (string)($line['description'] ?? $narration);
        $partyType = $line['party_type'] ?? null;
        $partyId = !empty($line['party_id']) ? (int)$line['party_id'] : null;

        $stmtLine->execute([
            $entryId, $accId, $debit, $credit, $desc, $partyType, $partyId
        ]);

        // Asset & Expense increase with Debit (+), decrease with Credit (-)
        // Liability, Equity & Revenue increase with Credit (+), decrease with Debit (-)
        $accType = db_fetch_val("SELECT account_type FROM accounts WHERE id = ?", [$accId]);
        if (in_array($accType, ['asset', 'expense'], true)) {
            $netChange = $debit - $credit;
        } else {
            $netChange = $credit - $debit;
        }
        $stmtAccBal->execute([$netChange, $accId]);
    }

    return $entryId;
}

/**
 * Update Customer balance atomically
 *
 * @param int $customerId
 * @param float $amount
 * @param string $direction 'increase' (debit: sale) or 'decrease' (credit: payment/return)
 * @return void
 */
function update_customer_balance(int $customerId, float $amount, string $direction = 'increase'): void {
    $delta = ($direction === 'increase') ? $amount : -$amount;
    $stmt = db()->prepare("
        UPDATE customers 
        SET current_balance = current_balance + ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$delta, $customerId]);
}

/**
 * Re-audit and sync Customer Balance from full journal transaction history
 */
function recalculate_customer_balance(int $customerId): float {
    $hasOpeningJE = (bool)db_fetch_val("
        SELECT jel.id FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.reference_type = 'opening'
    ", [$customerId]);
    $baseOpening = (!$hasOpeningJE) ? (float)db_fetch_val("SELECT opening_balance FROM customers WHERE id = ?", [$customerId]) : 0.0;

    $debits = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.debit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.is_posted = 1
    ", [$customerId]);

    $credits = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.credit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'customer' AND jel.party_id = ? AND je.is_posted = 1
    ", [$customerId]);

    $balance = round($baseOpening + $debits - $credits, 2);

    db()->prepare("UPDATE customers SET current_balance = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$balance, $customerId]);

    return $balance;
}

/**
 * Update Supplier balance atomically
 *
 * @param int $supplierId
 * @param float $amount
 * @param string $direction 'increase' (credit: purchase) or 'decrease' (debit: payment/return)
 * @return void
 */
function update_supplier_balance(int $supplierId, float $amount, string $direction = 'increase'): void {
    $delta = ($direction === 'increase') ? $amount : -$amount;
    $stmt = db()->prepare("
        UPDATE suppliers 
        SET current_balance = current_balance + ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$delta, $supplierId]);
}

/**
 * Re-audit and sync Supplier Balance from full journal transaction history
 */
function recalculate_supplier_balance(int $supplierId): float {
    $hasOpeningJE = (bool)db_fetch_val("
        SELECT jel.id FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.reference_type = 'opening'
    ", [$supplierId]);
    $baseOpening = (!$hasOpeningJE) ? (float)db_fetch_val("SELECT opening_balance FROM suppliers WHERE id = ?", [$supplierId]) : 0.0;

    $credits = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.credit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.is_posted = 1
    ", [$supplierId]);

    $debits = (float)db_fetch_val("
        SELECT COALESCE(SUM(jel.debit), 0) 
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE jel.party_type = 'supplier' AND jel.party_id = ? AND je.is_posted = 1
    ", [$supplierId]);

    $balance = round($baseOpening + $credits - $debits, 2);

    db()->prepare("UPDATE suppliers SET current_balance = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$balance, $supplierId]);

    return $balance;
}

/**
 * Get account details by ID
 */
function get_account_by_id(int $id): ?array {
    return db_fetch_one("SELECT * FROM accounts WHERE id = ?", [$id]);
}

/**
 * Get account details by code
 */
function get_account_by_code(string $code): ?array {
    return db_fetch_one("SELECT * FROM accounts WHERE account_code = ?", [$code]);
}

/**
 * Get all active chart of accounts
 */
function get_chart_of_accounts(?string $type = null): array {
    if ($type) {
        return db_fetch_all("
            SELECT * FROM accounts 
            WHERE is_active = 1 AND account_type = ? 
            ORDER BY account_code ASC
        ", [$type]);
    }
    return db_fetch_all("
        SELECT * FROM accounts 
        WHERE is_active = 1 
        ORDER BY account_type ASC, account_code ASC
    ");
}

/**
 * Get active expense categories
 */
function get_expense_categories(): array {
    return db_fetch_all("
        SELECT ec.*, a.account_name, a.account_code 
        FROM expense_categories ec
        LEFT JOIN accounts a ON ec.account_id = a.id
        WHERE ec.is_active = 1 
        ORDER BY ec.category_name ASC
    ");
}

/**
 * Calculate Account General Ledger statement
 */
function get_account_ledger(int $accountId, string $fromDate, string $toDate): array {
    $account = get_account_by_id($accountId);
    if (!$account) {
        throw new InvalidArgumentException("Account not found with ID {$accountId}");
    }

    $isDebitNormal = in_array($account['account_type'], ['asset', 'expense'], true);

    // 1. Calculate opening balance as of $fromDate
    // Sum of all journal entries before $fromDate
    $prior = db_fetch_one("
        SELECT 
            COALESCE(SUM(jel.debit), 0) as prior_debit,
            COALESCE(SUM(jel.credit), 0) as prior_credit
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? AND je.entry_date < ? AND je.is_posted = 1
    ", [$accountId, $fromDate]);

    $priorDebit = (float)($prior['prior_debit'] ?? 0);
    $priorCredit = (float)($prior['prior_credit'] ?? 0);
    $initialOpening = (float)($account['opening_balance'] ?? 0);

    if ($isDebitNormal) {
        $openingBalance = $initialOpening + ($priorDebit - $priorCredit);
    } else {
        $openingBalance = $initialOpening + ($priorCredit - $priorDebit);
    }

    // 2. Fetch journal entry lines for date range
    $lines = db_fetch_all("
        SELECT 
            jel.id as line_id,
            jel.debit,
            jel.credit,
            jel.description as line_desc,
            jel.party_type,
            jel.party_id,
            je.id as journal_id,
            je.entry_number,
            je.entry_date,
            je.reference_type,
            je.reference_id,
            je.narration
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = ? 
          AND je.entry_date >= ? 
          AND je.entry_date <= ?
          AND je.is_posted = 1
        ORDER BY je.entry_date ASC, je.id ASC, jel.id ASC
    ", [$accountId, $fromDate, $toDate]);

    // 3. Calculate running balance
    $runningBalance = $openingBalance;
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach ($lines as &$l) {
        $d = (float)$l['debit'];
        $c = (float)$l['credit'];
        $totalDebit += $d;
        $totalCredit += $c;

        if ($isDebitNormal) {
            $runningBalance += ($d - $c);
        } else {
            $runningBalance += ($c - $d);
        }
        $l['running_balance'] = $runningBalance;

        // Resolve party name if applicable
        $l['party_name'] = null;
        if (!empty($l['party_type']) && !empty($l['party_id'])) {
            if ($l['party_type'] === 'customer') {
                $l['party_name'] = db_fetch_val("SELECT shop_name FROM customers WHERE id = ?", [$l['party_id']]);
            } elseif ($l['party_type'] === 'supplier') {
                $l['party_name'] = db_fetch_val("SELECT supplier_name FROM suppliers WHERE id = ?", [$l['party_id']]);
            } elseif ($l['party_type'] === 'salesman') {
                $l['party_name'] = db_fetch_val("SELECT salesman_name FROM salesmen WHERE id = ?", [$l['party_id']]);
            }
        }
    }
    unset($l);

    return [
        'account'         => $account,
        'is_debit_normal' => $isDebitNormal,
        'from_date'       => $fromDate,
        'to_date'         => $toDate,
        'opening_balance' => round($openingBalance, 2),
        'lines'           => $lines,
        'total_debit'     => round($totalDebit, 2),
        'total_credit'    => round($totalCredit, 2),
        'closing_balance' => round($runningBalance, 2)
    ];
}
