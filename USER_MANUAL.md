# Distribution Management System (DMS) - User Manual & Operational SOP

**Client:** Muzzam Enterprises, Gujranwala  
**Software:** Distribution Management System (DMS) v1.0  
**Business Flow:** `Booking → Orders → Load Sheet Dispatch → Invoices → Delivery Run → Route Settlement → Ledgers → Reports & Excel`

---

## 1. System Overview & Keyboard Shortcuts

The DMS is optimized for desktop computers and warehouse terminals with fast, mouse-free keyboard navigation.

| Shortcut | Function | Destination Module |
|---|---|---|
| <kbd>F1</kbd> | Search / Focus Global Search | Header Search |
| <kbd>F2</kbd> | New Sales Order | `modules/orders/create.php` |
| <kbd>F3</kbd> | New Customer Registration | `modules/customers/create.php` |
| <kbd>F4</kbd> | New Expense / Cash Voucher | `modules/expenses/create.php` |
| <kbd>F5</kbd> | New Purchase Order | `modules/purchases/create.php` |
| <kbd>F6</kbd> | New Warehouse Loading Sheet | `modules/loading/create.php` |
| <kbd>F7</kbd> | New Direct Sales Invoice | `modules/invoices/create.php` |
| <kbd>F8</kbd> | Route Evening Settlement | `modules/settlements/create.php` |
| <kbd>F9</kbd> | Daily Sales Register (Photo 8/1) | `modules/reports/daily_sales.php` |
| <kbd>F10</kbd> | Parties Credit Ledger (Photo 6) | `modules/reports/customer_aging.php` |
| <kbd>ESC</kbd> | Cancel / Close Slide Drawer | Active Form / Drawer |

---

## 2. Daily Operational Workflow (Morning to Evening)

### Step 1: Morning Order Booking & Entry (<kbd>F2</kbd>)
1. Field bookers (e.g. Imran Butt, Muzamal Shah) visit market retail shops (Prince Grocery, Ramzan Sweets, Usman J.S, etc.) with physical booking pads.
2. In the office, open **Orders & Booking** (`F2`):
   - Select Booker, Area, Delivery Date, and Retail Customer.
   - Add ordered products by typing carton quantity or individual pieces. The system auto-calculates total pieces, carton conversions, price list rates, and net bill amounts.
   - Press **Save Order**.

### Step 2: Warehouse Loading Sheet & Stock Dispatch (<kbd>F6</kbd>)
1. Navigate to **Warehouse Loading** (`F6`):
   - Select Delivery Date, Salesman / Van Driver (e.g. Saghar), and Delivery Route / Area.
   - The system displays all pending orders for that route with checkboxes.
   - Select the orders to dispatch and click **Create Load Sheet**.
2. Click **Issue Stock Now**:
   - The warehouse inventory is automatically deducted in the stock ledger with transaction audit tags.
   - Sales Invoices are automatically generated for all included customer orders.
3. Print the **TOTAL STOCK ISSUE SUMMARY** (Matching Photos 5 & 9):
   - Click **Print Sheet (Photo 5/9)** to generate the gate dispatch sheet.
   - Verify carton and piece counts with the warehouse gate incharge and obtain the driver's signature.

### Step 3: Printing Customer Bills & Invoices
1. From the Load Sheet view, click **Print All Invoices** (or open `modules/invoices/batch_print.php`).
2. The print toolbar provides flexible layouts to minimize paper usage:
   - **2-up (Half A4):** Prints 2 bills per A4 page split top and bottom with a scissor cut line (`✁`). Recommended for standard 1–8 item orders (saves 50% paper cost!).
   - **1-up (Full Page):** Prints 1 bill per full A4 page for large orders with many items.
   - **3-up (Slip):** Compact slip format for 1–3 item orders.
   - **Duplicate Mode:** Toggle `Duplicate: ON` to print **Customer Copy** on top and **Office Copy** on bottom of the SAME sheet.
3. Printed bills strictly match the client's physical bill format (Photos 2 & 7), displaying Product name, Cartons, Conversion Packaging, Pieces, Unit Rate, and Total with yellow highlight bars.

### Step 4: Delivery Van Field Recovery Sheet
1. Open **Delivery Runs** and click on the generated run for the delivery van.
2. Click **Print Sheet (Photo 8)**:
   - Generates the **DAILY SALE RECIVED + CREDIT** blank field recovery sheet.
   - Pre-fills Sr., Shop Name, Bill No., and Bill Amount.
   - Leaves Credit, Return, and Cash columns with bordered blank cells for the driver to handwrite field collections throughout the day.

