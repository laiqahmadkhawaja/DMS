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
    $knownBooleans = ['allow_realtime_routes', 'enforce_fixed_routes'];
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
                                    <label class="form-label fw-semibold">Primary Contact Phone</label>
                                    <input type="text" name="settings[distributor_phone]" class="form-control" 
                                           value="<?= htmlspecialchars(get_setting('distributor_phone', '03000673276')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">City</label>
                                    <input type="text" name="settings[city]" class="form-control" 
                                           value="<?= htmlspecialchars(get_setting('city', 'Gujranwala')) ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Warehouse / Physical Address</label>
                                <textarea name="settings[distributor_address]" class="form-control" rows="2" required><?= htmlspecialchars(get_setting('distributor_address', 'Jandiala Bhagwala G.T. Road Gujranwala')) ?></textarea>
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

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
