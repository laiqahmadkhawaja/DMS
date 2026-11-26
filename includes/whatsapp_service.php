<?php
/**
 * DMS - Distribution Management System
 * Open-WA WhatsApp Messaging Service Provider
 * 
 * Supports sending:
 * - Direct Rich Text (Invoices, Receipts, Ledgers, Summaries)
 * - PDF Documents (Invoices, Load Sheets, Statement PDFs)
 * - Images (Receipts, Payment Proofs, Product Photos)
 * - Live Healthcheck & Session Status Pings
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Format any mobile phone number to Open-WA WhatsApp JID format (e.g. 923001234567@c.us)
 *
 * @param string $phone
 * @param string|null $defaultCountryCode
 * @return string|null
 */
function whatsapp_format_number(string $phone, ?string $defaultCountryCode = null): ?string {
    $phone = trim($phone);
    if (empty($phone)) {
        return null;
    }

    // If already has @c.us or @g.us suffix
    if (str_contains($phone, '@c.us') || str_contains($phone, '@g.us')) {
        return $phone;
    }

    // Strip all non-numeric characters except +
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    if (empty($cleaned)) {
        return null;
    }

    $cc = $defaultCountryCode ?: get_setting('whatsapp_default_country_code', '92');

    // Pakistan mobile number handling: 03001234567 -> 923001234567
    if (str_starts_with($cleaned, '03') && strlen($cleaned) === 11) {
        $cleaned = $cc . substr($cleaned, 1);
    } elseif (str_starts_with($cleaned, '3') && strlen($cleaned) === 10) {
        $cleaned = $cc . $cleaned;
    } elseif (str_starts_with($cleaned, '0') && strlen($cleaned) >= 10) {
        $cleaned = $cc . ltrim($cleaned, '0');
    }

    return $cleaned . '@c.us';
}

/**
 * Check if WhatsApp service is enabled in DMS settings
 *
 * @return bool
 */
function whatsapp_is_enabled(): bool {
    return (string)get_setting('whatsapp_enabled', '1') === '1';
}

/**
 * Execute HTTP REST API Request to self-hosted Open-WA server
 *
 * @param string $endpoint e.g. '/sendText', '/sendFile', '/getHostNumber'
 * @param string $method 'POST' or 'GET'
 * @param array $payload
 * @param int $timeoutSeconds
 * @return array ['success' => bool, 'status' => int, 'response' => mixed, 'error' => ?string]
 */
function whatsapp_api_request(string $endpoint, string $method = 'POST', array $payload = [], int $timeoutSeconds = 12): array {
    $baseUrl = rtrim((string)get_setting('whatsapp_api_url', 'http://localhost:8080'), '/');
    if (empty($baseUrl)) {
        return ['success' => false, 'status' => 0, 'response' => null, 'error' => 'Open-WA server URL is not configured.'];
    }

    $url = $baseUrl . '/' . ltrim($endpoint, '/');
    $apiKey = trim((string)get_setting('whatsapp_api_key', ''));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if (!empty($apiKey)) {
        $headers[] = 'api_key: ' . $apiKey;
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [
            'success'  => false,
            'status'   => $httpCode,
            'response' => null,
            'error'    => 'Connection failed: ' . ($curlErr ?: 'Unable to reach Open-WA server at ' . $baseUrl)
        ];
    }

    $json = json_decode($raw, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    return [
        'success'  => $isSuccess,
        'status'   => $httpCode,
        'response' => $json !== null ? $json : $raw,
        'error'    => $isSuccess ? null : ($json['message'] ?? $json['error'] ?? "HTTP error {$httpCode}")
    ];
}

/**
 * Check connection status with self-hosted Open-WA server
 *
 * @return array
 */
function whatsapp_check_connection(): array {
    // 1. Try getHostNumber or getMe endpoint
    $res = whatsapp_api_request('/getHostNumber', 'GET', [], 5);
    if ($res['success']) {
        return [
            'connected'   => true,
            'host_number' => $res['response']['response'] ?? $res['response'] ?? 'Connected',
            'details'     => 'Open-WA server online and authenticated.'
        ];
    }

    // 2. Try pinging root or battery
    $resPing = whatsapp_api_request('/getBatteryLevel', 'GET', [], 4);
    if ($resPing['success']) {
        return [
            'connected'   => true,
            'host_number' => 'Online',
            'details'     => 'Open-WA server online.'
        ];
    }

    return [
        'connected' => false,
        'error'     => $res['error'] ?: 'Could not connect to Open-WA session.'
    ];
}

/**
 * Send Plain / Rich Formatted Text Message via Open-WA
 *
 * @param string $phone Customer/User mobile number
 * @param string $message Text content with standard WhatsApp markdown (*bold*, _italic_, etc.)
 * @return array
 */
function whatsapp_send_text(string $phone, string $message): array {
    $jid = whatsapp_format_number($phone);
    if (!$jid) {
        return ['success' => false, 'error' => 'Invalid mobile phone number provided.'];
    }

    // Open-WA supports both {to, content} and {chatId, body} formats
    $payload = [
        'to'      => $jid,
        'chatId'  => $jid,
        'content' => $message,
        'body'    => $message
    ];

    return whatsapp_api_request('/sendText', 'POST', $payload);
}

/**
 * Send File (PDF, Image, Excel, etc.) via Open-WA
 *
 * @param string $phone
 * @param string $base64OrDataUri Raw base64 string or data:application/pdf;base64,...
 * @param string $filename Name of attachment (e.g. "Invoice_1001.pdf")
 * @param string $caption Optional message caption
 * @return array
 */
function whatsapp_send_file(string $phone, string $base64OrDataUri, string $filename, string $caption = ''): array {
    $jid = whatsapp_format_number($phone);
    if (!$jid) {
        return ['success' => false, 'error' => 'Invalid mobile phone number provided.'];
    }

    // Ensure data URI prefix if raw base64
    if (!str_starts_with($base64OrDataUri, 'data:')) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream'
        };
        $base64OrDataUri = "data:{$mime};base64," . $base64OrDataUri;
    }

    $payload = [
        'to'       => $jid,
        'chatId'   => $jid,
        'file'     => $base64OrDataUri,
        'filename' => $filename,
        'caption'  => $caption
    ];

    // Try /sendFileFromBase64 first, fallback to /sendFile
    $res = whatsapp_api_request('/sendFileFromBase64', 'POST', $payload);
    if (!$res['success']) {
        $res = whatsapp_api_request('/sendFile', 'POST', $payload);
    }

    return $res;
}

