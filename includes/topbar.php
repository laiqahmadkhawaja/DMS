<?php
/**
 * DMS - Distribution Management System
 * Top Navigation Bar Layout
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

// Ensure sidebar is rendered in Column 1 before opening Column 2 (.dms-main)
if (!defined('DMS_SIDEBAR_RENDERED')) {
    require_once __DIR__ . '/sidebar.php';
}

if (defined('DMS_TOPBAR_RENDERED')) {
    return;
}
define('DMS_TOPBAR_RENDERED', true);

$user = current_user();
?>
<div class="dms-main">
    <header class="dms-topbar">
        <div class="dms-topbar-left d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-light border py-1 px-2 me-2 dms-sidebar-toggle-btn shadow-none" id="dmsSidebarToggle" title="Toggle Sidebar (Ctrl+B)">
                <i class="bi bi-list fs-5 lh-1"></i>
            </button>
            <span class="fw-semibold text-secondary d-none d-sm-inline">
                <i class="bi bi-calendar3 me-1"></i> <?= date('D, d M Y') ?>
            </span>
            <span class="badge bg-light text-dark border ms-2">
                <i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= e(get_setting('city', 'Gujranwala')) ?>
            </span>
        </div>
        
        <div class="dms-topbar-right">
            <!-- Quick Actions -->
            <a href="<?= APP_URL ?>/modules/orders/create.php" class="btn btn-sm btn-outline-primary py-1 px-2" title="New Booking Order (F6)">
                <i class="bi bi-plus-circle me-1"></i> Order (F6)
            </a>
            <a href="<?= APP_URL ?>/modules/invoices/create.php" class="btn btn-sm btn-outline-success py-1 px-2" title="New Sales Invoice (F7)">
                <i class="bi bi-receipt me-1"></i> Bill (F7)
            </a>
            <a href="<?= APP_URL ?>/modules/settlements/create.php" class="btn btn-sm btn-outline-warning text-dark py-1 px-2" title="Daily Route Settlement (F8)">
                <i class="bi bi-cash-stack me-1"></i> Settlement (F8)
            </a>

            <!-- Keyboard Help Button -->
            <button type="button" class="btn btn-sm btn-light border py-1 px-2 text-secondary" onclick="openDrawer('drawer-shortcuts-help')" title="Keyboard Shortcuts (F1)">
                <i class="bi bi-keyboard"></i> F1
            </button>

            <!-- User Menu -->
            <div class="d-flex align-items-center ms-2 ps-2 border-start">
                <div class="text-end me-2 d-none d-md-block">
                    <div class="fw-semibold small leading-none"><?= e($user['full_name'] ?? 'User') ?></div>
                    <small class="text-muted" style="font-size:0.7rem;"><?= e(strtoupper(implode(', ', $user['roles'] ?? ['USER']))) ?></small>
                </div>
                <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-outline-danger py-1 px-2" title="Sign Out">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Dynamic Content Wrapper -->
    <main class="dms-content">
        <!-- Flash Notifications -->
        <?php foreach (get_flash_messages() as $msg): ?>
            <div class="alert alert-<?= e($msg['type']) ?> alert-dismissible fade show py-2 px-3 small mb-3" role="alert">
                <?= e($msg['message']) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
