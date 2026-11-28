<?php
/**
 * DMS - Mobile App Module for Field Sales, Delivery & Warehouse
 * Modern Responsive Touch-Optimized Interface (English)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();

$pageTitle = 'Field Sales & Delivery Mobile App';
$activeUser = current_user();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0d233a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= e(CLIENT_NAME) ?> — Field Sales & Delivery App</title>

    <!-- Local Offline Assets -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/icons/bootstrap-icons.css">

    <style>
        :root {
            --app-primary: #0d233a;
            --app-primary-dark: #071524;
            --app-secondary: #0d6efd;
            --app-accent: #f59e0b;
            --app-success: #10b981;
            --app-danger: #ef4444;
            --app-bg: #f8fafc;
            --app-card-bg: #ffffff;
            --app-text: #0f172a;
            --app-text-muted: #64748b;
            --app-border: #e2e8f0;
            --app-font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: var(--app-bg);
            color: var(--app-text);
            font-family: var(--app-font-family);
            font-size: 14px;
            padding-bottom: 84px; /* Space for bottom nav */
            user-select: none;
            -webkit-user-select: none;
        }

        input, textarea, select, button {
            user-select: auto !important;
            -webkit-user-select: auto !important;
        }

        /* App Top Header */
        .app-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: linear-gradient(135deg, var(--app-primary) 0%, #173b5c 100%);
            color: #ffffff;
            padding: 10px 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        .app-brand {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .app-status-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Salesman Selector Bar */
        .salesman-bar {
            background: #ffffff;
            border-bottom: 1px solid var(--app-border);
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .salesman-select {
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 4px 10px;
            background-color: #f8fafc;
            max-width: 190px;
        }

        /* Tab Content Container */
        .app-content {
            padding: 12px;
            max-width: 600px;
            margin: 0 auto;
        }

        .tab-pane {
            display: none;
            animation: fadeIn 0.2s ease-in-out;
        }

        .tab-pane.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards */
        .app-card {
            background: var(--app-card-bg);
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            border: 1px solid var(--app-border);
        }

        .app-stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }

        .app-stat-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid var(--app-border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .app-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        /* Quick Action Buttons */
        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }

        .action-btn {
            background: #ffffff;
            border: 1px solid var(--app-border);
            border-radius: 14px;
            padding: 14px 10px;
            text-align: center;
            font-weight: 600;
            color: var(--app-text);
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .action-btn:active {
            transform: scale(0.97);
            background: #f1f5f9;
        }

        .action-btn i {
            font-size: 24px;
        }

        /* Category pills slider */
        .category-scroll {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .category-scroll::-webkit-scrollbar {
            display: none;
        }

        .cat-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            background: #ffffff;
            border: 1px solid var(--app-border);
            color: var(--app-text);
            cursor: pointer;
        }

        .cat-pill.active {
            background: var(--app-primary);
            color: #ffffff;
            border-color: var(--app-primary);
        }

        /* Product Card */
        .prod-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            border: 1px solid var(--app-border);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .prod-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .prod-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 2px;
            color: #0f172a;
        }

        .prod-sub {
            font-size: 11px;
            color: var(--app-text-muted);
        }

        .prod-rates {
            display: flex;
            gap: 6px;
            font-size: 12px;
            flex-wrap: wrap;
        }

        .prod-rates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(88px, 1fr));
            gap: 6px;
            margin: 2px 0 6px 0;
        }

        .prod-rate-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px 8px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .prod-rate-box .rate-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1.1;
            margin-bottom: 2px;
        }

        .prod-rate-box .rate-val {
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
        }

        .prod-rate-badge {
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 600;
        }

        /* Stepper Counter */
        .qty-row {
            display: grid;
            gap: 6px;
            background: #f8fafc;
            padding: 8px;
            border-radius: 8px;
            border: 1px solid #f1f5f9;
        }

        .stepper-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 2px;
            gap: 2px;
        }

        .stepper-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #1e293b;
            flex-shrink: 0;
            touch-action: manipulation;
        }

        .stepper-btn:active {
            background: #cbd5e1;
        }

        .stepper-val {
            flex: 1;
            min-width: 36px;
            height: 32px;
            text-align: center;
            font-weight: 700;
            border: 1px solid transparent;
            border-radius: 6px;
            outline: none;
            font-size: 14px;
            background: #ffffff;
            color: #0f172a;
            padding: 0 4px;
            cursor: text;
            user-select: text !important;
            -webkit-user-select: text !important;
            touch-action: manipulation;
        }

        .stepper-val:focus {
            border-color: var(--app-secondary);
            background: #eff6ff;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.2);
        }

        /* Floating Bottom Cart Bar */
        .cart-sticky-bar {
            position: fixed;
            bottom: 68px;
            left: 0;
            right: 0;
            max-width: 580px;
            margin: 0 auto;
            padding: 0 12px;
            z-index: 1020;
            display: none;
        }

        .cart-bar-inner {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border-radius: 14px;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
            cursor: pointer;
        }

        /* Bottom Navigation Bar */
        .app-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: #ffffff;
            border-top: 1px solid var(--app-border);
            display: flex;
            align-items: center;
            justify-content: space-around;
            z-index: 1040;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.04);
            max-width: 600px;
            margin: 0 auto;
        }

        .nav-item-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #64748b;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            width: 20%;
            height: 100%;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: color 0.15s ease;
        }

        .nav-item-btn i {
            font-size: 20px;
            margin-bottom: 2px;
        }

        .nav-item-btn.active {
            color: var(--app-secondary);
        }

        .nav-item-btn.active i {
            color: var(--app-secondary);
            transform: translateY(-1px);
        }

        /* Stop Shop List in Deliveries */
        .stop-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--app-border);
            padding: 12px;
            margin-bottom: 10px;
            border-left: 5px solid #94a3b8;
            transition: all 0.2s ease;
        }

        .stop-card.delivered {
            border-left-color: var(--app-success);
        }

        .stop-card.partial {
            border-left-color: #3b82f6;
        }

        .stop-card.refused {
            border-left-color: var(--app-danger);
        }

        /* Modal Sheets */
        .modal-sheet {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
            align-items: flex-end;
            justify-content: center;
        }

        .modal-sheet.show {
            display: flex;
        }

        .sheet-container {
            background: #ffffff;
            width: 100%;
            max-width: 600px;
            max-height: 88vh;
            border-radius: 20px 20px 0 0;
            padding: 18px 16px;
            overflow-y: auto;
            animation: slideUp 0.25s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        .sheet-handle {
            width: 40px;
            height: 4px;
            background: #cbd5e1;
            border-radius: 2px;
            margin: 0 auto 14px auto;
        }

        /* Form Inputs */
        .app-input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 14px;
            margin-bottom: 10px;
            background: #f8fafc;
        }

        .app-input:focus {
            outline: none;
            border-color: var(--app-secondary);
            background: #ffffff;
        }

        .btn-app-primary {
            background: var(--app-primary);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 700;
            font-size: 14px;
            width: 100%;
            cursor: pointer;
        }

        .btn-app-success {
            background: var(--app-success);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 700;
            font-size: 14px;
            width: 100%;
            cursor: pointer;
        }

        /* Toast notification */
        .app-toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            z-index: 1090;
            display: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            max-width: 90%;
            text-align: center;
        }
    </style>
</head>
<body>

<!-- Toast Notification -->
<div class="app-toast" id="appToast">Notification</div>

<!-- Topbar -->
<header class="app-topbar">
    <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-phone text-warning fs-5"></i>
            <div>
                <div class="app-brand"><?= e(CLIENT_NAME) ?></div>
                <small class="opacity-75" style="font-size: 11px;">Field Sales & Delivery App</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="app-status-badge text-white" id="onlinePill">
                <i class="bi bi-circle-fill text-success" style="font-size: 7px;"></i> Online
            </span>
            <?php if (!is_field_staff()): ?>
            <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-sm btn-outline-light py-0 px-2" style="font-size: 11px;" title="Go to Desktop ERP Dashboard">
                <i class="bi bi-laptop"></i> ERP
            </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- Salesman / Staff Identity Bar (No Switching) -->
<div class="salesman-bar">
    <div class="d-flex align-items-center gap-2 overflow-hidden">
        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-1 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; flex-shrink: 0;">
            <i class="bi bi-person-fill fs-6"></i>
        </div>
        <div class="text-truncate">
            <strong class="d-block text-truncate text-dark" id="loggedStaffName"><?= e($activeUser['full_name']) ?></strong>
            <small class="text-muted d-block" id="loggedStaffRole" style="font-size: 11px; line-height: 1;">Field Staff</small>
        </div>
    </div>
    <div class="text-end d-flex align-items-center gap-2 flex-shrink-0">
        <span class="badge bg-light text-dark border" id="todayDateBadge"><?= date('d-M-Y') ?></span>
        <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-outline-danger py-1 px-2" style="font-size: 11px;" title="Sign Out">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<!-- Main App Container -->
