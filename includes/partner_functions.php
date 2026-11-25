<?php
/**
 * DMS - Distribution Management System
 * Partner Management, Khata Ledger & Shared Expense Functions
 */

if (!defined('DMS_RUNNING')) {
    exit('No direct script access allowed');
}

/**
 * Check if the Partner Management & Expense Sharing System is enabled in System Settings
 *
 * Mode 'standard': Expenses are directly company-funded; no partner splits, personal funding, or partner khatas.
 * Mode 'partnership': Shared expenses, personal pocket funding, and partner khata distribution enabled.
 *
 * @return bool
 */
function is_partner_expense_sharing_enabled(): bool {
    $mode = function_exists('get_setting') ? get_setting('expense_management_mode', '') : '';
    if ($mode === 'partnership') {
        return true;
    }
    if ($mode === 'standard') {
        return false;
    }

    // Default fallback: if not explicitly configured in settings yet,
    // check if multiple active partners exist in the database (preserves backward compatibility)
    try {
        $count = (int)db_fetch_val("SELECT COUNT(*) FROM partners WHERE is_active = 1");
        return ($count > 1);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Fetch all partners
 *
 * @param bool $activeOnly
 * @return array
 */
function get_partners(bool $activeOnly = true): array {
    $where = $activeOnly ? "WHERE p.is_active = 1" : "";
    return db_fetch_all("
        SELECT p.*, s.supplier_name, s.company_name as supplier_company
        FROM partners p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        {$where}
        ORDER BY p.id ASC
    ");
}

/**
 * Fetch partner by ID
 *
 * @param int $id
 * @return array|null
 */
function get_partner_by_id(int $id): ?array {
    return db_fetch_one("
        SELECT p.*, s.supplier_name, s.company_name as supplier_company
        FROM partners p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.id = ?
    ", [$id]);
}

/**
 * Fetch partner by linked supplier ID
 *
 * @param int $supplierId
 * @return array|null
 */
function get_partner_by_supplier(int $supplierId): ?array {
    return db_fetch_one("
        SELECT p.*, s.supplier_name, s.company_name as supplier_company
        FROM partners p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.supplier_id = ?
    ", [$supplierId]);
}

/**
 * Record a Partner Khata Transaction
 *
 * @param array $data
 * @return int Transaction ID
 */
function record_partner_transaction(array $data): int {
    $partnerId = (int)($data['partner_id'] ?? 0);
    if ($partnerId <= 0) {
        throw new InvalidArgumentException("Valid Partner ID is required.");
    }

    $date        = $data['transaction_date'] ?? date('Y-m-d');
    $type        = $data['transaction_type'] ?? 'adjustment';
    $refType     = $data['reference_type'] ?? 'manual';
    $refId       = !empty($data['reference_id']) ? (int)$data['reference_id'] : null;
    $debit       = round(max(0, (float)($data['debit'] ?? 0)), 2);
    $credit      = round(max(0, (float)($data['credit'] ?? 0)), 2);
    $description = (string)($data['description'] ?? '');
    $userId      = current_user_id();

    // Fetch current partner balance
    $currentBal = (float)db_fetch_val("SELECT current_balance FROM partners WHERE id = ?", [$partnerId]);
    $newBal = round($currentBal + $credit - $debit, 2);

    // Generate unique transaction number
    $txnNo = generate_document_number('PTR', 'partner_transactions', 'transaction_number');

    $stmt = db()->prepare("
        INSERT INTO partner_transactions (
            transaction_number, transaction_date, partner_id, transaction_type,
            reference_type, reference_id, debit, credit, running_balance,
            description, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $txnNo, $date, $partnerId, $type,
        $refType, $refId, $debit, $credit, $newBal,
        $description, $userId
    ]);
    $txnId = (int)db()->lastInsertId();

    // Update partner balance
    db()->prepare("UPDATE partners SET current_balance = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$newBal, $partnerId]);

    return $txnId;
}

/**
 * Calculate expense share distribution between partners
 *
 * @param float $amount
 * @param string $splitType
 * @param array|null $customShares [partner_id => amount]
 * @return array [partner_id => share_amount]
 */
function calculate_expense_split(float $amount, string $splitType, ?array $customShares = null): array {
    if (!is_partner_expense_sharing_enabled()) {
        return [];
    }

    $amount = round($amount, 2);
    if ($amount <= 0) return [];

    $moazzam = db_fetch_one("SELECT id FROM partners WHERE partner_code = 'PTR-MOAZZAM' OR partner_name LIKE '%Moazzam%' LIMIT 1");
    $rehman  = db_fetch_one("SELECT id FROM partners WHERE partner_code = 'PTR-REHMAN' OR partner_name LIKE '%Rehman%' LIMIT 1");

    $mId = $moazzam ? (int)$moazzam['id'] : 1;
    $rId = $rehman ? (int)$rehman['id'] : 2;

    $shares = [];

    switch ($splitType) {
        case 'equal_50_50':
            $half = round($amount / 2, 2);
            $rem = round($amount - $half, 2);
            $shares[$mId] = $half;
            $shares[$rId] = $rem;
            break;

        case 'only_moazzam':
            $shares[$mId] = $amount;
            break;

        case 'only_rehman':
            $shares[$rId] = $amount;
            break;

        case 'custom':
            if (!empty($customShares)) {
                foreach ($customShares as $pId => $shareAmt) {
                    $shares[(int)$pId] = round((float)$shareAmt, 2);
                }
            } else {
                $half = round($amount / 2, 2);
                $shares[$mId] = $half;
                $shares[$rId] = round($amount - $half, 2);
            }
            break;

        case 'none':
        default:
            // Charged to central pool
            break;
    }

    return $shares;
}

/**
 * Synchronize an expense with partner transactions ledger
 *
 * @param int $expenseId
 * @param array $expenseData
 * @return void
 */
function sync_expense_partner_transactions(int $expenseId, array $expenseData): void {
    // 1. Delete prior transactions for this expense
    $priorTxns = db_fetch_all("
        SELECT partner_id, debit, credit 
        FROM partner_transactions 
        WHERE reference_type = 'expense' AND reference_id = ?
    ", [$expenseId]);

    // Reverse balances
    foreach ($priorTxns as $pt) {
        $revDebit = (float)$pt['debit'];
        $revCredit = (float)$pt['credit'];
        // Reverse: deduct credit, add back debit
        db()->prepare("
            UPDATE partners 
            SET current_balance = current_balance - ? + ?, updated_at = NOW() 
            WHERE id = ?
        ")->execute([$revCredit, $revDebit, (int)$pt['partner_id']]);
    }

    db()->prepare("DELETE FROM partner_transactions WHERE reference_type = 'expense' AND reference_id = ?")
        ->execute([$expenseId]);

    // If partner expense sharing is disabled, stop here without recording partner transactions
    if (!is_partner_expense_sharing_enabled()) {
        return;
    }

    $amount      = round((float)($expenseData['amount'] ?? 0), 2);
    $date        = $expenseData['expense_date'] ?? date('Y-m-d');
    $paidByType  = $expenseData['paid_by_type'] ?? 'central_cash';
    $paidByPid   = !empty($expenseData['paid_by_partner_id']) ? (int)$expenseData['paid_by_partner_id'] : null;
    $splitType   = $expenseData['split_type'] ?? 'equal_50_50';
    $customSplit = !empty($expenseData['split_details']) ? json_decode($expenseData['split_details'], true) : null;
    $desc        = (string)($expenseData['description'] ?? '');
    $catName     = (string)($expenseData['category_name'] ?? 'Expense');

    // If a partner paid from their own personal pocket, credit that partner for funding the expense!
    if ($paidByType === 'partner' && $paidByPid > 0) {
        $payer = get_partner_by_id($paidByPid);
        $payerName = $payer ? $payer['partner_name'] : "Partner #{$paidByPid}";
        record_partner_transaction([
            'partner_id'       => $paidByPid,
            'transaction_date' => $date,
            'transaction_type' => 'expense_paid',
            'reference_type'   => 'expense',
            'reference_id'     => $expenseId,
            'credit'           => $amount,
            'debit'            => 0.00,
            'description'      => "Paid expense out of personal pocket: {$catName} (" . format_currency($amount) . ")" . ($desc ? " | {$desc}" : "")
        ]);
    }

    // Now distribute the expense shares (charge/debit each partner for their share)
    $shares = calculate_expense_split($amount, $splitType, $customSplit);
    foreach ($shares as $pId => $shareAmt) {
        if ($shareAmt <= 0) continue;
        $partner = get_partner_by_id($pId);
        $pName = $partner ? $partner['partner_name'] : "Partner #{$pId}";

        record_partner_transaction([
            'partner_id'       => $pId,
            'transaction_date' => $date,
            'transaction_type' => 'expense_share',
            'reference_type'   => 'expense',
            'reference_id'     => $expenseId,
            'debit'            => $shareAmt,
            'credit'           => 0.00,
            'description'      => "Share of {$catName} expense: " . format_currency($shareAmt) . " (Split: {$splitType})" . ($desc ? " | {$desc}" : "")
        ]);
    }
}

/**
 * Record a Partner Cash Withdrawal / Advance from Central Sales Pool
 *
 * @param int $partnerId
 * @param float $amount
 * @param string $date
 * @param string $paymentMode
 * @param int $paidFromAccountId
 * @param string $description
 * @return int Transaction ID
 */
function record_partner_drawing(int $partnerId, float $amount, string $date, string $paymentMode, int $paidFromAccountId, string $description): int {
    $partner = get_partner_by_id($partnerId);
    if (!$partner) {
        throw new Exception("Partner not found.");
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new Exception("Withdrawal amount must be greater than zero.");
    }

    $openedTxn = false;
    if (!db()->inTransaction()) {
        db()->beginTransaction();
        $openedTxn = true;
    }
    try {
        // 1. Record in Partner Khata Ledger as cash_drawn (Debit)
        $txnId = record_partner_transaction([
            'partner_id'       => $partnerId,
            'transaction_date' => $date,
            'transaction_type' => 'cash_drawn',
            'reference_type'   => 'cash_drawing',
            'reference_id'     => null,
            'debit'            => $amount,
            'credit'           => 0.00,
            'description'      => "Cash withdrawal / advance taken from Central Pool: " . format_currency($amount) . ($description ? " | {$description}" : "")
        ]);

        // 2. Post General Ledger Entry
        // Debit: Partner Drawings / Owner Equity Account (3010)
        // Credit: Cash / Bank Account
        $journalHeader = [
            'entry_date'     => $date,
            'reference_type' => 'partner_drawing',
            'reference_id'   => $txnId,
            'narration'      => "Partner Drawing: {$partner['partner_name']} withdrew " . format_currency($amount) . " via {$paymentMode}" . ($description ? " | {$description}" : "")
        ];

        $journalLines = [
            [
                'account_id'  => 3010, // Owner Capital / Drawings
                'debit'       => $amount,
                'credit'      => 0.00,
                'description' => "Drawings by {$partner['partner_name']}",
                'party_type'  => 'partner',
                'party_id'    => $partnerId
            ],
            [
                'account_id'  => $paidFromAccountId,
                'debit'       => 0.00,
                'credit'      => $amount,
                'description' => "Paid to {$partner['partner_name']} via {$paymentMode}",
                'party_type'  => null,
                'party_id'    => null
            ]
        ];

        post_journal_entry($journalHeader, $journalLines);

        if ($openedTxn && db()->inTransaction()) {
            db()->commit();
        }
        return $txnId;
    } catch (Exception $e) {
        if ($openedTxn && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Fetch Managing Partner / Cash Custodian
 *
 * @return array|null
 */
function get_managing_partner(): ?array {
    $mp = db_fetch_one("SELECT * FROM partners WHERE role = 'managing_partner' AND is_active = 1 LIMIT 1");
    if (!$mp) {
        $mp = db_fetch_one("SELECT * FROM partners WHERE partner_code = 'PTR-CENTRAL' LIMIT 1");
    }
    if (!$mp) {
        $mp = db_fetch_one("SELECT * FROM partners WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    }
    return $mp;
}

/**
 * Record Daily Sales Cash Handover to Managing Partner from Day Closing
 *
 * @param int $partnerId
 * @param float $amount
 * @param string $date
 * @param int $paidFromAccountId
 * @param string $description
 * @param int|null $closingReference
 * @return int Transaction ID
 */
function record_partner_cash_handover(int $partnerId, float $amount, string $date, int $paidFromAccountId, string $description = '', ?int $closingReference = null): int {
    $partner = get_partner_by_id($partnerId);
    if (!$partner) {
        throw new Exception("Selected partner does not exist.");
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new Exception("Handover cash amount must be greater than zero.");
    }

    $openedTxn = false;
    if (!db()->inTransaction()) {
        db()->beginTransaction();
        $openedTxn = true;
    }

    try {
        $note = $description ? $description : "Daily sales cash handed over to {$partner['partner_name']} at Day Closing (" . format_date($date) . ")";
        
        // 1. Record in Partner Khata (Debit partner, increases what partner holds / owes)
        $txnId = record_partner_transaction([
            'partner_id'       => $partnerId,
            'transaction_date' => $date,
            'transaction_type' => 'sales_cash_handover',
            'reference_type'   => 'daily_closing',
            'reference_id'     => $closingReference,
            'debit'            => $amount,
            'credit'           => 0.00,
            'description'      => "سیلز کیش سپردگی: " . format_currency($amount) . " | " . $note
        ]);

        // 2. Post General Ledger Entry
        // Debit: Partner Drawings / Owner Equity Account (3010)
        // Credit: Cash in Hand (1001) / selected liquid drawer
        $journalHeader = [
            'entry_date'     => $date,
            'reference_type' => 'sales_cash_handover',
            'reference_id'   => $txnId,
            'narration'      => "Sales Cash Handover to {$partner['partner_name']} (" . format_currency($amount) . ") | {$note}"
        ];

        $journalLines = [
            [
                'account_id'  => 3010, // Owner Capital / Drawings
                'debit'       => $amount,
                'credit'      => 0.00,
                'description' => "Sales cash handed over to {$partner['partner_name']}",
                'party_type'  => 'partner',
                'party_id'    => $partnerId
            ],
            [
                'account_id'  => $paidFromAccountId,
                'debit'       => 0.00,
                'credit'      => $amount,
                'description' => "Cash transferred from drawer to {$partner['partner_name']}",
                'party_type'  => null,
                'party_id'    => null
            ]
        ];

        post_journal_entry($journalHeader, $journalLines);

        if ($openedTxn && db()->inTransaction()) {
            db()->commit();
        }
        return $txnId;
    } catch (Exception $e) {
        if ($openedTxn && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Record Cash Received Back from Partner into Business Cash/Bank
 *
 * @param int $partnerId
 * @param float $amount
 * @param string $date
 * @param int $depositToAccountId
 * @param string $paymentMode
 * @param string $description
 * @return int Transaction ID
 */
function record_partner_cash_received(int $partnerId, float $amount, string $date, int $depositToAccountId, string $paymentMode = 'cash', string $description = ''): int {
    $partner = get_partner_by_id($partnerId);
    if (!$partner) {
        throw new Exception("Selected partner does not exist.");
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new Exception("Received cash amount must be greater than zero.");
    }

    $openedTxn = false;
    if (!db()->inTransaction()) {
        db()->beginTransaction();
        $openedTxn = true;
    }

    try {
        $note = $description ? $description : "Cash received back / recovered from {$partner['partner_name']} (" . format_date($date) . ")";

        // 1. Record in Partner Khata (Credit partner, reduces what partner holds / owes)
        $txnId = record_partner_transaction([
            'partner_id'       => $partnerId,
            'transaction_date' => $date,
            'transaction_type' => 'cash_returned',
            'reference_type'   => 'partner_reimbursement',
            'reference_id'     => null,
            'debit'            => 0.00,
            'credit'           => $amount,
            'description'      => "کیش واپسی / وصولی از پارٹنر: " . format_currency($amount) . " | " . $note
        ]);

        // 2. Post General Ledger Entry
        // Debit: Cash in Hand (1001) / Bank Account
        // Credit: Partner Drawings / Owner Equity Account (3010)
        $journalHeader = [
            'entry_date'     => $date,
            'reference_type' => 'partner_cash_received',
            'reference_id'   => $txnId,
            'narration'      => "Cash Received from {$partner['partner_name']} (" . format_currency($amount) . ") via {$paymentMode} | {$note}"
        ];

        $journalLines = [
            [
                'account_id'  => $depositToAccountId,
                'debit'       => $amount,
                'credit'      => 0.00,
                'description' => "Cash received from {$partner['partner_name']} into drawer/bank",
                'party_type'  => null,
                'party_id'    => null
            ],
            [
                'account_id'  => 3010, // Owner Capital / Drawings
                'debit'       => 0.00,
                'credit'      => $amount,
                'description' => "Cash returned / reimbursement by {$partner['partner_name']}",
                'party_type'  => 'partner',
                'party_id'    => $partnerId
            ]
        ];

        post_journal_entry($journalHeader, $journalLines);

        if ($openedTxn && db()->inTransaction()) {
            db()->commit();
        }
        return $txnId;
    } catch (Exception $e) {
        if ($openedTxn && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Record an Inter-Partner Settlement / Balance Adjustment
 *
 * @param int $fromPartnerId Payer partner
 * @param int $toPartnerId Receiver partner
 * @param float $amount
 * @param string $date
 * @param string $description
 * @return void
 */
function record_partner_settlement(int $fromPartnerId, int $toPartnerId, float $amount, string $date, string $description): void {
    $from = get_partner_by_id($fromPartnerId);
    $to   = get_partner_by_id($toPartnerId);

    if (!$from || !$to) {
        throw new Exception("Both partners must be valid.");
    }
    if ($fromPartnerId === $toPartnerId) {
        throw new Exception("Payer and receiver cannot be the same partner.");
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new Exception("Settlement amount must be greater than zero.");
    }

    $openedTxn = false;
    if (!db()->inTransaction()) {
        db()->beginTransaction();
        $openedTxn = true;
    }
    try {
        // Payer Partner: credited because they paid money to settle
        record_partner_transaction([
            'partner_id'       => $fromPartnerId,
            'transaction_date' => $date,
            'transaction_type' => 'inter_settlement',
            'reference_type'   => 'inter_partner_transfer',
            'reference_id'     => $toPartnerId,
            'debit'            => 0.00,
            'credit'           => $amount,
            'description'      => "Direct settlement paid to {$to['partner_name']}: " . format_currency($amount) . ($description ? " | {$description}" : "")
        ]);

        // Receiver Partner: debited because they received money
        record_partner_transaction([
            'partner_id'       => $toPartnerId,
            'transaction_date' => $date,
            'transaction_type' => 'inter_settlement',
            'reference_type'   => 'inter_partner_transfer',
            'reference_id'     => $fromPartnerId,
            'debit'            => $amount,
            'credit'           => 0.00,
            'description'      => "Direct settlement received from {$from['partner_name']}: " . format_currency($amount) . ($description ? " | {$description}" : "")
        ]);

        if ($openedTxn && db()->inTransaction()) {
            db()->commit();
        }
    } catch (Exception $e) {
        if ($openedTxn && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Get Partner Complete Khata Ledger Statement
 *
 * @param int $partnerId
 * @param string $fromDate
 * @param string $toDate
 * @return array
 */
function get_partner_statement(int $partnerId, string $fromDate, string $toDate): array {
    $partner = get_partner_by_id($partnerId);
    if (!$partner) return [];

    // 1. Calculate Opening Balance before $fromDate
    $prior = db_fetch_one("
        SELECT 
            COALESCE(SUM(credit), 0) as total_credit,
            COALESCE(SUM(debit), 0) as total_debit
        FROM partner_transactions
        WHERE partner_id = ? AND transaction_date < ?
    ", [$partnerId, $fromDate]);

    $openingBalance = (float)($prior['total_credit'] - $prior['total_debit']);

    // 2. Fetch transactions in range
    $transactions = db_fetch_all("
        SELECT *
        FROM partner_transactions
        WHERE partner_id = ? AND transaction_date >= ? AND transaction_date <= ?
        ORDER BY transaction_date ASC, id ASC
    ", [$partnerId, $fromDate, $toDate]);

    $running = $openingBalance;
    foreach ($transactions as &$t) {
        $running = round($running + (float)$t['credit'] - (float)$t['debit'], 2);
        $t['calculated_balance'] = $running;
    }
    unset($t);

    return [
        'partner'         => $partner,
        'from_date'       => $fromDate,
        'to_date'         => $toDate,
        'opening_balance' => $openingBalance,
        'transactions'    => $transactions,
        'closing_balance' => $running
    ];
}

/**
 * Fetch Comprehensive Company Sales & Profit Summary for a Partner
 *
 * @param int $partnerId
 * @param string $fromDate
 * @param string $toDate
 * @return array
 */
function get_partner_sales_profit_summary(int $partnerId, string $fromDate, string $toDate): array {
    $partner = get_partner_by_id($partnerId);
    if (!$partner || empty($partner['supplier_id'])) {
        return [
            'gross_sales'        => 0.0,
            'return_amount'      => 0.0,
            'net_sales'          => 0.0,
            'cogs'               => 0.0,
            'gross_profit'       => 0.0,
            'profit_margin'      => 0.0,
            'market_receivables' => 0.0,
            'cartons_sold'       => 0.0,
            'pieces_sold'        => 0.0
        ];
    }

    $supId = (int)$partner['supplier_id'];

    // 1. Sales
    $sales = db_fetch_one("
        SELECT 
            COALESCE(SUM(sii.carton_qty), 0) as cartons_sold,
            COALESCE(SUM(sii.pcs_qty), 0) as pcs_sold,
            COALESCE(SUM(sii.line_total), 0) as gross_amount,
            COALESCE(SUM(sii.discount_amount), 0) as discount_amount,
            COALESCE(SUM(
                CASE 
                    WHEN si.gross_amount > 0 AND si.credit_amount > 0 
                    THEN (sii.line_total / si.gross_amount) * si.credit_amount 
                    ELSE 0 
                END
            ), 0) as market_receivables
        FROM sales_invoice_items sii
        JOIN sales_invoices si ON sii.invoice_id = si.id
        JOIN products p ON sii.product_id = p.id
        WHERE p.supplier_id = ? 
          AND si.invoice_date >= ? AND si.invoice_date <= ? 
          AND si.status != 'cancelled'
    ", [$supId, $fromDate, $toDate]) ?: [];

    // 2. Returns
    $returns = db_fetch_one("
        SELECT COALESCE(SUM(sri.amount), 0) as return_amount
        FROM sales_return_items sri
        JOIN sales_returns sr ON sri.sales_return_id = sr.id
        JOIN products p ON sri.product_id = p.id
        WHERE p.supplier_id = ? 
          AND sr.return_date >= ? AND sr.return_date <= ? 
          AND sr.status != 'rejected'
    ", [$supId, $fromDate, $toDate]) ?: [];

    // 3. Cost of Goods Sold (COGS)
    $cogsRow = db_fetch_one("
        SELECT 
            COALESCE(SUM(
                sii.quantity * (
                    CASE 
                        WHEN p.purchase_piece_rate > 0 THEN p.purchase_piece_rate 
                        WHEN p.carton_qty > 0 THEN p.purchase_rate / p.carton_qty 
                        ELSE p.purchase_rate 
                    END
                )
            ), 0) as total_cogs
        FROM sales_invoice_items sii
        JOIN sales_invoices si ON sii.invoice_id = si.id
        JOIN products p ON sii.product_id = p.id
        WHERE p.supplier_id = ? 
          AND si.invoice_date >= ? AND si.invoice_date <= ? 
          AND si.status != 'cancelled'
    ", [$supId, $fromDate, $toDate]) ?: [];

    $grossSales = (float)($sales['gross_amount'] ?? 0);
    $retAmount  = (float)($returns['return_amount'] ?? 0);
    $netSales   = max(0, $grossSales - $retAmount);
    $cogs       = (float)($cogsRow['total_cogs'] ?? 0);
    $grossProfit = $netSales - $cogs;
    $margin     = $netSales > 0 ? ($grossProfit / $netSales) * 100 : 0.0;

    return [
        'partner'            => $partner,
        'supplier_id'        => $supId,
        'gross_sales'        => $grossSales,
        'return_amount'      => $retAmount,
        'net_sales'          => $netSales,
        'cogs'               => $cogs,
        'gross_profit'       => $grossProfit,
        'profit_margin'      => round($margin, 2),
        'market_receivables' => (float)($sales['market_receivables'] ?? 0),
        'cartons_sold'       => (float)($sales['cartons_sold'] ?? 0),
        'pieces_sold'        => (float)($sales['pcs_sold'] ?? 0)
    ];
}
