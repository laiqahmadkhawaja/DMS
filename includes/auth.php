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
 * Guard function to enforce authentication on pages and AJAX endpoints
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
        $stmt = db()->prepare("
            SELECT id, username, email, password_hash, full_name, status, login_attempts, locked_until 
            FROM users 
            WHERE username = ? AND is_deleted = 0 
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

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
            $upd = db()->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?");
            $upd->execute([$attempts, $lockedUntil, $user['id']]);

            log_audit('login_failed', 'auth', $user['id'], "Failed login attempt ({$attempts}) for user: {$username}");
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        // Password verified - reset attempts and update login time
        $upd = db()->prepare("
            UPDATE users 
            SET login_attempts = 0, locked_until = NULL, last_login_at = NOW() 
            WHERE id = ?
        ");
        $upd->execute([$user['id']]);

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
