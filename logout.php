<?php
/**
 * DMS - Distribution Management System
 * Logout Handler
 */

require_once __DIR__ . '/config/config.php';

logout_user();
set_flash_message('info', 'You have been signed out safely.');
redirect(APP_URL . '/login.php');
