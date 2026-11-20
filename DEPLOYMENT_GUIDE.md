# Distribution Management System (DMS) - Deployment & Server Configuration Guide

**Client:** Muzzam Enterprises, Gujranwala  
**Software Version:** 1.0.0 Production Release  
**Architecture:** Core PHP 8.2+ (Modular Non-MVC, Function-Based, PDO Prepared Statements, Local Assets)

---

## 1. System Requirements

### Server / Local Environment
- **Web Server:** Apache 2.4+ (with `mod_rewrite` enabled)
- **PHP Version:** PHP 8.1 or PHP 8.2 (Tested on PHP 8.2.12)
- **Database:** MySQL 5.7+ or MariaDB 10.4+ (InnoDB engine with `utf8mb4_unicode_ci`)
- **Required PHP Extensions:**
  - `pdo_mysql` (Database connectivity)
  - `zip` (Required by `PhpOffice/PhpSpreadsheet` for native `.xlsx` generation)
  - `gd` (Required by `PhpOffice/PhpSpreadsheet` for column auto-sizing and font metrics)
  - `session`, `json`, `mbstring`, `fileinfo`

---

## 2. Directory Structure & File Permissions

```
Distribution/
├── config/                  (chmod 755 - Configuration files)
│   ├── config.php
│   ├── database.php
│   └── constants.php
├── includes/                (chmod 755 - Core helpers & template headers)
├── modules/                 (chmod 755 - Business operation modules)
├── templates/               (chmod 755 - Printable voucher layouts)
├── assets/                  (chmod 755 - 100% offline Bootstrap & Icon assets)
├── database/                (chmod 755 - SQL migrations & reset scripts)
├── storage/                 (chmod 775 - Writable by web server)
│   ├── backups/
│   ├── exports/             (Native .xlsx files)
│   ├── imports/
│   └── documents/
├── logs/                    (chmod 775 - Error and audit logs)
└── vendor/                  (Composer libraries - PhpSpreadsheet)
```

---

## 3. Local Installation Steps (XAMPP / WAMP)

1. **Extract/Clone Project:**
   Place the project folder in your web root:
   - For WAMP: `C:\wamp64\www\Distribution\`
   - For XAMPP: `C:\xampp\htdocs\Distribution\`

2. **Configure PHP Extensions:**
   Open `php.ini` (e.g. `C:\xampp\php\php.ini` or via WAMP tray icon):
   Ensure the following lines are uncommented (no leading semicolon `;`):
   ```ini
   extension=pdo_mysql
   extension=zip
   extension=gd
   extension=mbstring
   ```
   Restart Apache.

3. **Configure Database Connection:**
   Open `config/database.php` and verify connection parameters:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', '3306');
   define('DB_NAME', 'distribution_dms');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

4. **Initialize Database Schema & Masters:**
   - Open browser and navigate to: `http://localhost/Distribution/setup.php`
   - Alternatively via CLI:
     ```bash
     cd C:\wamp64\www\Distribution
     php setup.php
     php database/seed_test_masters.php
     ```
   The setup runner automatically executes 55 InnoDB tables with foreign key constraints and creates default Chart of Accounts and Administrative credentials.

5. **Default Administrative Login:**
   - **URL:** `http://localhost/Distribution/login.php`
   - **Username:** `admin`
   - **Password:** `admin123`

---

## 4. Production Web Hosting Deployment (cPanel / Apache / VPS)

1. **Upload Files:**
   Upload all repository files to `public_html` (or a subdomain folder such as `dms.muzzam.com`).
   *Ensure `vendor/` folder is uploaded or run `composer install --no-dev --optimize-autoloader` on the server.*

2. **Set Up MySQL Database:**
   - Create a new MySQL database via cPanel or MySQL shell (e.g. `muzzam_dms`).
   - Create a database user with full privileges (`ALL PRIVILEGES`).
   - Import `database/schema.sql` and `database/seed.sql` using phpMyAdmin or command line:
     ```bash
     mysql -u username -p muzzam_dms < database/schema.sql
     mysql -u username -p muzzam_dms < database/seed.sql
     ```

3. **Update Configuration:**
   Edit `config/database.php` with production credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_PORT', '3306');
   define('DB_NAME', 'muzzam_dms');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_secure_password');
   ```
   Edit `config/constants.php`:
   ```php
   define('APP_ENV', 'production');
   define('APP_DEBUG', false);
   define('APP_URL', 'https://dms.muzzam.com');
   ```

4. **Secure File Permissions:**
   - Files: `644`
   - Folders: `755`
   - `storage/` and `logs/`: `775` (writable by web server process)
   - Remove or restrict access to `setup.php` after initial deployment:
     ```bash
     chmod 600 setup.php
     # or rename to setup.php.bak
     ```

5. **PHP Production Settings in `php.ini` or `.user.ini`:**
   ```ini
   display_errors = Off
   log_errors = On
   error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
   upload_max_filesize = 20M
   post_max_size = 25M
   memory_limit = 256M
   max_execution_time = 120
   ```

---

## 5. Security & CSRF Notice

Per Specification Section 6, CSRF tokens were excluded from the initial local rapid-development build to facilitate keyboard-driven workflows and headless AJAX interaction.

Implemented security controls:
- **Authentication & Role-Based Access Control (RBAC):** Every module validates user session and permissions (`invoices_create`, `loading_issue`, etc.) before rendering or processing.
- **Strict SQL Injection Defense:** 100% of SQL queries utilize PDO prepared statements with parameterized input.
- **Data Sanitization & XSS Escaping:** All user-supplied inputs pass through `sanitize_input()` and HTML outputs use `htmlspecialchars()`.
- **Double-Entry Financial Enforcements:** Journal vouchers strictly validate $\sum \text{Debit} == \sum \text{Credit}$. Transactions cannot post unbalanced entries.
- **Stock Movement Integrity:** Physical inventory cannot be updated arbitrarily; all quantity shifts must originate from an audit-logged transaction (GRN, Dispatch, Return, Adjustment).

---

## 6. Full Automated Test Suite Verification

Before putting the system into live use, run the master automated verification suite from the server terminal:
```bash
php run_all_tests.php
```
Verify that all 7 suites report **PASSED** with **173 passed tests and 0 failures**.
