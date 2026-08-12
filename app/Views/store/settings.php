<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-settings.css') ?>">
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

    <article class="settings-card">
        <h4>Payment Method Management</h4>
        <p class="settings-note">Debt is a protected system method and cannot be deleted.</p>
        <div class="settings-add-row">
            <label class="settings-input-field" for="new-method-label"><span>Method label</span><input id="new-method-label" type="text" placeholder="e.g. Maya"></label>
            <label class="settings-input-field" for="new-method-code"><span>Code (optional)</span><input id="new-method-code" type="text" placeholder="e.g. maya"></label>
            <label class="settings-input-field" for="new-method-icon"><span>Icon class (optional)</span><input id="new-method-icon" type="text" placeholder="e.g. bi bi-wallet2"></label>
            <button id="add-method-btn" class="primary-btn" type="button">Add Method</button>
        </div>
        <div class="settings-table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Label</th>
                        <th>Icon</th>
                        <th class="w-actions-220">Actions</th>
                    </tr>
                </thead>
                <tbody id="payment-method-body">
                    <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 4, 'type' => 'loading', 'message' => 'Loading payment methods...']) ?>
                </tbody>
            </table>
        </div>
        <p id="settings-method-result" class="settings-result"></p>
    </article>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-settings.js') ?>"></script>
<?= $this->endSection() ?>
