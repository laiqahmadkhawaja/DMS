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
 * Normalize Open-WA API Base URL to guarantee proper http:// or https:// protocol
 *
 * @param string $url
 * @return string
 */
function whatsapp_normalize_url(string $url): string {
    $url = trim($url);
    if (empty($url)) {
        return '';
    }

    // Check if protocol is already specified (http:// or https://)
    if (!preg_match('#^https?://#i', $url)) {
        // If domain has extension or subdomain and is not a local private IP, default to https://
        if (str_contains($url, '.') && !str_starts_with($url, '192.168.') && !str_starts_with($url, '10.') && !str_starts_with($url, '127.0.0.1') && !str_starts_with($url, 'localhost')) {
            $url = 'https://' . $url;
        } else {
            $url = 'http://' . $url;
        }
    }

    return rtrim($url, '/');
}

/**
 * Get active Open-WA configuration with optional runtime overrides
 *
 * @param string|null $baseUrl
 * @param string|null $apiKey
 * @param string|null $sessionId
 * @return array ['base_url' => string, 'api_key' => string, 'session_id' => string, 'is_https' => bool]
 */
function whatsapp_get_config(?string $baseUrl = null, ?string $apiKey = null, ?string $sessionId = null): array {
    $rawUrl = $baseUrl !== null ? $baseUrl : (string)get_setting('whatsapp_api_url', '');
    $normalizedUrl = whatsapp_normalize_url($rawUrl);

    $resolvedApiKey = $apiKey !== null ? trim($apiKey) : trim((string)get_setting('whatsapp_api_key', ''));
    $resolvedSession = $sessionId !== null ? trim($sessionId) : trim((string)get_setting('whatsapp_session_id', 'default'));

    if (empty($resolvedSession)) {
        $resolvedSession = 'default';
    }

    return [
        'base_url'   => $normalizedUrl,
        'api_key'    => $resolvedApiKey,
        'session_id' => $resolvedSession,
        'is_https'   => str_starts_with(strtolower($normalizedUrl), 'https://')
    ];
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
 * Execute HTTP REST API Request to self-hosted Open-WA server (supports HTTP and HTTPS)
 *
 * @param string $endpoint e.g. '/sendText', '/sendFile', '/api/sessions/{sessionId}/messages/send-text'
 * @param string $method 'POST' or 'GET'
 * @param array $payload
 * @param int $timeoutSeconds
 * @param array|null $configOverride ['base_url' => ..., 'api_key' => ..., 'session_id' => ...]
 * @return array ['success' => bool, 'status' => int, 'response' => mixed, 'error' => ?string]
 */
function whatsapp_api_request(string $endpoint, string $method = 'POST', array $payload = [], int $timeoutSeconds = 12, ?array $configOverride = null): array {
    $cfg = whatsapp_get_config(
        $configOverride['base_url'] ?? null,
        $configOverride['api_key'] ?? null,
        $configOverride['session_id'] ?? null
    );

    $baseUrl = $cfg['base_url'];
    if (empty($baseUrl)) {
        return ['success' => false, 'status' => 0, 'response' => null, 'error' => 'Open-WA server URL is not configured.'];
    }

    $apiKey    = $cfg['api_key'];
    $sessionId = $cfg['session_id'];

    $url = $baseUrl . '/' . ltrim($endpoint, '/');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    
    // Support both HTTP and HTTPS (including self-signed or custom domain certs)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 4);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if (!empty($apiKey)) {
        $headers[] = 'X-API-Key: ' . $apiKey;
        $headers[] = 'api_key: ' . $apiKey;
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    if (!empty($sessionId)) {
        $headers[] = 'session: ' . $sessionId;
        $headers[] = 'sessionId: ' . $sessionId;
        $headers[] = 'X-Session-Id: ' . $sessionId;
    }

    if (strtoupper($method) === 'POST') {
        // Only inject session in payload for classic/legacy endpoints (Modern Gateway has session in URL)
        if (!empty($sessionId) && !str_contains($endpoint, '/sessions/')) {
            if (!isset($payload['session'])) $payload['session'] = $sessionId;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($raw === false) {
        $detail = $curlErr;
        if ($curlErrNo === CURLE_COULDNT_RESOLVE_HOST) {
            $parsedHost = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $detail = "Could not resolve domain name '{$parsedHost}'. Check your server URL, DNS settings, or network connection.";
        } elseif ($curlErrNo === CURLE_OPERATION_TIMEDOUT) {
            $detail = "Connection timed out to Open-WA server ({$baseUrl}). Ensure server is online and accepting requests.";
        } elseif ($curlErrNo === CURLE_COULDNT_CONNECT) {
            $detail = "Could not connect to Open-WA server ({$baseUrl}). Port or server is unreachable.";
        }
        return [
            'success'  => false,
            'status'   => $httpCode,
            'response' => null,
            'error'    => 'Connection failed: ' . ($detail ?: 'Unable to reach Open-WA server at ' . $baseUrl)
        ];
    }

    $json = json_decode($raw, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    $errMsg = null;
    if (!$isSuccess) {
        if (isset($json['message'])) {
            $errMsg = is_array($json['message']) ? implode('; ', $json['message']) : (string)$json['message'];
        } elseif (isset($json['error'])) {
            $errMsg = is_array($json['error']) ? implode('; ', $json['error']) : (string)$json['error'];
        } elseif (is_string($raw) && strlen($raw) < 200) {
            $errMsg = trim($raw);
        } else {
            $errMsg = "HTTP error {$httpCode}";
        }
    }

    return [
        'success'  => $isSuccess,
        'status'   => $httpCode,
        'response' => $json !== null ? $json : $raw,
        'error'    => $errMsg
    ];
}

/**
 * Check connection status with self-hosted Open-WA server (supports both HTTP and HTTPS, with Session ID)
 *
 * @param string|null $baseUrl Optional live URL from form
 * @param string|null $apiKey Optional live API key from form
 * @param string|null $sessionId Optional live Session ID from form
 * @return array
 */
function whatsapp_check_connection(?string $baseUrl = null, ?string $apiKey = null, ?string $sessionId = null): array {
    $cfg = whatsapp_get_config($baseUrl, $apiKey, $sessionId);
    if (empty($cfg['base_url'])) {
        return [
            'connected' => false,
            'error'     => 'Open-WA server URL is not configured. Please enter your Open-WA server address (e.g. https://wa.darwarqamgrw.in).'
        ];
    }

    $sId = $cfg['session_id'];
    $override = ['base_url' => $cfg['base_url'], 'api_key' => $cfg['api_key'], 'session_id' => $sId];

    // Try standard endpoints in priority order (Modern OpenWA Gateway first, then fallback)
    $testEndpoints = [
        "/api/sessions/" . urlencode($sId),
        "/api/sessions/" . urlencode($sId) . "/status",
        "/api/sessions",
        "/health",
        "/status",
        "/" . urlencode($sId) . "/getHostNumber",
        "/getHostNumber",
        "/" . urlencode($sId) . "/getBatteryLevel",
        "/getBatteryLevel",
        "/api/" . urlencode($sId) . "/status-session",
        "/"
    ];

    $lastError = 'Could not connect to Open-WA server.';

    foreach ($testEndpoints as $ep) {
        $res = whatsapp_api_request($ep, 'GET', [], 4, $override);
        if ($res['success']) {
            $body = $res['response'];
            $hostNo = 'Online & Connected';
            if (is_array($body)) {
                $statusKey = $body['status'] ?? $body['state'] ?? $body['sessionStatus'] ?? null;
                if ($statusKey) {
                    $hostNo = match (strtolower((string)$statusKey)) {
                        'ready', 'connected' => 'Online & Ready',
                        'qr_ready', 'scan_qr_code' => 'QR Scan Required (Please scan QR code)',
                        'authenticating', 'starting' => 'Authenticating...',
                        'action_required' => 'Action Required on Phone',
                        default => 'Status: ' . ucfirst((string)$statusKey)
                    };
                }
                if (!empty($body['host']) || !empty($body['phone'])) {
                    $phoneStr = $body['host'] ?? $body['phone'];
                    $hostNo .= " ({$phoneStr})";
                }
            } elseif (is_string($body) && strlen($body) < 50) {
                $hostNo = $body;
            }
            return [
                'connected'   => true,
                'host_number' => $hostNo,
                'details'     => "Open-WA server online ({$cfg['base_url']}) with Session: {$sId}"
            ];
        }

        // Authentication failure is fatal - don't keep probing
        if ($res['status'] === 401 || $res['status'] === 403) {
            return [
                'connected' => false,
                'error'     => "Server reached at {$cfg['base_url']}, but Authentication failed (HTTP {$res['status']}). Check your API Secret Key."
            ];
        }

        // Network-level failures (cannot resolve domain, timeout, connection refused) - fail immediately!
        if (!empty($res['error'])) {
            $lastError = $res['error'];
            if (str_contains($res['error'], 'Could not resolve') || 
                str_contains($res['error'], 'Connection timed out') ||
                str_contains($res['error'], 'Could not connect')) {
                return [
                    'connected' => false,
                    'error'     => $res['error']
                ];
            }
        }
    }

    return [
        'connected' => false,
        'error'     => $lastError
    ];
}

/**
 * Send Plain / Rich Formatted Text Message via Open-WA
 *
 * @param string $phone Customer/User mobile number
 * @param string $message Text content with standard WhatsApp markdown (*bold*, _italic_, etc.)
 * @param array|null $configOverride Optional live config
 * @return array
 */
function whatsapp_send_text(string $phone, string $message, ?array $configOverride = null): array {
    $jid = whatsapp_format_number($phone);
    if (!$jid) {
        return ['success' => false, 'error' => 'Invalid mobile phone number provided.'];
    }

    $cfg = whatsapp_get_config(
        $configOverride['base_url'] ?? null,
        $configOverride['api_key'] ?? null,
        $configOverride['session_id'] ?? null
    );
    $sId = $cfg['session_id'];

    // Strict payload for Modern Open-WA Gateway SendTextMessageDto
    $modernPayload = [
        'chatId' => $jid,
        'text'   => $message
    ];

    // 1. Try Modern Open-WA: /api/sessions/{sessionId}/messages/send-text
    $res = whatsapp_api_request("/api/sessions/" . urlencode($sId) . "/messages/send-text", 'POST', $modernPayload, 16, $configOverride);
    if ($res['success']) {
        return $res;
    }
    if ($res['status'] !== 404 && $res['status'] !== 0) {
        return $res;
    }

    // 2. Try Classic Open-WA with session prefix: /{sessionId}/sendText
    $classicPayload = [
        'chatId'    => $jid,
        'to'        => $jid,
        'content'   => $message,
        'text'      => $message,
        'body'      => $message,
        'session'   => $sId
    ];

    if ($res['status'] === 404) {
        $res = whatsapp_api_request("/" . urlencode($sId) . "/sendText", 'POST', $classicPayload, 12, $configOverride);
        if ($res['success']) {
            return $res;
        }
    }

    // 3. Try Classic Open-WA root: /sendText
    if ($res['status'] === 404) {
        $res = whatsapp_api_request('/sendText', 'POST', $classicPayload, 12, $configOverride);
    }

    return $res;
}

/**
 * Send File (PDF, Image, Excel, etc.) via Open-WA
 *
 * @param string $phone
 * @param string $base64OrDataUri Raw base64 string or data:application/pdf;base64,...
 * @param string $filename Name of attachment (e.g. "Invoice_1001.pdf")
 * @param string $caption Optional message caption
 * @param array|null $configOverride Optional live config
 * @return array
 */
function whatsapp_send_file(string $phone, string $base64OrDataUri, string $filename, string $caption = '', ?array $configOverride = null): array {
    $jid = whatsapp_format_number($phone);
    if (!$jid) {
        return ['success' => false, 'error' => 'Invalid mobile phone number provided.'];
    }

    $cfg = whatsapp_get_config(
        $configOverride['base_url'] ?? null,
        $configOverride['api_key'] ?? null,
        $configOverride['session_id'] ?? null
    );
    if (empty($cfg['base_url'])) {
        return ['success' => false, 'error' => 'Open-WA server URL is not configured.'];
    }
    $sId = $cfg['session_id'];

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        'csv' => 'text/csv',
        default => 'application/octet-stream'
    };

    $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);

    // Extract raw base64 and data URI prefix
    if (str_starts_with($base64OrDataUri, 'data:')) {
        $dataUri = $base64OrDataUri;
        $parts = explode(',', $base64OrDataUri, 2);
        $rawBase64 = $parts[1] ?? $base64OrDataUri;
    } else {
        $rawBase64 = $base64OrDataUri;
        $dataUri = "data:{$mime};base64," . $base64OrDataUri;
    }

    // 1. Strict payload for Modern Open-WA Gateway SendMediaMessageDto
    $modernPayload = [
        'chatId'   => $jid,
        'base64'   => $rawBase64,
        'mimetype' => $mime
    ];
    if (!empty($caption)) {
        $modernPayload['caption'] = $caption;
    }
    if (!$isImage && !empty($filename)) {
        $modernPayload['filename'] = $filename;
    }

    // Priority 1: Modern Open-WA Gateway endpoints
    $modernEndpoints = $isImage
        ? [
            "/api/sessions/" . urlencode($sId) . "/messages/send-image",
            "/api/sessions/" . urlencode($sId) . "/messages/send-document"
          ]
        : [
            "/api/sessions/" . urlencode($sId) . "/messages/send-document"
          ];

    foreach ($modernEndpoints as $ep) {
        $res = whatsapp_api_request($ep, 'POST', $modernPayload, 20, $configOverride);
        if ($res['success']) {
            return $res;
        }
        // If not a 404 route error, return the actual failure response from server
        if ($res['status'] !== 404 && $res['status'] !== 0) {
            return $res;
        }
    }

    // 2. Fallback payload for Classic / Legacy Open-WA endpoints
    $classicPayload = [
        'chatId'    => $jid,
        'to'        => $jid,
        'file'      => $dataUri,
        'filename'  => $filename,
        'caption'   => $caption,
        'session'   => $sId
    ];

    $legacyEndpoints = $isImage
        ? [
            "/" . urlencode($sId) . "/sendImage",
            "/" . urlencode($sId) . "/sendFile",
            "/sendImage",
            "/sendFile"
          ]
        : [
            "/" . urlencode($sId) . "/sendFile",
            "/sendFile"
          ];

    foreach ($legacyEndpoints as $ep) {
        $res = whatsapp_api_request($ep, 'POST', $classicPayload, 20, $configOverride);
        if ($res['success']) {
            return $res;
        }
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
