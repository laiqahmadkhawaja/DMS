<?php
/**
 * DMS - Distribution Management System
 * Global Footer, Keyboard Bar & Script Inclusions
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}
?>
    </main>

    <!-- Bottom Keyboard Shortcut Status Bar -->
    <div class="dms-shortcut-bar no-print">
        <div><span class="shortcut-pill">F1</span> Help</div>
        <div><span class="shortcut-pill">F2</span> Search</div>
        <div><span class="shortcut-pill">F6</span> New Order</div>
        <div><span class="shortcut-pill">F7</span> New Invoice</div>
        <div><span class="shortcut-pill">F8</span> Settlement</div>
        <div><span class="shortcut-pill">F9</span> / <span class="shortcut-pill">Ctrl+S</span> Save</div>
        <div><span class="shortcut-pill">F10</span> Print</div>
        <div><span class="shortcut-pill">ESC</span> Close Panel</div>
        <div class="ms-auto text-secondary small">
            DMS v<?= APP_VERSION ?> | <?= e(CLIENT_NAME) ?>
        </div>
    </div>
</div> <!-- .dms-main -->
</div> <!-- .dms-wrapper -->

<!-- Non-Modal Slide-out Drawer: Keyboard Shortcuts Guide (F1) -->
<div id="drawer-shortcuts-help" class="dms-drawer">
    <div class="dms-drawer-header">
        <span><i class="bi bi-keyboard me-2"></i> Keyboard Shortcuts Guide</span>
        <button type="button" class="btn-close" onclick="closeDrawer()"></button>
    </div>
    <div class="dms-drawer-body">
        <p class="text-muted small">Fast keyboard operations designed for high-speed office order entry and cashier settlement:</p>
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr><th>Key</th><th>Action</th></tr>
            </thead>
            <tbody>
                <tr><td><span class="shortcut-pill">F1</span></td><td>Open / Close this Shortcuts Reference</td></tr>
                <tr><td><span class="shortcut-pill">F2</span></td><td>Focus and select the main search input</td></tr>
                <tr><td><span class="shortcut-pill">F6</span></td><td>Jump directly to New Order Booking screen</td></tr>
                <tr><td><span class="shortcut-pill">F7</span></td><td>Jump directly to New Sales Invoice screen</td></tr>
                <tr><td><span class="shortcut-pill">F8</span></td><td>Jump directly to Route Settlement screen</td></tr>
                <tr><td><span class="shortcut-pill">F9 / Ctrl+S</span></td><td>Save and submit currently open form</td></tr>
                <tr><td><span class="shortcut-pill">F10</span></td><td>Print current document / report</td></tr>
                <tr><td><span class="shortcut-pill">Enter</span></td><td>Move to next line / Add row in order entry</td></tr>
                <tr><td><span class="shortcut-pill">ESC</span></td><td>Close side panel / cancel action</td></tr>
            </tbody>
        </table>
        <div class="alert alert-info py-2 small mt-3">
            <i class="bi bi-info-circle me-1"></i> No modal popup windows are used in DMS to maintain keyboard-driven focus and uninterrupted data entry.
        </div>
    </div>
    <div class="dms-drawer-footer">
        <button type="button" class="btn btn-sm btn-secondary" onclick="closeDrawer()">Close (ESC)</button>
    </div>
</div>

<!-- Local Scripts (No CDNs) -->
<script src="<?= APP_URL ?>/assets/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
