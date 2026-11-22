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

    // 6. Sidebar Scroll Preservation & Auto-scroll to Active Link
    function initSidebarScroll() {
        const sidebarMenu = document.querySelector('.dms-sidebar-menu');
        if (!sidebarMenu) return;

        const activeItem = sidebarMenu.querySelector('.dms-menu-item.active');
        const savedScroll = sessionStorage.getItem('dms_sidebar_scroll');

        let restored = false;
        if (savedScroll !== null) {
            const scrollVal = parseInt(savedScroll, 10);
            if (!isNaN(scrollVal)) {
                sidebarMenu.scrollTop = scrollVal;
                // Verify if active item is comfortably visible in the menu viewport
                if (activeItem) {
                    const menuRect = sidebarMenu.getBoundingClientRect();
                    const itemRect = activeItem.getBoundingClientRect();
                    if (itemRect.top >= menuRect.top + 10 && itemRect.bottom <= menuRect.bottom - 10) {
                        restored = true;
                    }
                } else {
                    restored = true;
                }
            }
        }

        // If not restored or active item is outside visible range, scroll it into view (centered)
        if (!restored && activeItem) {
            const menuRect = sidebarMenu.getBoundingClientRect();
            const itemRect = activeItem.getBoundingClientRect();
            const relativeTop = itemRect.top - menuRect.top + sidebarMenu.scrollTop;
            const targetScrollTop = relativeTop - (menuRect.height / 2) + (itemRect.height / 2);
            sidebarMenu.scrollTop = Math.max(0, targetScrollTop);
        }
    }

    // Capture sidebar scroll position whenever a user clicks any sidebar link
    document.addEventListener('click', function (e) {
        const link = e.target.closest('.dms-sidebar-menu a');
        if (link) {
            const sidebarMenu = document.querySelector('.dms-sidebar-menu');
            if (sidebarMenu) {
                sessionStorage.setItem('dms_sidebar_scroll', sidebarMenu.scrollTop);
            }
        }
    });

    // Also capture sidebar scroll before page unload
    window.addEventListener('beforeunload', function () {
        const sidebarMenu = document.querySelector('.dms-sidebar-menu');
        if (sidebarMenu) {
            sessionStorage.setItem('dms_sidebar_scroll', sidebarMenu.scrollTop);
        }
    });

    // 7. Universal Searchable Select Component (For Customer & Party Selects)
    window.initSearchableSelect = function (select) {
        if (!select || select.dataset.dmsSearchableInit === 'true') return;
        select.dataset.dmsSearchableInit = 'true';

        const isSmall = select.classList.contains('form-select-sm') || select.classList.contains('form-control-sm');
        const wasRequired = select.hasAttribute('required');
        if (wasRequired) {
            select.dataset.wasRequired = 'true';
            select.removeAttribute('required');
        }

        // Keep native select in DOM for form submission & scripts, but hide visually
        select.style.position = 'absolute';
        select.style.opacity = '0';
        select.style.pointerEvents = 'none';
        select.style.height = '0';
        select.style.width = '0';
        select.style.margin = '0';
        select.style.padding = '0';
        select.style.border = 'none';
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        // Create container wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'dms-searchable-wrapper';
        if (select.parentElement && select.parentElement.classList.contains('input-group')) {
            wrapper.classList.add('flex-grow-1');
        }

        // Insert wrapper before select and move select inside wrapper
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        // Create Display Control Element
        const control = document.createElement('div');
        control.className = `dms-searchable-control ${isSmall ? 'form-select-sm' : ''}`;
        control.setAttribute('tabindex', '0');
        control.setAttribute('role', 'combobox');
        control.setAttribute('aria-expanded', 'false');

        const label = document.createElement('span');
        label.className = 'dms-searchable-label';
        control.appendChild(label);

        const actions = document.createElement('div');
        actions.className = 'dms-searchable-actions';

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'dms-searchable-clear';
        clearBtn.innerHTML = '&times;';
        clearBtn.title = 'Clear selection';
        clearBtn.tabIndex = -1;
        clearBtn.style.display = 'none';
        actions.appendChild(clearBtn);

        const chevron = document.createElement('i');
        chevron.className = 'bi bi-chevron-down dms-searchable-chevron';
        actions.appendChild(chevron);

        control.appendChild(actions);
        wrapper.appendChild(control);

        // Create Dropdown Element
        const dropdown = document.createElement('div');
        dropdown.className = 'dms-searchable-dropdown shadow';

        const searchWrapper = document.createElement('div');
        searchWrapper.className = 'dms-searchable-search-wrapper';
        searchWrapper.innerHTML = `
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white text-muted border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control border-start-0 dms-searchable-search-input" placeholder="Type to search (shop, area, code)..." autocomplete="off" spellcheck="false">
                <button type="button" class="btn btn-outline-secondary dms-searchable-search-clear border-start-0" style="display:none;" tabindex="-1">&times;</button>
            </div>
        `;
        dropdown.appendChild(searchWrapper);

        const searchInput = searchWrapper.querySelector('.dms-searchable-search-input');
        const searchClear = searchWrapper.querySelector('.dms-searchable-search-clear');

        const meta = document.createElement('div');
        meta.className = 'dms-searchable-meta';
        meta.innerHTML = `
            <span class="dms-searchable-count">Loading...</span>
            <span class="text-muted d-none d-sm-inline">Use &uarr;&darr; keys &bull; Enter to select</span>
        `;
        dropdown.appendChild(meta);
        const countSpan = meta.querySelector('.dms-searchable-count');

        const menu = document.createElement('div');
        menu.className = 'dms-searchable-menu';
        menu.setAttribute('role', 'listbox');
        dropdown.appendChild(menu);

        wrapper.appendChild(dropdown);

        let optionsData = [];
        let focusedIndex = -1;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function highlightMatch(text, query) {
            if (!query || !text) return escapeHtml(text);
            const safeQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp(`(${safeQuery})`, 'gi');
            return escapeHtml(text).replace(regex, '<span class="dms-searchable-highlight">$1</span>');
        }

        function buildOptionsData() {
            optionsData = [];
            const optElements = select.querySelectorAll('option');
            optElements.forEach((opt, idx) => {
                const val = opt.value;
                const rawText = opt.textContent.trim();
                const balanceAttr = opt.getAttribute('data-balance');
                const balance = balanceAttr !== null && balanceAttr !== '' ? parseFloat(balanceAttr) : null;
                const code = opt.getAttribute('data-code') || '';
                const area = opt.getAttribute('data-area') || '';
                const phone = opt.getAttribute('data-phone') || '';
                const owner = opt.getAttribute('data-owner') || '';

                let title = rawText;
                let subtitle = '';
                if (rawText.includes('(') && rawText.includes(')')) {
                    const match = rawText.match(/^(.*?)\s*\((.*?)\)(.*)$/);
                    if (match) {
                        title = match[1].trim();
                        subtitle = '(' + match[2].trim() + ')' + (match[3] ? ' ' + match[3].trim() : '');
                    }
                }

                const searchTokens = (rawText + ' ' + code + ' ' + area + ' ' + phone + ' ' + owner).toLowerCase();

                optionsData.push({
                    index: idx,
                    value: val,
                    rawText: rawText,
                    title: title,
                    subtitle: subtitle,
                    balance: balance,
                    code: code,
                    searchTokens: searchTokens
                });
            });
        }

        function renderMenu(query = '') {
            menu.innerHTML = '';
            const cleanQuery = query.trim().toLowerCase();
            let visibleCount = 0;

            optionsData.forEach((item) => {
                const matches = !cleanQuery || item.searchTokens.includes(cleanQuery);
                if (!matches) return;

                visibleCount++;
                const isSelected = (select.value === item.value) && (item.value !== '' || select.selectedIndex === item.index);

                const itemEl = document.createElement('div');
                itemEl.className = `dms-searchable-item ${isSelected ? 'is-selected' : ''}`;
                itemEl.setAttribute('role', 'option');
                itemEl.setAttribute('data-value', item.value);
                itemEl.setAttribute('data-index', item.index);

                if (item.value === '') {
                    itemEl.innerHTML = `<div class="dms-item-main text-muted fst-italic">${escapeHtml(item.rawText)}</div>`;
                } else {
                    let badgeHtml = '';
                    if (item.balance !== null && !isNaN(item.balance)) {
                        if (item.balance > 0) {
                            badgeHtml = `<span class="dms-item-badge dms-item-badge-danger">Bal: Rs. ${item.balance.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 2})}</span>`;
                        } else if (item.balance < 0) {
                            badgeHtml = `<span class="dms-item-badge dms-item-badge-success">Adv: Rs. ${Math.abs(item.balance).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 2})}</span>`;
                        } else {
                            badgeHtml = `<span class="dms-item-badge dms-item-badge-secondary">Clear (0.00)</span>`;
                        }
                    }

                    itemEl.innerHTML = `
                        <div class="dms-item-main">
                            <div class="dms-item-title">${highlightMatch(item.title, cleanQuery)}</div>
                            ${item.subtitle ? `<div class="dms-item-sub">${highlightMatch(item.subtitle, cleanQuery)}</div>` : ''}
                        </div>
                        ${badgeHtml}
                    `;
                }

                itemEl.addEventListener('click', (e) => {
                    e.stopPropagation();
                    selectValue(item.value, true);
                    closeDropdown();
                    advanceFocusAfterSelection();
                });

                menu.appendChild(itemEl);
            });

            if (visibleCount === 0) {
                menu.innerHTML = `<div class="dms-searchable-empty"><i class="bi bi-search me-1"></i> No customer matching "<strong>${escapeHtml(query)}</strong>"</div>`;
                countSpan.textContent = '0 results';
            } else {
                countSpan.textContent = cleanQuery ? `${visibleCount} found` : `${visibleCount} customers`;
            }

            focusedIndex = -1;
        }

        function syncFromSelect() {
            const curVal = select.value;
            const curOpt = select.options[select.selectedIndex];
            if (curOpt && curVal) {
                label.textContent = curOpt.textContent.trim();
                label.classList.remove('placeholder');
                clearBtn.style.display = 'inline-flex';
                control.classList.remove('is-invalid');
            } else {
                const placeholder = curOpt ? curOpt.textContent.trim() : '-- Select Customer --';
                label.textContent = placeholder;
                label.classList.add('placeholder');
                clearBtn.style.display = 'none';
            }
        }

        function selectValue(val, triggerChange = true) {
            select.value = val;
            syncFromSelect();

            if (triggerChange) {
                if (typeof select.onchange === 'function') {
                    select.onchange.call(select, new Event('change'));
                }
                select.dispatchEvent(new Event('change', { bubbles: true }));
                select.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        function openDropdown() {
            document.querySelectorAll('.dms-searchable-wrapper.is-open').forEach(w => {
                if (w !== wrapper) {
                    w.classList.remove('is-open');
                    const dd = w.querySelector('.dms-searchable-dropdown');
                    if (dd) dd.style.display = 'none';
                }
            });

            wrapper.classList.add('is-open');
            control.setAttribute('aria-expanded', 'true');
            dropdown.style.display = 'flex';
            renderMenu(searchInput.value);

            setTimeout(() => {
                searchInput.focus();
                searchInput.select();
            }, 30);
        }

        function closeDropdown() {
            wrapper.classList.remove('is-open');
            control.setAttribute('aria-expanded', 'false');
            dropdown.style.display = 'none';
            searchInput.value = '';
            searchClear.style.display = 'none';
            focusedIndex = -1;
        }

        function advanceFocusAfterSelection() {
            control.blur();
            const productSearch = document.getElementById('productSearch');
            if (productSearch) {
                setTimeout(() => {
                    productSearch.focus();
                    if (typeof productSearch.select === 'function') {
                        productSearch.select();
                    }
                }, 50);
                return;
            }
            const form = select.form;
            if (form) {
                const formElements = Array.from(form.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button[type="submit"]:not([disabled])'));
                const nextElem = formElements.find(el => el !== select && !wrapper.contains(el));
                if (nextElem) {
                    setTimeout(() => nextElem.focus(), 50);
                }
            }
        }

        // Toggle dropdown on control click
        control.addEventListener('click', (e) => {
            if (e.target === clearBtn || clearBtn.contains(e.target)) return;
            if (wrapper.classList.contains('is-open')) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        // Clear button action
        clearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            selectValue('', true);
            closeDropdown();
            control.blur();
        });

        // Control keyboard triggers: Only Enter or Space opens dropdown (Arrow keys do NOT open)
        control.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDropdown();
            }
        });

        // Search input typing
        searchInput.addEventListener('input', () => {
            const q = searchInput.value;
            searchClear.style.display = q ? 'inline-block' : 'none';
            renderMenu(q);
        });

        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            searchClear.style.display = 'none';
            renderMenu('');
            searchInput.focus();
        });

        // Search input keyboard navigation
        searchInput.addEventListener('keydown', (e) => {
            const visibleItems = menu.querySelectorAll('.dms-searchable-item');
            if (visibleItems.length === 0) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeDropdown();
                    control.blur();
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusedIndex++;
                if (focusedIndex >= visibleItems.length) focusedIndex = 0;
                updateFocusedItem(visibleItems);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusedIndex--;
                if (focusedIndex < 0) focusedIndex = visibleItems.length - 1;
                updateFocusedItem(visibleItems);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (focusedIndex >= 0 && focusedIndex < visibleItems.length) {
                    visibleItems[focusedIndex].click();
                } else if (visibleItems.length > 0) {
                    visibleItems[0].click();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown();
                control.blur();
            } else if (e.key === 'Tab') {
                closeDropdown();
            }
        });

        function updateFocusedItem(visibleItems) {
            visibleItems.forEach((el, i) => {
                if (i === focusedIndex) {
                    el.classList.add('is-focused');
                    el.scrollIntoView({ block: 'nearest' });
                } else {
                    el.classList.remove('is-focused');
                }
            });
        }

        // Close on clicking outside
        document.addEventListener('click', (e) => {
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        // Handle external changes to select
        select.addEventListener('change', () => {
            syncFromSelect();
        });

        // Form submit validation for required selects
        const parentForm = select.form;
        if (parentForm && wasRequired) {
            parentForm.addEventListener('submit', (e) => {
                if (!select.value || select.value === '0') {
                    e.preventDefault();
                    e.stopPropagation();
                    control.classList.add('is-invalid');
                    openDropdown();
                    if (typeof window.showToast === 'function') {
                        window.showToast('Please select a customer before submitting.', 'warning');
                    }
                    return false;
                } else {
                    control.classList.remove('is-invalid');
                }
            });
        }

        // MutationObserver to auto-update when options change dynamically
        const observer = new MutationObserver(() => {
            buildOptionsData();
            syncFromSelect();
            if (wrapper.classList.contains('is-open')) {
                renderMenu(searchInput.value);
            }
        });
        observer.observe(select, { childList: true, subtree: true });

        // Initial setup
        buildOptionsData();
        syncFromSelect();

        // Transfer autofocus if present
        if (select.hasAttribute('autofocus')) {
            setTimeout(() => control.focus(), 60);
        }
    };

    window.refreshSearchableSelect = function (select) {
        if (select && select.dataset.dmsSearchableInit === 'true') {
            select.dispatchEvent(new Event('change'));
        }
    };

    function initAllCustomerSelects() {
        const customerSelects = document.querySelectorAll(
            'select[name="customer_id"], select#customerSelect, select.searchable-customer, select.searchable-select, select[data-searchable="customer"]'
        );
        customerSelects.forEach(s => window.initSearchableSelect(s));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initSidebarScroll();
            initAllCustomerSelects();
        });
    } else {
        initSidebarScroll();
        initAllCustomerSelects();
    }
    requestAnimationFrame(initSidebarScroll);

})();