<main class="app-content">

    <!-- ================================================================= -->
    <!-- TAB 1: HOME / DASHBOARD                                           -->
    <!-- ================================================================= -->
    <div class="tab-pane active" id="tab-home">
        <!-- Salesman Welcome Card -->
        <div class="app-card" style="background: linear-gradient(135deg, #0d233a 0%, #1e3a5f 100%); color: #ffffff;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span class="badge bg-warning text-dark mb-1">Field Operations</span>
                    <h5 class="fw-bold mb-0" id="welcomeSalesmanName">Welcome!</h5>
                </div>
                <div class="bg-white bg-opacity-10 p-2 rounded-circle text-center" style="width: 44px; height: 44px;">
                    <i class="bi bi-truck text-warning fs-4"></i>
                </div>
            </div>
            <p class="mb-0 opacity-75 small" id="welcomeSubText">Daily field order booking, delivery run sheets, and recovery management.</p>
        </div>

        <!-- 4 Stat Counters -->
        <div class="app-stat-grid">
            <div class="app-stat-card">
                <div class="app-stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-bag-check"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 11px;">Today's Orders</div>
                    <div class="fw-bold fs-6" id="statOrdersCount">0 Orders</div>
                    <small class="text-primary fw-bold" id="statOrdersAmt">Rs. 0</small>
                </div>
            </div>

            <div class="app-stat-card">
                <div class="app-stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 11px;">Cash Collected</div>
                    <div class="fw-bold fs-6" id="statCashColl">Rs. 0</div>
                    <small class="text-success fw-bold">Cash in Hand</small>
                </div>
            </div>

            <div class="app-stat-card">
                <div class="app-stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-geo-alt"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 11px;">Delivery Stops</div>
                    <div class="fw-bold fs-6" id="statDeliveredStops">0 / 0</div>
                    <small class="text-info fw-bold" id="statDeliveredPct">0% Completed</small>
                </div>
            </div>

            <div class="app-stat-card">
                <div class="app-stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-arrow-return-left"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size: 11px;">Field Returns</div>
                    <div class="fw-bold fs-6" id="statPendingReturns">0 Items</div>
                    <small class="text-warning fw-bold">Pending Verification</small>
                </div>
            </div>
        </div>

        <!-- Quick Action Buttons -->
        <h6 class="fw-bold mb-2 text-navy"><i class="bi bi-lightning-charge me-1 text-warning"></i> Quick Actions</h6>
        <div class="action-grid">
            <div class="action-btn" onclick="switchTab('order')">
                <div class="bg-primary bg-opacity-10 p-2 rounded-circle text-primary">
                    <i class="bi bi-cart-plus"></i>
                </div>
                <span>Book Order</span>
                <small class="text-muted" style="font-size: 10px;">Retailer Order Booking</small>
            </div>

            <div class="action-btn" onclick="switchTab('delivery')">
                <div class="bg-success bg-opacity-10 p-2 rounded-circle text-success">
                    <i class="bi bi-journal-check"></i>
                </div>
                <span>Delivery Sheet</span>
                <small class="text-muted" style="font-size: 10px;">Field Run & Recoveries</small>
            </div>

            <div class="action-btn" onclick="switchTab('returns')">
                <div class="bg-warning bg-opacity-10 p-2 rounded-circle text-warning">
                    <i class="bi bi-box-seam"></i>
                </div>
                <span>Record Returns</span>
                <small class="text-muted" style="font-size: 10px;">Field Stock Return</small>
            </div>

            <div class="action-btn" onclick="switchTab('verify')">
                <div class="bg-secondary bg-opacity-10 p-2 rounded-circle text-secondary">
                    <i class="bi bi-shield-check"></i>
                </div>
                <span>Verify & Settle</span>
                <small class="text-muted" style="font-size: 10px;">Warehouse & Cashier</small>
            </div>
        </div>

        <!-- Today's Run Banner -->
        <div class="app-card border-primary" id="homeRunCard" style="display: none;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-primary mb-1">Today's Active Route</span>
                    <h6 class="fw-bold mb-0" id="homeRunNumber">RUN-0000</h6>
                    <small class="text-muted" id="homeRunDetails">0 Shops • Total: Rs. 0</small>
                </div>
                <button class="btn btn-sm btn-primary rounded-pill px-3" onclick="switchTab('delivery')">
                    Open Sheet <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 2: ORDER BOOKING                                              -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-order">
        <!-- Step 1: Customer Selection Card -->
        <div class="app-card" id="selectedCustomerCard">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <span class="badge bg-secondary mb-1">Customer / Retailer</span>
                    <h6 class="fw-bold mb-0" id="currentShopName">Select Customer / Shop</h6>
                    <small class="text-muted" id="currentShopRoute">No Route Selected</small>
                </div>
                <button class="btn btn-sm btn-outline-primary rounded-pill py-1 px-3" onclick="openCustomerModal()">
                    <i class="bi bi-search me-1"></i> Change Shop
                </button>
            </div>
            <div class="d-flex gap-3 pt-2 border-top" style="font-size: 12px;">
                <div><strong>Previous Balance:</strong> <span class="text-danger fw-bold" id="currentShopBal">Rs. 0</span></div>
                <div><strong>Credit Limit:</strong> <span class="text-success fw-bold" id="currentShopLimit">Rs. 0</span></div>
            </div>
            <div id="custDiscountNotice" class="mt-2 pt-2 border-top small text-primary fw-bold" style="display: none;">
                <i class="bi bi-tag-fill me-1"></i> <span id="custDiscountNoticeVal">0% Default Discount</span>
            </div>
        </div>

        <!-- Pricing Mode (Wholesale vs MRP) & Trade Scheme Bar -->
        <div class="app-card p-2 mb-2 bg-white shadow-sm border">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <span class="small fw-bold text-dark d-flex align-items-center gap-1">
                    <i class="bi bi-tag-fill text-primary"></i> Rate Tier:
                </span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Pricing Mode Tier">
                    <input type="radio" class="btn-check" name="app_pricing_mode" id="appPricingWholesale" value="wholesale" checked autocomplete="off" onchange="setAppPricingMode('wholesale')">
                    <label class="btn btn-outline-primary btn-sm px-2 py-1 fw-bold cursor-pointer" for="appPricingWholesale" id="lblAppWholesale" title="Apply standard Wholesale rates to cart">
                        <i class="bi bi-shop me-1"></i> Wholesale Price
                    </label>

                    <input type="radio" class="btn-check" name="app_pricing_mode" id="appPricingMrp" value="mrp" autocomplete="off" onchange="setAppPricingMode('mrp')">
                    <label class="btn btn-outline-success btn-sm px-2 py-1 fw-bold cursor-pointer" for="appPricingMrp" id="lblAppMrp" title="Apply printed MRP / Retail rates to cart">
                        <i class="bi bi-tag-fill me-1"></i> Retail / MRP Price
                    </label>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="form-check form-switch m-0 d-inline-flex align-items-center gap-2">
                    <input class="form-check-input cursor-pointer" type="checkbox" role="switch" id="appFreeLooseToggle" onchange="onAppFreeLooseToggle()">
                    <label class="form-check-label small fw-bold text-success cursor-pointer" for="appFreeLooseToggle" title="Trade Scheme: Loose pieces will be free (Rs 0) in bill total, but will be dispatched on load form">
                        <i class="bi bi-gift-fill text-warning me-1"></i> Free Loose Pcs (سکیم / بونس پیس مفت)
                    </label>
                </div>
                <span class="badge bg-warning text-dark small" id="schemeActivePill" style="display:none; font-size: 10px;">
                    <i class="bi bi-check-circle-fill me-1"></i>Scheme Active
                </span>
            </div>
        </div>

        <!-- Product Search & Filter -->
        <div class="mb-2">
            <input type="text" class="app-input mb-2" id="prodSearchInput" placeholder="Search product name or barcode..." oninput="filterProducts()">
        </div>

        <!-- Category Carousel -->
        <div class="category-scroll mb-3" id="categoryPills">
            <div class="cat-pill active" onclick="filterByCategory(0, this)">All Categories</div>
        </div>

        <!-- Product List Cards -->
        <div id="productListContainer">
            <div class="text-center py-4 text-muted">
                <div class="spinner-border text-primary spinner-border-sm" role="status"></div> Loading catalog...
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 3: DELIVERIES & RUN SHEET                                     -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-delivery">
        <!-- Run Selector -->
        <div class="app-card mb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0"><i class="bi bi-truck text-primary me-1"></i> Delivery Run Sheet</h6>
                <input type="date" class="form-control form-control-sm w-auto" id="deliveryDateFilter" value="<?= date('Y-m-d') ?>" onchange="loadDeliveryRuns()">
            </div>
            <select class="form-select form-select-sm" id="deliveryRunSelect" onchange="renderSelectedRunBills()">
                <option value="">Select Delivery Run...</option>
            </select>
        </div>

        <!-- Progress Overview -->
        <div class="app-card mb-2" id="runProgressCard" style="display: none;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small fw-bold">Route Progress:</span>
                <span class="small fw-bold text-primary" id="runProgressText">0 / 0 Completed</span>
            </div>
            <div class="progress mb-2" style="height: 8px;">
                <div class="progress-bar bg-success" id="runProgressBar" style="width: 0%"></div>
            </div>
            <div class="d-flex justify-content-between text-muted" style="font-size: 11px;">
                <span>Total Bills: <strong class="text-dark" id="runTotalAmount">Rs. 0</strong></span>
                <span>Collected: <strong class="text-success" id="runTotalCash">Rs. 0</strong></span>
                <span>Returns: <strong class="text-warning" id="runTotalReturn">Rs. 0</strong></span>
            </div>
        </div>

        <!-- Stops Shop List -->
        <div id="deliveryBillsContainer">
            <div class="text-center py-4 text-muted">Select a delivery run to view route stops.</div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 4: FIELD RETURNS OF GOODS                                     -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-returns">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h6 class="fw-bold mb-0"><i class="bi bi-box-arrow-in-left text-warning me-1"></i> Field Returns</h6>
                <small class="text-muted">Stock returned by retailers during delivery run</small>
            </div>
            <button class="btn btn-sm btn-warning text-dark fw-bold rounded-pill" onclick="openNewReturnModal()">
                <i class="bi bi-plus-circle me-1"></i> New Return
            </button>
        </div>

        <div id="fieldReturnsListContainer">
            <div class="text-center py-4 text-muted">No field returns recorded for today.</div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 5: WAREHOUSE & CASHIER VERIFICATION                           -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-verify">
        <div class="app-card mb-3">
            <ul class="nav nav-pills nav-fill mb-3" id="verifyTabs">
                <li class="nav-item">
                    <button class="nav-link active py-2 fw-bold" id="btnTabWh" onclick="switchVerifySubTab('wh')">
                        <i class="bi bi-box-seam me-1"></i> Warehouse Stock
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link py-2 fw-bold" id="btnTabCashier" onclick="switchVerifySubTab('cashier')">
                        <i class="bi bi-cash-coin me-1"></i> Cashier Handover
                    </button>
                </li>
            </ul>

            <!-- SUB-TAB 1: WAREHOUSE VERIFICATION -->
            <div id="subtab-wh">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="bi bi-info-circle me-1"></i> <strong>Warehouse Incharge:</strong> Inspect the returned stock brought back by the delivery salesman and confirm items into warehouse inventory.
                </div>
                <div id="warehouseVerifyList">
                    <div class="text-center py-3 text-muted">No pending return items awaiting verification.</div>
                </div>
                <div class="mt-3" id="btnBatchVerifyContainer" style="display: none;">
                    <button class="btn-app-success" onclick="verifyWarehouseStockBatch()">
                        <i class="bi bi-check2-all me-1"></i> Confirm & Receive Selected Stock
                    </button>
                </div>
            </div>

            <!-- SUB-TAB 2: CASHIER HANDOVER & SETTLEMENT -->
            <div id="subtab-cashier" style="display: none;">
                <div class="alert alert-warning py-2 px-3 small mb-3">
                    <i class="bi bi-shield-check me-1"></i> <strong>Cashier:</strong> Verify physical cash handed over by salesman against delivery collections to reconcile and close route.
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Select Delivery Run:</label>
                    <select class="form-select form-select-sm" id="cashierRunSelect" onchange="renderCashierSummary()">
                        <option value="">Select Run...</option>
                    </select>
                </div>

                <div id="cashierSummaryCard" class="p-3 border rounded-3 bg-light mb-3" style="display: none;">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Total Invoiced Bills:</span>
                        <strong id="cTotBills">Rs. 0</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-success">
                        <span>Salesman Collected Cash:</span>
                        <strong id="cTotCash">Rs. 0</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-primary">
                        <span>Credit Allowed:</span>
                        <strong id="cTotCredit">Rs. 0</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-warning">
                        <span>Returns Value:</span>
                        <strong id="cTotReturn">Rs. 0</strong>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top fw-bold fs-6">
                        <span>Reconciliation Difference:</span>
                        <span id="cDiff" class="text-dark">Rs. 0</span>
                    </div>
                </div>

                <div id="cashierActionBox" style="display: none;">
                    <label class="form-label small fw-bold">Physical Cash Handover Amount:</label>
                    <input type="number" step="0.01" class="app-input fs-5 fw-bold text-success" id="cashierPhysicalCash" placeholder="0.00">
                    <label class="form-label small fw-bold">Remarks / Settlement Notes:</label>
                    <input type="text" class="app-input" id="cashierRemarks" placeholder="e.g., Full cash verified and received...">
                    <button class="btn-app-primary" onclick="submitCashierHandover()">
                        <i class="bi bi-check-circle me-1"></i> Confirm Cash Handover & Close Route
                    </button>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- Sticky Floating Cart Bar -->
