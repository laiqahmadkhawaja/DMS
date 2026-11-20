<?php
/**
 * DMS - Distribution Management System
 * Expense Categories Management
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permission.php';

require_login();
require_permission('expenses_manage');

$pageTitle = "Expense Categories";
$activeMenu = "expenses";
$errors = [];
$success = "";

// Handle Add / Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code        = strtoupper(trim(clean_input($_POST['category_code'] ?? '')));
    $name        = trim(clean_input($_POST['category_name'] ?? ''));
    $accountId   = (int)($_POST['account_id'] ?? 6050);
    $description = clean_input($_POST['description'] ?? '');

    if (empty($code)) {
        $errors[] = "Category code is required (e.g. EXP-MARKET).";
    }
    if (empty($name)) {
        $errors[] = "Category name is required.";
    }

    if (empty($errors)) {
        try {
            $stmt = db()->prepare("
                INSERT INTO expense_categories (category_code, category_name, account_id, description, is_active)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    category_name = VALUES(category_name),
                    account_id = VALUES(account_id),
                    description = VALUES(description),
                    is_active = 1
            ");
            $stmt->execute([$code, $name, $accountId, $description]);
            $success = "Expense category saved successfully.";
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch categories and expense accounts
$categories = db_fetch_all("
    SELECT ec.*, a.account_code, a.account_name,
        (SELECT COUNT(e.id) FROM expenses e WHERE e.expense_category_id = ec.id) as expense_count,
        (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.expense_category_id = ec.id) as total_spent
    FROM expense_categories ec
    LEFT JOIN accounts a ON ec.account_id = a.id
    ORDER BY ec.id ASC
");

$expenseAccounts = db_fetch_all("
    SELECT id, account_code, account_name 
    FROM accounts 
    WHERE account_type = 'expense' AND is_active = 1 
    ORDER BY account_code ASC
");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="dms-main-content">
    <div class="container-fluid py-3">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-tags me-2"></i>Expense Categories
                </h4>
                <p class="text-muted small mb-0">Configure operational cost heads and map them to corresponding General Ledger expense accounts.</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Expenses
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

        <?php if ($success): ?>
            <div class="alert alert-success shadow-sm py-2">
                <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- Inline Create / Add Category Form (NO MODAL) -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm sticky-top" style="top: 20px;">
                    <div class="card-header bg-white py-3 border-bottom">
                        <div class="fw-bold text-navy"><i class="bi bi-plus-square me-1"></i> Add / Edit Category</div>
                    </div>
                    <div class="card-body p-3">
                        <form method="POST" action="categories.php">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Category Code <span class="text-danger">*</span></label>
                                <input type="text" name="category_code" class="form-control form-control-sm text-uppercase" placeholder="e.g. EXP-MARKET" required>
                                <div class="form-text">Unique code identifier</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                                <input type="text" name="category_name" class="form-control form-control-sm" placeholder="e.g. Market Promotion & Samples" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Linked General Ledger Account <span class="text-danger">*</span></label>
                                <select name="account_id" class="form-select form-select-sm" required>
                                    <?php foreach ($expenseAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>" <?= $acc['id'] == 6050 ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($acc['account_code']) ?> - <?= htmlspecialchars($acc['account_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Which GL expense account will be debited</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Description</label>
                                <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Description of this expense head"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                                <i class="bi bi-save me-1"></i> Save Category
                            </button>

                        </form>
                    </div>
                </div>
            </div>

            <!-- Categories Table -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <div class="fw-bold text-navy"><i class="bi bi-list-ul me-1"></i> Active Categories List</div>
                        <span class="badge bg-light text-dark border"><?= count($categories) ?> categories</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Code</th>
                                        <th>Category Name</th>
                                        <th>Linked GL Account</th>
                                        <th>Description</th>
                                        <th class="text-center">Vouchers</th>
                                        <th class="text-end">Total Spent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr>
                                            <td class="fw-bold text-navy text-nowrap"><?= htmlspecialchars($cat['category_code']) ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($cat['category_name']) ?></td>
                                            <td class="small text-muted text-nowrap">
                                                <?= htmlspecialchars($cat['account_code']) ?> - <?= htmlspecialchars($cat['account_name'] ?? 'Default Expense') ?>
                                            </td>
                                            <td class="small text-muted"><?= htmlspecialchars($cat['description'] ?: '—') ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-light text-dark border"><?= (int)$cat['expense_count'] ?></span>
                                            </td>
                                            <td class="text-end fw-bold text-navy">
                                                <?= format_currency($cat['total_spent']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
