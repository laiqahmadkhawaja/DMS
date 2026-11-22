<?php
/**
 * DMS - Product Price & Cost Inspection Modal
 * Reusable modal for inspecting Purchase Rate, Default Rate, Wholesale Rate, and Retail Rate
 */
if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}
?>

<!-- Product Pricing & Cost Inspection Modal -->
<div class="modal fade" id="productPriceModal" tabindex="-1" aria-labelledby="productPriceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-navy text-white py-2 px-3">
                <h6 class="modal-title fw-bold" id="productPriceModalLabel">
                    <i class="bi bi-info-circle-fill text-warning me-2"></i>Product Price & Cost Sheet
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Product Basic Header Banner -->
                <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-1" id="ppm_product_name">-</h5>
                        <div class="d-flex flex-wrap gap-1 align-items-center small">
                            <span class="badge bg-light text-dark border font-monospace" id="ppm_product_code">-</span>
                            <span class="badge bg-secondary-subtle text-secondary border" id="ppm_category_name">-</span>
                            <span class="badge bg-primary-subtle text-primary border" id="ppm_brand_name">-</span>
                            <span class="text-muted ms-1" id="ppm_packing_text">-</span>
                        </div>
                    </div>
                    <div class="text-end">
                        <small class="text-muted text-uppercase d-block fw-semibold" style="font-size:0.7rem;">Warehouse Stock</small>
                        <span class="badge bg-success-subtle text-success border fs-6 fw-bold" id="ppm_stock_badge">0 avail</span>
                    </div>
                </div>

                <!-- 4 Core Price Cards -->
                <div class="row g-2 mb-3">
                    <!-- 1. Purchase / Cost Rate -->
                    <div class="col-6 col-md-3">
                        <div class="card border border-warning h-100 p-2 text-center" style="background-color: #fffbeb;">
                            <div class="text-warning-emphasis fw-bold text-uppercase small" style="font-size:0.72rem;">
                                <i class="bi bi-cart-check me-1"></i>Purchase / Cost
                            </div>
                            <div class="small text-muted" style="font-size:0.75rem;">(خرید قیمت)</div>
                            <div class="fs-4 fw-bold text-dark mt-1" id="ppm_purchase_rate"><?= DEFAULT_CURRENCY ?> 0.00</div>
                            <small class="text-muted" style="font-size:0.7rem;">Supplier Invoice Cost</small>
                        </div>
                    </div>

                    <!-- 2. Default Sale Rate -->
                    <div class="col-6 col-md-3">
                        <div class="card border border-primary h-100 p-2 text-center" style="background-color: #eff6ff;">
                            <div class="text-primary fw-bold text-uppercase small" style="font-size:0.72rem;">
                                <i class="bi bi-tag me-1"></i>Default Sale Rate
                            </div>
                            <div class="small text-muted" style="font-size:0.75rem;">(ڈیفالٹ قیمت)</div>
                            <div class="fs-4 fw-bold text-primary mt-1" id="ppm_default_sale_rate"><?= DEFAULT_CURRENCY ?> 0.00</div>
                            <small class="text-muted" style="font-size:0.7rem;">Standard Book Rate</small>
                        </div>
                    </div>

                    <!-- 3. Wholesale Rate -->
                    <div class="col-6 col-md-3">
                        <div class="card border border-success h-100 p-2 text-center" style="background-color: #f0fdf4;">
                            <div class="text-success fw-bold text-uppercase small" style="font-size:0.72rem;">
                                <i class="bi bi-shop me-1"></i>Wholesale Rate
                            </div>
                            <div class="small text-muted" style="font-size:0.75rem;">(ہول سیل قیمت)</div>
                            <div class="fs-4 fw-bold text-success mt-1" id="ppm_wholesale_rate"><?= DEFAULT_CURRENCY ?> 0.00</div>
                            <small class="text-muted" style="font-size:0.7rem;">Order Booker Rate</small>
                        </div>
                    </div>

                    <!-- 4. Retail / Minimum MRP Rate -->
                    <div class="col-6 col-md-3">
                        <div class="card border border-info h-100 p-2 text-center" style="background-color: #f0fdfa;">
                            <div class="text-info-emphasis fw-bold text-uppercase small" style="font-size:0.72rem;">
                                <i class="bi bi-cash-coin me-1"></i>Retail / MRP
                            </div>
                            <div class="small text-muted" style="font-size:0.75rem;">(پرچون / منیمم ریٹ)</div>
                            <div class="fs-4 fw-bold text-dark mt-1" id="ppm_retail_rate"><?= DEFAULT_CURRENCY ?> 0.00</div>
                            <small class="text-muted" style="font-size:0.7rem;">Consumer End Price</small>
                        </div>
                    </div>
                </div>

                <!-- Carton Breakdown & Margins Table -->
                <div class="card border mb-0">
                    <div class="card-header bg-light py-1 px-3 d-flex justify-content-between align-items-center">
                        <span class="fw-semibold small text-dark"><i class="bi bi-box-seam me-1"></i> Carton Breakdown & Profit Margins</span>
                        <span class="badge bg-light text-muted border font-monospace" id="ppm_ctn_ratio_badge">-</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered text-center mb-0 align-middle">
                            <thead class="table-light small">
                                <tr>
                                    <th>Tier / Level</th>
                                    <th>Unit Rate</th>
                                    <th>Carton Rate</th>
                                    <th>Unit Margin</th>
                                    <th>Carton Margin</th>
                                    <th>Margin %</th>
                                </tr>
                            </thead>
                            <tbody class="small font-monospace">
                                <tr>
                                    <td class="text-start fw-bold text-warning-emphasis font-sans-serif">
                                        <i class="bi bi-cart me-1"></i> Purchase Cost
                                    </td>
                                    <td id="ppm_tbl_cost_unit">-</td>
                                    <td id="ppm_tbl_cost_ctn" class="fw-bold">-</td>
                                    <td class="text-muted">-</td>
                                    <td class="text-muted">-</td>
                                    <td class="text-muted">Base</td>
                                </tr>
                                <tr>
                                    <td class="text-start fw-bold text-success font-sans-serif">
                                        <i class="bi bi-shop me-1"></i> Wholesale Sale
                                    </td>
                                    <td id="ppm_tbl_ws_unit">-</td>
                                    <td id="ppm_tbl_ws_ctn" class="fw-bold text-success">-</td>
                                    <td id="ppm_tbl_ws_margin" class="text-success">-</td>
                                    <td id="ppm_tbl_ws_ctn_margin" class="fw-bold text-success">-</td>
                                    <td><span class="badge bg-success-subtle text-success border" id="ppm_tbl_ws_pct">0%</span></td>
                                </tr>
                                <tr>
                                    <td class="text-start fw-bold text-info font-sans-serif">
                                        <i class="bi bi-bag-check me-1"></i> Retail / MRP
                                    </td>
                                    <td id="ppm_tbl_ret_unit">-</td>
                                    <td id="ppm_tbl_ret_ctn" class="fw-bold text-primary">-</td>
                                    <td id="ppm_tbl_ret_margin" class="text-primary">-</td>
                                    <td id="ppm_tbl_ret_ctn_margin" class="fw-bold text-primary">-</td>
                                    <td><span class="badge bg-primary-subtle text-primary border" id="ppm_tbl_ret_pct">0%</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i> Close (ESC)
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" id="ppm_btn_add_to_cart">
                        <i class="bi bi-plus-circle me-1"></i> Add Item to Bill / Form
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Global Product Price Modal Handler
 * Inspects all product pricing (purchase rate, default rate, wholesale rate, retail rate, margins)
 */
