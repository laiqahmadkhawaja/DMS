-- =====================================================================
-- DMS - Distribution Management System Seed Data
-- Default System Configuration, Roles, Permissions, Accounts & Settings
-- =====================================================================

USE `distribution_dms`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. ROLES
-- ---------------------------------------------------------------------

INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system`) VALUES
(1, 'Administrator', 'admin', 'Full system access with all administrative privileges', 1),
(2, 'Operations Manager', 'manager', 'Oversees order confirmations, loading, delivery, and settlements', 1),
(3, 'Office Operator', 'operator', 'Enters market orders, customer bills, and daily entries', 1),
(4, 'Order Booker', 'booker', 'Collects retailer orders from market routes', 1),
(5, 'Warehouse Storekeeper', 'storekeeper', 'Handles load sheet dispatch, receiving, and stock custody', 1),
(6, 'Delivery Salesman', 'salesman', 'Delivers customer orders and brings cash/returns for settlement', 1),
(7, 'Accountant', 'accountant', 'Manages cash flow, vouchers, customer credit, and ledgers', 1),
(8, 'Report Viewer', 'viewer', 'Read-only access to operational and management reports', 1)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- 2. PERMISSIONS
-- ---------------------------------------------------------------------

INSERT INTO `permissions` (`module`, `permission_code`, `permission_name`, `description`) VALUES
-- Customers
('customers', 'customers_view', 'View Customers', 'Access customer list, details, and balance'),
('customers', 'customers_create', 'Create Customer', 'Register new retail shops/customers'),
('customers', 'customers_edit', 'Edit Customer', 'Update customer profile and credit limit'),
('customers', 'customers_delete', 'Delete Customer', 'Soft delete customer records'),
('customers', 'customers_statement', 'View Customer Ledger', 'View customer debit/credit statement and aging'),

-- Suppliers
('suppliers', 'suppliers_view', 'View Suppliers', 'Access supplier list and balances'),
('suppliers', 'suppliers_create', 'Create Supplier', 'Register new wholesale suppliers'),
('suppliers', 'suppliers_edit', 'Edit Supplier', 'Update supplier details'),
('suppliers', 'suppliers_delete', 'Delete Supplier', 'Soft delete supplier records'),
('suppliers', 'suppliers_ledger', 'View Supplier Ledger', 'View supplier ledger and payment history'),

-- Products & Masters
('products', 'products_view', 'View Products', 'Browse catalog, prices, and stock units'),
('products', 'products_create', 'Create Product', 'Add new products and packaging units'),
('products', 'products_edit', 'Edit Product', 'Update product details and default rates'),
('products', 'products_delete', 'Delete Product', 'Soft delete product records'),
('masters', 'masters_manage', 'Manage Masters', 'Manage Areas, Bookers, Salesmen, Categories, Units, Brands'),

-- Orders
('orders', 'orders_view', 'View Orders', 'View order booking list and status'),
('orders', 'orders_create', 'Create Order', 'Enter market booked orders'),
('orders', 'orders_edit', 'Edit Order', 'Modify draft orders'),
('orders', 'orders_confirm', 'Confirm Order', 'Confirm orders for warehouse loading'),
('orders', 'orders_cancel', 'Cancel Order', 'Cancel pending or draft orders'),

-- Loading / Issue
('loading', 'loading_view', 'View Loading Sheets', 'Browse van loading sheets and dispatch orders'),
('loading', 'loading_create', 'Create Loading Sheet', 'Consolidate orders and generate load sheet'),
('loading', 'loading_issue', 'Issue Stock / Dispatch', 'Confirm warehouse issue and deduct stock'),
('loading', 'loading_cancel', 'Cancel Loading Sheet', 'Cancel and reverse unissued or issued loads'),

-- Invoices
('invoices', 'invoices_view', 'View Sales Invoices', 'Access customer bills and invoices'),
('invoices', 'invoices_create', 'Create Sales Invoice', 'Generate direct bills or order invoices'),
('invoices', 'invoices_finalize', 'Finalize Invoice', 'Finalize bill and post financial/delivery status'),
('invoices', 'invoices_print', 'Print Customer Invoices', 'Single or multi-invoice printing on A4'),
('invoices', 'invoices_cancel', 'Cancel Invoice', 'Cancel finalized bills with audit reason'),

-- Delivery Runs
('delivery', 'delivery_view', 'View Delivery Runs', 'View assigned routes and delivery run sheets'),
('delivery', 'delivery_manage', 'Manage Delivery Runs', 'Create delivery run sheets and assign salesmen'),
('delivery', 'delivery_update_status', 'Update Delivery Status', 'Mark bills delivered, partial, or refused'),

-- Route Settlements
('settlements', 'settlements_view', 'View Settlements', 'View daily route reconciliation sheets'),
('settlements', 'settlements_create', 'Create Settlement', 'Enter cash, credit, return per bill'),
('settlements', 'settlements_reconcile', 'Reconcile Settlement', 'Verify zero difference or manager override'),
('settlements', 'settlements_finalize', 'Finalize Settlement', 'Post cash, customer credit, and return stock'),
('settlements', 'cash_submission', 'Manage Cash Submissions', 'Record physical cash handed over to office'),

-- Returns
('returns', 'returns_view', 'View Returns', 'View customer sales returns and conditions'),
('returns', 'returns_create', 'Create Sales Return', 'Record saleable, damaged, or expired returns'),
('returns', 'returns_process', 'Process Returns', 'Move stock to saleable or damaged locations'),

-- Inventory
('inventory', 'inventory_view', 'View Stock Balances', 'View real-time inventory and valuation'),
('inventory', 'inventory_ledger', 'View Stock Ledger', 'Audit trail of every stock in/out movement'),
('inventory', 'inventory_adjust', 'Stock Adjustment', 'Perform physical audit adjustments'),

-- Purchases
('purchases', 'purchases_view', 'View Purchases', 'View purchase orders and bills'),
('purchases', 'purchases_create', 'Create Purchase', 'Record supplier purchases'),
('purchases', 'purchases_receive', 'Receive Goods (GRN)', 'Inspect and receive ordered stock into warehouse'),

-- Expenses & Accounts
('accounts', 'accounts_view', 'View Chart of Accounts', 'View account balances and ledger'),
('accounts', 'expenses_manage', 'Manage Expenses', 'Record daily operating expenses and vouchers'),
('accounts', 'journal_entries', 'Journal Entries', 'Post manual vouchers and view transaction journals'),
('accounts', 'payments_manage', 'Manage Payments', 'Record customer receipts and supplier payments'),

-- Reports & Excel
('reports', 'reports_daily', 'Daily Reports', 'View daily sales, recovery, loading, settlement registers'),
('reports', 'reports_management', 'Management Reports', 'View monthly, yearly, product, booker, salesman performance'),
('reports', 'reports_financial', 'Financial Reports', 'View Trial Balance, P&L, Balance Sheet, Ledger'),
('excel', 'excel_import', 'Excel Data Import', 'Import master files and opening balances from XLSX'),
('excel', 'excel_export', 'Excel Data Export', 'Export real transaction registers and reports to XLSX'),

-- Administration
('settings', 'settings_manage', 'Manage Settings', 'Update business profile, invoice print templates, numbering rules'),
('users', 'users_manage', 'Manage Users & Permissions', 'Create users, assign roles and configure access rights'),
('audit', 'audit_view', 'View Audit Logs', 'Inspect system security and transaction audit logs')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- 3. ROLE-PERMISSION MAPPINGS (Admin gets all permissions)
-- ---------------------------------------------------------------------

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Manager Permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `module` IN (
  'customers', 'suppliers', 'products', 'masters', 'orders', 'loading', 
  'invoices', 'delivery', 'settlements', 'returns', 'inventory', 'purchases', 
  'accounts', 'reports', 'excel'
)
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Office Operator Permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `permission_code` IN (
  'customers_view', 'customers_create', 'customers_edit', 'customers_statement',
  'products_view', 'orders_view', 'orders_create', 'orders_edit',
  'invoices_view', 'invoices_create', 'invoices_print',
  'settlements_view', 'settlements_create',
  'reports_daily', 'excel_export'
)
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Storekeeper Permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 5, `id` FROM `permissions` WHERE `permission_code` IN (
  'products_view', 'loading_view', 'loading_issue',
  'inventory_view', 'inventory_ledger', 'purchases_view', 'purchases_receive',
  'returns_view', 'returns_process'
)
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Delivery Salesman Permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 6, `id` FROM `permissions` WHERE `permission_code` IN (
  'delivery_view', 'delivery_update_status', 'invoices_view', 'invoices_print'
)
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Accountant Permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 7, `id` FROM `permissions` WHERE `module` IN (
  'accounts', 'settlements', 'reports', 'excel'
) OR `permission_code` IN ('customers_statement', 'suppliers_ledger')
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- Report Viewer Permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 8, `id` FROM `permissions` WHERE `permission_code` LIKE '%_view' OR `module` = 'reports'
ON DUPLICATE KEY UPDATE `permission_id` = `permission_id`;

-- ---------------------------------------------------------------------
-- 4. DEFAULT USERS
-- Password for admin: admin123 (hashed with bcrypt)
-- ---------------------------------------------------------------------

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `phone`, `status`) VALUES
(1, 'admin', 'admin@muzzam.pk', '$2y$10$/ZAdAND2ZCmJoD7qWXdNs.q58gYcyzzzvFQ000w0qPWE5Ean8il/6', 'System Administrator', '03000673276', 'active'),
(2, 'manager', 'manager@muzzam.pk', '$2y$10$/ZAdAND2ZCmJoD7qWXdNs.q58gYcyzzzvFQ000w0qPWE5Ean8il/6', 'Operations Manager', '03001234567', 'active'),
(3, 'operator', 'operator@muzzam.pk', '$2y$10$/ZAdAND2ZCmJoD7qWXdNs.q58gYcyzzzvFQ000w0qPWE5Ean8il/6', 'Office Operator', '03007654321', 'active')
ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`), `status` = VALUES(`status`);

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 1),
(2, 2),
(3, 3)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- ---------------------------------------------------------------------
-- 5. BUSINESS SETTINGS
-- ---------------------------------------------------------------------

