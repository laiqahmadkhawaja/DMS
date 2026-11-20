<?php
/**
 * DMS - Distribution Management System
 * Application Entry Point & Route Dispatcher
 */

require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect(APP_URL . '/dashboard.php');
} else {
    redirect(APP_URL . '/login.php');
}