let currentModalProductId = null;

function showProductPriceModal(event, productId) {
    if (event) {
        event.stopPropagation();
        event.preventDefault();
    }

    currentModalProductId = productId;
    const p = (typeof productMap !== 'undefined' && productMap[productId]) ? productMap[productId] : null;

    if (!p) {
        alert('Product details could not be loaded.');
        return;
    }

    const currency = '<?= DEFAULT_CURRENCY ?>';

    // Basic Information
    document.getElementById('ppm_product_name').textContent = p.product_name || 'Product';
    document.getElementById('ppm_product_code').textContent = p.product_code || '-';
    document.getElementById('ppm_category_name').textContent = p.category_name || 'Category';
    document.getElementById('ppm_brand_name').textContent = p.brand_name || 'Brand: -';
    
    // Ratios & packing
    const cartonQty = parseFloat(p.carton_qty) || 1;
    const packQty   = parseFloat(p.pack_qty) || 1;
    const packingDesc = p.packing_description || `1 Ctn = ${cartonQty} Units`;
    document.getElementById('ppm_packing_text').textContent = packingDesc;
    document.getElementById('ppm_ctn_ratio_badge').textContent = `1 Ctn = ${cartonQty} units`;

    // Stock Badge
    const availStock = parseFloat(p.saleable_qty) || 0;
    const stockBadge = document.getElementById('ppm_stock_badge');
    if (stockBadge) {
        stockBadge.textContent = `${availStock} avail`;
        if (availStock <= 0) {
            stockBadge.className = 'badge bg-danger-subtle text-danger border fs-6 fw-bold';
            stockBadge.textContent = '0 avail (Out of Stock)';
        } else {
            stockBadge.className = 'badge bg-success-subtle text-success border fs-6 fw-bold';
        }
    }

    // Rates
    const purchaseRate    = parseFloat(p.purchase_rate) || parseFloat(p.cost_price) || 0;
    const defaultSaleRate = parseFloat(p.default_sale_rate) || parseFloat(p.wholesale_rate) || 0;
    const wholesaleRate   = parseFloat(p.wholesale_rate) || 0;
    const retailRate      = parseFloat(p.retail_rate) || 0;

    // Format Number Helper
    const fmt = (num) => `${currency} ${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    // 4 Price Cards
    document.getElementById('ppm_purchase_rate').textContent    = fmt(purchaseRate);
    document.getElementById('ppm_default_sale_rate').textContent= fmt(defaultSaleRate);
    document.getElementById('ppm_wholesale_rate').textContent   = fmt(wholesaleRate);
    document.getElementById('ppm_retail_rate').textContent      = fmt(retailRate);

    // Table values
    // Cost
    document.getElementById('ppm_tbl_cost_unit').textContent = fmt(purchaseRate);
    document.getElementById('ppm_tbl_cost_ctn').textContent  = fmt(purchaseRate * cartonQty);

    // Wholesale
    document.getElementById('ppm_tbl_ws_unit').textContent = fmt(wholesaleRate);
    document.getElementById('ppm_tbl_ws_ctn').textContent  = fmt(wholesaleRate * cartonQty);
    const wsMargin = wholesaleRate - purchaseRate;
    document.getElementById('ppm_tbl_ws_margin').textContent     = fmt(wsMargin);
    document.getElementById('ppm_tbl_ws_ctn_margin').textContent = fmt(wsMargin * cartonQty);
    const wsPct = purchaseRate > 0 ? ((wsMargin / purchaseRate) * 100).toFixed(1) : '0';
    document.getElementById('ppm_tbl_ws_pct').textContent = `${wsPct}%`;

    // Retail
    document.getElementById('ppm_tbl_ret_unit').textContent = fmt(retailRate);
    document.getElementById('ppm_tbl_ret_ctn').textContent  = fmt(retailRate * cartonQty);
    const retMargin = retailRate - purchaseRate;
    document.getElementById('ppm_tbl_ret_margin').textContent     = fmt(retMargin);
    document.getElementById('ppm_tbl_ret_ctn_margin').textContent = fmt(retMargin * cartonQty);
    const retPct = purchaseRate > 0 ? ((retMargin / purchaseRate) * 100).toFixed(1) : '0';
    document.getElementById('ppm_tbl_ret_pct').textContent = `${retPct}%`;

    // Show Modal
    const modalEl = document.getElementById('productPriceModal');
    if (modalEl) {
        let bsModal = bootstrap.Modal.getInstance(modalEl);
        if (!bsModal) {
            bsModal = new bootstrap.Modal(modalEl);
        }
        bsModal.show();
    }
}

// Add Item Button in Modal Footer
document.addEventListener('DOMContentLoaded', function () {
    const btnAdd = document.getElementById('ppm_btn_add_to_cart');
    if (btnAdd) {
        btnAdd.addEventListener('click', function () {
            if (!currentModalProductId) return;

            // Trigger the page-specific add function
            if (typeof addProductToInvoice === 'function') {
                addProductToInvoice(currentModalProductId);
            } else if (typeof addProductToCart === 'function') {
                addProductToCart(currentModalProductId);
            }

            // Hide modal
            const modalEl = document.getElementById('productPriceModal');
            if (modalEl) {
                const bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            }
        });
    }
});
</script>
