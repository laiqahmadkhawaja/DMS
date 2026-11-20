<?php
/**
 * DMS - Distribution Management System
 * Role & Permission Enforcement
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

/**
 * Check if the current user possesses a permission
 *
 * @param string $permissionCode
 * @return bool
 */
function has_permission(string $permissionCode): bool {
    if (!is_logged_in()) {
        return false;
    }
    // Super administrator has unrestricted access
    if (in_array('admin', $_SESSION['roles'] ?? [], true)) {
        return true;
    }
    return in_array($permissionCode, $_SESSION['permissions'] ?? [], true);
}

/**
 * Check if the current user belongs to a specific role
 *
 * @param string $roleCode
 * @return bool
 */
function has_role(string $roleCode): bool {
    if (!is_logged_in()) {
        return false;
    }
    return in_array($roleCode, $_SESSION['roles'] ?? [], true);
}

/**
 * Guard function to enforce permission access
 *
 * @param string $permissionCode
 * @return void
 */
function require_permission(string $permissionCode): void {
    require_login();
    if (!has_permission($permissionCode)) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        
        if ($isAjax) {
            json_unauthorized('Access denied. You lack the permission: ' . $permissionCode);
        } else {
            set_flash_message('danger', 'Access denied. You do not possess permission for that operation.');
            redirect(APP_URL . '/dashboard.php');
        }
    }
}
