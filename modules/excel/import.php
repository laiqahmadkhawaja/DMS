<?php
/**
 * DMS - Distribution Management System
 * Master Data Excel Importer (PhpSpreadsheet)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('excel_import');

$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    die("PhpSpreadsheet library is not installed.");
}
require_once $vendorAutoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$pageTitle = "Excel Master Importer";
$activeMenu = "excel";

$errors = [];
$successMsg = "";
$importedCount = 0;

// Handle Template Download
if (isset($_GET['download_template'])) {
    $tType = clean_input($_GET['download_template']);
    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();

    if ($tType === 'customers') {
        $filename = "customer_import_template.xlsx";
        $headers = ['Shop Name*', 'Owner Name', 'Phone*', 'Address*', 'Area Name*', 'Credit Limit', 'Opening Balance'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        foreach ($headers as $i => $h) {
            $sh->setCellValue($cols[$i] . '1', $h);
        }
        $sh->setCellValue('A2', 'Madina Karyana Store');
        $sh->setCellValue('B2', 'Muhammad Asif');
        $sh->setCellValue('C2', '03001234567');
        $sh->setCellValue('D2', 'Main Bazar');
        $sh->setCellValue('E2', 'Jandiala Bhagwala');
        $sh->setCellValue('F2', '50000');
        $sh->setCellValue('G2', '12500');
    } elseif ($tType === 'products') {
        $filename = "product_import_template.xlsx";
        $headers = ['Product Code*', 'Product Name*', 'Category*', 'Carton Ratio*', 'Purchase Rate*', 'Wholesale Rate*', 'Opening Stock (Pcs)'];
        $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        foreach ($headers as $i => $h) {
            $sh->setCellValue($cols[$i] . '1', $h);
        }
        $sh->setCellValue('A2', 'NDM-36');
        $sh->setCellValue('B2', 'Nimko Daal Moth (1*36)');
        $sh->setCellValue('C2', 'Nimko & Snacks');
        $sh->setCellValue('D2', '36');
        $sh->setCellValue('E2', '1350');
        $sh->setCellValue('F2', '1512');
        $sh->setCellValue('G2', '108');
    } else {
        die("Invalid template type.");
    }

    foreach ($cols as $c) {
        $sh->getColumnDimension($c)->setAutoSize(true);
    }

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $wr = new Xlsx($ss);
    $wr->save('php://output');
    exit;
}

// Handle File Upload & Import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $importType = clean_input($_POST['import_type'] ?? 'customers');

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a valid Excel file to upload.";
    } else {
        $uploadedTmp = $_FILES['excel_file']['tmp_name'];
        $origName = $_FILES['excel_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            $errors[] = "Only real Excel workbooks (.xlsx, .xls) are permitted.";
        } else {
            try {
                $spreadsheet = IOFactory::load($uploadedTmp);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();

                if (count($rows) < 2) {
                    throw new Exception("The uploaded spreadsheet contains no data rows.");
                }

                $headerRow = array_shift($rows); // Remove header row

                db()->beginTransaction();

                if ($importType === 'customers') {
                    // Columns: [Shop Name, Owner Name, Phone, Address, Area Name, Credit Limit, Opening Balance]
                    $stmtCust = db()->prepare("
                        INSERT INTO customers (
                            customer_code, shop_name, owner_name, phone, address,
                            area_id, credit_limit, opening_balance, current_balance,
                            is_active, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            1, NOW()
                        )
                    ");

                    foreach ($rows as $r) {
                        $shopName = trim($r[0] ?? '');
                        if (empty($shopName)) continue;

                        $ownerName = trim($r[1] ?? '');
                        $phone     = trim($r[2] ?? '03000000000');
                        $address   = trim($r[3] ?? 'Gujranwala');
                        $areaName  = trim($r[4] ?? 'Jandiala Bhagwala');
                        $limit     = round((float)($r[5] ?? 50000), 2);
                        $opening   = round((float)($r[6] ?? 0), 2);

                        // Find or create area
                        $areaId = (int)db_fetch_val("SELECT id FROM areas WHERE area_name = ?", [$areaName]);
                        if (!$areaId) {
                            $areaCode = 'AR-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $areaName), 0, 4));
                            $stmtA = db()->prepare("INSERT INTO areas (area_code, area_name, is_active) VALUES (?, ?, 1)");
                            $stmtA->execute([$areaCode, $areaName]);
                            $areaId = (int)db()->lastInsertId();
                        }

                        // Check duplicate shop name in area
                        $exists = db_fetch_val("SELECT id FROM customers WHERE shop_name = ? AND area_id = ? AND is_deleted = 0", [$shopName, $areaId]);
                        if ($exists) continue;

                        $code = 'CUST-' . strtoupper(bin2hex(random_bytes(3)));
                        $stmtCust->execute([
                            $code, $shopName, $ownerName, $phone, $address,
                            $areaId, $limit, $opening, $opening
                        ]);
                        $custId = (int)db()->lastInsertId();

                        // Post opening balance journal entry if > 0
                        if ($opening > 0) {
                            post_journal_entry([
                                'entry_date'     => date('Y-m-d'),
                                'reference_type' => 'opening',
                                'reference_id'   => $custId,
                                'narration'      => "Opening Receivable Balance: {$shopName}"
                            ], [
                                [
                                    'account_id'  => ACC_RECEIVABLES,
                                    'debit'       => $opening,
                                    'credit'      => 0.00,
                                    'description' => "Opening Receivable: {$shopName}",
                                    'party_type'  => 'customer',
                                    'party_id'    => $custId
                                ],
                                [
                                    'account_id'  => ACC_CAPITAL,
                                    'debit'       => 0.00,
                                    'credit'      => $opening,
                                    'description' => "Opening Balance Equity - {$shopName}",
                                    'party_type'  => null,
                                    'party_id'    => null
                                ]
                            ]);
                        }
                        $importedCount++;
                    }

                } elseif ($importType === 'products') {
                    // Columns: [Product Code, Product Name, Category, Carton Ratio, Purchase Rate, Wholesale Rate, Opening Stock]
                    $stmtProd = db()->prepare("
                        INSERT INTO products (
                            product_code, product_name, category_id, base_unit_id,
                            carton_qty, pack_qty, purchase_rate, default_sale_rate,
                            wholesale_rate, retail_rate, is_active, created_at
                        ) VALUES (
                            ?, ?, ?, 4,
                            ?, 1, ?, ?,
                            ?, ?, 1, NOW()
                        )
                    ");

                    foreach ($rows as $r) {
                        $pCode = trim($r[0] ?? '');
                        $pName = trim($r[1] ?? '');
                        if (empty($pCode) || empty($pName)) continue;

                        $catName  = trim($r[2] ?? 'General');
                        $ratio    = max(1, (float)($r[3] ?? 24));
                        $pRate    = round((float)($r[4] ?? 0), 2);
                        $wRate    = round((float)($r[5] ?? 0), 2);
                        $opStock  = round((float)($r[6] ?? 0), 2);

                        // Find or create category
                        $catId = (int)db_fetch_val("SELECT id FROM product_categories WHERE category_name = ?", [$catName]);
                        if (!$catId) {
                            $catCode = 'CAT-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $catName), 0, 4));
                            $stmtCat = db()->prepare("INSERT INTO product_categories (category_code, category_name) VALUES (?, ?)");
                            $stmtCat->execute([$catCode, $catName]);
                            $catId = (int)db()->lastInsertId();
                        }

                        // Check duplicate code
                        $exists = db_fetch_val("SELECT id FROM products WHERE product_code = ? AND is_deleted = 0", [$pCode]);
                        if ($exists) continue;

                        $stmtProd->execute([
                            $pCode, $pName, $catId,
                            $ratio, $pRate, $wRate,
                            $wRate, $wRate
                        ]);
                        $prodId = (int)db()->lastInsertId();

                        // Record opening stock movement if > 0
                        if ($opStock > 0) {
                            record_stock_movement([
                                'product_id'     => $prodId,
                                'warehouse_id'   => 1,
                                'movement_date'  => date('Y-m-d H:i:s'),
                                'movement_type'  => 'opening_stock',
                                'reference_type' => 'opening',
                                'reference_id'   => $prodId,
                                'qty_in'         => $opStock,
                                'qty_out'        => 0.00,
                                'base_unit_qty'  => $opStock,
                                'unit_cost'      => $pRate,
                                'remarks'        => 'Opening Inventory Import'
                            ]);
                        }
                        $importedCount++;
                    }
                }

                db()->commit();
                $successMsg = "Successfully imported {$importedCount} records from Excel into the database.";

            } catch (Exception $e) {
                db()->rollBack();
                $errors[] = "Import failed: " . $e->getMessage();
            }
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-upload me-2"></i>Master Data Excel Importer
                </h4>
                <p class="text-muted small mb-0">Import customers, product catalogs, and opening inventory balances from Microsoft Excel (.xlsx).</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Excel Center
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm py-2">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($successMsg): ?>
            <div class="alert alert-success shadow-sm py-2">
                <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- Upload Box -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-bottom">
                        <div class="fw-bold text-navy"><i class="bi bi-cloud-arrow-up me-1"></i> Upload Spreadsheet</div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="import.php" enctype="multipart/form-data">
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Select Data Entity <span class="text-danger">*</span></label>
                                <select name="import_type" id="importTypeSelect" class="form-select" required>
                                    <option value="customers">Customers / Retail Stores (with Opening Balances)</option>
                                    <option value="products">Products & SKUs (with Opening Warehouse Stock)</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Choose Excel File (.xlsx) <span class="text-danger">*</span></label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
                                <div class="form-text">Must be a valid Microsoft Excel workbook (.xlsx). CSV files will be rejected.</div>
                            </div>

                            <button type="submit" class="btn btn-primary px-4 fw-bold">
                                <i class="bi bi-check2-circle me-1"></i> Validate & Import Data
                            </button>

                        </form>
                    </div>
                </div>
            </div>

            <!-- Templates & Instructions -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-bottom">
                        <div class="fw-bold text-navy"><i class="bi bi-file-earmark-arrow-down me-1"></i> Sample Templates</div>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small">Download sample pre-formatted Excel templates with correct column headers to ensure clean imports:</p>
                        
                        <div class="d-grid gap-2">
                            <a href="import.php?download_template=customers" class="btn btn-outline-primary text-start p-3">
                                <div class="fw-bold"><i class="bi bi-download me-2"></i>Customer Import Template (.xlsx)</div>
                                <div class="small text-muted">Shop Name, Owner, Phone, Route, Credit Limit, Opening Balance</div>
                            </a>
                            <a href="import.php?download_template=products" class="btn btn-outline-info text-start p-3">
                                <div class="fw-bold text-dark"><i class="bi bi-download me-2"></i>Product Import Template (.xlsx)</div>
                                <div class="small text-muted">Code, Name, Category, Carton Ratio, Wholesale Rate, Opening Stock</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
