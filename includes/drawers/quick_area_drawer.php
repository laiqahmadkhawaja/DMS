<?php
/**
 * DMS - Distribution Management System
 * Global Slide-out Drawer: Quick Register Delivery Area / Route in Real-Time
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

$defaultCity = get_setting('city', 'Gujranwala');
$allowRealtimeRoutes = get_setting('allow_realtime_routes', '1');
?>
<!-- Non-Modal Slide-out Drawer: Quick Add Area / Route -->
<div id="drawer-quick-add-area" class="dms-drawer">
    <div class="dms-drawer-header bg-navy text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-geo-alt-fill text-warning me-2"></i> Quick Add Delivery Route / Beat</span>
        <button type="button" class="btn-close btn-close-white" onclick="closeDrawer()"></button>
    </div>
    <div class="dms-drawer-body p-3">
        <p class="text-muted small mb-3">
            Register a new delivery sector or market route on the fly. It will be immediately saved to the master list and selected on your current form without reloading.
        </p>

        <form id="quickAreaForm" onsubmit="event.preventDefault(); submitQuickAreaForm();" autocomplete="off">
            <div class="mb-3">
                <label class="form-label small fw-bold text-dark">
                    Area / Route Name <span class="text-danger">*</span>
                </label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-signpost-2"></i></span>
                    <input type="text" id="quickAreaName" class="form-control" placeholder="e.g. Model Town Block B, Gondlanwala" required autofocus>
                </div>
                <small class="text-muted" style="font-size: 0.72rem;">Unique sector or delivery beat name</small>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold text-dark">City / Territory</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-building"></i></span>
                    <input type="text" id="quickAreaCity" class="form-control" value="<?= htmlspecialchars($defaultCity) ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold text-dark">Route Description / Landmarks</label>
                <textarea id="quickAreaDesc" class="form-control form-control-sm" rows="3" placeholder="Key retail clusters, major road landmarks, or route sequence notes..."></textarea>
            </div>
            
            <div class="alert alert-info py-2 small mb-0">
                <i class="bi bi-lightning-charge-fill me-1 text-primary"></i> 
                <strong>Real-Time Sync:</strong> The new route is instantly appended to the dropdown list.
            </div>
        </form>
    </div>
    <div class="dms-drawer-footer d-flex justify-content-between">
        <button type="button" class="btn btn-sm btn-secondary" onclick="closeDrawer()">Cancel (ESC)</button>
        <button type="button" class="btn btn-sm btn-primary fw-bold" id="quickAreaSubmitBtn" onclick="submitQuickAreaForm()">
            <i class="bi bi-check2-circle me-1"></i> Save & Select Route
        </button>
    </div>
</div>

<script>
window.currentQuickAreaTargetSelect = null;

/**
 * Open the Real-Time Quick Area Slide Drawer
 * @param {string|HTMLElement|null} targetSelectId - Target select ID or element to populate
 */
window.openQuickAreaDrawer = function (targetSelectId) {
    window.currentQuickAreaTargetSelect = targetSelectId || 'areaSelect';
    const nameInput = document.getElementById('quickAreaName');
    const descInput = document.getElementById('quickAreaDesc');
    
    if (nameInput) nameInput.value = '';
    if (descInput) descInput.value = '';
    
    openDrawer('drawer-quick-add-area');
    setTimeout(() => {
        if (nameInput) nameInput.focus();
    }, 200);
};

/**
 * Submit Quick Area Form via AJAX
 */
window.submitQuickAreaForm = async function () {
    const nameInput = document.getElementById('quickAreaName');
    const cityInput = document.getElementById('quickAreaCity');
    const descInput = document.getElementById('quickAreaDesc');
    const btn       = document.getElementById('quickAreaSubmitBtn');

    if (!nameInput || !nameInput.value.trim()) {
        if (typeof showToast === 'function') {
            showToast("Please enter an Area / Route Name.", "danger");
        } else {
            alert("Please enter an Area / Route Name.");
        }
        if (nameInput) nameInput.focus();
        return;
    }

    const payload = {
        area_name: nameInput.value.trim(),
        city: cityInput ? cityInput.value.trim() : 'Gujranwala',
        description: descInput ? descInput.value.trim() : ''
    };

    const origBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    try {
        const response = await fetch('<?= APP_URL ?>/ajax/areas/quick_create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (data.success && data.area) {
            const area = data.area;
            const optionText = `${area.area_name} (${area.city})`;
            const optionVal  = String(area.id);

            // Locate target select element
            let targetSelect = null;
            if (typeof window.currentQuickAreaTargetSelect === 'string') {
                targetSelect = document.getElementById(window.currentQuickAreaTargetSelect);
            } else if (window.currentQuickAreaTargetSelect instanceof HTMLElement) {
                targetSelect = window.currentQuickAreaTargetSelect;
            }

            // Fallback heuristics if specific target wasn't found
            if (!targetSelect) {
                targetSelect = document.querySelector('select[name="area_id"]:not([disabled])') ||
                               document.querySelector('select[name="assigned_area_id"]:not([disabled])') ||
                               document.getElementById('areaSelect');
            }

            if (targetSelect) {
                // Check if option already exists
                let opt = Array.from(targetSelect.options).find(o => o.value == optionVal);
                if (!opt) {
                    opt = new Option(optionText, optionVal, true, true);
                    targetSelect.add(opt);
                } else {
                    opt.selected = true;
                }
                targetSelect.value = optionVal;
                // Trigger change event for chained calculations/booker lookups
                targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // Also synchronize any other open area select lists on the page
            document.querySelectorAll('select[name="area_id"], select[name="assigned_area_id"]').forEach(sel => {
                if (sel !== targetSelect) {
                    if (!Array.from(sel.options).some(o => o.value == optionVal)) {
                        sel.add(new Option(optionText, optionVal, false, false));
                    }
                }
            });

            closeDrawer();
            if (typeof showToast === 'function') {
                showToast(data.message, "success");
            }
        } else {
            if (typeof showToast === 'function') {
                showToast(data.message || "Failed to create area.", "danger");
            } else {
                alert(data.message || "Failed to create area.");
            }
        }
    } catch (err) {
        console.error("Quick Area Error:", err);
        if (typeof showToast === 'function') {
            showToast("Network error while registering route.", "danger");
        } else {
            alert("Network error while registering route.");
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = origBtnHtml;
    }
};
</script>
