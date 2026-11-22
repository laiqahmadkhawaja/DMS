<?php
/**
 * DMS - Bulk Category Updater Shortcut
 */
require_once __DIR__ . '/../../config/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
redirect(APP_URL . '/modules/categories/bulk_update.php' . $qs);