<div class="cart-sticky-bar" id="cartStickyBar">
    <div class="cart-bar-inner" onclick="openCartModal()">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-white text-dark rounded-pill px-2 py-1" id="cartItemsBadge">0</span>
            <span class="fw-bold">View Order Cart</span>
        </div>
        <div class="fw-bold fs-6" id="cartTotalAmt">Rs. 0</div>
    </div>
</div>

<!-- Bottom Navigation Bar -->
<nav class="app-bottom-nav">
    <button class="nav-item-btn active" id="btnNavHome" onclick="switchTab('home')">
        <i class="bi bi-house-door"></i>
        <span>Home</span>
    </button>
    <button class="nav-item-btn" id="btnNavOrder" onclick="switchTab('order')">
        <i class="bi bi-cart-plus"></i>
        <span>Book Order</span>
    </button>
    <button class="nav-item-btn" id="btnNavDelivery" onclick="switchTab('delivery')">
        <i class="bi bi-truck"></i>
        <span>Delivery</span>
    </button>
    <button class="nav-item-btn" id="btnNavReturns" onclick="switchTab('returns')">
        <i class="bi bi-box-arrow-in-left"></i>
        <span>Returns</span>
    </button>
    <button class="nav-item-btn" id="btnNavVerify" onclick="switchTab('verify')">
        <i class="bi bi-shield-check"></i>
        <span>Verify</span>
    </button>
</nav>

<!-- ================================================================= -->
<!-- MODAL 1: SELECT CUSTOMER (ORDER BOOKING)                          -->
<!-- ================================================================= -->
<div class="modal-sheet" id="modalCustomerSheet">
    <div class="sheet-container">
        <div class="sheet-handle"></div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0">Select Customer / Shop</h6>
            <button type="button" class="btn-close" onclick="closeCustomerModal()"></button>
        </div>
        <input type="text" class="app-input" id="custSearchInput" placeholder="Search customer name, phone, or route..." oninput="filterCustomerList()">
        <div class="overflow-auto" style="max-height: 55vh;" id="customerListContainer">
            <div class="text-center py-4 text-muted">Loading customer directory...</div>
        </div>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL 2: ORDER CART & CHECKOUT                                    -->
<!-- ================================================================= -->
<div class="modal-sheet" id="modalCartSheet">
    <div class="sheet-container">
        <div class="sheet-handle"></div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="bi bi-cart4 text-success me-1"></i> Order Summary & Review</h6>
            <button type="button" class="btn-close" onclick="closeCartModal()"></button>
        </div>

        <div class="bg-light p-2 rounded-3 mb-2" style="font-size: 12px;">
            <div class="d-flex justify-content-between">
                <div><strong>Retailer:</strong> <span id="cartShopNameDisplay">-</span></div>
                <div><span class="badge bg-primary" id="cartPricingTierBadge">Wholesale</span></div>
            </div>
            <div class="d-flex justify-content-between mt-1">
                <div><strong>Current Balance:</strong> <span class="text-danger" id="cartShopBalDisplay">Rs. 0</span></div>
                <div id="cartSchemeNotice" style="display: none;"><span class="badge bg-warning text-dark"><i class="bi bi-gift-fill me-1"></i>Free Pcs Scheme Active</span></div>
            </div>
        </div>

        <!-- Discount Mechanism Bar in Cart Modal -->
        <div class="bg-light p-2 rounded-3 border mb-2" style="font-size: 12px;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <strong><i class="bi bi-percent text-danger me-1"></i> Discount Mechanism:</strong>
                <div class="btn-group btn-group-sm">
                    <input type="radio" class="btn-check" name="cart_discount_mode" id="cDiscItem" value="item_rate" checked onchange="setCartDiscountMode('item_rate')">
                    <label class="btn btn-outline-secondary btn-sm py-0 px-2" for="cDiscItem" style="font-size: 11px;">Per Item %</label>
                    <input type="radio" class="btn-check" name="cart_discount_mode" id="cDiscSubtotal" value="subtotal" onchange="setCartDiscountMode('subtotal')">
                    <label class="btn btn-outline-secondary btn-sm py-0 px-2" for="cDiscSubtotal" style="font-size: 11px;">Subtotal</label>
                </div>
            </div>
            <div id="cartSubtotalDiscRow" style="display: none;" class="pt-1 border-top mt-1">
                <div class="row g-2 align-items-center">
                    <div class="col-6">
                        <label class="small text-muted mb-0" style="font-size: 11px;">Disc %:</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm text-end" id="cartSubtotalPct" placeholder="0%" oninput="onCartSubtotalPctChange()">
                    </div>
                    <div class="col-6">
                        <label class="small text-muted mb-0" style="font-size: 11px;">Disc Rs:</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" id="cartSubtotalAmt" placeholder="0.00" oninput="onCartSubtotalAmtChange()">
                    </div>
                </div>
            </div>
        </div>

        <!-- Cart Items List -->
        <div id="cartItemsListContainer" style="max-height: 40vh; overflow-y: auto;" class="mb-3">
            <!-- Dynamic Cart Items -->
        </div>

        <!-- Totals Summary -->
        <div class="border-top pt-2 mb-3" style="font-size: 13px;">
            <div class="d-flex justify-content-between mb-1">
                <span>Total Cartons:</span>
                <strong id="cartSummaryCartons">0 Ctn</strong>
            </div>
            <div class="d-flex justify-content-between mb-1">
                <span>Total Units / Pieces:</span>
                <strong id="cartSummaryUnits">0 Pcs</strong>
            </div>
            <div class="d-flex justify-content-between mb-1 text-muted" id="cartSummaryGrossRow" style="display: none;">
                <span>Gross Amount:</span>
                <span id="cartSummaryGross">Rs. 0</span>
            </div>
            <div class="d-flex justify-content-between mb-1 text-danger" id="cartSummaryDiscountRow" style="display: none;">
                <span>Total Discount:</span>
                <strong id="cartSummaryDiscount">- Rs. 0</strong>
            </div>
            <div class="d-flex justify-content-between mb-1 fs-6 fw-bold border-top pt-1 text-success">
                <span>Net Order Total:</span>
                <span id="cartSummaryTotal">Rs. 0</span>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">Remarks / Notes:</label>
            <input type="text" class="app-input mb-0" id="cartRemarksInput" placeholder="Optional delivery instructions...">
        </div>

        <button class="btn-app-success" id="btnPlaceOrder" onclick="submitOrderBooking()">
            <i class="bi bi-check-circle me-1"></i> Confirm & Place Order
        </button>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL 3: RECORD DELIVERY & CASH (DELIVERY RUN)                    -->
<!-- ================================================================= -->
<div class="modal-sheet" id="modalDeliveryEntrySheet">
    <div class="sheet-container">
        <div class="sheet-handle"></div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="bi bi-cash-stack text-success me-1"></i> Record Delivery & Collection</h6>
            <button type="button" class="btn-close" onclick="closeDeliveryEntryModal()"></button>
        </div>

        <input type="hidden" id="entryRunBillId" value="">
        <input type="hidden" id="entryInvoiceId" value="">
        <input type="hidden" id="entryCustomerId" value="">

        <div class="bg-light p-2 rounded-3 mb-3" style="font-size: 12px;">
            <div class="fw-bold fs-6 text-dark" id="entryShopName">-</div>
            <div class="d-flex justify-content-between mt-1">
                <span>Invoice #: <strong id="entryBillNo">-</strong></span>
                <span>Bill Amount: <strong class="text-primary" id="entryBillAmount">Rs. 0</strong></span>
            </div>
            <div class="d-flex justify-content-between mt-1">
                <span>Previous Balance: <strong class="text-danger" id="entryPrevBal">Rs. 0</strong></span>
                <span>Total Due: <strong class="text-dark" id="entryTotalDue">Rs. 0</strong></span>
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label small fw-bold text-success">Cash Received:</label>
            <input type="number" step="0.01" class="app-input fs-5 fw-bold text-success" id="entryCashInput" placeholder="0.00" oninput="calculateRemainingCredit()">
        </div>

        <div class="mb-2">
            <label class="form-label small fw-bold text-warning">Returns Value:</label>
            <div class="input-group">
                <input type="number" step="0.01" class="form-control" id="entryReturnInput" placeholder="0.00" oninput="calculateRemainingCredit()">
                <button class="btn btn-outline-warning text-dark" type="button" onclick="openBillReturnPicker()">
                    <i class="bi bi-plus-circle"></i> Add Return Items
                </button>
            </div>
            <small class="text-muted" id="entryReturnSummary">No return items recorded</small>
        </div>

        <div class="mb-2">
            <label class="form-label small fw-bold text-primary">Remaining Credit / Balance:</label>
            <input type="number" step="0.01" class="app-input fs-6 fw-bold text-primary" id="entryCreditInput" placeholder="0.00">
        </div>

        <div class="mb-2">
            <label class="form-label small fw-bold">Delivery Status:</label>
            <select class="form-select form-select-sm" id="entryStatusSelect">
                <option value="delivered">Delivered (Full Delivery)</option>
                <option value="partial">Partial Delivery</option>
                <option value="refused">Refused / Not Delivered</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">Remarks / Reason:</label>
            <input type="text" class="app-input mb-0" id="entryRemarksInput" placeholder="Notes or remarks...">
        </div>

        <button class="btn-app-primary" onclick="saveDeliveryBillEntry()">
            <i class="bi bi-save me-1"></i> Save Delivery & Collection
        </button>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL 4: ON-THE-SPOT RETURN PICKER                                -->
<!-- ================================================================= -->
<div class="modal-sheet" id="modalReturnPickerSheet">
    <div class="sheet-container">
        <div class="sheet-handle"></div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="bi bi-box-seam text-warning me-1"></i> Field Stock Return Entry</h6>
            <button type="button" class="btn-close" onclick="closeReturnPickerModal()"></button>
        </div>

        <div class="mb-2">
            <label class="form-label small fw-bold">Select Product:</label>
            <select class="form-select form-select-sm" id="retProductSelect" onchange="onReturnProductChanged()">
                <option value="">Select Product...</option>
            </select>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-4">
                <label class="form-label small">Carton (Ctn):</label>
                <input type="number" step="1" min="0" class="form-control form-control-sm" id="retCartonQty" value="0" oninput="calculateReturnItemTotal()">
            </div>
            <div class="col-4">
                <label class="form-label small">Pack:</label>
                <input type="number" step="1" min="0" class="form-control form-control-sm" id="retPackQty" value="0" oninput="calculateReturnItemTotal()">
            </div>
            <div class="col-4">
                <label class="form-label small">Pieces (Pcs):</label>
                <input type="number" step="1" min="0" class="form-control form-control-sm" id="retPcsQty" value="0" oninput="calculateReturnItemTotal()">
            </div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label small">Condition:</label>
                <select class="form-select form-select-sm" id="retCondition">
                    <option value="saleable">Saleable (Resellable Stock)</option>
                    <option value="damaged">Damaged / Broken</option>
                    <option value="expired">Expired Stock</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label small">Return Amount:</label>
                <input type="number" step="0.01" class="form-control form-control-sm fw-bold text-warning" id="retItemAmount" value="0.00" readonly>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small">Return Reason:</label>
            <input type="text" class="app-input mb-0" id="retReason" placeholder="e.g., Retailer returned excess stock...">
        </div>

        <button class="btn-app-success" onclick="commitReturnItem()">
            <i class="bi bi-plus-circle me-1"></i> Add to Return List
        </button>
    </div>
