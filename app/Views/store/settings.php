<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-settings.css') ?>?v=20260824f">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="settings-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Store configuration',
        'title' => 'Store settings',
        'description' => 'Configure how this store operates, organizes products, and accepts payments.',
        'icon' => 'bi bi-gear',
    ]) ?>

    <article class="settings-card capability-settings-card">
        <div class="settings-card-head">
            <div>
                <h4>Business capabilities</h4>
                <p class="settings-note">Select every operation this store performs. Capabilities can be combined; a cafeteria can also sell retail goods and produced items.</p>
            </div>
            <button id="save-capabilities-btn" class="primary-btn" type="button"><i class="bi bi-check2-circle"></i> Save capabilities</button>
        </div>
        <div id="store-capability-grid" class="store-capability-grid" aria-live="polite">
            <label class="capability-option"><input type="checkbox" value="retail"><span><i class="bi bi-basket2"></i><strong>Retail goods</strong><small>Packaged snacks, bottled products, supplies, and other stocked items.</small></span></label>
            <label class="capability-option"><input type="checkbox" value="food_service"><span><i class="bi bi-cup-hot"></i><strong>Food service</strong><small>Prepared meals, daily dishes, servings, and made-to-order products.</small></span></label>
            <label class="capability-option"><input type="checkbox" value="production"><span><i class="bi bi-gear-wide-connected"></i><strong>Production</strong><small>Products made by the university, including processed dairy goods.</small></span></label>
            <label class="capability-option"><input type="checkbox" value="refill_service"><span><i class="bi bi-droplet"></i><strong>Refill service</strong><small>Water refills and products sold by gallon, liter, or container.</small></span></label>
            <label class="capability-option"><input type="checkbox" value="container_deposits"><span><i class="bi bi-arrow-left-right"></i><strong>Container deposits</strong><small>Separate refundable container or bottle deposit line items.</small></span></label>
        </div>
        <p class="settings-note capability-guidance"><i class="bi bi-info-circle"></i> Fixed sizes remain product variants. Checkout customizations and production recipes are separate concerns and are not disguised as variants.</p>
        <p id="capability-result" class="settings-result" role="status" aria-live="polite"></p>
    </article>

    <article id="category-settings-card" class="settings-card category-settings-card">
        <div class="settings-card-head category-card-head">
            <div>
                <h4>Category management</h4>
                <p class="settings-note">Organize products for Inventory and POS. Product counts update automatically.</p>
            </div>
            <div class="category-head-actions">
                <span id="category-count" class="settings-section-count" aria-live="polite">Loading categories</span>
                <button id="add-category-btn" class="primary-btn" type="button"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add category</button>
            </div>
        </div>
        <div id="category-tools" class="category-tools is-hidden" data-compact-filters>
            <label class="settings-input-field" for="category-search"><span>Search categories</span><input id="category-search" type="search" placeholder="Category name"></label>
            <label class="settings-input-field" for="category-sort"><span>Sort by</span><select id="category-sort"><option value="name-asc">Name (A–Z)</option><option value="name-desc">Name (Z–A)</option><option value="products-desc">Most products</option></select></label>
        </div>
        <div class="settings-table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="w-products">Products</th>
                        <th class="w-actions-220">Actions</th>
                    </tr>
                </thead>
                <tbody id="category-body">
                    <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 3, 'type' => 'loading', 'message' => 'Loading categories...']) ?>
                </tbody>
            </table>
        </div>
        <div id="category-pagination" class="category-pagination is-hidden" aria-label="Category pagination">
            <span id="category-page-summary">Page 1 of 1</span>
            <div>
                <button id="category-prev" class="secondary-btn btn-sm" type="button"><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</button>
                <button id="category-next" class="secondary-btn btn-sm" type="button">Next <i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
        </div>
        <p id="settings-result" class="settings-result" role="status" aria-live="polite"></p>
    </article>

    <article class="settings-card payment-settings-card">
        <div class="settings-card-head"><div><h4>Payment methods</h4><p class="settings-note">Cash is built in. Add the wallets, banks, terminals, or other payment destinations accepted by this store.</p></div><button id="add-payment-method-btn" class="primary-btn" type="button"><i class="bi bi-plus-circle"></i> Add payment method</button></div>
        <div id="payment-method-list" class="payment-method-list" aria-live="polite">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading payment methods...']) ?>
        </div>
        <p id="settings-method-result" class="settings-result" role="status" aria-live="polite"></p>
    </article>
</section>

