# DMS – Database Architecture Documentation

## 1. Overview
The DMS database (`distribution_dms`) is designed in MySQL 8.x using the **InnoDB** storage engine, full foreign key referential integrity, and **utf8mb4** charset for high-performance wholesale distribution workflows.

---

## 2. Table Groups & Relations

### 2.1 System & Access Control
* `users`: System login credentials, security lockouts, last login timestamps.
* `roles`: Predefined business roles (`admin`, `manager`, `operator`, `booker`, `storekeeper`, `salesman`, `accountant`, `viewer`).
* `permissions`: Granular operation permissions.
* `role_permissions`: M:N link between roles and permissions.
* `user_roles`: M:N link between users and roles.
* `settings`: Configurable business variables (Client Name, Phone, Address, Numbering prefixes).
* `audit_logs`: Transaction and user action audit trail.

### 2.2 Master Data
* `areas`: Route and geographic distribution zones (e.g. *Jandiala Bhagwala, Rahwali, Daal Bazar*).
* `bookers`: Market order collection agents.
* `booker_areas`: Route assignments for order bookers.
* `salesmen`: Delivery staff and van drivers.
* `suppliers`: Manufacturers and primary vendors.
* `product_categories`: Categorization (e.g. *Nimko, Snacks, Confectionery*).
* `brands`: Optional brand/manufacturer tracking.
* `units`: Packaging units (*CTN, BOX, PCK, PCS*).
* `products`: Master product catalog with purchase rate, default wholesale rate, and conversion ratios.
* `product_unit_conversions`: Conversion multipliers to base stock unit.
* `customers`: Retailers and shopkeepers with credit limits, opening balances, and assigned booker/area.
* `customer_addresses`: Additional shop/delivery locations.
* `price_lists`: Special tier pricing.
* `price_list_items`: Tier prices per product and unit.
* `customer_prices`: Customer-specific rate agreements.
* `warehouses`: Physical storage locations (Main Central Warehouse).

### 2.3 Order Booking
* `orders`: Order booking headers (`order_number`, `customer_id`, `booker_id`, `area_id`, `status`).
* `order_items`: Order line items (`carton_qty`, `pack_qty`, `quantity`, `rate`, `line_total`).

### 2.4 Warehouse Loading & Stock Dispatch
* `load_sheets`: Van loading headers (`load_number`, `salesman_id`, `warehouse_id`, `status`).
* `load_sheet_orders`: Orders included in the vehicle dispatch.
* `load_sheet_items`: Consolidated required product quantities vs actual loaded quantities.

### 2.5 Sales Invoices & Customer Bills
* `sales_invoices`: Customer tax/sale invoices (`invoice_number`, `gross_amount`, `discount`, `net_amount`, `paid_amount`, `credit_amount`, `return_amount`).
* `sales_invoice_items`: Invoiced products with rates and carton quantities.
* `invoice_discounts`: Special promotional or volume discounts.

### 2.6 Delivery Runs
* `delivery_runs`: Route run headers (`run_number`, `salesman_id`, `run_date`).
* `delivery_run_bills`: Delivery sequence, delivery status, and notes.

### 2.7 Route Settlement & Cash Submissions
* `settlements`: End-of-day route reconciliation (`settlement_number`, `total_invoices_amount`, `total_cash_received`, `total_credit_given`, `total_returns_amount`, `difference_amount`).
* `settlement_items`: Bill-wise breakdown (`bill_amount = cash_amount + credit_amount + return_amount`).
* `cash_submissions`: Formal physical cash handover records to office cashier.

### 2.8 Returns Management
* `sales_returns`: Customer return vouchers (`return_number`, `condition_type`: *saleable / damaged / expired*).
* `sales_return_items`: Return product lines and rates.
* `purchase_returns` & `purchase_return_items`: Return to supplier.

### 2.9 Inventory & Stock Control
* `stock_movements`: Immutable stock transaction ledger (`movement_type`, `qty_in`, `qty_out`, `base_unit_qty`, `unit_cost`).
* `stock_balances`: Cached snapshot of `saleable_qty`, `damaged_qty`, `expired_qty`, and total stock valuation.
* `stock_adjustments` & `stock_adjustment_items`: Warehouse audit adjustments.

### 2.10 Purchasing & Goods Receiving
* `purchase_orders` & `purchase_order_items`: Vendor purchase orders.
* `goods_receipts` & `goods_receipt_items`: Inspection and warehouse stock receipt vouchers (GRN).

### 2.11 Double-Entry Financial Engine
* `accounts`: Chart of Accounts (Cash, Bank, Debtors, Inventory, Creditors, Equity, Sales, COGS, Expenses).
* `journal_entries`: Double-entry vouchers (`entry_number`, `entry_date`, `total_debit = total_credit`).
* `journal_entry_lines`: Individual debit and credit splits with party sub-ledger links.
* `customer_payments`: Cash/cheque receipts from retail customers.
* `supplier_payments`: Payments issued to manufacturers/suppliers.
* `expense_categories` & `expenses`: Operating expense records.
* `cash_accounts`: Drawer and physical till master.