INSERT INTO `settings` (`setting_group`, `setting_key`, `setting_value`, `data_type`, `description`) VALUES
('general', 'distributor_name', 'Muzzam Enterprises', 'string', 'Official trade/distributor business name'),
('general', 'distributor_phone', '03000673276', 'string', 'Primary contact phone number'),
('general', 'distributor_address', 'Jandiala Bhagwala G.T. Road Gujranwala', 'string', 'Head office and warehouse location'),
('general', 'city', 'Gujranwala', 'string', 'Operating headquarters city'),
('general', 'currency_symbol', 'Rs.', 'string', 'Display currency symbol'),
('general', 'date_format', 'd-m-Y', 'string', 'Standard display date format'),
('numbering', 'order_prefix', 'ORD', 'string', 'Prefix for market booking orders'),
('numbering', 'invoice_prefix', 'INV', 'string', 'Prefix for sales bills and invoices'),
('numbering', 'load_prefix', 'LOAD', 'string', 'Prefix for warehouse load sheets'),
('numbering', 'run_prefix', 'RUN', 'string', 'Prefix for delivery run sheets'),
('numbering', 'settlement_prefix', 'SET', 'string', 'Prefix for route settlement vouchers'),
('numbering', 'grn_prefix', 'GRN', 'string', 'Prefix for goods receipt notes'),
('numbering', 'purchase_prefix', 'PUR', 'string', 'Prefix for supplier purchase orders'),
('numbering', 'return_prefix', 'RET', 'string', 'Prefix for sales returns'),
('print', 'invoice_paper_size', 'A4', 'string', 'Default paper size for invoices'),
('print', 'multi_invoice_fit_mode', 'auto_pack', 'string', 'Algorithm for packing multiple bills on A4 page'),
('print', 'show_signature_boxes', '1', 'boolean', 'Print receiver and salesman signature lines on bills'),
('distribution', 'route_assignment_mode', 'realtime', 'string', 'Route assignment policy: realtime allows dynamic area creation on all forms; fixed enforces strict salesman route assignment.'),
('distribution', 'allow_realtime_routes', '1', 'boolean', 'Allow adding new delivery areas / routes on-the-fly via slide drawer without reloading page'),
('distribution', 'enforce_fixed_routes', '0', 'boolean', 'Enforce drivers and salesmen to operate strictly within their pre-assigned delivery routes'),
('general', 'expense_management_mode', 'standard', 'string', 'Expense management mode: standard (single owner) or partnership (shared split)')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ---------------------------------------------------------------------
-- 6. CHART OF ACCOUNTS (Double-entry accounting foundation)
-- ---------------------------------------------------------------------

