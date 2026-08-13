<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-settings.css') ?>?v=20260813e">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="settings-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Store configuration',
        'title' => 'Store settings',
        'description' => 'Manage product categories and payment methods for this store.',
        'icon' => 'bi bi-gear',
    ]) ?>

    <article class="settings-card">
        <h4>Category Management</h4>
        <div class="category-add-row">
            <label class="settings-input-field" for="new-category-name"><span>Category name</span><input id="new-category-name" type="text" placeholder="e.g. Drinks"></label>
            <button id="add-category-btn" class="primary-btn" type="button">Add Category</button>
        </div>
        <div class="settings-table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="w-actions-220">Actions</th>
                    </tr>
                </thead>
                <tbody id="category-body">
                    <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 2, 'type' => 'loading', 'message' => 'Loading categories...']) ?>
                </tbody>
            </table>
        </div>
        <p id="settings-result" class="settings-result"></p>
    </article>

    <article class="settings-card payment-settings-card">
        <div class="settings-card-head"><div><h4>Payment Methods</h4><p class="settings-note">Cash is built in. Add the wallets, banks, terminals, or other payment destinations accepted by this store.</p></div><button id="add-payment-method-btn" class="primary-btn" type="button"><i class="bi bi-plus-circle"></i> Add payment method</button></div>
        <div id="payment-method-list" class="payment-method-list" aria-live="polite">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading payment methods...']) ?>
        </div>
        <p id="settings-method-result" class="settings-result"></p>
    </article>
</section>

<div id="payment-method-modal" class="settings-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="payment-method-modal-title">
    <div class="settings-modal-card">
        <header class="settings-modal-head"><div><span class="settings-modal-eyebrow">Payment settings</span><h3 id="payment-method-modal-title">Edit payment method</h3></div><button id="payment-method-modal-close" class="icon-btn" type="button" aria-label="Close payment method editor"><i class="bi bi-x-lg"></i></button></header>
        <div class="settings-modal-body">
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
<script src="<?= base_url('assets/js/store-settings.js') ?>?v=20260813f"></script>
<?= $this->endSection() ?>
