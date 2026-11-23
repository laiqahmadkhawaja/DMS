<?php
/**
 * DMS - Distribution Management System
 * Excel Data Center & Export Hub
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('excel_export');

$pageTitle = "Excel Data Center";
$activeMenu = "excel";

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-file-earmark-excel me-2"></i>Excel Data Center & Native XLSX Exports
                </h4>
                <p class="text-muted small mb-0">Export real operational and financial transactions into native Microsoft Excel (.xlsx) workbooks.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/modules/inventory/import_stock.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i> Import Opening Stock
                </a>
                <a href="<?= APP_URL ?>/modules/products/bulk_prices.php" class="btn btn-outline-info btn-sm text-dark">
                    <i class="bi bi-currency-dollar me-1"></i> Bulk Price Setting
                </a>
                <a href="import.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-upload me-1"></i> Import Masters from Excel
                </a>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Export 1: Daily Sales Register -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-warning bg-opacity-25 p-3 text-dark">
                                <i class="bi bi-calendar2-week fs-3 text-dark"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-navy mb-1">Daily Sales Register (.xlsx)</h5>
                                <div class="text-muted small">Matches Client Photo 1, 3, 8 format with Total Invoices, Cash, Credit, and Returns.</div>
                            </div>
                        </div>
                        <form method="GET" action="export_handler.php" class="row g-2 align-items-end mt-2">
                            <input type="hidden" name="type" value="daily_sales">
                            <div class="col-6">
                                <label class="form-label small text-muted mb-1">From Date</label>
                                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= $firstOfMonth ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted mb-1">To Date</label>
                                <input type="date" name="to_date" class="form-control form-control-sm" value="<?= $today ?>">
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold">
                                    <i class="bi bi-download me-1"></i> Download Daily Sales XLSX
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Export 2: Parties Credit Register -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-primary bg-opacity-25 p-3 text-primary">
                                <i class="bi bi-people fs-3"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-navy mb-1">Parties Credit & Customer Balances (.xlsx)</h5>
                                <div class="text-muted small">Exact replication of client's "Parties Credit" Excel sheet (Photo 6) with shop balances.</div>
                            </div>
                        </div>
                        <p class="text-muted small mb-4">Downloads full list of all active retail accounts with addresses, phone numbers, and live outstanding ledger balances.</p>
                        <a href="export_handler.php?type=customer_balances" class="btn btn-primary btn-sm w-100 fw-bold">
                            <i class="bi bi-download me-1"></i> Download Parties Credit XLSX
                        </a>
                    </div>
                </div>
            </div>

            <!-- Export 3: Products Catalog & Inventory -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-info bg-opacity-25 p-3 text-info">
                                <i class="bi bi-box-seam fs-3"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-navy mb-1">Product Catalog & Real-Time Stock (.xlsx)</h5>
                                <div class="text-muted small">Wholesale rates, carton ratios, piece counts, and stock valuations.</div>
                            </div>
                        </div>
                        <p class="text-muted small mb-4">Complete stock inventory report with carton conversions (e.g. 1*24, 1*36), wholesale pricing, and total stock values.</p>
                        <a href="export_handler.php?type=products_inventory" class="btn btn-info text-white btn-sm w-100 fw-bold">
                            <i class="bi bi-download me-1"></i> Download Inventory XLSX
                        </a>
                    </div>
                </div>
            </div>

            <!-- Export 4: General Ledger Transactions -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-secondary bg-opacity-25 p-3 text-secondary">
                                <i class="bi bi-journal-bookmark fs-3"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-navy mb-1">General Ledger Transactions (.xlsx)</h5>
                                <div class="text-muted small">Auditor-ready double-entry journal movements and account postings.</div>
                            </div>
                        </div>
                        <form method="GET" action="export_handler.php" class="row g-2 align-items-end mt-2">
                            <input type="hidden" name="type" value="general_ledger">
                            <div class="col-6">
                                <label class="form-label small text-muted mb-1">From Date</label>
                                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= $firstOfMonth ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted mb-1">To Date</label>
                                <input type="date" name="to_date" class="form-control form-control-sm" value="<?= $today ?>">
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-secondary btn-sm w-100 fw-bold">
                                    <i class="bi bi-download me-1"></i> Download General Ledger XLSX
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
