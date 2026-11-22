<?php
/**
 * DMS - Distribution Management System
 * Global Slide-out Drawer: Quick Register Retailer / Customer in Real-Time
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$drawerAreas = db_fetch_all("SELECT id, area_name, city FROM areas WHERE is_deleted = 0 AND is_active = 1 ORDER BY area_name ASC");
$drawerBookers = db_fetch_all("SELECT id, booker_name FROM bookers WHERE is_deleted = 0 AND is_active = 1 ORDER BY booker_name ASC");
?>
<!-- Non-Modal Slide-out Drawer: Quick Add Retailer / Customer -->
<div id="drawer-quick-add-customer" class="dms-drawer">
    <div class="dms-drawer-header bg-navy text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shop text-warning me-2"></i> Quick Register Retailer / Shop</span>
        <button type="button" class="btn-close btn-close-white" onclick="closeDrawer()"></button>
    </div>
    <div class="dms-drawer-body p-3">
        <p class="text-muted small mb-3">
            Register a new retail customer on the fly. It will be immediately saved to the master database and selected in your order or invoice without losing entered products.
        </p>

        <form id="quickCustomerForm" onsubmit="event.preventDefault(); submitQuickCustomerForm();" autocomplete="off">
            <div class="mb-2">
                <label class="form-label small fw-bold text-dark mb-1">
                    Shop / Store Name <span class="text-danger">*</span>
                </label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-shop"></i></span>
                    <input type="text" id="quickCustShopName" class="form-control" placeholder="e.g. Madina Karyana Store" required autofocus>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small fw-bold text-dark mb-1">Owner / Contact</label>
                    <input type="text" id="quickCustOwnerName" class="form-control form-control-sm" placeholder="Owner name">
                </div>
                <div class="col-6">
                    <label class="form-label small fw-bold text-dark mb-1">Phone Number <span class="text-danger">*</span></label>
                    <input type="text" id="quickCustPhone" class="form-control form-control-sm" placeholder="0300-1234567" required>
                </div>
            </div>

            <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-bold text-dark mb-0">
                        Delivery Route / Area <span class="text-danger">*</span>
                    </label>
                    <?php if (get_setting('allow_realtime_routes', '1') == '1'): ?>
                    <a href="javascript:void(0)" onclick="openQuickAreaDrawer('quickCustArea')" class="text-primary text-decoration-none small" style="font-size: 0.75rem;">
                        <i class="bi bi-plus-circle-fill"></i> New Route
                    </a>
                    <?php endif; ?>
                </div>
                <select id="quickCustArea" class="form-select form-select-sm" required>
                    <option value="">-- Choose Route / Area --</option>
                    <?php foreach ($drawerAreas as $da): ?>
                        <option value="<?= $da['id'] ?>"><?= htmlspecialchars($da['area_name']) ?> (<?= htmlspecialchars($da['city']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label small fw-bold text-dark mb-1">Order Booker (Optional)</label>
                <select id="quickCustBooker" class="form-select form-select-sm">
                    <option value="">-- Assigned Booker --</option>
                    <?php foreach ($drawerBookers as $dbk): ?>
                        <option value="<?= $dbk['id'] ?>"><?= htmlspecialchars($dbk['booker_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label small fw-bold text-dark mb-1">Shop Address / Landmark</label>
                <input type="text" id="quickCustAddress" class="form-control form-control-sm" placeholder="Main Bazar, Opposite Jamia Masjid...">
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-bold text-dark mb-1">Credit Limit (Rs.)</label>
                    <input type="number" id="quickCustCreditLimit" class="form-control form-control-sm text-end" value="0.00" step="100" min="0">
                </div>
                <div class="col-6">
                    <label class="form-label small fw-bold text-dark mb-1">Opening Balance (Rs.)</label>
                    <input type="number" id="quickCustOpeningBalance" class="form-control form-control-sm text-end" value="0.00" step="10" min="0">
                </div>
            </div>
            
            <div class="alert alert-info py-2 small mb-0">
                <i class="bi bi-lightning-charge-fill me-1 text-primary"></i> 
                <strong>Real-Time Sync:</strong> The new retailer is instantly selected and auto-populates route & booker on your form.
            </div>
        </form>
    </div>
    <div class="dms-drawer-footer d-flex justify-content-between">
        <button type="button" class="btn btn-sm btn-secondary" onclick="closeDrawer()">Cancel (ESC)</button>
        <button type="button" class="btn btn-sm btn-primary fw-bold" id="quickCustSubmitBtn" onclick="submitQuickCustomerForm()">
            <i class="bi bi-check2-circle me-1"></i> Save & Select Retailer
        </button>
    </div>
</div>

<script>
window.currentQuickCustomerTargetSelect = null;

/**
 * Open the Real-Time Quick Customer Slide Drawer
 * @param {string|HTMLElement|null} targetSelectId - Target select ID or element to populate
 */
