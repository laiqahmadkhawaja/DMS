<?php
/**
 * DMS - Distribution Management System
 * Sidebar Navigation Layout
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

if (defined('DMS_SIDEBAR_RENDERED')) {
    return;
}
define('DMS_SIDEBAR_RENDERED', true);

$activeMenu = get_active_menu();
?>
<aside class="dms-sidebar">
    <div class="dms-sidebar-brand">
        <i class="bi bi-box-seam-fill text-warning fs-5"></i>
        <div>
            <div><?= e(CLIENT_NAME) ?></div>
            <small>Distribution Management</small>
        </div>
    </div>
    
    <ul class="dms-sidebar-menu">
        <li class="dms-menu-header">Distribution Cycle</li>
        
        <li>
            <a href="<?= APP_URL ?>/dashboard.php" class="dms-menu-item <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>
        
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
                <i class="bi bi-truck-flatbed"></i> Warehouse Loading
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
                <i class="bi bi-geo-alt"></i> Delivery Run Sheets
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
        
        <li class="dms-menu-header">Inventory & Purchase</li>
        
        <?php if (has_permission('inventory_view')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/inventory/index.php" class="dms-menu-item <?= $activeMenu === 'inventory' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i> Stock Overview
            </a>
        </li>
        <li>
            <a href="<?= APP_URL ?>/modules/inventory/ledger.php" class="dms-menu-item <?= $activeMenu === 'stock_ledger' ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i> Stock Ledger
            </a>
        </li>
        <?php endif; ?>
        
        <?php if (has_permission('purchases_view')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/purchases/index.php" class="dms-menu-item <?= $activeMenu === 'purchases' ? 'active' : '' ?>">
                <i class="bi bi-bag-check"></i> Purchases & GRN
            </a>
        </li>
        <?php endif; ?>
        
        <li class="dms-menu-header">Financials & Ledgers</li>
        
        <?php if (has_permission('customers_statement')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/customers/statement.php" class="dms-menu-item <?= $activeMenu === 'cust_ledger' ? 'active' : '' ?>">
                <i class="bi bi-person-lines-fill"></i> Customer Ledgers
            </a>
        </li>
        <?php endif; ?>
        
        <li>
            <a href="<?= APP_URL ?>/modules/salesmen/ledger.php" class="dms-menu-item <?= $activeMenu === 'sm_ledger' ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i> Salesman Cash Ledger
            </a>
        </li>
        
        <?php if (has_permission('payments_manage')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/accounts/customer_payments.php" class="dms-menu-item <?= $activeMenu === 'customer_payments' ? 'active' : '' ?>">
                <i class="bi bi-cash-stack"></i> Customer Receipts
            </a>
        </li>
        <li>
            <a href="<?= APP_URL ?>/modules/accounts/supplier_payments.php" class="dms-menu-item <?= $activeMenu === 'supplier_payments' ? 'active' : '' ?>">
                <i class="bi bi-credit-card"></i> Supplier Payments
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
        
        <?php if (has_permission('accounts_view')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/accounts/index.php" class="dms-menu-item <?= $activeMenu === 'accounts' ? 'active' : '' ?>">
                <i class="bi bi-bank"></i> Chart of Accounts
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
                <i class="bi bi-journal-check"></i> Journal Vouchers
            </a>
        </li>
        <?php endif; ?>
        
        <li class="dms-menu-header">Reports & Exports</li>
        
        <?php if (has_permission('reports_daily')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/reports/daily_sales.php" class="dms-menu-item <?= $activeMenu === 'rpt_daily' ? 'active' : '' ?>">
                <i class="bi bi-table"></i> Daily Sales Register
            </a>
        </li>
        <li>
            <a href="<?= APP_URL ?>/modules/reports/customer_aging.php" class="dms-menu-item <?= $activeMenu === 'rpt_aging' ? 'active' : '' ?>">
                <i class="bi bi-hourglass-split"></i> Parties Credit & Aging
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
        <li>
            <a href="<?= APP_URL ?>/modules/reports/index.php" class="dms-menu-item <?= $activeMenu === 'reports' ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i> Reports Center Hub
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
        
        <li class="dms-menu-header">Master Files</li>
        
        <?php if (has_permission('customers_view')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/customers/index.php" class="dms-menu-item <?= $activeMenu === 'customers' ? 'active' : '' ?>">
                <i class="bi bi-shop"></i> Customers (Retailers)
            </a>
        </li>
        <?php endif; ?>
        
        <?php if (has_permission('products_view')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/products/index.php" class="dms-menu-item <?= $activeMenu === 'products' ? 'active' : '' ?>">
                <i class="bi bi-tag"></i> Products & Pricing
            </a>
        </li>
        <?php endif; ?>
        
        <?php if (has_permission('masters_manage')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/categories/index.php" class="dms-menu-item <?= $activeMenu === 'categories' ? 'active' : '' ?>">
                <i class="bi bi-tags"></i> Categories
            </a>
        </li>
        <li>
            <a href="<?= APP_URL ?>/modules/brands/index.php" class="dms-menu-item <?= $activeMenu === 'brands' ? 'active' : '' ?>">
                <i class="bi bi-bookmark-star"></i> Brands
            </a>
        </li>
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
        <li>
            <a href="<?= APP_URL ?>/modules/suppliers/index.php" class="dms-menu-item <?= $activeMenu === 'suppliers' ? 'active' : '' ?>">
                <i class="bi bi-building"></i> Suppliers
            </a>
        </li>
        <li>
            <a href="<?= APP_URL ?>/modules/warehouses/index.php" class="dms-menu-item <?= $activeMenu === 'warehouses' ? 'active' : '' ?>">
                <i class="bi bi-building-gear"></i> Warehouses
            </a>
        </li>
        <?php endif; ?>
        
        <?php if (has_permission('settings_manage') || has_permission('users_manage')): ?>
        <li class="dms-menu-header">Settings & Control</li>
        <?php if (has_permission('settings_manage')): ?>
        <li>
            <a href="<?= APP_URL ?>/modules/settings/index.php" class="dms-menu-item <?= $activeMenu === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> System Settings
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
        <?php endif; ?>
    </ul>
</aside>
