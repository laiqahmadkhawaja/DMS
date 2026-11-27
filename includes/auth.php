<?php
/**
 * DMS - Distribution Management System
 * Authentication & Session Management
 *
 * NOTE: CSRF protection is excluded from this initial local build as requested in Section 6.
 * Session security, password hashing, login rate limiting, and permission checks are strictly enforced.
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Check if a user is currently authenticated
 *
 * @return bool
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['username']);
}

/**
 * Get current authenticated user details
 *
 * @return array|null
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'] ?? 'User',
        'email'     => $_SESSION['email'] ?? '',
        'roles'     => $_SESSION['roles'] ?? []
    ];
}

/**
 * Get current authenticated user ID
 *
 * @return int|null
 */
function current_user_id(): ?int {
    return is_logged_in() ? (int)$_SESSION['user_id'] : null;
}

/**
 * Check if the currently authenticated user is strictly field staff (Order Booker or Salesman)
 * and not an administrative or back-office user.
 *
 * @return bool
 */
function is_field_staff(): bool {
    if (!is_logged_in()) {
        return false;
    }
    $roles = $_SESSION['roles'] ?? [];
    // If user has administrative / back-office roles, they are full ERP users
    if (in_array('admin', $roles, true) || in_array('manager', $roles, true) || in_array('accountant', $roles, true) || in_array('operator', $roles, true)) {
        return false;
    }
    // If user has booker or salesman role, they are strictly field staff
    if (in_array('booker', $roles, true) || in_array('salesman', $roles, true)) {
        return true;
    }
    return false;
}

/**
 * Resolve linked salesman record for the authenticated user
 */
function get_current_salesman(): ?array {
    $userId = current_user_id();
    if (!$userId) return null;

    // 1. Check direct user_id link
    $sm = db_fetch_one("SELECT * FROM salesmen WHERE user_id = ? AND is_deleted = 0", [$userId]);
    if ($sm) return $sm;

    // 2. Link by phone or full_name / username
    $u = db_fetch_one("SELECT id, username, full_name, phone FROM users WHERE id = ?", [$userId]);
    if ($u) {
        if (!empty($u['phone'])) {
            $sm = db_fetch_one("SELECT * FROM salesmen WHERE phone = ? AND is_deleted = 0", [$u['phone']]);
            if ($sm) {
                db()->prepare("UPDATE salesmen SET user_id = ? WHERE id = ?")->execute([$userId, $sm['id']]);
                return $sm;
            }
        }
        $sm = db_fetch_one("SELECT * FROM salesmen WHERE (salesman_name = ? OR employee_code = ?) AND is_deleted = 0", [$u['full_name'], $u['username']]);
        if ($sm) {
            db()->prepare("UPDATE salesmen SET user_id = ? WHERE id = ?")->execute([$userId, $sm['id']]);
            return $sm;
        }
    }
    return null;
}

/**
 * Resolve linked booker record for the authenticated user
 */
function get_current_booker(): ?array {
    $userId = current_user_id();
    if (!$userId) return null;

    // 1. Check direct user_id link
    $bk = db_fetch_one("SELECT * FROM bookers WHERE user_id = ? AND is_deleted = 0", [$userId]);
    if ($bk) return $bk;

    // 2. Link by phone or full_name / username
    $u = db_fetch_one("SELECT id, username, full_name, phone FROM users WHERE id = ?", [$userId]);
    if ($u) {
        if (!empty($u['phone'])) {
            $bk = db_fetch_one("SELECT * FROM bookers WHERE phone = ? AND is_deleted = 0", [$u['phone']]);
            if ($bk) {
                db()->prepare("UPDATE bookers SET user_id = ? WHERE id = ?")->execute([$userId, $bk['id']]);
                return $bk;
            }
        }
        $bk = db_fetch_one("SELECT * FROM bookers WHERE (booker_name = ? OR booker_code = ?) AND is_deleted = 0", [$u['full_name'], $u['username']]);
        if ($bk) {
            db()->prepare("UPDATE bookers SET user_id = ? WHERE id = ?")->execute([$userId, $bk['id']]);
            return $bk;
        }
    }
    return null;
}

/**
 * Guard function to enforce authentication on pages and AJAX endpoints
 * Strictly restricts orderbookers and salesmen to the mobile app interface.
 *
 * @return void
 */
function require_login(): void {
    if (!is_logged_in()) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        
        if ($isAjax) {
            json_unauthorized('Your session has expired or you are not logged in. Please log in.');
        } else {
            set_flash_message('warning', 'Please sign in to access the system.');
            redirect(APP_URL . '/login.php');
        }
    }

    // STRICT BOUNDARY RESTRICTION FOR ORDER BOOKERS & SALESMEN:
    // They are strictly barred from traversing any desktop ERP page.
    if (is_field_staff()) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $isAllowed = (strpos($script, '/modules/app/') !== false)
                  || (strpos($script, '/app/') !== false)
                  || (str_ends_with($script, 'app.php'))
                  || (str_ends_with($script, 'logout.php'));

        if (!$isAllowed) {
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                      || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            if ($isAjax) {
                if (!headers_sent()) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied: Field staff accounts are restricted to the Mobile App.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect(APP_URL . '/modules/app/index.php');
        }
    }
}

/**
 * Safely inspect and auto-heal missing columns in the `users` table
 * Ensures login never fails due to schema drift or incomplete table setups.
 *
 * @return array List of column names present in the `users` table
 */