</div>

<script>
/**
 * DMS Mobile App JavaScript Controller (English)
 */
window.APP_CONFIG = {
    apiUrl: "<?= APP_URL ?>/modules/app/api.php",
    currency: "Rs.",
    userId: <?= (int)$activeUser['id'] ?>
};

// Global App State
let APP_STATE = {
    salesmen: [],
    bookers: [],
    areas: [],
    categories: [],
    products: [],
    customers: [],
    activeSalesmanId: 0,
    activeBookerId: 0,
    selectedCustomerId: 0,
    selectedCustomer: null,
    pricingMode: 'wholesale', // 'wholesale' or 'mrp'
    freeLoosePieces: 0,        // 0 or 1 (Trade Scheme)
    discountMode: 'item_rate', // 'item_rate' or 'subtotal'
    subtotalDiscountPercent: 0,
    subtotalDiscountAmount: 0,
    cart: {}, // productId => { carton_qty, pack_qty, pcs_qty, discount_percent }
    deliveryRuns: [],
    activeRunId: 0,
    fieldReturns: [],
    tempReturnItems: []
};

// Initialize App on DOM Load
document.addEventListener('DOMContentLoaded', async () => {
    await fetchInitialData();
    await loadDailySummary();
    await loadDeliveryRuns();
    await loadFieldReturns();
});

// Toast Helper
function showToast(msg) {
    const toast = document.getElementById('appToast');
    toast.innerText = msg;
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 3200);
}

// Tab Switching
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-item-btn').forEach(el => el.classList.remove('active'));

    const targetTab = document.getElementById('tab-' + tabId);
    if (targetTab) targetTab.classList.add('active');

    const btnNav = document.getElementById('btnNav' + capitalizeFirst(tabId));
    if (btnNav) btnNav.classList.add('active');

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });

    if (tabId === 'delivery') {
        loadDeliveryRuns();
    } else if (tabId === 'returns') {
        loadFieldReturns();
    } else if (tabId === 'verify') {
        loadVerifyData();
    }
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// -----------------------------------------------------------------
// 1. INITIAL DATA FETCH
// -----------------------------------------------------------------
async function fetchInitialData() {
    try {
        const res = await fetch(APP_CONFIG.apiUrl + '?action=init');
        const json = await res.json();
        if (json.success) {
            APP_STATE.salesmen   = json.data.salesmen || [];
            APP_STATE.bookers    = json.data.bookers || [];
            APP_STATE.areas      = json.data.areas || [];
            APP_STATE.categories = json.data.categories || [];
            APP_STATE.products   = json.data.products || [];

            APP_STATE.activeSalesmanId = parseInt(json.data.assigned_salesman_id) || parseInt(json.data.default_salesman_id) || 0;
            APP_STATE.activeBookerId   = parseInt(json.data.assigned_booker_id) || parseInt(json.data.default_booker_id) || 0;

            if (document.getElementById('loggedStaffName')) {
                document.getElementById('loggedStaffName').textContent = json.data.staff_name || json.data.user.full_name;
            }
            if (document.getElementById('loggedStaffRole')) {
                document.getElementById('loggedStaffRole').textContent = json.data.staff_role || 'Field Staff';
            }

            updateSalesmanDisplay(json.data.staff_name, json.data.staff_vehicle);
            renderCategories();
            renderProducts();
            await fetchCustomers();
        }
    } catch (err) {
        console.error("Error loading initial data", err);
        showToast("Error loading initial application data.");
    }
}

function updateSalesmanDisplay(name, vehicle) {
    if (name) {
        document.getElementById('welcomeSalesmanName').textContent = name;
        document.getElementById('welcomeSubText').textContent = vehicle
            ? 'Vehicle: ' + vehicle + ' • Field sales & delivery active.'
            : 'Field sales & delivery active.';
    }
}

// -----------------------------------------------------------------
// 2. DAILY SUMMARY KPIS
// -----------------------------------------------------------------
async function loadDailySummary() {
    try {
        const res = await fetch(`${APP_CONFIG.apiUrl}?action=daily_summary&salesman_id=${APP_STATE.activeSalesmanId}`);
        const json = await res.json();
        if (json.success) {
            const d = json.data;
            document.getElementById('statOrdersCount').textContent = d.orders_count + ' Orders';
            document.getElementById('statOrdersAmt').textContent = 'Rs. ' + Number(d.orders_amount).toLocaleString();
            document.getElementById('statCashColl').textContent = 'Rs. ' + Number(d.cash_collected).toLocaleString();
            document.getElementById('statDeliveredStops').textContent = `${d.delivered_bills} / ${d.total_bills}`;

            const pct = d.total_bills > 0 ? Math.round((d.delivered_bills / d.total_bills) * 100) : 0;
            document.getElementById('statDeliveredPct').textContent = pct + '% Completed';
            document.getElementById('statPendingReturns').textContent = d.pending_returns + ' Items';
        }
    } catch (err) {
        console.error(err);
    }
}

// -----------------------------------------------------------------
// 3. CUSTOMER SELECTION & MODAL
// -----------------------------------------------------------------
async function fetchCustomers() {
    try {
        const res = await fetch(APP_CONFIG.apiUrl + '?action=customers');
        const json = await res.json();
        if (json.success) {
            APP_STATE.customers = json.data;
            renderCustomerList(APP_STATE.customers);
            if (APP_STATE.customers.length > 0 && !APP_STATE.selectedCustomerId) {
                selectCustomer(APP_STATE.customers[0]);
            }
        }
    } catch (err) {
        console.error(err);
    }
}

function openCustomerModal() {
    document.getElementById('modalCustomerSheet').classList.add('show');
    document.getElementById('custSearchInput').focus();
}

function closeCustomerModal() {
    document.getElementById('modalCustomerSheet').classList.remove('show');
}

function filterCustomerList() {
    const q = document.getElementById('custSearchInput').value.toLowerCase();
    const filtered = APP_STATE.customers.filter(c => 
        (c.shop_name && c.shop_name.toLowerCase().includes(q)) ||
        (c.owner_name && c.owner_name.toLowerCase().includes(q)) ||
        (c.phone && c.phone.includes(q))
    );
    renderCustomerList(filtered);
}

function renderCustomerList(list) {
    const container = document.getElementById('customerListContainer');
    if (!list || list.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted">No customers found.</div>';
        return;
    }

    container.innerHTML = list.map(c => `
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="cursor: pointer;" onclick="selectCustomerById(${c.id})">
            <div>
                <div class="fw-bold">${c.shop_name}</div>
                <small class="text-muted">${c.owner_name ? c.owner_name + ' • ' : ''}${c.area_name || ''}</small>
            </div>
            <div class="text-end">
                <span class="badge ${parseFloat(c.current_balance) > 0 ? 'bg-danger' : 'bg-success'}">
                    Rs. ${Number(c.current_balance).toLocaleString()}
                </span>
            </div>
        </div>
    `).join('');
}

function selectCustomerById(id) {
    const c = APP_STATE.customers.find(item => item.id == id);
    if (c) selectCustomer(c);
    closeCustomerModal();
}

function selectCustomer(c) {
    APP_STATE.selectedCustomerId = c.id;
    APP_STATE.selectedCustomer = c;
    document.getElementById('currentShopName').textContent = c.shop_name;
    document.getElementById('currentShopRoute').textContent = (c.area_name || '-') + (c.phone ? ' • ' + c.phone : '');
    document.getElementById('currentShopBal').textContent = 'Rs. ' + Number(c.current_balance).toLocaleString();
    document.getElementById('currentShopLimit').textContent = 'Rs. ' + Number(c.credit_limit || 0).toLocaleString();

    document.getElementById('cartShopNameDisplay').textContent = c.shop_name;
    document.getElementById('cartShopBalDisplay').textContent = 'Rs. ' + Number(c.current_balance).toLocaleString();

    const discNotice = document.getElementById('custDiscountNotice');
    const discNoticeVal = document.getElementById('custDiscountNoticeVal');
    const defaultDisc = parseFloat(c.default_discount_percent) || 0;
    if (defaultDisc > 0) {
        if (discNotice) discNotice.style.display = 'block';
        if (discNoticeVal) discNoticeVal.textContent = defaultDisc + '% Default Retailer Discount Active';
        // Prefill default discount on existing cart items if item has no discount
        Object.keys(APP_STATE.cart).forEach(pid => {
            if (!APP_STATE.cart[pid].discount_percent) {
                APP_STATE.cart[pid].discount_percent = defaultDisc;
            }
        });
    } else {
        if (discNotice) discNotice.style.display = 'none';
    }
}

// -----------------------------------------------------------------
// 4. ORDER BOOKING & PRODUCT CARDS
// -----------------------------------------------------------------
function getProductRates(p, mode) {
    mode = mode || APP_STATE.pricingMode || 'wholesale';
    const packsPerCtn = parseFloat(p.packs_per_ctn) > 0 ? parseFloat(p.packs_per_ctn) : (parseFloat(p.carton_qty) > 0 ? parseFloat(p.carton_qty) : 1);
    const pcsPerPack  = parseFloat(p.pcs_per_pack) > 0 ? parseFloat(p.pcs_per_pack) : (parseFloat(p.pack_qty) > 0 ? parseFloat(p.pack_qty) : 1);
    const pcsPerCtn   = parseFloat(p.pcs_per_ctn) > 0 ? parseFloat(p.pcs_per_ctn) : (packsPerCtn * pcsPerPack);

    if (mode === 'mrp') {
        const mrpCarton = parseFloat(p.mrp_carton_rate) > 0 
            ? parseFloat(p.mrp_carton_rate) 
            : (parseFloat(p.retail_carton_rate) > 0 ? parseFloat(p.retail_carton_rate) : (parseFloat(p.retail_rate) > 0 ? parseFloat(p.retail_rate) : (parseFloat(p.carton_rate) || parseFloat(p.wholesale_rate) || 0)));
        const mrpPack   = packsPerCtn > 0 ? (mrpCarton / packsPerCtn) : mrpCarton;
        const mrpPiece  = parseFloat(p.mrp_piece_rate) > 0 
            ? parseFloat(p.mrp_piece_rate) 
            : (parseFloat(p.retail_piece_rate) > 0 ? parseFloat(p.retail_piece_rate) : (pcsPerCtn > 0 ? mrpCarton / pcsPerCtn : mrpCarton));
        return {
            carton_rate: mrpCarton,
            pack_rate: mrpPack,
            piece_rate: mrpPiece,
            packs_per_ctn: packsPerCtn,
            pcs_per_pack: pcsPerPack,
            pcs_per_ctn: pcsPerCtn
        };
    } else {
        const wsCarton  = parseFloat(p.wholesale_carton_rate) > 0 
            ? parseFloat(p.wholesale_carton_rate) 
            : (parseFloat(p.carton_rate) > 0 ? parseFloat(p.carton_rate) : (parseFloat(p.wholesale_rate) || 0));
        const wsPack    = packsPerCtn > 0 ? (wsCarton / packsPerCtn) : wsCarton;
        const wsPiece   = parseFloat(p.wholesale_piece_rate) > 0 
            ? parseFloat(p.wholesale_piece_rate) 
            : (parseFloat(p.piece_rate) > 0 ? parseFloat(p.piece_rate) : (pcsPerCtn > 0 ? wsCarton / pcsPerCtn : wsCarton));
        return {
            carton_rate: wsCarton,
            pack_rate: wsPack,
            piece_rate: wsPiece,
            packs_per_ctn: packsPerCtn,
            pcs_per_pack: pcsPerPack,
            pcs_per_ctn: pcsPerCtn
        };
    }
}