<div id="category-edit-modal" class="settings-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="category-edit-title" aria-describedby="category-edit-subtitle">
    <div class="settings-modal-card category-edit-card app-inset-modal-card">
        <header class="settings-modal-head category-edit-head">
            <div class="category-modal-heading">
                <span class="category-modal-icon" aria-hidden="true"><i class="bi bi-tags"></i></span>
                <div>
                    <span class="settings-modal-eyebrow">Category settings</span>
                    <h3 id="category-edit-title">Edit category</h3>
                    <p id="category-edit-subtitle">Rename this category across Inventory and POS.</p>
                </div>
            </div>
            <button id="category-edit-close" class="icon-btn" type="button" aria-label="Close category editor"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="settings-modal-body category-edit-body app-inset-modal-scroll">
            <div class="category-edit-summary">
                <i class="bi bi-box-seam" aria-hidden="true"></i>
                <div>
                    <strong id="category-edit-current">Selected category</strong>
                    <span id="category-edit-usage">Checking assigned products...</span>
                </div>
            </div>
            <label class="settings-input-field category-edit-field" for="category-edit-name">
                <span>Category name</span>
                <input id="category-edit-name" type="text" maxlength="100" autocomplete="off" placeholder="e.g. Beverages" aria-describedby="category-edit-guidance category-edit-result">
            </label>
            <p id="category-edit-guidance" class="category-edit-guidance"><i class="bi bi-info-circle" aria-hidden="true"></i><span id="category-edit-guidance-text">Products are not deleted. Their category label updates automatically.</span></p>
            <p id="category-edit-result" class="settings-result category-edit-result" role="alert" aria-live="assertive"></p>
        </div>
        <footer class="settings-modal-actions">
            <button id="category-edit-cancel" class="secondary-btn" type="button">Cancel</button>
            <button id="category-edit-save" class="primary-btn" type="button"><i class="bi bi-check2-circle" aria-hidden="true"></i> Save changes</button>
        </footer>
    </div>
</div>

<div id="payment-method-modal" class="settings-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="payment-method-modal-title">
    <div class="settings-modal-card app-inset-modal-card">
        <header class="settings-modal-head"><div><span class="settings-modal-eyebrow">Payment settings</span><h3 id="payment-method-modal-title">Edit payment method</h3></div><button id="payment-method-modal-close" class="icon-btn" type="button" aria-label="Close payment method editor"><i class="bi bi-x-lg"></i></button></header>
        <div class="settings-modal-body app-inset-modal-scroll">
            <section class="method-editor-section">
                <h4>Display</h4>
                <div class="method-display-grid">
                    <label class="settings-input-field"><span>Label</span><input id="edit-method-label" type="text"></label>
                    <label id="method-code-field" class="settings-input-field is-hidden"><span>Code</span><input id="edit-method-code" type="text" placeholder="Generated from label"></label>
                    <label class="settings-input-field"><span>Display image (optional)</span><input id="edit-method-image" type="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                </div>
                <div id="method-image-preview" class="method-image-preview"><span><i class="bi bi-wallet2"></i></span><small>Standard payment image</small></div>
                <small id="method-display-guidance" class="settings-note"></small>
            </section>
            <section id="method-accounts-section" class="method-editor-section">
                <div class="method-section-head"><div><h4>Receiving Accounts &amp; Customer QR</h4><p>Each account appears as an exact destination in POS and reports.</p></div><button id="show-account-form" class="secondary-btn btn-sm" type="button"><i class="bi bi-plus-circle"></i> Add account</button></div>
                <div id="modal-account-form" class="modal-account-form is-hidden">
                    <label class="settings-input-field"><span>Account name</span><input id="account-name" type="text" placeholder="e.g. Main Store GCash"></label>
                    <label class="settings-input-field"><span>Account number</span><input id="account-number" type="text" placeholder="e.g. 0917 000 1234"></label>
                    <label class="settings-input-field"><span>Customer QR (optional)</span><input id="account-qr" type="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
                    <div class="modal-account-actions"><button id="cancel-account-form" class="secondary-btn" type="button">Cancel</button><button id="add-account-btn" class="primary-btn" type="button"><i class="bi bi-check2"></i> Save account</button></div>
                </div>
                <div id="payment-account-list" class="payment-account-list"></div>
                <p id="settings-account-result" class="settings-result"></p>
            </section>
        </div>
        <footer class="settings-modal-actions"><button id="delete-method-btn" class="danger-btn is-hidden" type="button"><i class="bi bi-trash3"></i> Delete payment method</button><span class="settings-modal-spacer"></span><button id="payment-method-modal-cancel" class="secondary-btn" type="button">Cancel</button><button id="save-method-btn" class="primary-btn" type="button"><i class="bi bi-check2-circle"></i> Save changes</button></footer>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-settings.js') ?>?v=20260824f"></script>
<script src="<?= base_url('assets/js/store-settings.part2.js') ?>?v=20260824f"></script>
<?= $this->endSection() ?>
