# DMS Changelog

All notable changes and architectural updates are documented in this file.

## [1.0.2] - 2026-11-20 (POS Fast Card Catalog & UI Streamlining)
### Added & Enhanced
- **POS-Style Product Card Catalog (`modules/orders/create.php` & `modules/invoices/create.php`):**
  - Transformed the slow, dropdown-based row addition system into an ultra-fast split catalog view with clickable product cards.
  - Real-time instant search input (`F2` / `/` shortcut) filtering products by name, code, or packaging ratio.
  - Category filter pills (All, Snacks, Confectionery, Drinks, etc.) for instant catalog narrowing.
  - Interactive product cards displaying name, packaging ratio (`1*24`, `1*36`), wholesale rate, available warehouse stock, and in-cart count badge.
  - Single-click card selection: Clicking a card immediately inserts it into the cart or increments its carton count by +1.
- **Interactive Cart with Full Value Editing:**
  - After adding products from the catalog cards, users can freely edit **Carton Quantity**, **Pieces Quantity**, **Wholesale Rate**, or **Discount %** in the cart table.
  - Dynamic real-time calculation of Line Totals and overall Order/Invoice summaries (Total Cartons, Total Pieces, Gross, Discount, Net Amount).
- **Stepping Arrows Removal:**
  - Added global CSS rules in `assets/css/app.css` removing browser up/down stepper spin buttons (`-webkit-inner-spin-button`, `-moz-appearance: textfield`) across all numeric inputs in the application, preventing accidental number shifts and decluttering data entry.

## [1.0.1] - 2026-11-20 (Debug & Stabilization Release)
### Fixed
- **System Notice Error on Load Sheet Dispatch (`modules/loading/create.php`):**
  - Resolved `Uncaught Error: Call to undefined function set_flash()` by implementing robust, centrally defined `set_flash()`, `set_flash_message()`, and `get_flash_messages()` session helpers across both `includes/functions.php` and `includes/response.php`.
  - Corrected database query in `modules/inventory/index.php`, `modules/purchases/create.php`, `modules/goods_receiving/create.php`, and `modules/inventory/adjust.php` where non-existent column `p.cost_price` was queried instead of `p.purchase_rate as cost_price`.
- **404 Not Found on System Settings (`modules/settings/index.php`):**
  - Implemented the complete System Settings module with Distributor Profile configuration (Business Name, Phone, Address, City, Currency, Date Format), document numbering sequence prefixes, and print layout packaging controls.
- **404 Not Found on System Audit Trail (`modules/settings/audit.php`):**
  - Built the immutable Audit Trail explorer supporting date range filtering, module filtering, action filtering, keyword search across activity logs, and pagination.
- **404 Not Found on Users & Roles (`modules/users/index.php`):**
  - Built the Users & Roles management console supporting operator listing, slide-out drawer account creation (`F4`), BCrypt password hashing, role assignment, active/inactive account status toggling, and administrative password resets.
- **Sidebar & Topbar Complete Route Verification:**
  - Audited all 34 navigational and action links across the entire application, verifying 100% of routes resolve to active, fully operational endpoints with zero 404 or 500 errors.

## [1.0.0-phase8] - 2026-11-20
### Added
- Complete Final System QA, Master Regression Verification Suite, Administrative Data Reset Tool, Deployment Guide, and Operational SOP Manual.
- Master Test Runner (`run_all_tests.php`): Executes test suites for Phases 1 through 7, validates all 173 test assertions with zero failures, and prints an executive coverage scorecard.
- Administrative Transactional Data Reset Tool (`database/reset_data.php`): Safely purges transactional records (orders, load sheets, invoices, deliveries, settlements, returns, purchases, GRNs, expenses, payments, stock ledger, journal entries) while strictly preserving Master Data (users, roles, permissions, chart of accounts, areas, bookers, salesmen, customers, suppliers, products, categories, units, settings) and restoring opening balances.
- Server Deployment & Configuration Guide (`DEPLOYMENT_GUIDE.md`): Detailed instructions for local WAMP/XAMPP and production Apache/MySQL/cPanel environments, PHP extensions configuration, permissions, security hardening, and CSRF documentation.
- User Manual & Daily Standard Operating Procedure (`USER_MANUAL.md`): End-to-end operational guide for office and warehouse staff covering keyboard navigation (`F1`–`F10`, `ESC`), morning booking, loading dispatch, multi-bill packing, van delivery recovery, evening route reconciliation, financial vouchers, ledgers, and Excel center.

