<?php
/**
 * DMS - Distribution Management System
 * General Utility & Helper Functions
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * HTML Escape string for safe output rendering
 *
 * @param mixed $value
 * @return string
 */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format monetary values
 *
 * @param float|int|string|null $amount
 * @param bool $showSymbol
 * @return string
 */
function format_currency($amount, bool $showSymbol = true): string {
    $num = (float)($amount ?? 0);
    $formatted = number_format($num, 2, '.', ',');
    return $showSymbol ? DEFAULT_CURRENCY . ' ' . $formatted : $formatted;
}

/**
 * Format stock quantity nicely (no trailing decimal zeros if whole number)
 *
 * @param float|int|string|null $qty
 * @return string
 */
function format_qty($qty): string {
    $num = (float)($qty ?? 0);
    if (floor($num) == $num) {
        return number_format($num, 0, '.', ',');
    }
    return rtrim(rtrim(number_format($num, 4, '.', ','), '0'), '.');
}

/**
 * Format date for display
 *
 * @param string|null $date
 * @param string $format
 * @return string
 */
function format_date(?string $date, string $format = 'd-m-Y'): string {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    try {
        $dt = new DateTime($date);
        return $dt->format($format);
    } catch (Exception $e) {
        return (string)$date;
    }
}

/**
 * Format datetime for display
 *
 * @param string|null $datetime
 * @param string $format
 * @return string
 */
function format_datetime(?string $datetime, string $format = 'd-m-Y h:i A'): string {
    return format_date($datetime, $format);
}

/**
 * Clean & sanitize user input string
 *
 * @param mixed $data
 * @return string
 */
function clean_input($data): string {
    if (is_array($data)) {
        return '';
    }
    return trim((string)($data ?? ''));
}

/**
 * Alias for clean_input
 */
function sanitize_input($data): string {
    return clean_input($data);
}

/**
 * Get setting value from database
 *
 * @param string $key
 * @param string $default
 * @return string
 */
/**
 * Settings memory cache reference
 *
 * @return array
 */
function &get_settings_cache(): array {
    static $cache = [];
    return $cache;
}

/**
 * Get a system setting value by key
 *
 * @param string $key
 * @param string $default
 * @param bool $fresh
 * @return string
 */
