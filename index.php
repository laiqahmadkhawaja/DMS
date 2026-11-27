<?php
/**
 * DMS - Distribution Management System
 * Application Entry Point & Route Dispatcher
 */

require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    if (is_field_staff()) {
        redirect(APP_URL . '/modules/app/index.php');
    }
    redirect(APP_URL . '/dashboard.php');
} else {
    redirect(APP_URL . '/login.php');
}