### Step 5: Evening Route Reconciliation & Cash Handover (<kbd>F8</kbd>)
1. When the delivery salesman returns to the warehouse in the evening, open **Route Settlements** (`F8`):
   - Select the Delivery Run or Salesman.
   - The system lists all dispatched bills and amounts.
2. Enter the field numbers recorded on the driver's sheet:
   - **Cash Collected:** Actual rupees received from the retailer.
   - **Credit Allowed:** Balance left on market credit for approved credit parties.
   - **Returns Received:** Damaged or rejected stock returned to the van.
3. **Zero-Sum Reconciliation Identity:**
   $$\text{Total Dispatched Invoices} = \text{Cash Collected} + \text{Credit Allowed} + \text{Returns Received}$$
   The live balancing card displays any shortage or excess in real time.
4. Finalize the Settlement:
   - Physical returned goods are credited back into warehouse inventory.
   - Credit amounts are automatically posted to the respective customer ledgers.
   - Cash amount is handed to the office cashier and creates a formal Cash Submission voucher.
   - A balanced General Ledger journal entry is automatically posted (Debit Cash `1010` & AR `1020`, Credit Sales `4010`).
5. Print the **Route Settlement Sheet** (Matching Photos 1 & 3):
   - Obtain signatures of the Salesman, Head Cashier, and Reconciling Auditor.

---

## 3. Financials, Ledgers & Expenses

### Operating Expenses (<kbd>F4</kbd>)
- Daily distributor expenses (Vehicle Fuel `6010`, Staff Salaries `6020`, Warehouse Rent `6030`, Vehicle Maintenance `6040`, Office Tea/Stationery `6050`) are recorded under **Operating Expenses**.
- Generates printable Petty Cash payment slips with account codes and receiver signatures.

### Customer Recoveries & Credit Collections
- When a retailer pays off prior market credit, go to **Customer Payments**:
  - Select Customer (live balance shown).
  - Enter Amount and select Deposit Account (Cash in Hand `1010` or Bank Account `1011`).
  - System updates the party ledger and prints a Money Receipt Slip.

### Supplier / Factory Disbursements
- When paying goods manufacturers or factories (Nimko, Confectionery, Vermicelli factories), record via **Supplier Payments**:
  - Live payable balance lookup, payment method (Cash / Cheque / Bank Transfer).
  - Deducts company cash and reduces factory payables in real time.

### General Ledger & Journal Vouchers
- View real-time account ledgers under **Accounting & GL**:
  - Explore Chart of Accounts (Assets `1xxx`, Liabilities `2xxx`, Equity `3xxx`, Revenue `4xxx`, Expenses `5xxx/6xxx`).
  - View Running Account Statements with dynamic opening balances brought forward.
  - Create manual multi-line adjusting Journal Vouchers (`journal_create.php`) with real-time balancing validation ($\sum \text{Debit} == \sum \text{Credit}$).

---

## 4. Reports & Native Excel Data Center

| Report | Purpose | Matching Client Photo |
|---|---|---|
| **Daily Sales Register** | Summary & Detailed breakdown of Invoices, Cash, Credit, Returns | Photos 1, 3, 8 |
| **Parties Credit Aging** | Outstanding market receivables grouped by 0–15, 16–30, 31–60, 60+ days | Photo 6 (Rs. 415,259 format) |
| **Booker Sales Performance** | Sales volume, cartons sold, discounts, net sales, and commissions | Booker Analysis |
| **Salesman Recovery Register** | Delivered bills, cash recovered, credit issued, recovery % | Photo 4 (Saghar Ledger) |
| **Product Sales Summary** | Item sales quantities, cartons, piece units, gross sales, returns | Stock Velocity |
| **Trial Balance** | Double-entry ledger verification statement (Debit == Credit) | Audit Verification |
| **Profit & Loss Statement** | Gross Sales - Discounts - Returns - COGS - Expenses = Net Profit | Financial Position |
| **Balance Sheet** | $\text{Assets} = \text{Liabilities} + \text{Equity}$ Statement | Balance Sheet |

### Native Excel Export & Import
- All reports can be exported directly to native binary `.xlsx` spreadsheets with navy header blocks, bold yellow totals, and currency formatting via **Excel Data Center** (`modules/excel/`).
- Master data for Customers and Products can be bulk-uploaded using standard Excel templates via `modules/excel/import.php`.

---

## 5. Administrative Maintenance & Data Reset

If you need to clear demo/test orders and start fresh with real live business transactions while preserving your registered Customers, Products, Price Lists, Bookers, Salesmen, and Chart of Accounts:
- Run the administrative reset command:
  ```bash
  php database/reset_data.php --confirm
  ```
- This safely empties all test orders, load sheets, invoices, deliveries, settlements, expenses, and journal entries, and resets customer/supplier balances to their original opening balances.
