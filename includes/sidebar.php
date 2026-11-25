<?php
/**
 * DMS - Distribution Management System
 * Sidebar Navigation Layout with Collapsible Domain Sections
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

if (defined('DMS_SIDEBAR_RENDERED')) {
    return;
}
define('DMS_SIDEBAR_RENDERED', true);

$activeMenu = get_active_menu();

// Section Active Determination
$isSalesActive      = in_array($activeMenu, ['orders', 'loading', 'invoices', 'delivery', 'settlements', 'returns']) || $activeMenu === 'dashboard';
$isCustomersActive  = in_array($activeMenu, ['customers', 'cust_ledger', 'customer_payments', 'rpt_aging']);
$isPurchasesActive  = in_array($activeMenu, ['suppliers', 'purchases', 'goods_receiving', 'supplier_payments', 'supplier_ledger']);
$isInventoryActive  = in_array($activeMenu, ['inventory', 'stock_ledger', 'products', 'products_import', 'categories', 'brands', 'warehouses', 'bulk_brands', 'bulk_categories', 'import_stock', 'bulk_prices']);
$isAccountsActive   = in_array($activeMenu, ['accounts', 'manage_accounts', 'general_ledger', 'journal_entries', 'expenses', 'sm_ledger', 'partners', 'partner_drawings', 'partner_settle', 'partner_ledger']);
$isOperationsActive = in_array($activeMenu, ['areas', 'bookers', 'salesmen']);
$isReportsActive    = in_array($activeMenu, ['reports', 'rpt_closing', 'rpt_daily', 'rpt_company', 'rpt_profitability', 'rpt_tb', 'rpt_pl', 'rpt_bs', 'excel']);
$isSettingsActive   = in_array($activeMenu, ['settings', 'users', 'audit']);
?>
<aside class="dms-sidebar" id="dmsSidebar">
    <div class="dms-sidebar-brand d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2 overflow-hidden text-nowrap">
            <i class="bi bi-box-seam-fill text-warning fs-5 flex-shrink-0"></i>
            <div class="dms-brand-text">
                <div class="text-truncate fw-bold"><?= e(CLIENT_NAME) ?></div>
                <small class="text-truncate d-block text-secondary">Distribution Management</small>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-link text-white-50 p-0 dms-sidebar-collapse-btn d-none d-md-inline-flex" id="sidebarCollapseBtn" title="Collapse Sidebar (Ctrl+B)">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>
    
    <ul class="dms-sidebar-menu">
        <!-- 1. DASHBOARD -->
        <li>
            <a href="<?= APP_URL ?>/dashboard.php" class="dms-menu-item <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2 text-info"></i> Dashboard
            </a>
        </li>

        <!-- 2. SALES & DISTRIBUTION CYCLE -->
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isSalesActive && $activeMenu !== 'dashboard' ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-sales" aria-expanded="<?= $isSalesActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-cart4 text-primary me-2"></i> Sales & Distribution</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isSalesActive ? 'show' : '' ?>" id="sec-sales">
                <ul class="dms-submenu">
                    <?php if (has_permission('orders_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/orders/index.php" class="dms-menu-item <?= $activeMenu === 'orders' ? 'active' : '' ?>">
                            <i class="bi bi-card-checklist"></i> Order Booking
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('loading_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/loading/index.php" class="dms-menu-item <?= $activeMenu === 'loading' ? 'active' : '' ?>">
                            <i class="bi bi-truck-flatbed"></i> Load Form (لوڈ فارم)
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('invoices_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/invoices/index.php" class="dms-menu-item <?= $activeMenu === 'invoices' ? 'active' : '' ?>">
                            <i class="bi bi-receipt"></i> Sales Invoices
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('delivery_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/delivery/index.php" class="dms-menu-item <?= $activeMenu === 'delivery' ? 'active' : '' ?>">
                            <i class="bi bi-journal-check"></i> Credit Sheet (کریڈٹ شیٹ)
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('settlements_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/settlements/index.php" class="dms-menu-item <?= $activeMenu === 'settlements' ? 'active' : '' ?>">
                            <i class="bi bi-cash-stack"></i> Route Settlement
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('returns_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/returns/index.php" class="dms-menu-item <?= $activeMenu === 'returns' ? 'active' : '' ?>">
                            <i class="bi bi-arrow-return-left"></i> Sales Returns
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>

        <!-- 3. CUSTOMERS & RETAILERS -->
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isCustomersActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-customers" aria-expanded="<?= $isCustomersActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-shop text-success me-2"></i> Customers & Retailers</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isCustomersActive ? 'show' : '' ?>" id="sec-customers">
                <ul class="dms-submenu">
                    <?php if (has_permission('customers_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/customers/index.php" class="dms-menu-item <?= $activeMenu === 'customers' ? 'active' : '' ?>">
                            <i class="bi bi-person-lines-fill"></i> Customer Directory
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('customers_statement')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/customers/statement.php" class="dms-menu-item <?= $activeMenu === 'cust_ledger' ? 'active' : '' ?>">
                            <i class="bi bi-journal-text"></i> Customer Ledgers / Khata
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('payments_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/accounts/customer_payments.php" class="dms-menu-item <?= $activeMenu === 'customer_payments' ? 'active' : '' ?>">
                            <i class="bi bi-cash-coin"></i> Customer Receipts
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('reports_daily') || has_permission('customers_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/customer_aging.php" class="dms-menu-item <?= $activeMenu === 'rpt_aging' ? 'active' : '' ?>">
                            <i class="bi bi-hourglass-split"></i> Parties Credit & Aging
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>

        <!-- 4. PURCHASING & SUPPLIERS -->
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isPurchasesActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-purchases" aria-expanded="<?= $isPurchasesActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-truck text-warning me-2"></i> Purchasing & Suppliers</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isPurchasesActive ? 'show' : '' ?>" id="sec-purchases">
                <ul class="dms-submenu">
                    <?php if (has_permission('suppliers_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/suppliers/index.php" class="dms-menu-item <?= $activeMenu === 'suppliers' ? 'active' : '' ?>">
                            <i class="bi bi-building"></i> Suppliers Directory
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('purchases_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/purchases/index.php" class="dms-menu-item <?= $activeMenu === 'purchases' ? 'active' : '' ?>">
                            <i class="bi bi-bag-check"></i> Purchase Orders
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('purchases_receive') || has_permission('purchases_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/goods_receiving/index.php" class="dms-menu-item <?= $activeMenu === 'goods_receiving' ? 'active' : '' ?>">
                            <i class="bi bi-box-arrow-in-down"></i> Goods Receiving (GRN)
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('payments_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/accounts/supplier_payments.php" class="dms-menu-item <?= $activeMenu === 'supplier_payments' ? 'active' : '' ?>">
                            <i class="bi bi-credit-card"></i> Supplier Payments
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('suppliers_ledger')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/suppliers/ledger.php" class="dms-menu-item <?= $activeMenu === 'supplier_ledger' ? 'active' : '' ?>">
                            <i class="bi bi-journal-bookmark"></i> Supplier Ledgers / Khata
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>

        <!-- 5. INVENTORY & PRODUCTS -->
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isInventoryActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-inventory" aria-expanded="<?= $isInventoryActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-boxes text-info me-2"></i> Inventory & Products</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isInventoryActive ? 'show' : '' ?>" id="sec-inventory">
                <ul class="dms-submenu">
                    <?php if (has_permission('inventory_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/inventory/index.php" class="dms-menu-item <?= $activeMenu === 'inventory' ? 'active' : '' ?>">
                            <i class="bi bi-box-seam"></i> Stock Overview
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/inventory/ledger.php" class="dms-menu-item <?= $activeMenu === 'stock_ledger' ? 'active' : '' ?>">
                            <i class="bi bi-journal-check"></i> Stock Movement Ledger
                        </a>
                    </li>
                    <?php if (has_permission('inventory_adjust') || has_permission('excel_import')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/inventory/import_stock.php" class="dms-menu-item <?= $activeMenu === 'import_stock' ? 'active' : '' ?>">
                            <i class="bi bi-file-earmark-arrow-up text-success"></i> Import Opening Stock
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (has_permission('products_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/products/index.php" class="dms-menu-item <?= $activeMenu === 'products' ? 'active' : '' ?>">
                            <i class="bi bi-tag"></i> Products & Pricing
                        </a>
                    </li>
                    <?php if (has_permission('products_edit') || has_permission('masters_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/products/bulk_prices.php" class="dms-menu-item <?= $activeMenu === 'bulk_prices' ? 'active' : '' ?>">
                            <i class="bi bi-currency-dollar text-success"></i> Bulk Price Setting
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/products/import.php" class="dms-menu-item <?= $activeMenu === 'products_import' ? 'active' : '' ?>">
                            <i class="bi bi-file-earmark-excel text-success"></i> Import / Export Excel
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('masters_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/categories/index.php" class="dms-menu-item <?= $activeMenu === 'categories' ? 'active' : '' ?>">
                            <i class="bi bi-tags"></i> Product Categories
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/categories/bulk_update.php" class="dms-menu-item <?= $activeMenu === 'bulk_categories' ? 'active' : '' ?>">
                            <i class="bi bi-tags-fill text-info"></i> Bulk Category Updater
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/brands/index.php" class="dms-menu-item <?= $activeMenu === 'brands' ? 'active' : '' ?>">
                            <i class="bi bi-bookmark-star"></i> Brands
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/brands/bulk_update.php" class="dms-menu-item <?= $activeMenu === 'bulk_brands' ? 'active' : '' ?>">
                            <i class="bi bi-bookmark-star-fill text-warning"></i> Bulk Brand Updater
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/warehouses/index.php" class="dms-menu-item <?= $activeMenu === 'warehouses' ? 'active' : '' ?>">
                            <i class="bi bi-building-gear"></i> Warehouses
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>

        <!-- 6. ACCOUNTS & FINANCIALS -->
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isAccountsActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-accounts" aria-expanded="<?= $isAccountsActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-bank text-danger me-2"></i> Accounts & Finance</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isAccountsActive ? 'show' : '' ?>" id="sec-accounts">
                <ul class="dms-submenu">
                    <?php if (has_permission('accounts_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/accounts/manage_accounts.php" class="dms-menu-item <?= $activeMenu === 'manage_accounts' ? 'active' : '' ?>">
                            <i class="bi bi-wallet2 text-success"></i> Cash, Banks & Wallets
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/accounts/index.php" class="dms-menu-item <?= $activeMenu === 'accounts' ? 'active' : '' ?>">
                            <i class="bi bi-diagram-3"></i> Chart of Accounts
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/accounts/ledger.php" class="dms-menu-item <?= $activeMenu === 'general_ledger' ? 'active' : '' ?>">
                            <i class="bi bi-book"></i> General Ledger
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('journal_entries')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/accounts/journal_list.php" class="dms-menu-item <?= $activeMenu === 'journal_entries' ? 'active' : '' ?>">
                            <i class="bi bi-journal-arrow-down"></i> Journal Vouchers
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('expenses_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/expenses/index.php" class="dms-menu-item <?= $activeMenu === 'expenses' ? 'active' : '' ?>">
                            <i class="bi bi-wallet2"></i> Daily Expenses
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('partners_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/partners/index.php" class="dms-menu-item <?= in_array($activeMenu, ['partners', 'partner_drawings', 'partner_settle', 'partner_ledger']) ? 'active' : '' ?>">
                            <i class="bi bi-people-fill text-warning"></i> Partners & Khatas (پارٹنرز کھاتہ)
                        </a>
                    </li>
                    <?php endif; ?>

                    <li>
                        <a href="<?= APP_URL ?>/modules/salesmen/ledger.php" class="dms-menu-item <?= $activeMenu === 'sm_ledger' ? 'active' : '' ?>">
                            <i class="bi bi-person-badge"></i> Salesman Cash Ledger
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- 7. FIELD OPERATIONS & ROUTES -->
        <?php if (has_permission('masters_manage')): ?>
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isOperationsActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-operations" aria-expanded="<?= $isOperationsActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-pin-map text-primary me-2"></i> Field Operations</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isOperationsActive ? 'show' : '' ?>" id="sec-operations">
                <ul class="dms-submenu">
                    <li>
                        <a href="<?= APP_URL ?>/modules/areas/index.php" class="dms-menu-item <?= $activeMenu === 'areas' ? 'active' : '' ?>">
                            <i class="bi bi-map"></i> Areas / Routes
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/bookers/index.php" class="dms-menu-item <?= $activeMenu === 'bookers' ? 'active' : '' ?>">
                            <i class="bi bi-people"></i> Order Bookers
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/salesmen/index.php" class="dms-menu-item <?= $activeMenu === 'salesmen' ? 'active' : '' ?>">
                            <i class="bi bi-truck"></i> Delivery Salesmen
                        </a>
                    </li>
                </ul>
            </div>
        </li>
        <?php endif; ?>

        <!-- 8. REPORTS & EXPORTS -->
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isReportsActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-reports" aria-expanded="<?= $isReportsActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-graph-up-arrow text-info me-2"></i> Reports & Exports</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isReportsActive ? 'show' : '' ?>" id="sec-reports">
                <ul class="dms-submenu">
                    <?php if (has_permission('reports_daily')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/daily_closing.php" class="dms-menu-item <?= $activeMenu === 'rpt_closing' ? 'active' : '' ?>">
                            <i class="bi bi-journal-bookmark-fill text-warning"></i> Daily Closing (Roznamcha)
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/company_sales.php" class="dms-menu-item <?= $activeMenu === 'rpt_company' ? 'active' : '' ?>">
                            <i class="bi bi-building-check text-info"></i> Company Sales & Udhaar
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/product_profitability.php" class="dms-menu-item <?= $activeMenu === 'rpt_profitability' ? 'active' : '' ?>">
                            <i class="bi bi-trophy text-warning"></i> Top Profitable Products
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/index.php" class="dms-menu-item <?= $activeMenu === 'reports' ? 'active' : '' ?>">
                            <i class="bi bi-bar-chart-line"></i> Reports Center Hub
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/daily_sales.php" class="dms-menu-item <?= $activeMenu === 'rpt_daily' ? 'active' : '' ?>">
                            <i class="bi bi-table"></i> Daily Sales Register
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/trial_balance.php" class="dms-menu-item <?= $activeMenu === 'rpt_tb' ? 'active' : '' ?>">
                            <i class="bi bi-scale"></i> Trial Balance
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/profit_loss.php" class="dms-menu-item <?= $activeMenu === 'rpt_pl' ? 'active' : '' ?>">
                            <i class="bi bi-currency-dollar"></i> Profit & Loss (P&L)
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/reports/balance_sheet.php" class="dms-menu-item <?= $activeMenu === 'rpt_bs' ? 'active' : '' ?>">
                            <i class="bi bi-briefcase"></i> Balance Sheet
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('excel_import') || has_permission('excel_export')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/excel/index.php" class="dms-menu-item <?= $activeMenu === 'excel' ? 'active' : '' ?>">
                            <i class="bi bi-file-earmark-excel"></i> Excel Data Center
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>

        <!-- 9. SETTINGS & ADMINISTRATION -->
        <?php if (has_permission('settings_manage') || has_permission('users_manage') || has_permission('audit_view')): ?>
        <li class="dms-sidebar-section">
            <button type="button" class="dms-section-toggle <?= $isSettingsActive ? 'has-active' : '' ?>" 
                    data-bs-toggle="collapse" data-bs-target="#sec-settings" aria-expanded="<?= $isSettingsActive ? 'true' : 'false' ?>">
                <span><i class="bi bi-gear text-secondary me-2"></i> System Settings</span>
                <i class="bi bi-chevron-right toggle-chevron"></i>
            </button>
            <div class="collapse <?= $isSettingsActive ? 'show' : '' ?>" id="sec-settings">
                <ul class="dms-submenu">
                    <?php if (has_permission('settings_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/settings/index.php" class="dms-menu-item <?= $activeMenu === 'settings' ? 'active' : '' ?>">
                            <i class="bi bi-sliders"></i> System Settings
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('users_manage')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/users/index.php" class="dms-menu-item <?= $activeMenu === 'users' ? 'active' : '' ?>">
                            <i class="bi bi-shield-lock"></i> Users & Roles
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (has_permission('audit_view')): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/settings/audit.php" class="dms-menu-item <?= $activeMenu === 'audit' ? 'active' : '' ?>">
                            <i class="bi bi-clock-history"></i> Audit Trail
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
        <?php endif; ?>
    </ul>
</aside>