## [1.0.0-phase7] - 2026-11-20
### Added
- Comprehensive Paper Printing & Physical Packing Layouts matching Client Photos 1 through 9.
- Multi-Invoice A4 Packing Template (`modules/invoices/batch_print.php` & `templates/invoices/multi_bill_print.php`):
  - **1-up (Full Page):** 1 bill per full A4 page for long invoices.
  - **2-up (Half A4):** 2 bills per A4 page split top and bottom with a cutting scissor divider line (`✁`), cutting paper costs by 50%.
  - **3-up (Slip):** 3 compact slip bills per A4 page for 1–3 item orders.
  - **Duplicate Mode:** One-click toggle printing **Customer Copy** on top and **Office Copy** on bottom of the SAME sheet.
- Single Bill Print Screen (`modules/invoices/print.php` & `templates/invoices/bill_print.php`): Exact replica of Client Photos 2 & 7 (**Muzzam Enterprises Bill / Cash Memo**) with yellow date badge and yellow total bar.
- Standalone Warehouse Loading Sheet Print (`modules/loading/print.php` & `templates/loading_sheets/load_sheet_print.php`): Exact replica of Client Photos 5 & 9 (**TOTAL STOCK ISSUE SUMMARY**) with company/area, product, ctn qty, pcs qty, rate, total amount, return, credit, and gate pass signatures.
- Standalone Field Delivery Recovery Sheet Print (`modules/delivery/print.php` & `templates/delivery_sheets/delivery_print.php`): Exact replica of Client Photo 8 (**DAILY SALE RECIVED + CREDIT**) in blank field mode with bordered cells for driver handwriting.
- Standalone Route Evening Settlement Voucher Print (`modules/settlements/print.php` & `templates/settlements/settlement_print.php`): Exact replica of Client Photos 1 & 3 with itemized shop settlements, zero-sum reconciliation audit, and Cashier Handover receipt.
- Phase 7 automated verification test suite (`test_phase7.php`): 60/60 tests passing.

## [1.0.0-phase6] - 2026-11-20
### Added
- Complete Reports Center, Operational Registers, Financial Statements, and Native XLSX Excel Data Center.
- Reports Center Hub (`modules/reports/index.php`): Executive business dashboard with real-time operational KPI snapshots (Billing, MTD Sales, Market Receivables, Factory Payables, Expenses).
- Daily Sales Register (`modules/reports/daily_sales.php`): Direct replication of client spreadsheets (Photos 1, 3, 8: **MUZZAM DAILY SALE RECIVED + CREDIT**), with date summary and itemized shop breakdown adhering to the Zero-Sum rule ($\text{Invoices} = \text{Cash} + \text{Credit} + \text{Returns}$).
- Booker Sales Performance Register (`modules/reports/booker_sales.php`): Volume metrics, cartons, gross sales, discounts, net sales, and calculated commission earnings.
- Delivery Salesman Recovery Register (`modules/reports/salesman_delivery.php`): Dispatched loads, bills delivered, cash brought to office, credit issued, and recovery percentages matching Photo 4.
- Product-Wise Sales Summary (`modules/reports/product_sales.php`): Item quantities, carton ratios, gross revenue, returns deducted, and net sales realization.
- Customer Aging & Parties Credit Register (`modules/reports/customer_aging.php`): Replicating client's Photo 6 (**Parties Credit**) with overdue aging buckets (0–15, 16–30, 31–60, 60+ days).
- Trial Balance Statement (`modules/reports/trial_balance.php`): Double-entry balance statement with zero imbalance verification.
- Profit & Loss Statement (`modules/reports/profit_loss.php`): Multi-step Income Statement calculating Gross Trade Profit, Operating Overhead, and Net Operating Income.
- Balance Sheet (`modules/reports/balance_sheet.php`): Statement of Financial Position verifying $\text{Assets} = \text{Liabilities} + \text{Equity}$.
- Local installation of `PhpOffice/PhpSpreadsheet` via Composer with enabled `zip` and `gd` extensions.
- Native XLSX Export Handler (`modules/excel/export_handler.php`): Streams binary Excel spreadsheets with styled headers, custom widths, and double-line totals.
- Excel Data Center (`modules/excel/index.php`) and Master Data Excel Importer (`modules/excel/import.php`).
- Phase 6 automated verification test suite (`test_phase6.php`): 16/16 tests passing.