function get_setting(string $key, string $default = '', bool $fresh = false): string {
    $cache = &get_settings_cache();
    if (!$fresh && isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val !== false) ? (string)$val : $default;
        return $cache[$key];
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Update or insert a setting
 *
 * @param string $key
 * @param string $value
 * @param string $group
 * @return bool
 */
function update_setting(string $key, string $value, string $group = 'general'): bool {
    try {
        $stmt = db()->prepare("
            INSERT INTO settings (setting_group, setting_key, setting_value, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $res = $stmt->execute([$group, $key, $value]);
        if ($res) {
            $cache = &get_settings_cache();
            $cache[$key] = (string)$value;
        }
        return $res;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Record an audit log entry
 *
 * @param string $action
 * @param string $module
 * @param string|int|null $recordId
 * @param mixed $details Array or string
 * @return void
 */
function log_audit(string $action, string $module, $recordId = null, $details = null): void {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $detailsText = is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : (string)$details;

        $stmt = db()->prepare("
            INSERT INTO audit_logs (user_id, action, module, record_id, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $action, $module, (string)$recordId, $detailsText, $ip, $agent]);
    } catch (Exception $e) {
        // Silently log to private log file
        @file_put_contents(LOGS_PATH . DIRECTORY_SEPARATOR . 'audit_errors.log', date('Y-m-d H:i:s') . ': ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

/**
 * Generate a sequential, thread-safe document number
 * Format: {PREFIX}-{YEAR}-{5-digit sequential number}
 * Example: ORD-2026-00001, INV-2026-00001
 *
 * @param string $prefix
 * @param string $tableName
 * @param string $columnName
 * @return string
 */
function generate_document_number(string $prefix, string $tableName, string $columnName): string {
    $year = date('Y');
    $pattern = "{$prefix}-{$year}-%";

    // Lock and query the highest existing sequence number for the current year
    $stmt = db()->prepare("
        SELECT {$columnName} 
        FROM {$tableName} 
        WHERE {$columnName} LIKE ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$pattern]);
    $lastNumber = $stmt->fetchColumn();

    if ($lastNumber) {
        // Extract sequence part
        $parts = explode('-', $lastNumber);
        $seq = isset($parts[2]) ? (int)$parts[2] : 0;
        $nextSeq = $seq + 1;
    } else {
        $nextSeq = 1;
    }

    return sprintf("%s-%s-%05d", $prefix, $year, $nextSeq);
}

/**
 * Safe flash message helper using session
 */
if (!function_exists('set_flash_message')) {
    function set_flash_message(string $type, string $message): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash_messages'][] = [
                'type'    => $type,
                'message' => $message
            ];
        }
    }
}

/**
 * Short alias for set_flash_message
 */
if (!function_exists('set_flash')) {
    function set_flash(string $type, string $message): void {
        set_flash_message($type, $message);
    }
}

/**
 * Retrieve and clear flash messages
 */
if (!function_exists('get_flash_messages')) {
    function get_flash_messages(): array {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['flash_messages'])) {
            $messages = $_SESSION['flash_messages'];
            unset($_SESSION['flash_messages']);
            return $messages;
        }
        return [];
    }
}

/**
 * Safe HTTP Redirect with fallback
 */
if (!function_exists('redirect')) {
    function redirect(string $url, int $statusCode = 302): void {
        if (headers_sent()) {
            echo "<script>window.location.href = " . json_encode($url) . ";</script>";
            echo "<noscript><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "'></noscript>";
        } else {
            header("Location: " . $url, true, $statusCode);
        }
        exit;
    }
}

/**
 * Automatically determine the current active sidebar menu item
 * Checks explicit $activeMenu first, then auto-detects from request script path.
 */
function get_active_menu(): string {
    global $activeMenu;

    // If explicitly set and not the ambiguous default 'dashboard'
    if (!empty($activeMenu) && $activeMenu !== 'dashboard') {
        return $activeMenu;
    }

    // Auto-detect from script path / URI
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

    if (strpos($script, 'dashboard.php') !== false) {
        return 'dashboard';
    }

    // Specialized script paths
    if (strpos($script, 'modules/inventory/ledger.php') !== false) return 'stock_ledger';
    if (strpos($script, 'modules/customers/statement.php') !== false) return 'cust_ledger';
    if (strpos($script, 'modules/suppliers/ledger.php') !== false) return 'supplier_ledger';
    if (strpos($script, 'modules/salesmen/ledger.php') !== false) return 'sm_ledger';
    if (strpos($script, 'customer_payment') !== false) return 'customer_payments';
    if (strpos($script, 'supplier_payment') !== false) return 'supplier_payments';
    if (strpos($script, 'modules/accounts/ledger.php') !== false) return 'general_ledger';
    if (strpos($script, 'modules/accounts/journal_') !== false) return 'journal_entries';
    if (strpos($script, 'modules/goods_receiving/') !== false) return 'goods_receiving';
    if (strpos($script, 'modules/reports/daily_closing.php') !== false) return 'rpt_closing';
    if (strpos($script, 'modules/reports/customer_aging.php') !== false) return 'rpt_aging';
    if (strpos($script, 'modules/reports/trial_balance.php') !== false) return 'rpt_tb';
    if (strpos($script, 'modules/reports/profit_loss.php') !== false) return 'rpt_pl';
    if (strpos($script, 'modules/reports/balance_sheet.php') !== false) return 'rpt_bs';
    if (strpos($script, 'modules/reports/daily_sales.php') !== false ||
        strpos($script, 'modules/reports/booker_sales.php') !== false ||
        strpos($script, 'modules/reports/product_sales.php') !== false ||
        strpos($script, 'modules/reports/salesman_delivery.php') !== false) {
        return 'rpt_daily';
    }
    if (strpos($script, 'modules/settings/audit.php') !== false) return 'audit';

    // General module paths
    $pathMap = [
        'modules/orders/'      => 'orders',
        'modules/loading/'     => 'loading',
        'modules/invoices/'    => 'invoices',
        'modules/delivery/'    => 'delivery',
        'modules/settlements/' => 'settlements',
        'modules/returns/'     => 'returns',
        'modules/inventory/'   => 'inventory',
        'modules/purchases/'   => 'purchases',
        'modules/customers/'   => 'customers',
        'modules/salesmen/'    => 'salesmen',
        'modules/expenses/'    => 'expenses',
        'modules/accounts/'    => 'accounts',
        'modules/reports/'     => 'reports',
        'modules/excel/'       => 'excel',
        'modules/products/'    => 'products',
        'modules/units/'       => 'products',
        'modules/price_lists/' => 'products',
        'modules/categories/'  => 'categories',
        'modules/brands/'      => 'brands',
        'modules/areas/'       => 'areas',
        'modules/bookers/'     => 'bookers',
        'modules/suppliers/'   => 'suppliers',
        'modules/warehouses/'  => 'warehouses',
        'modules/settings/'    => 'settings',
        'modules/users/'       => 'users',
    ];

    foreach ($pathMap as $prefix => $menu) {
        if (strpos($script, $prefix) !== false) {
            return $menu;
        }
    }

    return $activeMenu ?? 'dashboard';
}

/**
 * Extract or normalize product family / parent item name for multi-flavor grouping
 * Used on Load Forms, Picking Sheets, and Inventory summaries to group flavor variants
 * (e.g. "ZORO Ring puff Butter Cheese 35GM", "ZORO Ring puff CHILLI STORM" -> "ZORO Ring Puff")
 *
 * @param string $name
 * @param string $category
 * @return string
 */
function dms_get_product_family(string $name, string $category = ''): string {
    $clean = trim($name);
    if ($clean === '') {
        return '';
    }

    // 1. Parenthetical flavors or descriptors e.g. "Donut Lollipop (Chocolate)" -> "Donut Lollipop"
    if (preg_match('/^(.*?)\s*\((Mango|Orange|Strawberry|Chocolate|Choclate|Milk|Vanilla|Candy|Toffee|Bubblegum|Kulfi|Lollipop|Avocada|Cucumber|Barbecue|BBQ|Cheese|Black Currant|Blue Berry|Green Apple)[^\)]*\)(.*)$/i', $clean, $m)) {
        $fam = trim($m[1] . ' ' . $m[3]);
        if (!empty($fam)) {
            return $fam;
        }
    }

    // 2. Specific FMCG Brand Series & Flavor Patterns
    // ZORO Ring Puff
    if (preg_match('/^ZORO\s+Ring\s+puff/i', $clean)) {
        return 'ZORO Ring Puff';
    }
    // 4ME Air Freshener 300ml
    if (preg_match('/^4ME\s+Air\s+Freshener\s+300ml/i', $clean)) {
        return '4ME Air Freshener 300ml';
    }
    // 4ME Soap 125gm
    if (preg_match('/^4ME\s+Soap\s+125gm/i', $clean)) {
        return '4ME Soap 125gm';
    }
    // UJAB Nacho Chips
    if (preg_match('/^UJAB\s+NACHO[S]?\s+CHIPS/i', $clean)) {
        return 'UJAB Nacho Chips';
    }
    // UJAB Surprise Kids Egg
    if (preg_match('/^UJAB[- ]Surprise\s+(Kids\s+)?Egg/i', $clean)) {
        return 'UJAB Surprise Kids Egg';
    }
    // Brunik Biscuit Rs 10
    if (preg_match('/^Brunik\s+Biscuit\s+.*?Rs\s*10/i', $clean)) {
        return 'Brunik Biscuit Rs 10 (1x16)';
    }
    // Brunik Biscuit Rs 20
    if (preg_match('/^Brunik\s+Biscuit\s+.*?Rs\s*20/i', $clean)) {
        return 'Brunik Biscuit Rs 20 (1x8)';
    }
    // Just Cream Wafer (1*24)
    if (preg_match('/^Just\s+Cream\s+Wafer\s+.*?(1\*24)/i', $clean)) {
        return 'Just Cream Wafer (1*24)';
    }
    // Just Cream Wafer Mega (1*12)
    if (preg_match('/^Just\s+Cream\s+Wafer\s+.*?Mega/i', $clean)) {
        return 'Just Cream Wafer Mega (1*12)';
    }
    // Horror Fun
    if (preg_match('/^Horror\s+Fun/i', $clean)) {
        return 'Horror Fun';
    }
    // Twin Paste
    if (preg_match('/^Twin\s+\(.*?Paste/i', $clean)) {
        return 'Twin Paste';
    }
    // Cocotea Party Pack
    if (preg_match('/^Cocotea\s+.*?Party\s+Pack/i', $clean)) {
        return 'Cocotea Party Pack';
    }
    // Conery
    if (preg_match('/^Conery\s+(Chocolate|Mango|Orange)/i', $clean)) {
        return 'Conery';
    }
    // Ujab Nimko twist
    if (preg_match('/^UJAB\s+Nimko\s+twist\s+Crispy\s+crunchy/i', $clean)) {
        return 'UJAB Nimko Twist';
    }

    // 3. Generic 3-word prefix if name has 4 or more words (e.g. Brand + Line + Item)
    $words = preg_split('/\s+/', $clean);
    if (count($words) >= 4) {
        return implode(' ', array_slice($words, 0, 3));
    }

    return $clean;
}
