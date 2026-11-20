# DMS – Distribution Management System

A production-grade, fast, modular Core PHP Distribution Management System tailored strictly for wholesale FMCG distributors (specifically modeled for Muzzam Enterprises, Gujranwala).

---

## 1. Core Business Workflow
The architecture faithfully executes the actual daily distribution cycle:
```
Market Order Booking (F6)
         ↓
Office Order Entry & Review
         ↓
Order Confirmation
         ↓
Warehouse Loading Sheet (Van Loading & Stock Dispatch)
         ↓
Customer Delivery Run Sheet
         ↓
Daily Sales Invoice & Multi-Bill A4 Printing (F7)
         ↓
Route Settlement (Cash + Credit + Return Reconciliation) (F8)
         ↓
Salesman Cash Ledger Submission
         ↓
Customer Balance & Market Recovery Ledger
         ↓
Daily Sales & Recovery Register
```

---

## 2. Technology Stack
* **Backend:** Core PHP 8.x (Modular, non-MVC, PDO Prepared Statements, strict error handling).
* **Database:** MySQL / MariaDB (InnoDB, Foreign Key Constraints, Transactions, UTF-8MB4).
* **Frontend:** HTML5, CSS3, Vanilla JS, Bootstrap 5 (Local assets, Zero external CDN dependencies).
* **Environment:** Tested on XAMPP / WAMP Apache server running locally at `c:\wamp64\www\Distribution`.

---

## 3. Installation & Database Setup
1. Clone / place files into your web root (e.g., `C:/wamp64/www/Distribution`).
2. Ensure MySQL is running on `127.0.0.1:3306`.
3. Run the automated database installer:
   ```bash
   php setup.php
   ```
   Or visit in browser: `http://localhost/Distribution/setup.php`
4. Default Administrator Credentials:
   * **Username:** `admin`
   * **Password:** `admin123`

---

## 4. Key Architectural Standards
1. **Zero Header Errors:** Redirect logic strictly executes before any HTML output; sessions and headers are managed centrally.
2. **Strict Stock Integrity:** Stock balances are never updated directly. Every single balance change is backed by an auditable movement record in `stock_movements`.
3. **Double-Entry Financial Foundation:** Every financial transaction posts to balanced double-entry journal vouchers (`Total Debit = Total Credit`).
4. **No Modal Dialogs:** The interface avoids Bootstrap modal popups, using dedicated pages, inline forms, and non-modal slide drawers for uninterrupted keyboard-driven data entry.
5. **Keyboard Shortcuts:**
   * `F1`: Keyboard Shortcuts Reference
   * `F2`: Instant Search Focus
   * `F6`: New Booking Order
   * `F7`: New Sales Invoice
   * `F8`: Daily Route Settlement
   * `F9` / `Ctrl+S`: Save Form
   * `F10`: Print Document
   * `ESC`: Close Drawers / Side Panels

---

## 5. Directory Structure
```
Distribution/
├── index.php                 # Application entry point
├── login.php                 # Secure login & rate limiting
├── logout.php                # Safe session destruction
├── dashboard.php             # Real-time operational distributor dashboard
├── setup.php                 # Automated schema & seed runner
├── config/                   # Configuration, constants & PDO database singleton
├── includes/                 # Core functions, permissions, validation & accounting
├── modules/                  # Business modules (Orders, Invoices, Loading, Settlements, etc.)
├── ajax/                     # Clean JSON AJAX endpoints
├── templates/                # Printable invoice, loading & settlement layouts
├── assets/                   # Local Bootstrap, Icons, CSS & JS
├── database/                 # schema.sql, seed.sql & migrations
├── storage/                  # Imports, exports, backups
└── logs/                     # Private protected error & audit logs
```
