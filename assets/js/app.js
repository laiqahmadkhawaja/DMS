/**
 * DMS - Distribution Management System
 * Core Client-Side Application Script
 * Keyboard shortcuts, Non-modal UI, AJAX handlers
 */

(function () {
    'use strict';

    // 1. Toast Notification System
    window.showToast = function (message, type = 'success') {
        let container = document.getElementById('dms-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'dms-toast-container';
            container.className = 'dms-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `dms-toast dms-toast-${type}`;
        toast.innerHTML = `
            <div>${message}</div>
            <button type="button" class="btn-close btn-close-white ms-2" style="font-size:0.65rem;" onclick="this.parentElement.remove()"></button>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }
        }, 4000);
    };

    // 2. Non-Modal Drawer System
    window.openDrawer = function (drawerId) {
        const drawer = document.getElementById(drawerId);
        let backdrop = document.getElementById('dms-drawer-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'dms-drawer-backdrop';
            backdrop.className = 'dms-drawer-backdrop';
            backdrop.onclick = window.closeDrawer;
            document.body.appendChild(backdrop);
        }
        if (drawer) {
            backdrop.style.display = 'block';
            drawer.classList.add('open');
            const firstInput = drawer.querySelector('input, select, textarea');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 250);
            }
        }
    };

    window.closeDrawer = function () {
        const openDrawers = document.querySelectorAll('.dms-drawer.open');
        openDrawers.forEach(d => d.classList.remove('open'));
        const backdrop = document.getElementById('dms-drawer-backdrop');
        if (backdrop) {
            backdrop.style.display = 'none';
        }
    };

    // 3. Non-Modal Inline Confirmation
    window.dmsConfirm = function (message, onConfirm) {
        if (window.confirm(message)) {
            onConfirm();
        }
    };

    // 4. Centralized Fetch / AJAX Handler
    window.dmsFetch = async function (url, options = {}) {
        options.headers = options.headers || {};
        options.headers['X-Requested-With'] = 'XMLHttpRequest';

        try {
            const response = await fetch(url, options);
            const contentType = response.headers.get('content-type') || '';

            if (!contentType.includes('application/json')) {
                const text = await response.text();
                console.error("Non-JSON Server Output:", text);
                showToast("Server returned invalid response format. Check server logs.", "danger");
                return { success: false, message: "Invalid server response" };
            }

            const data = await response.json();
            if (!data.success && data.message) {
                showToast(data.message, "danger");
            }
            return data;
        } catch (err) {
            console.error("Fetch Exception:", err);
            showToast("Network request failed. Please check connection.", "danger");
            return { success: false, message: err.message };
        }
    };

    // 5. Global Keyboard Shortcuts Handler
    document.addEventListener('keydown', function (e) {
        // Do not intercept standard input typing unless function keys or Ctrl combos
        const tag = (e.target.tagName || '').toLowerCase();
        const isInput = (tag === 'input' || tag === 'textarea' || tag === 'select');

        // F1: Help / Shortcuts modal replacement
        if (e.key === 'F1') {
            e.preventDefault();
            const helpDrawer = document.getElementById('drawer-shortcuts-help');
            if (helpDrawer) openDrawer('drawer-shortcuts-help');
            return;
        }

        // F2: Focus main search input
        if (e.key === 'F2') {
            e.preventDefault();
            const searchInput = document.querySelector('.dms-search-input, input[type="search"]');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
            return;
        }

        // F6: New Order Booking
        if (e.key === 'F6') {
            e.preventDefault();
            window.location.href = window.DMS_APP_URL + '/modules/orders/create.php';
            return;
        }

        // F7: New Sales Invoice
        if (e.key === 'F7') {
            e.preventDefault();
            window.location.href = window.DMS_APP_URL + '/modules/invoices/create.php';
            return;
        }

        // F8: Daily Route Settlement
        if (e.key === 'F8') {
            e.preventDefault();
            window.location.href = window.DMS_APP_URL + '/modules/settlements/create.php';
            return;
        }

        // F9 or Ctrl+S: Save current form
        if (e.key === 'F9' || (e.ctrlKey && e.key.toLowerCase() === 's')) {
            e.preventDefault();
            const submitBtn = document.querySelector('form button[type="submit"], form .btn-save-dms');
            if (submitBtn) {
                submitBtn.click();
            }
            return;
        }

        // F10: Print
        if (e.key === 'F10') {
            e.preventDefault();
            const printBtn = document.querySelector('.btn-print-dms');
            if (printBtn) {
                printBtn.click();
            } else {
                window.print();
            }
            return;
        }

        // Escape: Close drawer
        if (e.key === 'Escape') {
            window.closeDrawer();
        }
    });

    // Auto-dismiss server flash messages
    document.addEventListener('DOMContentLoaded', () => {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.4s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 400);
            }, 5000);
        });
    });

})();