function get_users_table_columns(): array {
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }

    try {
        $db = db();
        $stmt = $db->query("SHOW COLUMNS FROM `users`");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (\Throwable $e) {
        $cols = ['id', 'username', 'email', 'password_hash', 'full_name'];
    }

    $definitions = [
        'phone'          => "VARCHAR(25) DEFAULT NULL AFTER `full_name`",
        'status'         => "ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `phone`",
        'last_login_at'  => "DATETIME DEFAULT NULL AFTER `status`",
        'login_attempts' => "INT NOT NULL DEFAULT 0 AFTER `last_login_at`",
        'locked_until'   => "DATETIME DEFAULT NULL AFTER `login_attempts`",
        'is_deleted'     => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `locked_until`"
    ];

    foreach ($definitions as $col => $def) {
        if (!in_array($col, $cols, true)) {
            try {
                $db->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$def}");
                $cols[] = $col;
            } catch (\Throwable $t) {
                // If ALTER TABLE fails due to restricted host permissions, allow dynamic query fallback
            }
        }
    }

    return $cols;
}

/**
 * Attempt user login with rate limiting & lockouts
 *
 * @param string $username
 * @param string $password
 * @return array ['success' => bool, 'message' => string]
 */
function attempt_login(string $username, string $password): array {
    $username = trim($username);
    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'Please provide both username and password.'];
    }

    try {
        $cols = get_users_table_columns();
        $hasDeleted   = in_array('is_deleted', $cols, true);
        $hasAttempts  = in_array('login_attempts', $cols, true);
        $hasLocked    = in_array('locked_until', $cols, true);
        $hasStatus    = in_array('status', $cols, true);
        $hasLastLogin = in_array('last_login_at', $cols, true);

        // Build SELECT dynamically based on existing columns
        $fields = ['id', 'username', 'email', 'password_hash', 'full_name'];
        if ($hasStatus)   $fields[] = 'status';
        if ($hasAttempts) $fields[] = 'login_attempts';
        if ($hasLocked)   $fields[] = 'locked_until';

        $where = "WHERE username = ?";
        if ($hasDeleted) {
            $where .= " AND is_deleted = 0";
        }

        $sql = "SELECT " . implode(', ', $fields) . " FROM users {$where} LIMIT 1";
        $stmt = db()->prepare($sql);
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        // Fill safe defaults for any missing columns
        if (!isset($user['status'])) $user['status'] = 'active';
        if (!isset($user['login_attempts'])) $user['login_attempts'] = 0;
        if (!isset($user['locked_until'])) $user['locked_until'] = null;

        // Check account status
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'This account is currently deactivated. Please contact an administrator.'];
        }

        // Check account lockout
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'message' => "Account locked due to multiple failed attempts. Try again in {$remaining} minutes."];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $attempts = (int)$user['login_attempts'] + 1;
            $lockedUntil = null;
            if ($attempts >= 5) {
                $lockedUntil = date('Y-m-d H:i:s', time() + (15 * 60)); // Lock for 15 minutes
            }

            if ($hasAttempts && $hasLocked) {
                $upd = db()->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?");
                $upd->execute([$attempts, $lockedUntil, $user['id']]);
            } elseif ($hasAttempts) {
                $upd = db()->prepare("UPDATE users SET login_attempts = ? WHERE id = ?");
                $upd->execute([$attempts, $user['id']]);
            }

            log_audit('login_failed', 'auth', $user['id'], "Failed login attempt ({$attempts}) for user: {$username}");
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        // Password verified - reset attempts and update login time
        $updateSets = [];
        $updateParams = [];
        if ($hasAttempts) {
            $updateSets[] = "login_attempts = 0";
        }
        if ($hasLocked) {
            $updateSets[] = "locked_until = NULL";
        }
        if ($hasLastLogin) {
            $updateSets[] = "last_login_at = NOW()";
        }

        if (!empty($updateSets)) {
            $updSql = "UPDATE users SET " . implode(', ', $updateSets) . " WHERE id = ?";
            $updateParams[] = $user['id'];
            $upd = db()->prepare($updSql);
            $upd->execute($updateParams);
        }

        // Load roles
        $stmtRoles = db()->prepare("
            SELECT r.id, r.role_code, r.role_name 
            FROM roles r 
            INNER JOIN user_roles ur ON ur.role_id = r.id 
            WHERE ur.user_id = ?
        ");
        $stmtRoles->execute([$user['id']]);
        $roles = $stmtRoles->fetchAll();
        $roleCodes = array_column($roles, 'role_code');

        // Load permissions
        $stmtPerms = db()->prepare("
            SELECT DISTINCT p.permission_code 
            FROM permissions p 
            INNER JOIN role_permissions rp ON rp.permission_id = p.id 
            INNER JOIN user_roles ur ON ur.role_id = rp.role_id 
            WHERE ur.user_id = ?
        ");
        $stmtPerms->execute([$user['id']]);
        $permissions = $stmtPerms->fetchAll(PDO::FETCH_COLUMN);

        // Regenerate session ID to prevent session fixation if headers not sent
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id']     = (int)$user['id'];
        $_SESSION['username']    = $user['username'];
        $_SESSION['full_name']   = $user['full_name'];
        $_SESSION['email']       = $user['email'];
        $_SESSION['roles']       = $roleCodes;
        $_SESSION['permissions'] = $permissions;

        log_audit('login_success', 'auth', $user['id'], "User signed in: {$username}");
        return ['success' => true, 'message' => 'Login successful.'];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Login processing error: ' . $e->getMessage()];
    }
}

/**
 * Log out current user
 *
 * @return void
 */
function logout_user(): void {
    if (is_logged_in()) {
        log_audit('logout', 'auth', $_SESSION['user_id'], "User signed out: {$_SESSION['username']}");
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
}