## [1.0.0-phase5] - 2026-11-20
### Added
- Complete Financials, Expenses, Customer Receipts, Supplier Payments, Chart of Accounts, and General Ledger modules.
- Daily Expenses Module (`modules/expenses/`): Expense register, category filter, payment account filter, metric summary cards (Fuel, Wages, Rent, Maintenance, Office), keyboard-friendly creation (`create.php`, `F4`), automated balanced double-entry GL journal posting, printable Petty Cash Voucher (`view.php`), and Expense Category configuration (`categories.php`).
- Customer Payment Receipts Module (`modules/accounts/customer_payments.php`): Customer collection register, mode filter (Cash, Bank, Cheque, Online), receipt recording (`create_customer_payment.php`, `F4`), atomic customer receivable deduction (`update_customer_balance()`), balanced GL posting (Debit Cash/Bank == Credit Receivables 1020), and printable Money Receipt Slip (`view_customer_payment.php`).
- Supplier Payments Module (`modules/accounts/supplier_payments.php`): Factory payments register, disbursement voucher entry (`create_supplier_payment.php`, `F4`), atomic supplier payable deduction (`update_supplier_balance()`), balanced GL posting (Debit Payables 2010 == Credit Cash/Bank), and printable Supplier Disbursement Voucher (`view_supplier_payment.php`).
- Chart of Accounts Explorer (`modules/accounts/index.php`): Double-entry structure grouped by Assets (1xxx), Liabilities (2xxx), Equity (3xxx), Revenue (4xxx), and Expenses (5xxx/6xxx) with live balances and quick action tools.
- General Ledger Account Statement (`modules/accounts/ledger.php`): Detailed statement for any account over any date range, dynamic opening balance calculation, running balance tracking, and clean printable format.
- Manual Journal Vouchers (`modules/accounts/journal_list.php` & `journal_create.php`): System journal register with imbalance audit, multi-line dynamic voucher entry with real-time balancing calculation and server-side zero-imbalance enforcement, and formal printable Journal Voucher (`journal_view.php`).
- Phase 5 automated verification test suite (`test_phase5.php`): 26/26 tests passing.

## [1.0.0-phase4] - 2026-11-20
### Added
- Complete Purchasing, Goods Receiving Note (GRN), and Warehouse Inventory Management modules.
- Purchases Module (`modules/purchases/`): Purchase order entry, status pipeline (`ordered`, `received`), supplier terms, and printable PO slip.
- Goods Receiving Note Module (`modules/goods_receiving/`): Physical arrival inspection, carton/piece verification, damaged stock quarantine, atomic warehouse stock addition, supplier payable balance update, and double-entry General Ledger posting (Debit: Inventory Asset 1030, Credit: Accounts Payable 2010).
- Inventory Management Module (`modules/inventory/`): Real-time stock overview, multi-unit carton/pcs packaging breakdown, stock health alerts (low stock & out of stock), valuation calculator.
- Immutable Stock Movement Ledger (`modules/inventory/ledger.php`): Full audit trail for every inbound and outbound stock movement across the enterprise.
- Physical Stock Audit / Take Tool (`modules/inventory/adjust.php`): Stock adjustments for shrinkage, breakage, and surplus with direct COGS/Inventory journal posting.
- Phase 4 automated verification test suite (`test_phase4.php`): 14/14 tests passing.

## [1.0.0-phase3] - 2026-11-20
### Added
- Complete Order-to-Delivery business cycle matching Muzzam Enterprises real operational flow.
- Orders Module (`modules/orders/`): booking, carton/pack/pcs conversion, line discounts, confirmation workflow, and picking slip printing.
- Warehouse Loading & Consolidation (`modules/loading/`): multi-order load sheet generation, stock availability audit, dispatch stock deduction, auto invoice generation, and **TOTAL STOCK ISSUE SUMMARY** printable layout (Photo 5 & 9).
- Sales Invoices Module (`modules/invoices/`): direct spot billing (F7), customer bills register, batch route invoice printing, and single customer bill print template replicating Photo 2 & 7.
- Delivery Run Sheets (`modules/delivery/`): delivery salesman route assignment and printable Field Recovery Sheet (Photo 8: DAILY SALE RECIVED + CREDIT blank recovery form).
- Route Settlement & Daily Reconciliation (`modules/settlements/`): interactive reconciliation screen (Photo 1 & 3) enforcing Zero-Sum rule ($\text{Invoices} = \text{Cash} + \text{Credit} + \text{Returns}$), auto-calculating live differences, posting customer credit, office cash drawer submission, and balanced double-entry General Ledger journal entries.
- Sales Returns Module (`modules/returns/`): return reasons, restocking, customer credit notes, and quarantined damaged goods handling.
- Phase 3 automated verification test suite (`test_phase3.php`): 18/18 tests passing.

## [1.0.0-phase2] - 2026-11-20
### Added
- Complete Master Data modules: Areas, Bookers, Salesmen, Categories, Units, Products, Customers, Suppliers, Price Lists.
- Real client seed data (`database/seed_test_masters.php`) matching Muzzam Enterprises spreadsheets.
- Phase 2 automated verification test suite (`test_phase2.php`): 19/19 tests passing.

## [1.0.0-phase1] - 2026-11-20
### Added
- Core architecture, 55 InnoDB database tables, configuration, authentication, permissions, dashboard, responsive desktop UI, and testing framework.
- Phase 1 automated verification test suite (`test_phase1.php`): 20/20 tests passing.