window.openQuickCustomerDrawer = function (targetSelectId) {
    window.currentQuickCustomerTargetSelect = targetSelectId || 'customerSelect';
    
    const shopInput     = document.getElementById('quickCustShopName');
    const ownerInput    = document.getElementById('quickCustOwnerName');
    const phoneInput    = document.getElementById('quickCustPhone');
    const areaSelect    = document.getElementById('quickCustArea');
    const bookerSelect  = document.getElementById('quickCustBooker');
    const addressInput  = document.getElementById('quickCustAddress');
    const limitInput    = document.getElementById('quickCustCreditLimit');
    const balanceInput  = document.getElementById('quickCustOpeningBalance');

    if (shopInput) shopInput.value = '';
    if (ownerInput) ownerInput.value = '';
    if (phoneInput) phoneInput.value = '';
    if (addressInput) addressInput.value = '';
    if (limitInput) limitInput.value = '0.00';
    if (balanceInput) balanceInput.value = '0.00';

    // Auto-inherit active route and booker if already picked on the host page
    const pageAreaSelect = document.getElementById('areaSelect');
    if (areaSelect && pageAreaSelect && pageAreaSelect.value) {
        areaSelect.value = pageAreaSelect.value;
    } else if (areaSelect) {
        areaSelect.selectedIndex = 0;
    }

    const pageBookerSelect = document.getElementById('bookerSelect');
    if (bookerSelect && pageBookerSelect && pageBookerSelect.value) {
        bookerSelect.value = pageBookerSelect.value;
    } else if (bookerSelect) {
        bookerSelect.selectedIndex = 0;
    }

    openDrawer('drawer-quick-add-customer');
    setTimeout(() => {
        if (shopInput) shopInput.focus();
    }, 200);
};

/**
 * Submit Quick Customer Form via AJAX
 */
window.submitQuickCustomerForm = async function () {
    const shopInput    = document.getElementById('quickCustShopName');
    const ownerInput   = document.getElementById('quickCustOwnerName');
    const phoneInput   = document.getElementById('quickCustPhone');
    const areaSelect   = document.getElementById('quickCustArea');
    const bookerSelect = document.getElementById('quickCustBooker');
    const addressInput = document.getElementById('quickCustAddress');
    const limitInput   = document.getElementById('quickCustCreditLimit');
    const balanceInput = document.getElementById('quickCustOpeningBalance');
    const btn          = document.getElementById('quickCustSubmitBtn');

    if (!shopInput || !shopInput.value.trim()) {
        if (typeof showToast === 'function') {
            showToast("Please enter a Shop / Retailer Name.", "danger");
        } else {
            alert("Please enter a Shop / Retailer Name.");
        }
        if (shopInput) shopInput.focus();
        return;
    }

    if (!areaSelect || !areaSelect.value) {
        if (typeof showToast === 'function') {
            showToast("Please choose a Delivery Route / Area.", "danger");
        } else {
            alert("Please choose a Delivery Route / Area.");
        }
        if (areaSelect) areaSelect.focus();
        return;
    }

    const payload = {
        shop_name: shopInput.value.trim(),
        owner_name: ownerInput ? ownerInput.value.trim() : '',
        phone: phoneInput ? phoneInput.value.trim() : '',
        area_id: parseInt(areaSelect.value, 10),
        booker_id: bookerSelect && bookerSelect.value ? parseInt(bookerSelect.value, 10) : null,
        address: addressInput ? addressInput.value.trim() : '',
        credit_limit: limitInput ? parseFloat(limitInput.value || 0) : 0,
        opening_balance: balanceInput ? parseFloat(balanceInput.value || 0) : 0
    };

    const origBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    try {
        const response = await fetch('<?= APP_URL ?>/ajax/customers/quick_create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (data.success && data.customer) {
            const customer = data.customer;
            const optionText = `${customer.shop_name} (${customer.area_name || ''})`;
            const optionVal  = String(customer.id);

            // Locate target select element
            let targetSelect = null;
            if (typeof window.currentQuickCustomerTargetSelect === 'string') {
                targetSelect = document.getElementById(window.currentQuickCustomerTargetSelect);
            } else if (window.currentQuickCustomerTargetSelect instanceof HTMLElement) {
                targetSelect = window.currentQuickCustomerTargetSelect;
            }

            if (!targetSelect) {
                targetSelect = document.getElementById('customerSelect') ||
                               document.querySelector('select[name="customer_id"]:not([disabled])');
            }

            if (targetSelect) {
                let opt = Array.from(targetSelect.options).find(o => o.value == optionVal);
                if (!opt) {
                    opt = new Option(optionText, optionVal, true, true);
                    targetSelect.add(opt);
                } else {
                    opt.selected = true;
                }

                // Attach essential dataset attributes used by order and invoice forms
                opt.setAttribute('data-area', customer.area_id || '');
                opt.setAttribute('data-booker', customer.booker_id || '');
                opt.setAttribute('data-discount', customer.default_discount_percent || '0');
                opt.setAttribute('data-balance', customer.current_balance || '0');
                opt.setAttribute('data-limit', customer.credit_limit || '0');

                targetSelect.value = optionVal;
                // Trigger change event for auto-filling area, booker, and credit warning
                targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // Synchronize other customer dropdowns on current page
            document.querySelectorAll('select[name="customer_id"]').forEach(sel => {
                if (sel !== targetSelect) {
                    if (!Array.from(sel.options).some(o => o.value == optionVal)) {
                        const o = new Option(optionText, optionVal, false, false);
                        o.setAttribute('data-area', customer.area_id || '');
                        o.setAttribute('data-booker', customer.booker_id || '');
                        o.setAttribute('data-discount', customer.default_discount_percent || '0');
                        o.setAttribute('data-balance', customer.current_balance || '0');
                        o.setAttribute('data-limit', customer.credit_limit || '0');
                        sel.add(o);
                    }
                }
            });

            closeDrawer();
            if (typeof showToast === 'function') {
                showToast(data.message, "success");
            }
        } else {
            if (typeof showToast === 'function') {
                showToast(data.message || "Failed to create customer.", "danger");
            } else {
                alert(data.message || "Failed to create customer.");
            }
        }
    } catch (err) {
        console.error("Quick Customer Error:", err);
        if (typeof showToast === 'function') {
            showToast("Network error while registering customer.", "danger");
        } else {
            alert("Network error while registering customer.");
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = origBtnHtml;
    }
};
</script>