/**
 * Send Local File from Disk (PDF or Image)
 *
 * @param string $phone
 * @param string $filePath Absolute path on disk
 * @param string|null $filename Optional display filename
 * @param string $caption
 * @return array
 */
function whatsapp_send_local_file(string $phone, string $filePath, ?string $filename = null, string $caption = ''): array {
    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => "File not found on server: {$filePath}"];
    }

    $data = file_get_contents($filePath);
    $b64 = base64_encode($data);
    $name = $filename ?: basename($filePath);

    return whatsapp_send_file($phone, $b64, $name, $caption);
}

/**
 * Build Professional WhatsApp Bill Text for a Sales Invoice
 *
 * @param int $invoiceId
 * @return array ['text' => string, 'phone' => string, 'invoice' => array]
 */
function whatsapp_build_invoice_summary(int $invoiceId): array {
    $inv = db_fetch_one("
        SELECT i.*, c.shop_name, c.owner_name, c.phone as customer_phone, c.current_balance,
               a.area_name, b.booker_name, s.salesman_name
        FROM sales_invoices i
        JOIN customers c ON i.customer_id = c.id
        LEFT JOIN areas a ON i.area_id = a.id
        LEFT JOIN bookers b ON i.booker_id = b.id
        LEFT JOIN salesmen s ON i.salesman_id = s.id
        WHERE i.id = ?
    ", [$invoiceId]);

    if (!$inv) {
        throw new Exception("Invoice #{$invoiceId} not found.");
    }

    $items = db_fetch_all("
        SELECT ii.*, p.product_name, p.packing_description
        FROM sales_invoice_items ii
        JOIN products p ON ii.product_id = p.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ", [$invoiceId]);

    $clientName = get_setting('distributor_name', CLIENT_NAME);
    $clientPhone = get_setting('distributor_phone', '0300-6732761');
    $currency = DEFAULT_CURRENCY;
    $date = date('d-M-Y', strtotime($inv['invoice_date']));

    $lines = [];
    $lines[] = "🧾 *" . strtoupper($clientName) . "*";
    $lines[] = "📍 *" . CLIENT_LOCATION . "*";
    $lines[] = "------------------------------------------";
    $lines[] = "📄 *Bill No:* " . $inv['invoice_number'];
    $lines[] = "📅 *Date:* " . $date;
    $lines[] = "🏪 *Customer:* " . $inv['shop_name'] . ($inv['area_name'] ? " ({$inv['area_name']})" : "");
    if (!empty($inv['booker_name'])) {
        $lines[] = "👤 *Order Booker:* " . $inv['booker_name'];
    }
    $lines[] = "------------------------------------------";
    $lines[] = "📦 *ITEMS:*";

    $sr = 1;
    foreach ($items as $item) {
        $qtyParts = [];
        if ((float)$item['carton_qty'] > 0) $qtyParts[] = number_format($item['carton_qty'], 0) . " Ctn";
        if ((float)($item['pack_qty'] ?? 0) > 0) $qtyParts[] = number_format($item['pack_qty'], 0) . " Pck";
        if ((float)($item['pcs_qty'] ?? 0) > 0) $qtyParts[] = number_format($item['pcs_qty'], 0) . " Pcs";
        $qtyStr = !empty($qtyParts) ? implode(', ', $qtyParts) : number_format($item['quantity'], 0) . " Units";

        $lines[] = "{$sr}. *{$item['product_name']}*";
        $lines[] = "   ↳ {$qtyStr} @ {$currency} " . number_format($item['rate'], 2) . " = *{$currency} " . number_format($item['total_amount'], 0) . "*";
        $sr++;
    }

    $lines[] = "------------------------------------------";
    $lines[] = "💵 *Bill Subtotal:* {$currency} " . number_format($inv['total_amount'], 0);

    $discounts = (float)$inv['item_discount_amount'] + (float)$inv['extra_discount_amount'] + (float)($inv['subtotal_discount_amount'] ?? 0);
    if ($discounts > 0) {
        $lines[] = "🏷️ *Total Discount:* -{$currency} " . number_format($discounts, 0);
    }

    $lines[] = "💰 *Net Bill Amount:* *{$currency} " . number_format($inv['net_amount'], 0) . "*";

    // Cash Paid vs Balance
    $paid = (float)$inv['paid_amount'];
    if ($paid > 0) {
        $lines[] = "✅ *Cash Paid:* {$currency} " . number_format($paid, 0);
    }

    $remainingDue = max(0, (float)$inv['net_amount'] - $paid);
    if ($remainingDue > 0) {
        $lines[] = "⏳ *This Bill Balance:* {$currency} " . number_format($remainingDue, 0);
    }

    // Ledger Total Balance
    $custBal = (float)$inv['current_balance'];
    $lines[] = "📊 *Current Total Account Balance:* *{$currency} " . number_format($custBal, 0) . "*";

    // Custom bill instructions from settings
    $instructions = trim((string)get_setting('bill_instructions', ''));
    if (!empty($instructions)) {
        $lines[] = "------------------------------------------";
        $lines[] = "📝 _" . $instructions . "_";
    }

    $lines[] = "------------------------------------------";
    $lines[] = "شکریہ! آپ کے کاروبار کا شکریہ۔";
    if (!empty($clientPhone)) {
        $lines[] = "📞 رابطہ: {$clientPhone}";
    }

    return [
        'text'     => implode("\n", $lines),
        'phone'    => $inv['customer_phone'],
        'invoice'  => $inv
    ];
}

/**
 * Send Complete Invoice Bill to Customer via WhatsApp
 *
 * @param int $invoiceId
 * @param string|null $overridePhone Optional override phone number
 * @return array
 */
function whatsapp_send_invoice(int $invoiceId, ?string $overridePhone = null): array {
    $data = whatsapp_build_invoice_summary($invoiceId);
    $phone = $overridePhone ?: $data['phone'];

    if (empty($phone)) {
        return ['success' => false, 'error' => "No mobile phone number found for shop: {$data['invoice']['shop_name']}"];
    }

    // Send the rich formatted text bill
    $textResult = whatsapp_send_text($phone, $data['text']);
    
    // Log audit trail
    if ($textResult['success']) {
        log_audit(
            'whatsapp_invoice_sent',
            'sales_invoices',
            $invoiceId,
            "WhatsApp bill for {$data['invoice']['invoice_number']} sent to {$phone}"
        );
    }

    return $textResult;
}

/**
 * Build Customer Ledger Statement Summary Text
 *
 * @param int $customerId
 * @param string|null $startDate
 * @param string|null $endDate
 * @return array
 */
function whatsapp_build_customer_statement(int $customerId, ?string $startDate = null, ?string $endDate = null): array {
    $cust = db_fetch_one("SELECT * FROM customers WHERE id = ?", [$customerId]);
    if (!$cust) {
        throw new Exception("Customer #{$customerId} not found.");
    }

    $currency = DEFAULT_CURRENCY;
    $clientName = get_setting('distributor_name', CLIENT_NAME);
    $clientPhone = get_setting('distributor_phone', '0300-6732761');

    $lines = [];
    $lines[] = "🏢 *" . strtoupper($clientName) . "*";
    $lines[] = "📊 *CUSTOMER STATEMENT / کھاتہ سمری*";
    $lines[] = "📅 *Date:* " . date('d-M-Y H:i');
    $lines[] = "------------------------------------------";
    $lines[] = "🏪 *Shop:* " . $cust['shop_name'];
    $lines[] = "👤 *Owner:* " . ($cust['owner_name'] ?: 'N/A');
    $lines[] = "📍 *Area:* " . ($cust['city'] ?: CLIENT_LOCATION);
    $lines[] = "------------------------------------------";
    $lines[] = "💰 *Opening Balance:* {$currency} " . number_format($cust['opening_balance'], 0);
    $lines[] = "💳 *CURRENT OUTSTANDING BALANCE:*";
    $lines[] = "👉 *{$currency} " . number_format($cust['current_balance'], 0) . "*";
    $lines[] = "------------------------------------------";
    $lines[] = "برائے مہربانی اپنا کھاتہ چیک فرمائیں اور بقایا جات کی بروقت ادائیگی یقینی بنائیں۔";
    if (!empty($clientPhone)) {
        $lines[] = "📞 رابطہ: {$clientPhone}";
    }

    return [
        'text'     => implode("\n", $lines),
        'phone'    => $cust['phone'],
        'customer' => $cust
    ];
}

/**
 * Broadcast Daily Closing / Roznamcha Summary to Owners & Managing Partners
 *
 * @param string|null $closingDate YYYY-MM-DD (defaults to today)
 * @return array
 */
function whatsapp_broadcast_daily_closing(?string $closingDate = null): array {
    $date = $closingDate ?: date('Y-m-d');
    $currency = DEFAULT_CURRENCY;
    $clientName = get_setting('distributor_name', CLIENT_NAME);

    // Fetch daily aggregates
    $totalSales = (float)db_fetch_val("SELECT COALESCE(SUM(net_amount), 0) FROM sales_invoices WHERE invoice_date = ? AND status != 'cancelled'", [$date]);
    $cashSales = (float)db_fetch_val("SELECT COALESCE(SUM(paid_amount), 0) FROM sales_invoices WHERE invoice_date = ? AND status != 'cancelled'", [$date]);
    $recoveries = (float)db_fetch_val("SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE payment_date = ?", [$date]);
    $expenses = (float)db_fetch_val("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = ?", [$date]);
    $returns = (float)db_fetch_val("SELECT COALESCE(SUM(net_amount), 0) FROM sales_returns WHERE return_date = ? AND status != 'cancelled'", [$date]);

    $totalCashIn = $cashSales + $recoveries;
    $netCashRemaining = $totalCashIn - $expenses;

    $lines = [];
    $lines[] = "📈 *" . strtoupper($clientName) . "*";
    $lines[] = "📊 *DAILY ROZNAMCHA / کلوزنگ سمری*";
    $lines[] = "📅 *Date:* " . date('D, d-M-Y', strtotime($date));
    $lines[] = "------------------------------------------";
    $lines[] = "📦 *Total Sales Invoiced:* {$currency} " . number_format($totalSales, 0);
    $lines[] = "💵 *Cash Sales:* {$currency} " . number_format($cashSales, 0);
    $lines[] = "🤝 *Market Recoveries:* {$currency} " . number_format($recoveries, 0);
    $lines[] = "💰 *Total Cash Collected:* *{$currency} " . number_format($totalCashIn, 0) . "*";
    $lines[] = "📉 *Operating Expenses:* -{$currency} " . number_format($expenses, 0);
    if ($returns > 0) {
        $lines[] = "↩️ *Market Returns:* {$currency} " . number_format($returns, 0);
    }
    $lines[] = "------------------------------------------";
    $lines[] = "🏆 *NET CASH REMAINING:* *{$currency} " . number_format($netCashRemaining, 0) . "*";
    $lines[] = "------------------------------------------";
    $lines[] = "DMS Automated Executive Update";

    $message = implode("\n", $lines);

    $ownerPhonesStr = (string)get_setting('whatsapp_owner_phones', '');
    $phones = array_filter(array_map('trim', explode(',', $ownerPhonesStr)));

    if (empty($phones)) {
        return ['success' => false, 'error' => 'No owner phone numbers configured in WhatsApp settings.'];
    }

    $results = [];
    foreach ($phones as $p) {
        $results[$p] = whatsapp_send_text($p, $message);
    }

    return [
        'success' => true,
        'results' => $results,
        'message' => 'Daily closing summary sent to ' . count($phones) . ' recipients.'
    ];
}