function setAppPricingMode(mode) {
    APP_STATE.pricingMode = mode === 'mrp' ? 'mrp' : 'wholesale';
    const tierBadge = document.getElementById('cartPricingTierBadge');
    if (tierBadge) {
        tierBadge.textContent = APP_STATE.pricingMode === 'mrp' ? 'Retail / MRP Price' : 'Wholesale Price';
        tierBadge.className = APP_STATE.pricingMode === 'mrp' ? 'badge bg-success' : 'badge bg-primary';
    }
    renderProducts();
    updateCartBar();
    renderCartModalItems();
}

function onAppFreeLooseToggle() {
    const chk = document.getElementById('appFreeLooseToggle');
    APP_STATE.freeLoosePieces = chk && chk.checked ? 1 : 0;
    const pill = document.getElementById('schemeActivePill');
    if (pill) pill.style.display = APP_STATE.freeLoosePieces ? 'inline-block' : 'none';
    const notice = document.getElementById('cartSchemeNotice');
    if (notice) notice.style.display = APP_STATE.freeLoosePieces ? 'block' : 'none';
    renderProducts();
    updateCartBar();
    renderCartModalItems();
}

function setCartDiscountMode(mode) {
    APP_STATE.discountMode = mode === 'subtotal' ? 'subtotal' : 'item_rate';
    const subRow = document.getElementById('cartSubtotalDiscRow');
    if (subRow) subRow.style.display = APP_STATE.discountMode === 'subtotal' ? 'block' : 'none';
    updateCartBar();
    renderCartModalItems();
}

function onCartSubtotalPctChange() {
    if (APP_STATE.discountMode !== 'subtotal') return;
    const pct = parseFloat(document.getElementById('cartSubtotalPct').value) || 0;
    APP_STATE.subtotalDiscountPercent = pct;
    const totals = calculateCartTotals();
    const amt = Math.round(((totals.gross * pct) / 100) * 100) / 100;
    APP_STATE.subtotalDiscountAmount = amt;
    const amtInput = document.getElementById('cartSubtotalAmt');
    if (amtInput && document.activeElement !== amtInput) {
        amtInput.value = amt > 0 ? amt.toFixed(2) : '';
    }
    renderCartTotalsOnly();
}

function onCartSubtotalAmtChange() {
    if (APP_STATE.discountMode !== 'subtotal') return;
    const amt = parseFloat(document.getElementById('cartSubtotalAmt').value) || 0;
    APP_STATE.subtotalDiscountAmount = amt;
    const totals = calculateCartTotals();
    const pct = totals.gross > 0 ? Math.round(((amt / totals.gross) * 100) * 100) / 100 : 0;
    APP_STATE.subtotalDiscountPercent = pct;
    const pctInput = document.getElementById('cartSubtotalPct');
    if (pctInput && document.activeElement !== pctInput) {
        pctInput.value = pct > 0 ? pct.toFixed(2) : '';
    }
    renderCartTotalsOnly();
}

function updateItemDiscount(productId, discPct) {
    if (APP_STATE.cart[productId]) {
        APP_STATE.cart[productId].discount_percent = Math.max(0, Math.min(100, parseFloat(discPct) || 0));
        updateCartBar();
        renderCartModalItems();
    }
}

function calculateItemLine(p, item) {
    const rates = getProductRates(p, APP_STATE.pricingMode);
    const ctn = parseFloat(item.carton_qty) || 0;
    const pck = parseFloat(item.pack_qty) || 0;
    const pcs = parseFloat(item.pcs_qty) || 0;

    const totalPieces = (ctn * rates.pcs_per_ctn) + (pck * rates.pcs_per_pack) + pcs;
    const effectivePcs = APP_STATE.freeLoosePieces === 1 ? 0 : pcs;
    const billablePieces = (ctn * rates.pcs_per_ctn) + (pck * rates.pcs_per_pack) + effectivePcs;
    const cartonEquiv = ctn + (pck / rates.packs_per_ctn) + (effectivePcs / rates.pcs_per_ctn);
    const lineGross = Math.round(cartonEquiv * rates.carton_rate * 100) / 100;

    let lineDiscount = 0;
    if (APP_STATE.discountMode === 'item_rate') {
        const dPct = parseFloat(item.discount_percent) || 0;
        lineDiscount = Math.round((lineGross * (dPct / 100)) * 100) / 100;
    }
    const lineNet = Math.max(0, lineGross - lineDiscount);

    return {
        rates,
        ctn,
        pck,
        pcs,
        totalPieces,
        effectivePcs,
        billablePieces,
        cartonEquiv: ctn + (pck / rates.packs_per_ctn) + (pcs / rates.pcs_per_ctn),
        lineGross,
        lineDiscount,
        lineNet
    };
}

function calculateCartTotals() {
    let gross = 0;
    let itemDiscounts = 0;
    let totalCartons = 0;
    let totalPieces = 0;
    const keys = Object.keys(APP_STATE.cart);

    keys.forEach(pid => {
        const p = APP_STATE.products.find(item => item.id == pid);
        if (p) {
            const line = calculateItemLine(p, APP_STATE.cart[pid]);
            gross += line.lineGross;
            itemDiscounts += line.lineDiscount;
            totalCartons += line.cartonEquiv;
            totalPieces += line.totalPieces;
        }
    });

    let totalDiscount = 0;
    if (APP_STATE.discountMode === 'subtotal') {
        if (APP_STATE.subtotalDiscountAmount > 0) {
            totalDiscount = Math.min(gross, APP_STATE.subtotalDiscountAmount);
        } else if (APP_STATE.subtotalDiscountPercent > 0) {
            totalDiscount = Math.round((gross * (APP_STATE.subtotalDiscountPercent / 100)) * 100) / 100;
        }
    } else {
        totalDiscount = itemDiscounts;
    }

    const net = Math.max(0, Math.round((gross - totalDiscount) * 100) / 100);

    return {
        gross,
        totalDiscount,
        net,
        totalCartons,
        totalPieces,
        itemCount: keys.length
    };
}

function renderCategories() {
    const container = document.getElementById('categoryPills');
    container.innerHTML = '<div class="cat-pill active" onclick="filterByCategory(0, this)">All Categories</div>';
    APP_STATE.categories.forEach(cat => {
        const pill = document.createElement('div');
        pill.className = 'cat-pill';
        pill.textContent = cat.category_name;
        pill.onclick = () => filterByCategory(cat.id, pill);
        container.appendChild(pill);
    });
}

let activeCatId = 0;
function filterByCategory(catId, el) {
    activeCatId = catId;
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    renderProducts();
}

function filterProducts() {
    renderProducts();
}

