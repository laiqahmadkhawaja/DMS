<?php
/**
 * DMS - Distribution Management System
 * Users & Roles Management
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

require_login();
require_permission('users_manage');

$pageTitle = 'Users & Roles';
$activeMenu = 'users';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['label' => 'Settings & Control', 'url' => ''],
    ['label' => 'Users & Roles', 'url' => '']
];

$errors = [];
$success = '';

// Handle Create / Update User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_input($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $username = strtolower(trim(clean_input($_POST['username'] ?? '')));
        $fullName = trim(clean_input($_POST['full_name'] ?? ''));
        $email    = trim(clean_input($_POST['email'] ?? ''));
        $phone    = trim(clean_input($_POST['phone'] ?? ''));
        $roleId   = (int)($_POST['role_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $status   = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($username) || strlen($username) < 3) {
            $errors[] = "Username must be at least 3 characters.";
        }
        if (empty($fullName)) {
            $errors[] = "Full Name is required.";
        }
        if ($roleId <= 0) {
            $errors[] = "Please select a valid role.";
        }
        if (empty($password) || strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters.";
        }

        // Check if username already exists
        if (empty($errors)) {
            $existing = db_fetch_one("SELECT id FROM users WHERE username = ? AND is_deleted = 0", [$username]);
            if ($existing) {
                $errors[] = "Username '{$username}' is already in use.";
            }
        }

        if (empty($errors)) {
            try {
                db()->beginTransaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = db()->prepare("
                    INSERT INTO users (username, full_name, email, phone, password_hash, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$username, $fullName, $email, $phone, $hash, $status]);
                $newUserId = (int)db()->lastInsertId();

                // Assign role
                $stmtRole = db()->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, ?, NOW())");
                $stmtRole->execute([$newUserId, $roleId]);

                if (function_exists('audit_log')) {
                    audit_log('create', 'users', (string)$newUserId, "Created user '{$username}' with role ID {$roleId}");
                }

                db()->commit();
                set_flash('success', "User '<strong>{$username}</strong>' created successfully.");
                redirect(APP_URL . '/modules/users/index.php');

            } catch (Exception $e) {
                db()->rollBack();
                error_log("Error creating user: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['active', 'inactive']) ? $_POST['new_status'] : 'active';

        if ($userId === (int)current_user_id() && $newStatus === 'inactive') {
            $errors[] = "You cannot deactivate your own administrative account.";
        } else {
            try {
                $stmt = db()->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newStatus, $userId]);

                if (function_exists('audit_log')) {
                    audit_log('update', 'users', (string)$userId, "Changed status to {$newStatus}");
                }

                set_flash('success', "User account status updated.");
                redirect(APP_URL . '/modules/users/index.php');
            } catch (Exception $e) {
                $errors[] = "Error updating status: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';

        if (empty($newPass) || strlen($newPass) < 6) {
            $errors[] = "New password must be at least 6 characters.";
        } else {
            try {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = db()->prepare("UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hash, $userId]);

                if (function_exists('audit_log')) {
                    audit_log('update', 'users', (string)$userId, "Reset user password");
                }

                set_flash('success', "Password successfully reset for selected user.");
                redirect(APP_URL . '/modules/users/index.php');
            } catch (Exception $e) {
                $errors[] = "Error resetting password: " . $e->getMessage();
            }
        }
    }
}

// Fetch all users with their roles
$users = db_fetch_all("
    SELECT 
        u.id,
        u.username,
        u.full_name,
        u.email,
        u.phone,
        u.status,
        u.last_login_at,
        r.id as role_id,
        r.role_name,
        r.role_code
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    WHERE u.is_deleted = 0
    ORDER BY u.id ASC
");

// Fetch active roles for selection
$roles = db_fetch_all("SELECT id, role_name, role_code, description FROM roles ORDER BY id ASC");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
require_once INCLUDES_PATH . '/topbar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1 text-navy fw-bold">
                    <i class="bi bi-shield-lock-fill me-2"></i>Users & Role Permissions
                </h4>
                <p class="text-muted small mb-0">Manage office staff logins, roles (Cashier, Booker, Warehouse Manager), and security credentials</p>
            </div>
            <div>
                <button type="button" class="btn btn-navy" onclick="openDrawer('drawer-new-user')">
                    <i class="bi bi-person-plus-fill me-1"></i> Add New User (F4)
                </button>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm border-0 mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars(implode(', ', $errors)) ?>
            </div>
        <?php endif; ?>

        <!-- Users Table -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold text-navy">
                    <i class="bi bi-people-fill me-1"></i> System Operator Accounts (<?= count($users) ?>)
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 25%;">User Information</th>
                            <th style="width: 15%;">Role</th>
                            <th style="width: 15%;">Contact</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 15%;">Last Sign-In</th>
                            <th style="width: 13%;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $idx => $u): ?>
                            <tr>
                                <td class="text-muted"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="fw-bold text-navy"><?= htmlspecialchars($u['full_name']) ?></div>
                                    <small class="text-muted">Username: <code><?= htmlspecialchars($u['username']) ?></code></small>
                                </td>
                                <td>
                                    <span class="badge bg-navy text-white text-uppercase" style="font-size: 11px;">
                                        <?= htmlspecialchars($u['role_name'] ?: 'No Role') ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($u['phone'] ?: 'No Phone') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($u['email'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">
                                            <i class="bi bi-check-circle me-1"></i>Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">
                                            <i class="bi bi-slash-circle me-1"></i>Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?= !empty($u['last_login_at']) ? date('d-m-Y H:i', strtotime($u['last_login_at'])) : 'Never' ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Toggle Status -->
                                        <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('Change user status?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>">
                                            <button type="submit" class="btn btn-outline-secondary" title="Toggle Active/Inactive">
                                                <i class="bi <?= $u['status'] === 'active' ? 'bi-toggle-on text-success fs-6' : 'bi-toggle-off text-muted fs-6' ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Reset Password -->
                                        <button type="button" class="btn btn-outline-navy" title="Reset Password"
                                                onclick="promptResetPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-key-fill"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- System Roles Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="mb-0 fw-bold text-navy">
                    <i class="bi bi-person-badge-fill me-1"></i> Configured Access Roles & Responsibilities
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($roles as $r): ?>
                        <div class="col-md-3">
                            <div class="p-3 border rounded bg-light h-100">
                                <div class="fw-bold text-navy mb-1"><?= htmlspecialchars($r['role_name']) ?></div>
                                <code class="small text-primary mb-2 d-inline-block"><?= htmlspecialchars($r['role_code']) ?></code>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($r['description'] ?: 'Standard access profile') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Non-Modal Slide-out Drawer: Add New User -->
<div id="drawer-new-user" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-person-plus-fill me-2"></i> Create System Operator Account</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <form method="POST" action="index.php">
        <input type="hidden" name="action" value="create_user">
        <div class="dms-drawer-body">
            <div class="mb-3">
                <label class="form-label fw-semibold">Login Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" placeholder="e.g. imran_booker" required autocomplete="off">
                <small class="text-muted">Unique identifier used for logging into DMS.</small>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" placeholder="e.g. Imran Butt" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Assigned Role <span class="text-danger">*</span></label>
                <select name="role_id" class="form-select" required>
                    <option value="">-- Select Role Profile --</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?> (<?= htmlspecialchars($r['role_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Contact Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="0300-1234567">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="staff@distributor.com">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" minlength="6" placeholder="Min. 6 characters" required autocomplete="new-password">
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Initial Status</label>
                <select name="status" class="form-select">
                    <option value="active">Active (Permitted to sign in)</option>
                    <option value="inactive">Inactive (Suspended)</option>
                </select>
            </div>
        </div>
        <div class="dms-drawer-footer">
            <button type="button" class="btn btn-secondary me-2" onclick="closeDrawer()">Cancel</button>
            <button type="submit" class="btn btn-navy"><i class="bi bi-check-circle me-1"></i> Create Account</button>
        </div>
    </form>
</div>

<!-- Hidden Form for Password Reset Prompt -->
<form id="resetPassForm" method="POST" action="index.php" style="display: none;">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" id="resetUserId" value="">
    <input type="hidden" name="new_password" id="resetNewPass" value="">
</form>

<script>
function promptResetPassword(userId, username) {
    const newPass = prompt('Enter new password for user "' + username + '" (minimum 6 characters):');
    if (newPass && newPass.trim().length >= 6) {
        document.getElementById('resetUserId').value = userId;
        document.getElementById('resetNewPass').value = newPass.trim();
        document.getElementById('resetPassForm').submit();
    } else if (newPass !== null) {
        alert('Password must be at least 6 characters long.');
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'F4') {
        e.preventDefault();
        openDrawer('drawer-new-user');
    }
});
</script>

<?php
require_once INCLUDES_PATH . '/footer.php';
?>
