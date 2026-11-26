<?php
/**
 * DMS - Distribution Management System
 * Global Slide-out Drawer: Quick WhatsApp Sender (Open-WA Integration)
 * 
 * Supports:
 * - Direct Sales Invoice Bills
 * - Direct Customer Ledger Statements
 * - Direct Pictures (JPG, PNG, Receipts, Signed Invoices)
 * - Direct PDF Documents
 * - Custom Direct Text Messages
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}
?>
<!-- Non-Modal Slide-out Drawer: Open-WA WhatsApp Sender -->
<div id="drawer-quick-whatsapp" class="dms-drawer" style="width: 520px; max-width: 95vw;">
    <div class="dms-drawer-header bg-success text-white d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <i class="bi bi-whatsapp fs-5 me-2"></i>
            <div>
                <div class="fw-bold fs-6" id="waDrawerTitle">WhatsApp Dispatch (Open-WA)</div>
                <small class="text-white-50" style="font-size: 0.72rem;">Direct automated transmission</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-white text-dark small" id="waDrawerServerBadge" style="font-size:0.7rem;">
                <i class="bi bi-circle-fill text-muted me-1"></i> Checking...
            </span>
            <button type="button" class="btn-close btn-close-white" onclick="closeDrawer('drawer-quick-whatsapp')"></button>
        </div>
    </div>

    <div class="dms-drawer-body p-3">
        <!-- Target Info Header Card -->
        <div class="card border-0 bg-light p-3 mb-3" id="waDrawerRecipientCard">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small fw-semibold" id="waDrawerTargetSubtitle">Target Recipient</span>
                <span class="badge bg-navy text-white" id="waDrawerTargetTag">Bill</span>
            </div>
            <div class="fw-bold text-navy fs-6" id="waDrawerRecipientName">Madina Karyana Store</div>
            <div class="text-muted small" id="waDrawerContextNote">Invoice #INV-2026-00001</div>
        </div>

        <!-- Phone Number Input -->
        <div class="mb-3">
            <label class="form-label small fw-bold text-dark mb-1">
                Recipient WhatsApp Phone Number <span class="text-danger">*</span>
            </label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-telephone text-success"></i></span>
                <input type="text" id="waDrawerPhone" class="form-control" placeholder="03001234567" required>
            </div>
            <div class="form-text" style="font-size:0.75rem;">
                Enter local Pakistani mobile (03XXXXXXXXX) or international with country code.
            </div>
        </div>

        <!-- Mode Nav Pills -->
        <ul class="nav nav-pills nav-fill mb-3" id="waDrawerNavPills" style="font-size:0.8rem;">
            <li class="nav-item">
                <button type="button" class="nav-link active py-1" id="waTabBtnBill" onclick="switchWaDrawerTab('bill')">
                    <i class="bi bi-receipt me-1"></i> Bill / Ledger
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link py-1" id="waTabBtnFile" onclick="switchWaDrawerTab('file')">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i> Pic / PDF
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link py-1" id="waTabBtnCustom" onclick="switchWaDrawerTab('custom')">
                    <i class="bi bi-chat-text me-1"></i> Custom Text
                </button>
            </li>
        </ul>

        <!-- Tab 1: Bill / Ledger Preview / Info -->
        <div id="waTabContentBill">
            <div class="alert alert-info py-2 small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                <span id="waBillInfoText">Sends official formatted bill including store details, itemized goods, rates, subtotal, discounts, cash collected, and balance.</span>
            </div>
            <div class="p-2 bg-white border rounded mb-3" style="max-height: 180px; overflow-y: auto; font-size: 0.8rem; font-family: monospace; white-space: pre-wrap; background-color: #fafbfc !important;" id="waBillPreviewBox">
Loading bill content preview...
            </div>
        </div>

        <!-- Tab 2: Picture / PDF Direct Upload -->
        <div id="waTabContentFile" style="display:none;">
            <div class="mb-3">
                <label class="form-label small fw-bold text-dark mb-1">
                    Select Picture or PDF Document <span class="text-danger">*</span>
                </label>
                <input type="file" id="waDrawerFileInput" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp,application/pdf">
                <div class="form-text" style="font-size:0.75rem;">
                    Supported: Invoice PDF, delivery receipt, signed bill photo, bank deposit slip (Max: 15MB)
                </div>
            </div>

            <!-- Image / File Preview Box -->
            <div id="waFilePreviewContainer" class="text-center p-2 border rounded bg-light mb-3" style="display:none;">
                <img id="waFileImgPreview" src="" alt="Preview" style="max-height: 140px; max-width: 100%; border-radius: 4px; display:none;" class="mb-1">
                <div id="waFileDocPreview" style="display:none;" class="py-3 text-secondary">
                    <i class="bi bi-file-earmark-pdf-fill text-danger fs-1 d-block mb-1"></i>
                    <span id="waFileDocName" class="fw-bold small">document.pdf</span>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold text-dark mb-1">Attachment Caption / Note (Optional)</label>
                <input type="text" id="waDrawerFileCaption" class="form-control form-control-sm" placeholder="e.g. Please find invoice and payment proof attached.">
            </div>
        </div>

        <!-- Tab 3: Custom Text Message -->
        <div id="waTabContentCustom" style="display:none;">
            <div class="mb-3">
                <label class="form-label small fw-bold text-dark mb-1">
                    Message Body <span class="text-danger">*</span>
                </label>
                <textarea id="waDrawerCustomText" class="form-control form-control-sm" rows="5" placeholder="Type WhatsApp message here... Standard WhatsApp styling like *bold* or _italic_ supported."></textarea>
            </div>
        </div>

        <!-- Feedback & Status Notification Box -->
        <div id="waDrawerAlert" class="small mb-3" style="display:none;"></div>
    </div>

    <!-- Drawer Footer Actions -->
    <div class="dms-drawer-footer d-flex justify-content-between align-items-center bg-light border-top p-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeDrawer('drawer-quick-whatsapp')">
            Cancel
        </button>
        <button type="button" class="btn btn-sm btn-success px-3 fw-semibold shadow-sm" id="waDrawerSendBtn" onclick="submitWaDrawerSend()">
            <i class="bi bi-send-fill me-1"></i> Send via WhatsApp
        </button>
    </div>
</div>

<script>
(function() {
    // Drawer Internal State
    let currentWaState = {
        mode: 'bill', // 'bill', 'statement', 'file', 'custom'
        invoiceId: null,
        customerId: null,
        phone: '',
        recipientName: '',
        title: '',
        billText: ''
    };

    window.switchWaDrawerTab = function(tabName) {
        document.querySelectorAll('#waDrawerNavPills .nav-link').forEach(el => el.classList.remove('active'));
        document.getElementById('waTabContentBill').style.display = 'none';
        document.getElementById('waTabContentFile').style.display = 'none';
        document.getElementById('waTabContentCustom').style.display = 'none';

        if (tabName === 'bill') {
            document.getElementById('waTabBtnBill').classList.add('active');
            document.getElementById('waTabContentBill').style.display = 'block';
            currentWaState.mode = currentWaState.invoiceId ? 'bill' : (currentWaState.customerId ? 'statement' : 'bill');
        } else if (tabName === 'file') {
            document.getElementById('waTabBtnFile').classList.add('active');
            document.getElementById('waTabContentFile').style.display = 'block';
            currentWaState.mode = 'file';
        } else if (tabName === 'custom') {
            document.getElementById('waTabBtnCustom').classList.add('active');
            document.getElementById('waTabContentCustom').style.display = 'block';
            currentWaState.mode = 'custom';
        }
    };

    // File Preview Handler
    const fileInput = document.getElementById('waDrawerFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const container = document.getElementById('waFilePreviewContainer');
            const imgPreview = document.getElementById('waFileImgPreview');
            const docPreview = document.getElementById('waFileDocPreview');
            const docName = document.getElementById('waFileDocName');

            if (!file) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'block';
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    imgPreview.src = evt.target.result;
                    imgPreview.style.display = 'inline-block';
                    docPreview.style.display = 'none';
                };
                reader.readAsDataURL(file);
            } else {
                imgPreview.style.display = 'none';
                docPreview.style.display = 'block';
                docName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            }
        });
    }

    // Check Open-WA connection status for badge
    async function checkWaDrawerStatus() {
        const badge = document.getElementById('waDrawerServerBadge');
        if (!badge) return;
        try {
            const res = await fetch('<?= APP_URL ?>/ajax/whatsapp/send.php?action=test_connection', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(r => r.json());

            if (res && res.success) {
                badge.className = 'badge bg-success text-white small';
                badge.innerHTML = '<i class="bi bi-circle-fill me-1" style="font-size:0.5rem;"></i> Open-WA Ready';
            } else {
                badge.className = 'badge bg-warning text-dark small';
                badge.innerHTML = '<i class="bi bi-exclamation-circle-fill me-1" style="font-size:0.5rem;"></i> Server Offline';
            }
        } catch (e) {
            badge.className = 'badge bg-danger text-white small';
            badge.innerHTML = '<i class="bi bi-x-circle-fill me-1" style="font-size:0.5rem;"></i> Disconnected';
        }
    }

    /**
     * Global Invoker: Open WhatsApp Dispatcher Drawer
     * 
     * @param {Object} opts
     *   opts.mode: 'bill' | 'statement' | 'file' | 'custom'
     *   opts.invoiceId: number (optional)
     *   opts.customerId: number (optional)
     *   opts.phone: string
     *   opts.recipientName: string
     *   opts.title: string
     *   opts.contextNote: string
     */
    window.openWhatsAppDrawer = async function(opts = {}) {
        currentWaState = Object.assign({
            mode: opts.invoiceId ? 'bill' : (opts.customerId ? 'statement' : (opts.mode || 'bill')),
            invoiceId: opts.invoiceId || null,
            customerId: opts.customerId || null,
            phone: opts.phone || '',
            recipientName: opts.recipientName || 'Retail Customer',
            title: opts.title || 'WhatsApp Dispatch',
            contextNote: opts.contextNote || ''
        }, opts);

        // Reset UI
        document.getElementById('waDrawerTitle').textContent = currentWaState.title || 'WhatsApp Dispatch';
        document.getElementById('waDrawerRecipientName').textContent = currentWaState.recipientName || 'Customer';
        document.getElementById('waDrawerContextNote').textContent = currentWaState.contextNote || (currentWaState.invoiceId ? 'Invoice #' + currentWaState.invoiceId : '');
        document.getElementById('waDrawerPhone').value = currentWaState.phone || '';
        document.getElementById('waDrawerAlert').style.display = 'none';

        // Set Tab
        if (currentWaState.mode === 'file') {
            switchWaDrawerTab('file');
            document.getElementById('waDrawerTargetTag').textContent = 'File Attachment';
        } else if (currentWaState.mode === 'custom') {
            switchWaDrawerTab('custom');
            document.getElementById('waDrawerTargetTag').textContent = 'Message';
        } else {
            switchWaDrawerTab('bill');
            document.getElementById('waDrawerTargetTag').textContent = currentWaState.invoiceId ? 'Sales Bill' : 'Statement';
        }

        // Bill / Statement text preview
        const previewBox = document.getElementById('waBillPreviewBox');
        if (currentWaState.invoiceId) {
            previewBox.textContent = 'Generating live formatted invoice summary...';
            document.getElementById('waDrawerFileCaption').value = 'Bill #' + (currentWaState.contextNote || currentWaState.invoiceId);
            fetch('<?= APP_URL ?>/ajax/whatsapp/send.php?action=preview_invoice&invoice_id=' + currentWaState.invoiceId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.text) {
                    previewBox.textContent = res.text;
                    if (!document.getElementById('waDrawerPhone').value && res.phone) {
                        document.getElementById('waDrawerPhone').value = res.phone;
                    }
                }
            })
            .catch(() => {});
        } else if (currentWaState.customerId) {
            previewBox.textContent = 'Generating customer ledger balance statement...';
            document.getElementById('waDrawerFileCaption').value = 'Ledger Statement: ' + (currentWaState.recipientName || '');
            fetch('<?= APP_URL ?>/ajax/whatsapp/send.php?action=preview_statement&customer_id=' + currentWaState.customerId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.text) {
                    previewBox.textContent = res.text;
                    if (!document.getElementById('waDrawerPhone').value && res.phone) {
                        document.getElementById('waDrawerPhone').value = res.phone;
                    }
                }
            })
            .catch(() => {});
        } else {
            previewBox.textContent = 'No invoice or customer ledger selected. Use Pic/PDF or Custom Text tab.';
        }

        openDrawer('drawer-quick-whatsapp');
        checkWaDrawerStatus();
    };

    /**
     * Submit WhatsApp Send Action
     */
    window.submitWaDrawerSend = async function() {
        const phone = document.getElementById('waDrawerPhone').value.trim();
        const alertBox = document.getElementById('waDrawerAlert');
        const sendBtn = document.getElementById('waDrawerSendBtn');

        if (!phone) {
            alertBox.style.display = 'block';
            alertBox.className = 'small mb-3 alert alert-danger py-2';
            alertBox.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i> Please provide a valid recipient phone number.';
            document.getElementById('waDrawerPhone').focus();
            return;
        }

        sendBtn.disabled = true;
        const origBtnHtml = sendBtn.innerHTML;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Dispatching...';
        alertBox.style.display = 'block';
        alertBox.className = 'small mb-3 alert alert-info py-2';
        alertBox.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Transmitting to Open-WA WhatsApp gateway...';

        try {
            const fd = new FormData();
            fd.append('phone', phone);

            if (currentWaState.mode === 'file') {
                const file = fileInput.files[0];
                if (!file) {
                    throw new Error('Please select an image or PDF file to send.');
                }
                fd.append('action', 'send_file');
                fd.append('file', file);
                fd.append('caption', document.getElementById('waDrawerFileCaption').value.trim());
            } else if (currentWaState.mode === 'custom') {
                const msg = document.getElementById('waDrawerCustomText').value.trim();
                if (!msg) {
                    throw new Error('Please enter text message content.');
                }
                fd.append('action', 'send_test_message');
                fd.append('message', msg);
            } else if (currentWaState.invoiceId) {
                fd.append('action', 'send_invoice');
                fd.append('invoice_id', currentWaState.invoiceId);
            } else if (currentWaState.customerId) {
                fd.append('action', 'send_customer_statement');
                fd.append('customer_id', currentWaState.customerId);
            } else {
                throw new Error('Invalid send request mode.');
            }

            const res = await fetch('<?= APP_URL ?>/ajax/whatsapp/send.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(r => r.json());

            if (res.success) {
                alertBox.className = 'small mb-3 alert alert-success py-2';
                alertBox.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> ' + res.message;
                if (window.showToast) {
                    showToast(res.message, 'success');
                }
                // Auto close after 1.8 seconds on success
                setTimeout(() => {
                    closeDrawer('drawer-quick-whatsapp');
                }, 1800);
            } else {
                alertBox.className = 'small mb-3 alert alert-danger py-2';
                alertBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + (res.message || 'WhatsApp sending failed');
                if (window.showToast) {
                    showToast(res.message || 'WhatsApp sending failed', 'danger');
                }
            }
        } catch (err) {
            alertBox.className = 'small mb-3 alert alert-danger py-2';
            alertBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + err.message;
        } finally {
            sendBtn.disabled = false;
            sendBtn.innerHTML = origBtnHtml;
        }
    };
})();
</script>