function renderProducts() {
    const container = document.getElementById('productListContainer');
    const q = document.getElementById('prodSearchInput').value.toLowerCase();

    const filtered = APP_STATE.products.filter(p => {
        const matchCat = (activeCatId === 0 || p.category_id == activeCatId);
        const matchSearch = (!q || p.product_name.toLowerCase().includes(q) || (p.barcode && p.barcode.includes(q)) || (p.product_code && p.product_code.toLowerCase().includes(q)));
        return matchCat && matchSearch;
    });

    if (filtered.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted">No products found matching criteria.</div>';
        return;
    }

    container.innerHTML = filtered.map(p => {
        const defaultDisc = APP_STATE.selectedCustomer ? (parseFloat(APP_STATE.selectedCustomer.default_discount_percent) || 0) : 0;
        const cartItem = APP_STATE.cart[p.id] || { carton_qty: 0, pack_qty: 0, pcs_qty: 0, discount_percent: defaultDisc };
        const hasStock = p.saleable_qty > 0;
        const rates = getProductRates(p, APP_STATE.pricingMode);
        const hasPacks = rates.packs_per_ctn > 1;
        const isSchemeActive = APP_STATE.freeLoosePieces === 1;

        // Ratio & packing label
        let packingLabel = `${rates.pcs_per_ctn} Pcs/Ctn`;
        if (hasPacks) {
            packingLabel = `1 Ctn = ${rates.packs_per_ctn} Pks • 1 Pk = ${rates.pcs_per_pack} Pcs (${rates.pcs_per_ctn} Pcs/Ctn)`;
        } else {
            packingLabel = `1 Ctn = ${rates.pcs_per_ctn} Pcs`;
        }

        const line = calculateItemLine(p, cartItem);
        const itemHasQty = (cartItem.carton_qty > 0 || cartItem.pack_qty > 0 || cartItem.pcs_qty > 0);

        return `
            <div class="prod-card">
                <div class="prod-header">
                    <div>
                        <div class="prod-title">${p.product_name}</div>
                        <div class="prod-sub">${p.category_name ? p.category_name + ' • ' : ''}${packingLabel}</div>
                    </div>
                    <div>
                        <span class="badge ${hasStock ? 'bg-success' : 'bg-danger'}">
                            Stock: ${Math.round(p.saleable_qty).toLocaleString()}
                        </span>
                    </div>
                </div>

                <!-- Product-wise Rate Breakdown (Carton, Pack, Piece) -->
                <div class="prod-rates-grid">
                    <div class="prod-rate-box">
                        <span class="rate-lbl">Ctn Rate</span>
                        <strong class="rate-val text-primary">Rs. ${Math.round(rates.carton_rate).toLocaleString()}</strong>
                    </div>
                    ${hasPacks ? `
                    <div class="prod-rate-box">
                        <span class="rate-lbl">Pack Rate</span>
                        <strong class="rate-val text-info">Rs. ${rates.pack_rate.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
                    </div>
                    ` : ''}
                    <div class="prod-rate-box">
                        <span class="rate-lbl">Piece Rate</span>
                        <strong class="rate-val text-success">Rs. ${rates.piece_rate.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
                    </div>
                </div>

                <!-- Steppers: Cartons, Packs (if available), Loose Pieces -->
                <div class="qty-row" style="grid-template-columns: ${hasPacks ? '1fr 1fr 1fr' : '1fr 1fr'};">
                    <!-- Carton Counter -->
                    <div>
                        <small class="text-muted d-block mb-1" style="font-size: 11px;">Cartons (Ctn):</small>
                        <div class="stepper-box">
                            <button class="stepper-btn" onclick="updateQty(${p.id}, 'carton_qty', -1)" type="button">-</button>
                            <input type="number" inputmode="numeric" pattern="[0-9]*" class="stepper-val" value="${cartItem.carton_qty}" min="0" oninput="setDirectQty(${p.id}, 'carton_qty', this.value)" onfocus="if(this.value=='0')this.value='';this.select();" onblur="onQtyBlur(${p.id}, 'carton_qty', this)">
                            <button class="stepper-btn" onclick="updateQty(${p.id}, 'carton_qty', 1)" type="button">+</button>
                        </div>
                    </div>

                    ${hasPacks ? `
                    <!-- Pack Counter -->
                    <div>
                        <small class="text-muted d-block mb-1" style="font-size: 11px;">Packs (Pks):</small>
                        <div class="stepper-box">
                            <button class="stepper-btn" onclick="updateQty(${p.id}, 'pack_qty', -1)" type="button">-</button>
                            <input type="number" inputmode="numeric" pattern="[0-9]*" class="stepper-val" value="${cartItem.pack_qty || 0}" min="0" oninput="setDirectQty(${p.id}, 'pack_qty', this.value)" onfocus="if(this.value=='0')this.value='';this.select();" onblur="onQtyBlur(${p.id}, 'pack_qty', this)">
                            <button class="stepper-btn" onclick="updateQty(${p.id}, 'pack_qty', 1)" type="button">+</button>
                        </div>
                    </div>
                    ` : ''}

                    <!-- Pieces Counter -->
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted" style="font-size: 11px;">Pieces (Pcs):</small>
                            ${isSchemeActive && cartItem.pcs_qty > 0 ? `<span class="badge bg-warning text-dark py-0 px-1" style="font-size: 9px;">Free</span>` : ''}
                        </div>
                        <div class="stepper-box">
                            <button class="stepper-btn" onclick="updateQty(${p.id}, 'pcs_qty', -1)" type="button">-</button>
                            <input type="number" inputmode="numeric" pattern="[0-9]*" class="stepper-val" value="${cartItem.pcs_qty}" min="0" oninput="setDirectQty(${p.id}, 'pcs_qty', this.value)" onfocus="if(this.value=='0')this.value='';this.select();" onblur="onQtyBlur(${p.id}, 'pcs_qty', this)">
                            <button class="stepper-btn" onclick="updateQty(${p.id}, 'pcs_qty', 1)" type="button">+</button>
                        </div>
                    </div>
                </div>

                <!-- Product Card Subtotal & Scheme Note -->
                <div class="card-subtotal-footer d-flex justify-content-between align-items-center pt-2 border-top mt-1" id="cardFooter_${p.id}" style="font-size: 12px; display: ${itemHasQty ? 'flex' : 'none'};">
                    <span class="text-muted">
                        Qty: <strong>${Math.round(line.totalPieces)} Pcs</strong> (${line.cartonEquiv.toFixed(2)} Ctn)
                        ${isSchemeActive && cartItem.pcs_qty > 0 ? `<span class="text-success fw-bold ms-1">(+${cartItem.pcs_qty} Pcs Free Scheme)</span>` : ''}
                    </span>
                    <span class="fw-bold text-primary">Rs. ${line.lineNet.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
            </div>
        `;
    }).join('');
}

function ensureCartItem(productId) {
    if (!APP_STATE.cart[productId]) {
        const defaultDisc = APP_STATE.selectedCustomer ? (parseFloat(APP_STATE.selectedCustomer.default_discount_percent) || 0) : 0;
        APP_STATE.cart[productId] = { carton_qty: 0, pack_qty: 0, pcs_qty: 0, discount_percent: defaultDisc };
    }
}

function cleanCartItemIfEmpty(productId) {
    const it = APP_STATE.cart[productId];
    if (it && (it.carton_qty || 0) === 0 && (it.pack_qty || 0) === 0 && (it.pcs_qty || 0) === 0) {
        delete APP_STATE.cart[productId];
    }
}

function updateQty(productId, field, delta) {
    ensureCartItem(productId);
    const cur = parseFloat(APP_STATE.cart[productId][field]) || 0;
    const next = Math.max(0, cur + delta);
    APP_STATE.cart[productId][field] = next;

    cleanCartItemIfEmpty(productId);
    renderProducts();
    updateCartBar();
    renderCartModalItems();
}

function setDirectQty(productId, field, val) {
    ensureCartItem(productId);
    const num = Math.max(0, parseFloat(val) || 0);
    APP_STATE.cart[productId][field] = num;

    updateCartBar();
    renderCartTotalsOnly();
    updateCardSubtotalDisplay(productId);
}

function onQtyBlur(productId, field, el) {
    if (el.value === '' || isNaN(parseFloat(el.value))) {
        el.value = '0';
    }
    setDirectQty(productId, field, el.value);
    cleanCartItemIfEmpty(productId);
    updateCardSubtotalDisplay(productId);
}

function updateCardSubtotalDisplay(productId) {
    const cardFooter = document.getElementById('cardFooter_' + productId);
    if (!cardFooter) return;

    const p = APP_STATE.products.find(item => item.id == productId);
    const item = APP_STATE.cart[productId] || { carton_qty: 0, pack_qty: 0, pcs_qty: 0 };
    const hasQty = (item.carton_qty > 0 || item.pack_qty > 0 || item.pcs_qty > 0);

    if (!hasQty || !p) {
        cardFooter.style.display = 'none';
        return;
    }

    const line = calculateItemLine(p, item);
    const isSchemeActive = APP_STATE.freeLoosePieces === 1;

    cardFooter.innerHTML = `
        <span class="text-muted">
            Qty: <strong>${Math.round(line.totalPieces)} Pcs</strong> (${line.cartonEquiv.toFixed(2)} Ctn)
            ${isSchemeActive && item.pcs_qty > 0 ? `<span class="text-success fw-bold ms-1">(+${item.pcs_qty} Pcs Free Scheme)</span>` : ''}
        </span>
        <span class="fw-bold text-primary">Rs. ${line.lineNet.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
    `;
    cardFooter.style.display = 'flex';
}

function updateCartBar() {
    const cartBar = document.getElementById('cartStickyBar');
    const totals = calculateCartTotals();

    if (totals.itemCount === 0) {
        cartBar.style.display = 'none';
        return;
    }

    document.getElementById('cartItemsBadge').textContent = totals.itemCount;
    document.getElementById('cartTotalAmt').textContent = 'Rs. ' + Math.round(totals.net).toLocaleString();
    cartBar.style.display = 'block';
}

function openCartModal() {
    renderCartModalItems();
    document.getElementById('modalCartSheet').classList.add('show');
}

function closeCartModal() {
    document.getElementById('modalCartSheet').classList.remove('show');
}

function renderCartTotalsOnly() {
    const totals = calculateCartTotals();

    const ctnEl = document.getElementById('cartSummaryCartons');
    if (ctnEl) ctnEl.textContent = totals.totalCartons.toFixed(2) + ' Ctn';

    const unitEl = document.getElementById('cartSummaryUnits');
    if (unitEl) unitEl.textContent = Math.round(totals.totalPieces).toLocaleString() + ' Pcs';

    const grossRow = document.getElementById('cartSummaryGrossRow');
    const grossEl  = document.getElementById('cartSummaryGross');
    const discRow  = document.getElementById('cartSummaryDiscountRow');
    const discEl   = document.getElementById('cartSummaryDiscount');
    const totalEl  = document.getElementById('cartSummaryTotal');

    if (totals.totalDiscount > 0) {
        if (grossRow) grossRow.style.display = 'flex';
        if (grossEl) grossEl.textContent = 'Rs. ' + Math.round(totals.gross).toLocaleString();
        if (discRow) discRow.style.display = 'flex';
        if (discEl) discEl.textContent = '- Rs. ' + Math.round(totals.totalDiscount).toLocaleString();
    } else {
        if (grossRow) grossRow.style.display = 'none';
        if (discRow) discRow.style.display = 'none';
    }

    if (totalEl) totalEl.textContent = 'Rs. ' + Math.round(totals.net).toLocaleString();

    // Update sticky bar as well
    const cartTotalAmt = document.getElementById('cartTotalAmt');
    if (cartTotalAmt) cartTotalAmt.textContent = 'Rs. ' + Math.round(totals.net).toLocaleString();
}

function renderCartModalItems() {
    const container = document.getElementById('cartItemsListContainer');
    const keys = Object.keys(APP_STATE.cart);

    if (keys.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted">Your cart is empty.</div>';
        renderCartTotalsOnly();
        return;
    }

    const isScheme = APP_STATE.freeLoosePieces === 1;

    container.innerHTML = keys.map(pid => {
        const p = APP_STATE.products.find(item => item.id == pid);
        if (!p) return '';
        const item = APP_STATE.cart[pid];
        const line = calculateItemLine(p, item);

        return `
            <div class="p-2 border-bottom">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-bold">${p.product_name}</div>
                        <small class="text-muted">
                            ${item.carton_qty > 0 ? item.carton_qty + ' Ctn ' : ''}
                            ${item.pack_qty > 0 ? item.pack_qty + ' Pk ' : ''}
                            ${item.pcs_qty > 0 ? item.pcs_qty + ' Pcs ' : ''}
                            (@ Rs. ${line.rates.carton_rate.toLocaleString()}/ctn)
                        </small>
                        ${isScheme && item.pcs_qty > 0 ? `
                            <div class="small text-success fw-bold">
                                <i class="bi bi-gift-fill me-1"></i> ${item.pcs_qty} Loose Pcs Free Scheme (Rs. 0)
                            </div>
                        ` : ''}
                    </div>
                    <div class="text-end">
                        <div class="fw-bold text-primary">Rs. ${line.lineNet.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="removeCartItem(${pid})">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                    </div>
                </div>

                ${APP_STATE.discountMode === 'item_rate' ? `
                <div class="d-flex align-items-center justify-content-end gap-2 mt-1 pt-1 border-top" style="font-size: 11px;">
                    <span class="text-muted">Item Disc %:</span>
                    <input type="number" step="0.5" min="0" max="100" class="form-control form-control-sm text-end py-0 px-1" style="width: 70px; height: 24px; font-size: 11px;" value="${item.discount_percent || 0}" onchange="updateItemDiscount(${pid}, this.value)">
                    ${line.lineDiscount > 0 ? `<span class="text-danger">(-Rs. ${line.lineDiscount.toFixed(2)})</span>` : ''}
                </div>
                ` : ''}
            </div>
        `;
    }).join('');

    renderCartTotalsOnly();
}

function removeCartItem(pid) {
    delete APP_STATE.cart[pid];
    renderProducts();
    updateCartBar();
    renderCartModalItems();
    if (Object.keys(APP_STATE.cart).length === 0) {
        closeCartModal();
    }
}

async function submitOrderBooking() {
    if (!APP_STATE.selectedCustomerId) {
        showToast("Please select a customer / shop.");
        return;
    }

    const keys = Object.keys(APP_STATE.cart);
    if (keys.length === 0) {
        showToast("Please add at least one product to cart.");
        return;
    }

    const btn = document.getElementById('btnPlaceOrder');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Placing Order...';

    const orderItems = keys.map(pid => {
        const p = APP_STATE.products.find(item => item.id == pid);
        const item = APP_STATE.cart[pid];
        const rates = getProductRates(p, APP_STATE.pricingMode);
        return {
            product_id: parseInt(pid),
            unit: 'ctn',
            carton_qty: parseFloat(item.carton_qty) || 0,
            pack_qty: parseFloat(item.pack_qty) || 0,
            pcs_qty: parseFloat(item.pcs_qty) || 0,
            rate: rates.carton_rate,
            discount_percent: APP_STATE.discountMode === 'item_rate' ? (parseFloat(item.discount_percent) || 0) : 0
        };
    });

    const payload = {
        customer_id: APP_STATE.selectedCustomerId,
        salesman_id: APP_STATE.activeSalesmanId,
        booker_id: APP_STATE.activeBookerId,
        pricing_mode: APP_STATE.pricingMode,
        discount_mode: APP_STATE.discountMode,
        free_loose_pieces: APP_STATE.freeLoosePieces,
        subtotal_discount_percent: APP_STATE.subtotalDiscountPercent,
        subtotal_discount_amount: APP_STATE.subtotalDiscountAmount,
        order_date: '<?= date('Y-m-d') ?>',
        remarks: document.getElementById('cartRemarksInput').value || 'Booked via Mobile App',
        items: orderItems
    };

    try {
        const res = await fetch(APP_CONFIG.apiUrl + '?action=book_order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if (json.success) {
            showToast("Order placed successfully! Order #: " + json.data.order_number + " (" + (json.data.pricing_mode === 'mrp' ? 'MRP Price' : 'Wholesale') + ")");
            APP_STATE.cart = {};
            renderProducts();
            updateCartBar();
            closeCartModal();
            loadDailySummary();
        } else {
            showToast("Error: " + (json.message || "Failed to place order."));
        }
    } catch (err) {
        console.error(err);
        showToast("Unable to reach server.");
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Confirm & Place Order';
    }
}

// -----------------------------------------------------------------
// 5. DELIVERIES & RUN SHEET
// -----------------------------------------------------------------
async function loadDeliveryRuns() {
    try {
        const date = document.getElementById('deliveryDateFilter').value || '<?= date('Y-m-d') ?>';
        const res = await fetch(`${APP_CONFIG.apiUrl}?action=delivery_runs&salesman_id=${APP_STATE.activeSalesmanId}&run_date=${date}`);
        const json = await res.json();

        if (json.success) {
            APP_STATE.deliveryRuns = json.data;
            const select = document.getElementById('deliveryRunSelect');
            select.innerHTML = '<option value="">Select Delivery Run...</option>';

            const cashierSelect = document.getElementById('cashierRunSelect');
            if (cashierSelect) cashierSelect.innerHTML = '<option value="">Select Run...</option>';

            APP_STATE.deliveryRuns.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = `${r.run_number} (${r.total_bills} Shops • Rs. ${Number(r.total_amount).toLocaleString()})`;
                select.appendChild(opt);

                if (cashierSelect) {
                    const cOpt = opt.cloneNode(true);
                    cashierSelect.appendChild(cOpt);
                }
            });

            if (APP_STATE.deliveryRuns.length > 0) {
                select.value = APP_STATE.deliveryRuns[0].id;
                renderSelectedRunBills();

                // Home run card update
                const homeCard = document.getElementById('homeRunCard');
                if (homeCard) {
                    const topRun = APP_STATE.deliveryRuns[0];
                    document.getElementById('homeRunNumber').textContent = topRun.run_number;
                    document.getElementById('homeRunDetails').textContent = `${topRun.total_bills} Shops • Total: Rs. ${Number(topRun.total_amount).toLocaleString()}`;
                    homeCard.style.display = 'block';
                }
            }
        }
    } catch (err) {
        console.error(err);
    }
}

