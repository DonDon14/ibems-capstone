<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-inventory.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="inventory-shell">
    <div class="inventory-top">
        <div>
            <h3>Inventory Management</h3>
            <p>Stock-in products and monitor inventory movements.</p>
        </div>
        <div class="inventory-store-wrap">
            <label for="inventory-store-select">Store</label>
            <select id="inventory-store-select"></select>
        </div>
    </div>

    <div class="inventory-grid">
        <article class="inventory-card">
            <h4>Stock In</h4>
            <div class="form-grid">
                <div class="field">
                    <label for="restock-product">Product</label>
                    <select id="restock-product"></select>
                </div>
                <div class="field">
                    <label for="restock-qty">Quantity</label>
                    <input id="restock-qty" type="number" min="1" step="1" value="1">
                </div>
                <div class="field">
                    <label for="restock-unit-cost">Unit Cost</label>
                    <input id="restock-unit-cost" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label for="restock-reason">Reason</label>
                    <input id="restock-reason" type="text" value="Stock in">
                </div>
            </div>

            <div class="projection">
                <div><span>Product Price:</span> <strong id="proj-price">PHP 0.00</strong></div>
                <div><span>Total Cost:</span> <strong id="proj-cost">PHP 0.00</strong></div>
                <div><span>Expected Profit:</span> <strong id="proj-profit">PHP 0.00</strong></div>
            </div>

            <button id="restock-submit" class="primary-btn" type="button">Submit Stock In</button>
            <p id="inventory-result" class="inventory-result"></p>
        </article>

        <article class="inventory-card">
            <h4>Movements</h4>
            <div class="movement-filters">
                <div class="field">
                    <label for="mov-type">Type</label>
                    <select id="mov-type">
                        <option value="">All</option>
                        <option value="sale">Sale</option>
                        <option value="restock">Restock</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                </div>
                <div class="field">
                    <label for="mov-product">Product</label>
                    <select id="mov-product"></select>
                </div>
                <div class="field">
                    <label for="mov-date-from">From</label>
                    <input id="mov-date-from" type="date">
                </div>
                <div class="field">
                    <label for="mov-date-to">To</label>
                    <input id="mov-date-to" type="date">
                </div>
                <button id="mov-apply" class="history-action" type="button">Apply</button>
                <button id="mov-clear" class="history-action alt" type="button">Clear</button>
            </div>

            <div class="movement-table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Total Cost</th>
                            <th>Expected Profit</th>
                        </tr>
                    </thead>
                    <tbody id="movement-body">
                        <tr><td colspan="6">Loading movements...</td></tr>
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-inventory.js') ?>"></script>
<?= $this->endSection() ?>
