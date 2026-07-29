<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-settings.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="settings-shell">
    <div class="settings-top">
        <div>
            <h3>Store Settings</h3>
            <p>Manage product categories and payment methods for this store.</p>
        </div>
    </div>

    <article class="settings-card">
        <h4>Category Management</h4>
        <div class="category-add-row">
            <input id="new-category-name" type="text" placeholder="Category name (e.g. Drinks)">
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
                    <tr><td colspan="2">Loading categories...</td></tr>
                </tbody>
            </table>
        </div>
        <p id="settings-result" class="settings-result"></p>
    </article>

    <article class="settings-card">
        <h4>Payment Method Management</h4>
        <p class="settings-note">Debt is a protected system method and cannot be deleted.</p>
        <div class="settings-add-row">
            <input id="new-method-label" type="text" placeholder="Method label (e.g. Maya)">
            <input id="new-method-code" type="text" placeholder="Code (optional, e.g. maya)">
            <input id="new-method-icon" type="text" placeholder="Bootstrap icon class (optional)">
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
                    <tr><td colspan="4">Loading payment methods...</td></tr>
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