function renderSelectedRunBills() {
    const runId = parseInt(document.getElementById('deliveryRunSelect').value) || 0;
    const run = APP_STATE.deliveryRuns.find(r => r.id == runId);
    const container = document.getElementById('deliveryBillsContainer');
    const progCard = document.getElementById('runProgressCard');

    if (!run) {
        container.innerHTML = '<div class="text-center py-4 text-muted">Select a delivery run to view route stops.</div>';
        if (progCard) progCard.style.display = 'none';
        return;
    }

    if (progCard) {
        progCard.style.display = 'block';
        const total = run.bills.length;
        const delivered = run.delivered_count;
        const pct = total > 0 ? Math.round((delivered / total) * 100) : 0;
        document.getElementById('runProgressText').textContent = `${delivered} / ${total} Completed (${pct}%)`;
        document.getElementById('runProgressBar').style.width = pct + '%';
        document.getElementById('runTotalAmount').textContent = 'Rs. ' + Number(run.total_amount).toLocaleString();
        document.getElementById('runTotalCash').textContent = 'Rs. ' + Number(run.total_cash_collected).toLocaleString();
        document.getElementById('runTotalReturn').textContent = 'Rs. ' + Number(run.total_returns_value).toLocaleString();
    }

    if (run.bills.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted">No invoices or bills found in this delivery run.</div>';
        return;
    }

    container.innerHTML = run.bills.map((b, idx) => {
        let statusBadge = '<span class="badge bg-secondary">Pending</span>';
        let cardClass = 'stop-card';
        if (b.delivery_status === 'delivered') {
            statusBadge = '<span class="badge bg-success">Delivered</span>';
            cardClass += ' delivered';
        } else if (b.delivery_status === 'partial') {
            statusBadge = '<span class="badge bg-primary">Partial</span>';
            cardClass += ' partial';
        } else if (b.delivery_status === 'refused') {
            statusBadge = '<span class="badge bg-danger">Refused</span>';
            cardClass += ' refused';
        }

        return `
            <div class="${cardClass}">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <span class="badge bg-light text-dark border me-1">#${idx + 1}</span>
                        <strong class="fs-6">${b.shop_name}</strong>
                        <div class="text-muted small">${b.area_name || ''}</div>
                    </div>
                    <div>${statusBadge}</div>
                </div>

                <div class="row g-1 my-2 py-1 border-top border-bottom text-center" style="font-size: 11px;">
                    <div class="col-4">
                        <div class="text-muted">Bill Amount</div>
                        <strong class="text-primary">Rs. ${Number(b.bill_amount).toLocaleString()}</strong>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Prev Balance</div>
                        <strong class="text-danger">Rs. ${Number(b.customer_current_balance).toLocaleString()}</strong>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Total Due</div>
                        <strong class="text-dark">Rs. ${Number(b.total_due).toLocaleString()}</strong>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div style="font-size: 12px;">
                        ${b.cash_collected > 0 ? `<span class="badge bg-success me-1">Cash: Rs. ${Number(b.cash_collected).toLocaleString()}</span>` : ''}
                        ${b.return_value > 0 ? `<span class="badge bg-warning text-dark me-1">Ret: Rs. ${Number(b.return_value).toLocaleString()}</span>` : ''}
                    </div>
                    <div class="d-flex gap-1">
                        ${b.customer_phone ? `<a href="tel:${b.customer_phone}" class="btn btn-sm btn-outline-secondary py-1 px-2"><i class="bi bi-telephone"></i></a>` : ''}
                        <button class="btn btn-sm btn-primary py-1 px-3 fw-bold" onclick="openDeliveryEntryModal(${b.run_bill_id})">
                            <i class="bi bi-pencil-square me-1"></i> Record
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// -----------------------------------------------------------------
// 6. RECORD DELIVERY & CASH COLLECTION MODAL
// -----------------------------------------------------------------
let ACTIVE_BILL = null;

function openDeliveryEntryModal(runBillId) {
    let foundBill = null;
    for (const r of APP_STATE.deliveryRuns) {
        const b = r.bills.find(item => item.run_bill_id == runBillId);
        if (b) { foundBill = b; break; }
    }

    if (!foundBill) return;
    ACTIVE_BILL = foundBill;

    document.getElementById('entryRunBillId').value = foundBill.run_bill_id;
    document.getElementById('entryInvoiceId').value = foundBill.invoice_id || '';
    document.getElementById('entryCustomerId').value = foundBill.customer_id || '';

    document.getElementById('entryShopName').textContent = foundBill.shop_name;
    document.getElementById('entryBillNo').textContent = foundBill.invoice_number || 'N/A';
    document.getElementById('entryBillAmount').textContent = 'Rs. ' + Number(foundBill.bill_amount).toLocaleString();
    document.getElementById('entryPrevBal').textContent = 'Rs. ' + Number(foundBill.customer_current_balance).toLocaleString();
    document.getElementById('entryTotalDue').textContent = 'Rs. ' + Number(foundBill.total_due).toLocaleString();

    document.getElementById('entryCashInput').value = foundBill.cash_collected || foundBill.bill_amount;
    document.getElementById('entryReturnInput').value = foundBill.return_value || 0;
    document.getElementById('entryStatusSelect').value = foundBill.delivery_status !== 'pending' ? foundBill.delivery_status : 'delivered';
    document.getElementById('entryRemarksInput').value = foundBill.remarks || '';

    calculateRemainingCredit();
    document.getElementById('modalDeliveryEntrySheet').classList.add('show');
}

function closeDeliveryEntryModal() {
    document.getElementById('modalDeliveryEntrySheet').classList.remove('show');
}

function calculateRemainingCredit() {
    if (!ACTIVE_BILL) return;
    const billAmt = parseFloat(ACTIVE_BILL.bill_amount) || 0;
    const cash = parseFloat(document.getElementById('entryCashInput').value) || 0;
    const ret  = parseFloat(document.getElementById('entryReturnInput').value) || 0;

    const remaining = Math.max(0, billAmt - (cash + ret));
    document.getElementById('entryCreditInput').value = remaining.toFixed(2);
}

async function saveDeliveryBillEntry() {
    const runBillId = parseInt(document.getElementById('entryRunBillId').value);
    const cash = parseFloat(document.getElementById('entryCashInput').value) || 0;
    const ret  = parseFloat(document.getElementById('entryReturnInput').value) || 0;
    const cred = parseFloat(document.getElementById('entryCreditInput').value) || 0;
    const status = document.getElementById('entryStatusSelect').value;
    const remarks = document.getElementById('entryRemarksInput').value;

    const payload = {
        run_bill_id: runBillId,
        cash_collected: cash,
        credit_given: cred,
        return_value: ret,
        delivery_status: status,
        remarks: remarks
    };

    try {
        const res = await fetch(APP_CONFIG.apiUrl + '?action=save_delivery_entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if (json.success) {
            showToast("Delivery & collection saved successfully!");
            closeDeliveryEntryModal();
            await loadDeliveryRuns();
            await loadDailySummary();
        } else {
            showToast("Error: " + json.message);
        }
    } catch (err) {
        console.error(err);
        showToast("Server connection error.");
    }
}

// -----------------------------------------------------------------
// 7. FIELD RETURN ENTRY
// -----------------------------------------------------------------
function openBillReturnPicker() {
    openNewReturnModal(ACTIVE_BILL);
}

function openNewReturnModal(preBill = null) {
    const select = document.getElementById('retProductSelect');
    select.innerHTML = '<option value="">Select Product...</option>';
    APP_STATE.products.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = `${p.product_name} (Rs. ${p.piece_rate}/pc • Rs. ${p.carton_rate}/ctn)`;
        select.appendChild(opt);
    });

    document.getElementById('retCartonQty').value = 0;
    document.getElementById('retPackQty').value = 0;
    document.getElementById('retPcsQty').value = 0;
    document.getElementById('retItemAmount').value = '0.00';
    document.getElementById('modalReturnPickerSheet').classList.add('show');
}

function closeReturnPickerModal() {
    document.getElementById('modalReturnPickerSheet').classList.remove('show');
}

function onReturnProductChanged() {
    calculateReturnItemTotal();
}

function calculateReturnItemTotal() {
    const pid = parseInt(document.getElementById('retProductSelect').value) || 0;
    const p = APP_STATE.products.find(item => item.id == pid);
    if (!p) {
        document.getElementById('retItemAmount').value = '0.00';
        return;
    }

    const ctn = parseFloat(document.getElementById('retCartonQty').value) || 0;
    const pck = parseFloat(document.getElementById('retPackQty').value) || 0;
    const pcs = parseFloat(document.getElementById('retPcsQty').value) || 0;

    const basePieces = (ctn * p.pcs_per_ctn) + (pck * p.pcs_per_pack) + pcs;
    const rate = p.piece_rate > 0 ? p.piece_rate : (p.carton_rate / p.pcs_per_ctn);
    const amount = Math.round(basePieces * rate);

    document.getElementById('retItemAmount').value = amount.toFixed(2);
}

async function commitReturnItem() {
    const pid = parseInt(document.getElementById('retProductSelect').value) || 0;
    if (!pid) {
        showToast("Please select a product.");
        return;
    }

    const ctn = parseFloat(document.getElementById('retCartonQty').value) || 0;
    const pck = parseFloat(document.getElementById('retPackQty').value) || 0;
    const pcs = parseFloat(document.getElementById('retPcsQty').value) || 0;

    if (ctn === 0 && pck === 0 && pcs === 0) {
        showToast("Please enter quantity (Cartons or Pieces).");
        return;
    }

    const p = APP_STATE.products.find(item => item.id == pid);
    const rate = p.piece_rate > 0 ? p.piece_rate : (p.carton_rate / p.pcs_per_ctn);
    const condition = document.getElementById('retCondition').value;
    const reason = document.getElementById('retReason').value || 'Customer Return';

    // If active bill exists, save directly to server
    if (ACTIVE_BILL) {
        const payload = {
            run_bill_id: ACTIVE_BILL.run_bill_id,
            items: [{
                product_id: pid,
                carton_qty: ctn,
                pack_qty: pck,
                pcs_qty: pcs,
                rate: rate,
                condition_type: condition,
                reason: reason
            }]
        };

        try {
            const res = await fetch(APP_CONFIG.apiUrl + '?action=save_field_return', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            if (json.success) {
                showToast("Field return saved successfully!");
                document.getElementById('entryReturnInput').value = json.data.new_return_value;
                calculateRemainingCredit();
                closeReturnPickerModal();
                await loadDeliveryRuns();
                await loadFieldReturns();
            } else {
                showToast("Error: " + json.message);
            }
        } catch (err) {
            console.error(err);
        }
    } else {
        showToast("Please open a delivery bill first.");
    }
}

async function loadFieldReturns() {
    try {
        const res = await fetch(`${APP_CONFIG.apiUrl}?action=get_field_returns&salesman_id=${APP_STATE.activeSalesmanId}`);
        const json = await res.json();
        if (json.success) {
            APP_STATE.fieldReturns = json.data;
            renderFieldReturnsList();
        }
    } catch (err) {
        console.error(err);
    }
}

function renderFieldReturnsList() {
    const container = document.getElementById('fieldReturnsListContainer');
    if (!APP_STATE.fieldReturns || APP_STATE.fieldReturns.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted">No field returns recorded for today.</div>';
        return;
    }

    container.innerHTML = APP_STATE.fieldReturns.map(fr => {
        const isVerified = parseInt(fr.warehouse_verified) === 1;
        const statusBadge = isVerified 
            ? '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Warehouse Verified</span>'
            : '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1">Pending Verification</span>';

        return `
            <div class="app-card mb-2">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <strong class="fs-6">${fr.product_name}</strong>
                        <div class="small text-muted">${fr.shop_name} • Bill: ${fr.invoice_number || 'N/A'}</div>
                    </div>
                    <div>${statusBadge}</div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top" style="font-size: 12px;">
                    <div>Qty: <strong>${fr.carton_qty} Ctn, ${fr.pcs_qty} Pcs</strong> (${fr.condition_type})</div>
                    <div class="fw-bold text-warning fs-6">Rs. ${Number(fr.amount).toLocaleString()}</div>
                </div>
            </div>
        `;
    }).join('');
}

// -----------------------------------------------------------------
// 8. WAREHOUSE & CASHIER VERIFICATION
// -----------------------------------------------------------------
function switchVerifySubTab(subTab) {
    if (subTab === 'wh') {
        document.getElementById('subtab-wh').style.display = 'block';
        document.getElementById('subtab-cashier').style.display = 'none';
        document.getElementById('btnTabWh').classList.add('active');
        document.getElementById('btnTabCashier').classList.remove('active');
    } else {
        document.getElementById('subtab-wh').style.display = 'none';
        document.getElementById('subtab-cashier').style.display = 'block';
        document.getElementById('btnTabWh').classList.remove('active');
        document.getElementById('btnTabCashier').classList.add('active');
    }
}

async function loadVerifyData() {
    await loadWarehousePendingReturns();
}

async function loadWarehousePendingReturns() {
    try {
        const res = await fetch(`${APP_CONFIG.apiUrl}?action=get_field_returns&verified=0`);
        const json = await res.json();
        const container = document.getElementById('warehouseVerifyList');
        const batchBtn = document.getElementById('btnBatchVerifyContainer');

        if (json.success && json.data.length > 0) {
            batchBtn.style.display = 'block';
            container.innerHTML = json.data.map(item => `
                <div class="p-2 border rounded-3 mb-2 d-flex justify-content-between align-items-center bg-white">
                    <div class="d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input wh-verify-chk" value="${item.id}" checked>
                        <div>
                            <strong>${item.product_name}</strong>
                            <div class="small text-muted">${item.salesman_name} • Shop: ${item.shop_name}</div>
                            <div class="small text-primary">Qty: ${item.carton_qty} Ctn, ${item.pcs_qty} Pcs (${item.condition_type})</div>
                        </div>
                    </div>
                    <div class="fw-bold text-warning">
                        Rs. ${Number(item.amount).toLocaleString()}
                    </div>
                </div>
            `).join('');
        } else {
            batchBtn.style.display = 'none';
            container.innerHTML = '<div class="text-center py-4 text-muted">All returned goods have been verified. None pending.</div>';
        }
    } catch (err) {
        console.error(err);
    }
}

async function verifyWarehouseStockBatch() {
    const checked = Array.from(document.querySelectorAll('.wh-verify-chk:checked')).map(el => parseInt(el.value));
    if (checked.length === 0) {
        showToast("Please select at least one item.");
        return;
    }

    if (!confirm("Are you sure you want to confirm receipt of these goods into warehouse inventory? Stock balances will be updated immediately.")) {
        return;
    }

    try {
        const res = await fetch(APP_CONFIG.apiUrl + '?action=verify_warehouse_return', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_ids: checked })
        });
        const json = await res.json();
        if (json.success) {
            showToast("Warehouse stock verification completed!");
            await loadWarehousePendingReturns();
            await loadDailySummary();
        } else {
            showToast("Error: " + json.message);
        }
    } catch (err) {
        console.error(err);
    }
}

function renderCashierSummary() {
    const runId = parseInt(document.getElementById('cashierRunSelect').value) || 0;
    const run = APP_STATE.deliveryRuns.find(r => r.id == runId);
    const summaryCard = document.getElementById('cashierSummaryCard');
    const actionBox = document.getElementById('cashierActionBox');

    if (!run) {
        summaryCard.style.display = 'none';
        actionBox.style.display = 'none';
        return;
    }

    const totBills = parseFloat(run.total_amount) || 0;
    const totCash  = parseFloat(run.total_cash_collected) || 0;
    const totCred  = parseFloat(run.total_credit_given) || 0;
    const totRet   = parseFloat(run.total_returns_value) || 0;
    const diff     = (totBills - (totCash + totCred + totRet)).toFixed(2);

    document.getElementById('cTotBills').textContent = 'Rs. ' + totBills.toLocaleString();
    document.getElementById('cTotCash').textContent = 'Rs. ' + totCash.toLocaleString();
    document.getElementById('cTotCredit').textContent = 'Rs. ' + totCred.toLocaleString();
    document.getElementById('cTotReturn').textContent = 'Rs. ' + totRet.toLocaleString();

    const diffEl = document.getElementById('cDiff');
    diffEl.textContent = 'Rs. ' + diff;
    diffEl.className = Math.abs(parseFloat(diff)) < 1 ? 'text-success fw-bold' : 'text-danger fw-bold';

    document.getElementById('cashierPhysicalCash').value = totCash;
    summaryCard.style.display = 'block';
    actionBox.style.display = 'block';
}

async function submitCashierHandover() {
    const runId = parseInt(document.getElementById('cashierRunSelect').value) || 0;
    if (!runId) return;

    const cashReceived = parseFloat(document.getElementById('cashierPhysicalCash').value) || 0;
    const remarks = document.getElementById('cashierRemarks').value || 'Cashier Handover Verified';

    if (!confirm("Are you sure you have counted this physical cash and want to close this route?")) {
        return;
    }

    try {
        const res = await fetch(APP_CONFIG.apiUrl + '?action=verify_cashier_handover', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                delivery_run_id: runId,
                cash_received: cashReceived,
                remarks: remarks
            })
        });
        const json = await res.json();
        if (json.success) {
            showToast("Cashier verification completed and route closed!");
            await loadDeliveryRuns();
            await loadDailySummary();
            renderCashierSummary();
        } else {
            showToast("Error: " + json.message);
        }
    } catch (err) {
        console.error(err);
    }
}
</script>
</body>
</html>