INSERT INTO `accounts` (`id`, `account_code`, `account_name`, `account_type`, `sub_type`, `is_system`) VALUES
(1001, '1001', 'Cash in Hand (Office Main)', 'asset', 'cash', 1),
(1002, '1002', 'Petty Cash', 'asset', 'cash', 1),
(1010, '1010', 'Bank Al-Habib / MCB Current Account', 'asset', 'bank', 1),
(1020, '1020', 'Accounts Receivable (Trade Debtors)', 'asset', 'accounts_receivable', 1),
(1030, '1030', 'Inventory Asset (Finished Goods Stock)', 'asset', 'inventory', 1),
(2010, '2010', 'Accounts Payable (Trade Creditors)', 'liability', 'accounts_payable', 1),
(3010, '3010', 'Owner Capital / Equity', 'equity', 'equity', 1),
(4010, '4010', 'Sales Revenue', 'revenue', 'sales', 1),
(4020, '4020', 'Sales Discounts Allowed', 'revenue', 'sales_discount', 1),
(4030, '4030', 'Sales Returns & Allowances', 'revenue', 'sales_return', 1),
(5010, '5010', 'Cost of Goods Sold (COGS)', 'expense', 'cost_of_goods_sold', 1),
(6010, '6010', 'Operating Expenses - Fuel & Van Transport', 'expense', 'expense', 1),
(6020, '6020', 'Operating Expenses - Staff Salaries & Daily Wages', 'expense', 'expense', 1),
(6030, '6030', 'Operating Expenses - Warehouse Rent & Utilities', 'expense', 'expense', 1),
(6040, '6040', 'Operating Expenses - Vehicle Maintenance', 'expense', 'expense', 1),
(6050, '6050', 'Operating Expenses - General Office & Tea', 'expense', 'expense', 1)
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

