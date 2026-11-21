<?php
/**
 * DMS - Distribution Management System
 * Global HTML Head & Header Layout
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$pageTitle = $pageTitle ?? APP_NAME;
$activeMenu = get_active_menu();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(CLIENT_NAME) ?></title>
    
    <!-- Local Offline Bootstrap 5 & Bootstrap Icons (No CDNs) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/icons/bootstrap-icons.css">
    
    <!-- Custom DMS Desktop Light/Navy Styling -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    
    <script>
        window.DMS_APP_URL = "<?= APP_URL ?>";
    </script>
</head>
<body>
<div class="dms-wrapper">
