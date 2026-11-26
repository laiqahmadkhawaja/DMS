<?php
/**
 * DMS - Distribution Management System
 * System Settings & Enterprise Configuration
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

require_login();
require_permission('settings_manage');

$pageTitle = 'System Settings';
$activeMenu = 'settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settings & Control', 'url' => ''],
    ['label' => 'System Settings', 'url' => '']
];

$errors = [];
$successMsg = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsToUpdate = $_POST['settings'] ?? [];
    
    // Explicitly handle checkboxes that may not be sent if unchecked
    $knownBooleans = ['allow_realtime_routes', 'enforce_fixed_routes', 'whatsapp_enabled', 'whatsapp_auto_send_invoice'];
    foreach ($knownBooleans as $boolKey) {
        if (!isset($settingsToUpdate[$boolKey])) {
            $settingsToUpdate[$boolKey] = '0';
        }
    }

    if (is_array($settingsToUpdate) && !empty($settingsToUpdate)) {
        try {
            db()->beginTransaction();
            
            $stmt = db()->prepare("
                INSERT INTO settings (setting_group, setting_key, setting_value, updated_at)
                VALUES ('general', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            
            $updatedCount = 0;
            foreach ($settingsToUpdate as $key => $val) {
                $cleanedVal = trim(clean_input($val));
                $stmt->execute([$key, $cleanedVal]);
                $updatedCount++;
            }
            
            // Record in audit log
            if (function_exists('audit_log')) {
                audit_log('update', 'settings', 'system_config', "Updated {$updatedCount} system configuration settings");
            }
            
            db()->commit();
            set_flash('success', "System settings successfully updated.");
            redirect(APP_URL . '/modules/settings/index.php');
            
        } catch (Exception $e) {
            db()->rollBack();
            error_log("Settings update error: " . $e->getMessage());
            $errors[] = "Error saving settings: " . $e->getMessage();
        }
    }
}

// Fetch all current settings grouped
$allSettings = db_fetch_all("
    SELECT id, setting_group, setting_key, setting_value, data_type, description
    FROM settings
    ORDER BY setting_group ASC, id ASC
");

$settingsByGroup = [];
foreach ($allSettings as $s) {
    $settingsByGroup[$s['setting_group']][] = $s;
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-gear-fill me-2"></i>System Settings & Configuration
                </h4>
                <p class="text-muted small mb-0">Manage enterprise profile, document numbering prefixes, paper print configurations, and system defaults</p>
            </div>
            <div>
                <a href="<?= APP_URL ?>/modules/settings/audit.php" class="btn btn-outline-navy">
                    <i class="bi bi-clock-history me-1"></i> View Audit Trail
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars(implode(', ', $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <div class="row g-4">
                <!-- Left Column: Business & Enterprise Identity -->
                <div class="col-lg-6">
                    <!-- Enterprise Profile -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                            <i class="bi bi-building text-primary me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-navy">Distributor Profile & Invoice Header</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">These details appear directly on all printed customer bills, loading sheets, and financial statements.</p>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Distributor Business Name</label>
                                <input type="text" name="settings[distributor_name]" class="form-control" 
                                       value="<?= htmlspecialchars(get_setting('distributor_name', 'Muzzam Enterprises')) ?>" required>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Primary Contact Phone <span class="text-muted small fw-normal">(Optional)</span></label>
                                    <input type="text" name="settings[distributor_phone]" class="form-control" 
                                           value="<?= htmlspecialchars(get_setting('distributor_phone', '')) ?>" placeholder="e.g. 03001234567">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">City</label>
                                    <input type="text" name="settings[city]" class="form-control" 
                                           value="<?= htmlspecialchars(get_setting('city', 'Gujranwala')) ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Warehouse / Physical Address <span class="text-muted small fw-normal">(Optional)</span></label>
                                <textarea name="settings[distributor_address]" class="form-control" rows="2" placeholder="e.g. G.T. Road, Gujranwala"><?= htmlspecialchars(get_setting('distributor_address', '')) ?></textarea>
                            </div>

                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Currency Symbol</label>
                                    <input type="text" name="settings[currency_symbol]" class="form-control" 
                                           value="<?= htmlspecialchars(get_setting('currency_symbol', 'Rs.')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Date Format</label>
                                    <select name="settings[date_format]" class="form-select">
                                        <option value="d-m-Y" <?= get_setting('date_format') === 'd-m-Y' ? 'selected' : '' ?>>DD-MM-YYYY (19-11-2026)</option>
                                        <option value="d.m.y" <?= get_setting('date_format') === 'd.m.y' ? 'selected' : '' ?>>DD.MM.YY (19.11.26)</option>
                                        <option value="Y-m-d" <?= get_setting('date_format') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD (2026-11-19)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Print & Paper Saving Settings -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                            <i class="bi bi-printer text-success me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-navy">Print Layout & Paper-Saving Rules</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Invoice Paper Packing Mode</label>
                                <select name="settings[multi_invoice_fit_mode]" class="form-select">
                                    <option value="auto_pack" <?= get_setting('multi_invoice_fit_mode') === 'auto_pack' ? 'selected' : '' ?>>Auto-Pack (2-up Half A4 for standard orders, 3-up for small slips)</option>
                                    <option value="half_a4" <?= get_setting('multi_invoice_fit_mode') === 'half_a4' ? 'selected' : '' ?>>Force 2-up (Strictly 2 bills per A4 page with scissor cut line)</option>
                                    <option value="full_a4" <?= get_setting('multi_invoice_fit_mode') === 'full_a4' ? 'selected' : '' ?>>Full A4 (1 bill per page)</option>
                                </select>
                                <small class="text-muted">Controls default packing in batch printing to eliminate blank paper waste.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Print Signature Boxes</label>
                                <select name="settings[show_signature_boxes]" class="form-select">
                                    <option value="1" <?= get_setting('show_signature_boxes', '1') == '1' ? 'selected' : '' ?>>Enabled (Customer, Salesman & Cashier signatures)</option>
                                    <option value="0" <?= get_setting('show_signature_boxes') == '0' ? 'selected' : '' ?>>Disabled (Maximize printable line item space)</option>
                                </select>
                            </div>

                            <div class="mb-0">
                                <label class="form-label fw-semibold">Bill Terms & Customer Instructions (بل پر پرنٹ ہونے والی ہدایات / شرائط)</label>
                                <textarea name="settings[bill_instructions]" class="form-control" rows="2" placeholder="e.g. مال چیک کر کے وصول کریں، بعد میں واپسی یا کلیم قابل قبول نہیں ہوگا۔"><?= htmlspecialchars(get_setting('bill_instructions', 'نوٹ: برائے مہربانی مال گن کر اور چیک کر کے وصول کریں۔ بعد میں کسی کمی بیشی کی شکایت قابل قبول نہیں ہوگی۔')) ?></textarea>
                                <small class="text-muted d-block mt-1">These notes/terms will be neatly printed at the bottom of customer sales bills and invoices. Leave blank if not needed.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Delivery Routes & Driver Assignment Policy -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                            <i class="bi bi-geo-alt-fill text-danger me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-navy">Delivery Routes & Salesman Assignment Policy</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Configure whether delivery salesmen/drivers strictly follow pre-assigned fixed market routes or if staff can dynamically create new routes in real time.
                            </p>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Route Operation Mode</label>
                                <select name="settings[route_assignment_mode]" class="form-select mb-2" id="routeAssignmentModeSelect">
                                    <option value="realtime" <?= get_setting('route_assignment_mode', 'realtime') === 'realtime' ? 'selected' : '' ?>>
                                        Flexible / Dynamic Mode (Allow Real-Time Route Creation on all forms)
                                    </option>
                                    <option value="fixed" <?= get_setting('route_assignment_mode', 'realtime') === 'fixed' ? 'selected' : '' ?>>
                                        Strict Fixed Route Mode (Drivers strictly operate on assigned beats)
                                    </option>
                                </select>
                                <small class="text-muted d-block">
                                    In Dynamic Mode, staff can add new market routes instantly via side-drawer without page reloads.
                                </small>
                            </div>

                            <div class="border rounded p-3 bg-light">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="settings[allow_realtime_routes]" value="1" id="allowRealtimeRoutesSwitch"
                                           <?= get_setting('allow_realtime_routes', '1') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark" for="allowRealtimeRoutesSwitch">
                                        Enable Real-Time Route Creation (Quick Add Drawer)
                                    </label>
                                    <div class="text-muted small">
                                        Shows the <code>+</code> button next to Route/Area dropdowns across Order Booking, Billing, Customer, and Salesmen forms.
                                    </div>
                                </div>

                                <div class="form-check form-switch mb-0 pt-2 border-top">
                                    <input class="form-check-input" type="checkbox" name="settings[enforce_fixed_routes]" value="1" id="enforceFixedRoutesSwitch"
                                           <?= get_setting('enforce_fixed_routes', '0') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark" for="enforceFixedRoutesSwitch">
                                        Enforce Fixed Salesman Delivery Routes
                                    </label>
                                    <div class="text-muted small">
                                        When assigning deliveries, warns or locks to the salesman's fixed primary route.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Document Numbering & System Info -->
                <div class="col-lg-6">
                    <!-- Business Model & Expense Policy -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                            <i class="bi bi-pie-chart-fill text-primary me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-navy">Business Model & Expense Policy (کاروباری ماڈل و اخراجات)</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Choose how operating expenses and khatas are handled in your distribution business. Ideal for adapting to single-owner companies or multi-partner businesses in a SaaS environment.
                            </p>

                            <div class="mb-0">
                                <label class="form-label fw-semibold">Expense & Khata Management Mode</label>
                                <?php
                                    $currentExpenseMode = get_setting('expense_management_mode', is_partner_expense_sharing_enabled() ? 'partnership' : 'standard');
                                ?>
                                <div class="form-check p-3 border rounded mb-3 <?= $currentExpenseMode === 'standard' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>">
                                    <input class="form-check-input" type="radio" name="settings[expense_management_mode]" id="modeStandard" value="standard" <?= $currentExpenseMode === 'standard' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark d-block" for="modeStandard">
                                        🏢 Standard Business Mode (سادہ کاروباری طریقہ کار - سنگل اونر / کمپنی اکاؤنٹ)
                                    </label>
                                    <small class="text-muted d-block mt-1">
                                        For single-proprietorship or standard corporate businesses. Operating expenses are paid directly from Company Cash/Bank with simple, fast entry. Partner Khata splits, 50/50 options, and partner ledgers are hidden.
                                    </small>
                                </div>

                                <div class="form-check p-3 border rounded mb-0 <?= $currentExpenseMode === 'partnership' ? 'border-primary bg-primary bg-opacity-10' : 'bg-light' ?>">
                                    <input class="form-check-input" type="radio" name="settings[expense_management_mode]" id="modePartnership" value="partnership" <?= $currentExpenseMode === 'partnership' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark d-block" for="modePartnership">
                                        👥 Multi-Partner Sharing Mode (پارٹنرشپ ماڈل - مشترکہ اخراجات و پارٹنرز کھاتہ)
                                    </label>
                                    <small class="text-muted d-block mt-1">
                                        For partnership distribution businesses. Enables shared expense division (50/50, custom, etc.), personal pocket funding, partner cash drawings, and automated partner ledger accounts.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- WhatsApp & Open-WA Gateway Settings -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-whatsapp text-success me-2 fs-5"></i>
                                <h6 class="mb-0 fw-bold text-navy">WhatsApp Gateway (Open-WA Integration)</h6>
                            </div>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border" id="openwaLiveBadge">
                                <i class="bi bi-circle-fill me-1" style="font-size:0.5rem;"></i> Not Checked
                            </span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Connect to your self-hosted Open-WA REST API server to send direct customer invoices, PDF ledgers, and daily closing summaries to WhatsApp.
                            </p>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="settings[whatsapp_enabled]" id="waEnabled" value="1" <?= get_setting('whatsapp_enabled', '1') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="waEnabled">Enable WhatsApp Sending via Open-WA</label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Open-WA Server Base URL (HTTP or HTTPS)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light"><i class="bi bi-hdd-network"></i></span>
                                    <input type="text" name="settings[whatsapp_api_url]" id="waApiUrl" class="form-control" 
                                           value="<?= htmlspecialchars(get_setting('whatsapp_api_url', '')) ?>" placeholder="https://wa.darwarqamgrw.in">
                                    <button type="button" class="btn btn-outline-success" id="btnTestOpenWa">
                                        <i class="bi bi-plug-fill me-1"></i> Test Connection
                                    </button>
                                </div>
                                <small class="text-muted" style="font-size:0.75rem;">Your hosted Open-WA API address (e.g. <code>https://wa.darwarqamgrw.in</code>. Supports both HTTP and HTTPS).</small>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Session ID / Name</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light"><i class="bi bi-person-badge"></i></span>
                                        <input type="text" name="settings[whatsapp_session_id]" id="waSessionId" class="form-control" 
                                               value="<?= htmlspecialchars(get_setting('whatsapp_session_id', 'default')) ?>" placeholder="default">
                                    </div>
                                    <small class="text-muted" style="font-size:0.75rem;">Open-WA session identifier (default: <code>default</code>)</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">API Secret Key (Optional)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                        <input type="password" name="settings[whatsapp_api_key]" id="waApiKey" class="form-control" 
                                               value="<?= htmlspecialchars(get_setting('whatsapp_api_key', '')) ?>" placeholder="Leave blank if no key required">
                                    </div>
                                    <small class="text-muted" style="font-size:0.75rem;">Optional auth key configured on your server</small>
                                </div>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="settings[whatsapp_auto_send_invoice]" id="waAutoSend" value="1" <?= get_setting('whatsapp_auto_send_invoice', '0') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="waAutoSend">
                                    Auto-send WhatsApp bill to customer when Sales Invoice is finalized
                                </label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Daily Closing Broadcast Numbers (Owners / Partners)</label>
                                <input type="text" name="settings[whatsapp_owner_phones]" class="form-control form-control-sm" 
                                       value="<?= htmlspecialchars(get_setting('whatsapp_owner_phones', '')) ?>" placeholder="03001234567, 03017654321">
                                <small class="text-muted" style="font-size:0.75rem;">Comma-separated phone numbers that will receive daily Roznamcha summaries</small>
                            </div>

                            <!-- Interactive Live Test Send Box -->
                            <div class="p-3 bg-light rounded border">
                                <label class="form-label small fw-bold text-navy mb-2"><i class="bi bi-send-check text-primary me-1"></i> Quick Test WhatsApp Sending</label>
                                <div class="input-group input-group-sm mb-2">
                                    <input type="text" id="waTestPhone" class="form-control" placeholder="Recipient Phone (03001234567)">
                                    <button type="button" class="btn btn-navy" id="btnSendTestWa">
                                        <i class="bi bi-chat-dots me-1"></i> Send Text
                                    </button>
                                </div>
                                <div class="input-group input-group-sm">
                                    <input type="file" id="waTestFile" class="form-control" accept="image/*,application/pdf">
                                    <button type="button" class="btn btn-outline-success" id="btnSendTestFile">
                                        <i class="bi bi-paperclip me-1"></i> Send Pic/PDF
                                    </button>
                                </div>
                                <div id="waTestResult" class="small mt-2" style="display:none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Sequential Numbering Prefixes -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
                            <i class="bi bi-hash text-warning me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-navy">Document Numbering Prefixes</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">Thread-safe auto-generated sequence prefixes (e.g., <code>PREFIX-YYYY-00001</code>).</p>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Orders Prefix</label>
                                    <input type="text" name="settings[order_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('order_prefix', 'ORD')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Sales Invoices Prefix</label>
                                    <input type="text" name="settings[invoice_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('invoice_prefix', 'INV')) ?>" required>
                                </div>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Load Form Prefix (لوڈ فارم)</label>
                                    <input type="text" name="settings[load_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('load_prefix', 'LOAD')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Credit Sheet Prefix (کریڈٹ شیٹ)</label>
                                    <input type="text" name="settings[run_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('run_prefix', 'RUN')) ?>" required>
                                </div>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Route Settlement Prefix</label>
                                    <input type="text" name="settings[settlement_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('settlement_prefix', 'SET')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Sales Returns Prefix</label>
                                    <input type="text" name="settings[return_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('return_prefix', 'RET')) ?>" required>
                                </div>
                            </div>

                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Goods Receipt (GRN) Prefix</label>
                                    <input type="text" name="settings[grn_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('grn_prefix', 'GRN')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Purchase Order Prefix</label>
                                    <input type="text" name="settings[purchase_prefix]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars(get_setting('purchase_prefix', 'PUR')) ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Environment & Security Summary -->
                    <div class="card border-0 shadow-sm bg-light">
                        <div class="card-body">
                            <h6 class="fw-bold text-navy mb-2"><i class="bi bi-shield-check text-success me-1"></i> System Information</h6>
                            <ul class="list-unstyled small mb-0 text-muted">
                                <li class="mb-1"><strong>Application:</strong> Distribution Management System (DMS) v1.0.0</li>
                                <li class="mb-1"><strong>PHP Runtime:</strong> <?= phpversion() ?> (ZTS 64-bit)</li>
                                <li class="mb-1"><strong>Database:</strong> MariaDB / MySQL (InnoDB, utf8mb4)</li>
                                <li class="mb-1"><strong>Deployment Mode:</strong> Local XAMPP/WAMP (Self-Contained Offline Assets)</li>
                                <li><strong>Audit Trail:</strong> Active & Logging All Modifications</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save Action Button -->
            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-navy px-4 py-2 fw-semibold shadow-sm">
                    <i class="bi bi-check-circle me-1"></i> Save Changes (F9)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// WhatsApp Open-WA Interactive Handlers
document.addEventListener('DOMContentLoaded', function() {
    const btnTest = document.getElementById('btnTestOpenWa');
    const badge = document.getElementById('openwaLiveBadge');
    if (btnTest) {
        btnTest.addEventListener('click', async function() {
            const apiUrl = document.getElementById('waApiUrl')?.value?.trim() || '';
            const apiKey = document.getElementById('waApiKey')?.value?.trim() || '';
            const sessionId = document.getElementById('waSessionId')?.value?.trim() || '';

            if (!apiUrl) {
                showToast('Please enter your Open-WA Server Base URL (e.g. https://wa.darwarqamgrw.in)', 'warning');
                document.getElementById('waApiUrl').focus();
                return;
            }

            btnTest.disabled = true;
            const originalHtml = btnTest.innerHTML;
            btnTest.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Checking...';
            badge.className = 'badge bg-warning bg-opacity-10 text-warning border border-warning';
            badge.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Testing ' + apiUrl + '...';

            try {
                const fd = new FormData();
                fd.append('action', 'test_connection');
                fd.append('api_url', apiUrl);
                fd.append('api_key', apiKey);
                fd.append('session_id', sessionId);

                const res = await fetch('<?= APP_URL ?>/ajax/whatsapp/send.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                }).then(r => r.json());

                if (res && res.success) {
                    badge.className = 'badge bg-success bg-opacity-10 text-success border border-success';
                    badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> ' + (res.data?.host_number ? 'Online (' + res.data.host_number + ')' : 'Online');
                    showToast('Open-WA Server is Online & Authenticated!', 'success');
                } else {
                    badge.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger';
                    badge.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> ' + ((res && res.message) ? res.message : 'Offline / Error');
                    showToast(res && res.message ? res.message : 'Connection failed', 'danger');
                }
            } catch (e) {
                badge.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger';
                badge.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Unreachable';
                showToast('Unable to connect to WhatsApp server: ' + e.message, 'danger');
            } finally {
                btnTest.disabled = false;
                btnTest.innerHTML = originalHtml;
            }
        });
    }

    // Send Test Message
    const btnSendTest = document.getElementById('btnSendTestWa');
    const testPhoneInput = document.getElementById('waTestPhone');
    const testResult = document.getElementById('waTestResult');
    if (btnSendTest) {
        btnSendTest.addEventListener('click', async function() {
            const phone = testPhoneInput.value.trim();
            if (!phone) {
                showToast('Please enter recipient phone number (e.g. 03001234567)', 'warning');
                testPhoneInput.focus();
                return;
            }

            const apiUrl = document.getElementById('waApiUrl')?.value?.trim() || '';
            const apiKey = document.getElementById('waApiKey')?.value?.trim() || '';
            const sessionId = document.getElementById('waSessionId')?.value?.trim() || '';

            btnSendTest.disabled = true;
            const origHtml = btnSendTest.innerHTML;
            btnSendTest.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
            testResult.style.display = 'block';
            testResult.className = 'small mt-2 alert alert-info py-2 mb-0';
            testResult.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending test message to ' + phone + ' via Open-WA...';

            try {
                const fd = new FormData();
                fd.append('action', 'send_test_message');
                fd.append('phone', phone);
                fd.append('api_url', apiUrl);
                fd.append('api_key', apiKey);
                fd.append('session_id', sessionId);

                const res = await fetch('<?= APP_URL ?>/ajax/whatsapp/send.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                }).then(r => r.json());

                if (res.success) {
                    testResult.className = 'small mt-2 alert alert-success py-2 mb-0';
                    testResult.innerHTML = '<i class="bi bi-check-circle me-1"></i> ' + res.message;
                    showToast(res.message, 'success');
                } else {
                    testResult.className = 'small mt-2 alert alert-danger py-2 mb-0';
                    testResult.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> ' + (res.message || 'Sending failed');
                    showToast(res.message || 'Sending failed', 'danger');
                }
            } catch (err) {
                testResult.className = 'small mt-2 alert alert-danger py-2 mb-0';
                testResult.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> ' + err.message;
            } finally {
                btnSendTest.disabled = false;
                btnSendTest.innerHTML = origHtml;
            }
        });
    }

    // Send Test Picture / PDF File
    const btnSendTestFile = document.getElementById('btnSendTestFile');
    const testFileInput = document.getElementById('waTestFile');
    if (btnSendTestFile) {
        btnSendTestFile.addEventListener('click', async function() {
            const phone = testPhoneInput.value.trim();
            if (!phone) {
                showToast('Please enter recipient phone number (e.g. 03001234567)', 'warning');
                testPhoneInput.focus();
                return;
            }
            if (!testFileInput.files || !testFileInput.files[0]) {
                showToast('Please choose an image or PDF file to send', 'warning');
                testFileInput.click();
                return;
            }

            const apiUrl = document.getElementById('waApiUrl')?.value?.trim() || '';
            const apiKey = document.getElementById('waApiKey')?.value?.trim() || '';
            const sessionId = document.getElementById('waSessionId')?.value?.trim() || '';

            const file = testFileInput.files[0];
            btnSendTestFile.disabled = true;
            const origHtml = btnSendTestFile.innerHTML;
            btnSendTestFile.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
            testResult.style.display = 'block';
            testResult.className = 'small mt-2 alert alert-info py-2 mb-0';
            testResult.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Uploading and sending ' + file.name + ' via Open-WA...';

            try {
                const fd = new FormData();
                fd.append('action', 'send_file');
                fd.append('phone', phone);
                fd.append('caption', 'Test file transmission from DMS (' + file.name + ')');
                fd.append('file', file);
                fd.append('api_url', apiUrl);
                fd.append('api_key', apiKey);
                fd.append('session_id', sessionId);

                const res = await fetch('<?= APP_URL ?>/ajax/whatsapp/send.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                }).then(r => r.json());

                if (res.success) {
                    testResult.className = 'small mt-2 alert alert-success py-2 mb-0';
                    testResult.innerHTML = '<i class="bi bi-check-circle me-1"></i> ' + res.message;
                    showToast(res.message, 'success');
                } else {
                    testResult.className = 'small mt-2 alert alert-danger py-2 mb-0';
                    testResult.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> ' + (res.message || 'File send failed');
                    showToast(res.message || 'File send failed', 'danger');
                }
            } catch (err) {
                testResult.className = 'small mt-2 alert alert-danger py-2 mb-0';
                testResult.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> ' + err.message;
            } finally {
                btnSendTestFile.disabled = false;
                btnSendTestFile.innerHTML = origHtml;
            }
        });
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
