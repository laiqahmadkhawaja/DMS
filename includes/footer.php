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

<!-- Non-Modal Slide-out Drawer: System User Guide & Keyboard Shortcuts (F1) -->
<div id="drawer-shortcuts-help" class="dms-drawer" style="width: 580px; max-width: 95vw;">
    <div class="dms-drawer-header bg-navy text-white">
        <div class="d-flex align-items-center">
            <i class="bi bi-book-half text-warning fs-5 me-2"></i>
            <div>
                <div class="fw-bold fs-6">سسٹم رہنمائی و شارٹ کٹس (F1 Guide)</div>
                <small class="text-white-50" style="font-size: 0.72rem;">User Manual & Navigation Reference</small>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white" onclick="closeDrawer()" title="Close (ESC)"></button>
    </div>

    <!-- Quick Navigation / Search in Guide -->
    <div class="p-2 bg-light border-bottom">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" id="f1GuideSearch" class="form-control border-start-0" placeholder="رہنمائی سرچ کریں... (مثلاً: لوڈ فارم، کریڈٹ شیٹ، سیٹلمنٹ، اسٹاک)" onkeyup="filterF1Guide()">
            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('f1GuideSearch').value=''; filterF1Guide();">صاف کریں</button>
        </div>
    </div>

    <!-- Nav Tabs for Guide vs Shortcuts -->
    <ul class="nav nav-tabs nav-fill px-3 pt-2 bg-light border-bottom" id="f1HelpTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold small text-navy py-2" id="tab-guide-btn" data-bs-toggle="tab" data-bs-target="#tab-f1-guide" type="button" role="tab">
                <i class="bi bi-compass me-1 text-primary"></i> مکمل سسٹم رہنمائی (اردو)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold small text-navy py-2" id="tab-keys-btn" data-bs-toggle="tab" data-bs-target="#tab-f1-keys" type="button" role="tab">
                <i class="bi bi-keyboard me-1 text-success"></i> کی بورڈ شارٹ کٹس
            </button>
        </li>
    </ul>

    <div class="dms-drawer-body p-3" style="font-family: 'Segoe UI', Tahoma, Arial, sans-serif;">
        <div class="tab-content" id="f1HelpTabsContent">
            
            <!-- TAB 1: URDU USER GUIDE & SITEMAP -->
            <div class="tab-pane fade show active" id="tab-f1-guide" role="tabpanel">
                
                <!-- Daily Workflow Summary Alert -->
                <div class="card border-primary border-2 mb-3 shadow-sm bg-primary-subtle text-primary-emphasis">
                    <div class="card-body p-2" dir="rtl">
                        <div class="fw-bold small mb-1"><i class="bi bi-arrow-repeat me-1 text-primary"></i> روزمرہ کام کرنے کی درست ترتیب:</div>
                        <ol class="small mb-0 pe-3 text-secondary-emphasis" style="line-height: 1.6;">
                            <li><strong>صبح:</strong> مارکیٹ سے آرڈر بک کریں (Order Booking - F6)</li>
                            <li><strong>لوڈنگ:</strong> گودام سے مال نکالنے کے لیے <strong>لوڈ فارم (Load Form)</strong> بنائیں اور ایشو کریں۔</li>
                            <li><strong>روانگی:</strong> گاڑی روانہ کرتے وقت سیلز مین کو <strong>کریڈٹ شیٹ (Credit Sheet)</strong> پرنٹ کر کے دیں۔</li>
                            <li><strong>شام:</strong> گاڑی واپسی پر نقد، ادھار اور ریٹرن کا حساب <strong>روٹ سیٹلمنٹ (Route Settlement - F8)</strong> میں ڈالیں۔</li>
                            <li><strong>دن کا اختتام:</strong> <strong>روزنامچہ (Daily Closing)</strong> چیک اور پرنٹ کریں۔</li>
                        </ol>
                    </div>
                </div>

                <!-- Accordion / Cards of Modules -->
                <div class="f1-guide-cards" id="f1GuideList">
                    
                    <!-- 1. Order Booking -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="order booking آرڈر بکنگ بوکر دکان سیلز دکاندار f6">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-cart4 text-primary me-2"></i> 1. آرڈر بکنگ (Order Booking)</span>
                            <a href="<?= APP_URL ?>/modules/orders/create.php" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:0.75rem;">کھولیں (F6)</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Sales & Distribution &rarr; Order Booking</code> یا کی بورڈ سے <strong>F6</strong> دبائیں۔</p>
                            <p class="mb-1"><strong>طریقہ کار:</strong></p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li>دکاندار (Retailer)، بوکر اور روٹ منتخب کریں۔</li>
                                <li>سرچ بار میں آئٹم کا نام یا کوڈ لکھیں، مطلوبہ کارٹن (Ctn) یا کھلے پیس (Pcs) درج کریں۔</li>
                                <li><strong>F9</strong> یا "Confirm" پر کلک کر کے آرڈر محفوظ کر لیں۔</li>
                                <li><em>نیا دکاندار:</em> اگر کوئی نئی دکان ہے تو دکاندار کے نام کے ساتھ نیلے <strong>+</strong> بٹن سے اسی وقت نیا کسٹمر ایڈ کر سکتے ہیں۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 2. Load Form -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="load form لوڈ فارم ویئر ہاؤس لوڈنگ گودام مال گاڑی روانگی اسٹاک ڈسپیچ">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-truck-flatbed text-warning me-2"></i> 2. لوڈ فارم (Load Form / گودام لوڈنگ)</span>
                            <a href="<?= APP_URL ?>/modules/loading/index.php" class="btn btn-xs btn-outline-warning text-dark py-0 px-2" style="font-size:0.75rem;">کھولیں</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Sales & Distribution &rarr; Load Form (لوڈ فارم)</code> پر جائیں۔</p>
                            <p class="mb-1"><strong>طریقہ کار:</strong></p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li>"New Load Form (نیا لوڈ فارم)" پر کلک کریں۔</li>
                                <li>سیلز مین اور گودام منتخب کریں۔ روٹ کے بک شدہ آرڈرز کی فہرست سامنے آئے گی۔</li>
                                <li>جن آرڈرز کا مال گاڑی میں لوڈ کرنا ہے ان پر ٹک لگائیں۔ سسٹم تمام آئٹمز کے کل کارٹن اور پیس خودکار جمع کر دے گا۔</li>
                                <li>نیچے <strong>"Issue & Dispatch Stock"</strong> دبائیں؛ اسٹاک گودام سے منہا ہو جائے گا اور بل کٹ جائیں گے۔</li>
                                <li><strong>F10</strong> دبا کر لوڈ فارم پرنٹ کریں اور گودام انچارج اور سیلز مین کو دیں۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 3. Credit Sheet -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="credit sheet کریڈٹ شیٹ ڈیلیوری رن شیٹ سیلز مین شیٹ ریکوری ادھار کیش واپسی بل">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-journal-check text-success me-2"></i> 3. کریڈٹ شیٹ (Credit Sheet / سیلز مین شیٹ)</span>
                            <a href="<?= APP_URL ?>/modules/delivery/index.php" class="btn btn-xs btn-outline-success py-0 px-2" style="font-size:0.75rem;">کھولیں</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Sales & Distribution &rarr; Credit Sheet (کریڈٹ شیٹ)</code> پر جائیں۔</p>
                            <p class="mb-1"><strong>طریقہ کار:</strong></p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li>لوڈ فارم سے خودکار طور پر کریڈٹ شیٹ تیار ہو جاتی ہے۔</li>
                                <li>اگر بغیر آرڈر کے مارکیٹ میں ریکوری یا کیش سیل کرنی ہو تو <strong>"New Manual Credit Sheet"</strong> پر کلک کریں۔</li>
                                <li>اس شیٹ پر ہر دکاندار کا سابقہ بیلنس، موجودہ بل اور آگے تین کالم بنے ہیں: <strong>کریڈٹ (Credit)</strong>، <strong>کیش (Cash)</strong> اور <strong>ریٹرن (Return)</strong>۔</li>
                                <li>پرنٹ نکال کر ڈیلیوری سیلز مین کے حوالے کریں تاکہ وہ مارکیٹ میں ہر دکان پر ریکوری اور واپسی درج کر سکے۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 4. Route Settlement -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="route settlement روٹ سیٹلمنٹ کیشیئر کیش حساب کتاب ادھار کھاتہ واپسی f8">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-cash-stack text-success me-2"></i> 4. روٹ سیٹلمنٹ (Route Settlement / حساب کتاب)</span>
                            <a href="<?= APP_URL ?>/modules/settlements/create.php" class="btn btn-xs btn-outline-success py-0 px-2" style="font-size:0.75rem;">کھولیں (F8)</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Sales & Distribution &rarr; Route Settlement</code> یا **F8** دبائیں۔</p>
                            <p class="mb-1"><strong>طریقہ کار:</strong></p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li>شام کو جب گاڑی واپس آئے، سیلز مین منتخب کریں۔</li>
                                <li>ہر دکان کے سامنے وصول شدہ نقد رقم (<strong>Cash</strong>)، دکان پر رہ جانے والا ادھار (<strong>Credit</strong>) اور واپس لایا گیا مال (<strong>Return</strong>) درج کریں۔</li>
                                <li>نیچے دیے گئے ٹوٹل کیش کو کیشیئر سے ملائیں اور سیٹلمنٹ محفوظ کریں۔</li>
                                <li>نقد کیش کیش ان ہینڈ میں شامل ہوگا، ادھار دکاندار کے لیجر میں جائے گا، اور ریٹرن مال گودام میں خودکار واپس جمع ہوگا۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 5. Customers & Khata -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="customer shop retailer دکاندار کسٹمر دکان کھاتہ لیجر نیا کسٹمر بیلنس">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-people text-info me-2"></i> 5. دکاندار اور کسٹمرز لیجر (Customers & Retailers)</span>
                            <a href="<?= APP_URL ?>/modules/customers/index.php" class="btn btn-xs btn-outline-info text-dark py-0 px-2" style="font-size:0.75rem;">کھولیں</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Customers & Retailers &rarr; Customers List</code> یا <code>Customer Ledgers</code>۔</p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li><strong>نیا کسٹمر:</strong> "+ Add Customer" پر کلک کر کے دکان کا نام، مالک، روٹ اور موبائل درج کریں۔</li>
                                <li><strong>کھاتہ / لیجر:</strong> کسی بھی دکاندار کا سابقہ ادھار، روزمرہ بل اور وصولیاں دیکھنے کے لیے "Ledger" پر کلک کریں۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 6. Inventory & Products -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="inventory stock products ریٹ کارٹن پیس اسٹاک مال انوینٹری قیمت purchase wholesale">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-box-seam text-primary me-2"></i> 6. انوینٹری، اسٹاک اور ریٹ لسٹ (Inventory & Products)</span>
                            <a href="<?= APP_URL ?>/modules/inventory/index.php" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:0.75rem;">کھولیں</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Inventory & Products</code> سیکشن۔</p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li><strong>اسٹاک چیک کریں:</strong> <code>Stock Overview</code> میں ہر پروڈکٹ کے موجودہ کارٹن اور پیس ریئل ٹائم نظر آتے ہیں۔</li>
                                <li><strong>ریٹ تبدیل کرنا:</strong> <code>Bulk Price Setting</code> پر جائیں، تمام آئٹمز کے خرید اور ہول سیل ریٹس ایک ساتھ تبدیل کر سکتے ہیں۔</li>
                                <li><strong>اسٹاک لیجر:</strong> <code>Stock Movement Ledger</code> میں ہر آئٹم کی انوائس، لوڈنگ اور واپسی کی ہر اینٹری درج رہتی ہے۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 7. Partners Khata & Shared Expenses -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="partner khata expense خرچہ پارٹنر معظم حامد لائق مشترکہ خرچہ ڈیزل کرایہ ڈرائنگ">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-people-fill text-warning me-2"></i> 7. پارٹنرز کھاتہ اور مشترکہ اخراجات (Partners & Expenses)</span>
                            <a href="<?= APP_URL ?>/modules/partners/index.php" class="btn btn-xs btn-outline-warning text-dark py-0 px-2" style="font-size:0.75rem;">کھولیں</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Accounts & Finance &rarr; Partners & Khatas</code>۔</p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li><strong>مشترکہ خرچے:</strong> جب بھی کوئی خرچہ (کرایہ، بل، پیٹرول، چائے) درج کریں، وہ تینوں پارٹنرز (معظم، حامد، لائق) میں خودکار 33.33% تقسیم ہو جاتا ہے۔</li>
                                <li><strong>ذاتی کیش ایڈوانس:</strong> اگر کسی پارٹنر نے ذاتی خرچ کے لیے پیسے نکالے ہوں تو وہ اس کے ذاتی کھاتے میں درج ہوتے ہیں۔</li>
                                <li><strong>ایجنسی وائز منافع:</strong> یوجب (Ujab) اور ایس ایم ایس (SMS) کا ایجنسی وائز پرافٹ اور اخراجات کا حصہ خودکار بنتا ہے۔</li>
                            </ul>
                        </div>
                    </div>

                    <!-- 8. Daily Closing & Reports -->
                    <div class="card border-0 shadow-sm mb-2 f1-guide-item" data-keywords="reports closing roznamcha روزنامچہ ڈیلی کلوزنگ رپورٹ منافع نقصان balance sheet">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-navy small"><i class="bi bi-journal-bookmark-fill text-info me-2"></i> 8. روزنامچہ اور رپورٹس (Daily Closing & Reports)</span>
                            <a href="<?= APP_URL ?>/modules/reports/daily_closing.php" class="btn btn-xs btn-outline-info text-dark py-0 px-2" style="font-size:0.75rem;">کھولیں</a>
                        </div>
                        <div class="card-body p-2 small text-secondary" dir="rtl">
                            <p class="mb-1"><strong>کہاں جانا ہے:</strong> بائیں مینو میں <code>Reports & Exports &rarr; Daily Closing (Roznamcha)</code>۔</p>
                            <ul class="mb-1 pe-3" style="line-height:1.5;">
                                <li><strong>روزنامچہ:</strong> دن بھر کی کل سیل، کیش وصولیاں، کریڈٹ (ادھار)، اخراجات اور کیش ان ہینڈ کا باقاعدہ فائنل پرنٹ نکالیں۔</li>
                                <li><strong>سیلز و ادھار رپورٹ:</strong> <code>Company Sales & Udhaar</code> میں ایجنسی وائز سیل اور بقایا دیکھیں</li>
                            </ul>
                        </div>
                    </div>

                </div>
            </div>

            <!-- TAB 2: KEYBOARD SHORTCUTS REFERENCE -->
            <div class="tab-pane fade" id="tab-f1-keys" role="tabpanel">
                <p class="text-muted small mb-2">High-speed keyboard operations for fast office data entry:</p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-3">
                        <thead class="table-light small">
                            <tr>
                                <th style="width: 120px;">کی (Key)</th>
                                <th>عمل / فنکشن (Action)</th>
                                <th style="width: 140px;">شارٹ کٹ منزل</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <tr>
                                <td><span class="shortcut-pill">F1</span></td>
                                <td><strong>ہیلپ و یوزر مینوئل کھولیں / بند کریں</strong></td>
                                <td><span class="text-muted">Global Help Drawer</span></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F2</span></td>
                                <td><strong>فوری سرچ بار پر جائیں</strong> (Search Product / Customer)</td>
                                <td><span class="text-muted">Instant Search</span></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F4</span></td>
                                <td><strong>نیا لوڈ فارم بنائیں</strong> (Create Load Form)</td>
                                <td><a href="<?= APP_URL ?>/modules/loading/create.php" class="text-decoration-none">Load Form</a></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F6</span></td>
                                <td><strong>نئی مارکیٹ آرڈر بکنگ کھولیں</strong> (New Order Booking)</td>
                                <td><a href="<?= APP_URL ?>/modules/orders/create.php" class="text-decoration-none">Order Booking</a></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F7</span></td>
                                <td><strong>نیا ڈائریکٹ سیلز بل بنائیں</strong> (New Sales Invoice)</td>
                                <td><a href="<?= APP_URL ?>/modules/invoices/create.php" class="text-decoration-none">Sales Invoice</a></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F8</span></td>
                                <td><strong>شام کی روٹ سیٹلمنٹ کریں</strong> (Route Settlement)</td>
                                <td><a href="<?= APP_URL ?>/modules/settlements/create.php" class="text-decoration-none">Settlement</a></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F9 / Ctrl+S</span></td>
                                <td><strong>فارم محفوظ اور سبمٹ کریں</strong> (Save & Confirm)</td>
                                <td><span class="text-muted">Active Form</span></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">F10</span></td>
                                <td><strong>موجودہ رپورٹ یا بل پرنٹ کریں</strong> (Print Document)</td>
                                <td><span class="text-muted">Browser Print</span></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">Enter</span></td>
                                <td><strong>اگلی فیلڈ پر جائیں / آئٹم ایڈ کریں</strong> (Next Input / Add)</td>
                                <td><span class="text-muted">Form Inputs</span></td>
                            </tr>
                            <tr>
                                <td><span class="shortcut-pill">ESC</span></td>
                                <td><strong>کھلا ہوا سائیڈ پینل بند کریں</strong> (Close Drawer)</td>
                                <td><span class="text-muted">Close Slide Drawer</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-info py-2 small mb-0">
                    <i class="bi bi-info-circle me-1"></i> <strong>بغیر ماؤس کے تیز ترین کام:</strong> پورے سسٹم کو کی بورڈ شارٹ کٹس کے ساتھ استعمال کریں تاکہ بلنگ اور روٹ سیٹلمنٹ سیکنڈوں میں مکمل ہو۔
                </div>
            </div>

        </div>
    </div>
    <div class="dms-drawer-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small">Muzzam Enterprises DMS v<?= APP_VERSION ?></span>
        <button type="button" class="btn btn-sm btn-secondary" onclick="closeDrawer()">بند کریں (ESC)</button>
    </div>
</div>

<script>
function filterF1Guide() {
    var query = document.getElementById('f1GuideSearch').value.toLowerCase().trim();
    var items = document.querySelectorAll('.f1-guide-item');
    items.forEach(function(item) {
        var text = (item.getAttribute('data-keywords') || '') + ' ' + item.innerText.toLowerCase();
        if (query === '' || text.indexOf(query) !== -1) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>

<!-- Global Quick Area / Route Slide Drawer (Real-Time Dynamic Route Creation) -->
<?php require_once __DIR__ . '/drawers/quick_area_drawer.php'; ?>

<!-- Global Quick Customer / Retailer Slide Drawer (Real-Time Dynamic Customer Creation) -->
<?php require_once __DIR__ . '/drawers/quick_customer_drawer.php'; ?>

<!-- Local Scripts (No CDNs) -->
<script src="<?= APP_URL ?>/assets/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