INSERT INTO `cash_accounts` (`id`, `account_id`, `account_name`, `custodian_name`) VALUES
(1, 1001, 'Main Office Cash Drawer', 'Cashier / Admin'),
(2, 1002, 'Daily Route Delivery Cash Buffer', 'Sales Manager')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- ---------------------------------------------------------------------
-- 7. DEFAULT UNITS
-- ---------------------------------------------------------------------

INSERT INTO `units` (`id`, `unit_code`, `unit_name`, `symbol`) VALUES
(1, 'CTN', 'Carton', 'Ctn'),
(2, 'BOX', 'Box', 'Box'),
(3, 'PCK', 'Packet', 'Pck'),
(4, 'PCS', 'Piece', 'Pcs')
ON DUPLICATE KEY UPDATE `unit_name` = VALUES(`unit_name`);

-- ---------------------------------------------------------------------
-- 8. DEFAULT WAREHOUSE
-- ---------------------------------------------------------------------

INSERT INTO `warehouses` (`id`, `warehouse_code`, `warehouse_name`, `location`, `is_primary`) VALUES
(1, 'WH-01', 'Main Central Warehouse', 'Jandiala Bhagwala G.T. Road Gujranwala', 1)
ON DUPLICATE KEY UPDATE `warehouse_name` = VALUES(`warehouse_name`);

-- ---------------------------------------------------------------------
-- 9. EXPENSE CATEGORIES
-- ---------------------------------------------------------------------

INSERT INTO `expense_categories` (`id`, `category_code`, `category_name`, `account_id`, `description`) VALUES
(1, 'EXP-FUEL', 'Fuel & Transport', 6010, 'Van petrol, diesel, and freight costs'),
(2, 'EXP-SALARY', 'Salaries & Allowances', 6020, 'Sales staff, bookers, and warehouse daily wages'),
(3, 'EXP-RENT', 'Rent & Electricity', 6030, 'Warehouse and shop premises rent and power'),
(4, 'EXP-MAINT', 'Vehicle Maintenance', 6040, 'Van repair, bike maintenance, tires, and oil'),
(5, 'EXP-LOAD', 'Loading & Unloading', 6010, 'Labour charges for wagon/truck loading'),
(6, 'EXP-OFFICE', 'Office & Tea Expense', 6050, 'Daily office refreshments, stationery, printing')
ON DUPLICATE KEY UPDATE `category_name` = VALUES(`category_name`);

SET FOREIGN_KEY_CHECKS = 1;
