<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell" data-store-id="<?= (int) $storeId ?>" data-details-url="<?= site_url('store-admin/stores/' . (int) $storeId . '/data') ?>" data-review-url-prefix="<?= site_url('store-admin/store-day-sessions') ?>">
    <?= view('components/page_header', [
        'eyebrow' => 'Store operations',
        'title' => 'Store details',
        'titleId' => 'sd-store-name',
        'description' => 'Loading store profile...',
        'descriptionId' => 'sd-store-meta',
        'icon' => 'bi bi-shop-window',
    ]) ?>

    <div class="summary-grid">
        <?= view('components/stat_card', ['title' => 'Total Transactions', 'value' => '0', 'valueId' => 'sd-txn-count', 'icon' => 'bi bi-receipt-cutoff', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Total Sales', 'value' => 'PHP 0.00', 'valueId' => 'sd-sales-total', 'icon' => 'bi bi-graph-up-arrow', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Products', 'value' => '0', 'valueId' => 'sd-product-count', 'icon' => 'bi bi-box-seam', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Total Stock Units', 'value' => '0', 'valueId' => 'sd-stock-units', 'icon' => 'bi bi-boxes', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Today Sales', 'value' => 'PHP 0.00', 'valueId' => 'sd-today-sales', 'icon' => 'bi bi-calendar2-check', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Transactions Today', 'value' => '0', 'valueId' => 'sd-debt-txns', 'icon' => 'bi bi-credit-card-2-front', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Active Products', 'value' => '0', 'valueId' => 'sd-active-products', 'icon' => 'bi bi-box-seam', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Low Stock', 'value' => '0', 'valueId' => 'sd-low-stock', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'warning']) ?>
    </div>

    <div class="overview-grid store-detail-overview-grid">
        <article class="dash-panel">
            <h4>Store Day</h4>
            <div id="sd-day-session" class="stack-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading store day status...']) ?>
            </div>
        </article>

        <article class="dash-panel">
            <h4>Assigned Officers</h4>
            <div id="sd-officers" class="stack-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading officers...']) ?>
            </div>
        </article>
    </div>

    <section class="data-panel">
        <header class="data-panel-head store-day-history-head">
            <div>
                <h4>Store Day History</h4>
                <p>Review recent sessions and unresolved historical variances.</p>
            </div>
            <div class="store-detail-table-tools">
                <label class="store-day-history-filter">
                    <span>Review status</span>
                    <select id="sd-session-filter">
                        <option value="all">All sessions</option>
                        <option value="unresolved">Unresolved only</option>
                        <option value="needs_investigation">Needs investigation</option>
                        <option value="pending">Pending review</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </label>
                <label class="store-day-history-filter store-detail-rows-filter">
                    <span>Rows</span>
                    <select id="sd-session-page-size"><option value="10">10</option><option value="25">25</option><option value="50">50</option></select>
                </label>
            </div>
        </header>
        <div class="data-panel-body table-standard-wrap">
            <table class="table table-standard">
                <thead><tr><th>Date</th><th>Day</th><th>Variance</th><th>Review</th><th>Closed By</th><th>Action</th></tr></thead>
                <tbody id="sd-session-history-body">
                    <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 6, 'type' => 'loading', 'message' => 'Loading store day history...']) ?>
                </tbody>
            </table>
        </div>
        <div id="sd-session-pager" class="overview-pager store-detail-pager"></div>
    </section>

    <section class="data-panel">
        <header class="data-panel-head store-detail-section-head"><div><h4>Inventory</h4><p id="sd-inventory-count">Loading products...</p></div><label class="store-day-history-filter store-detail-rows-filter"><span>Rows</span><select id="sd-inventory-page-size"><option value="10">10</option><option value="25">25</option><option value="50">50</option></select></label></header>
        <div class="data-panel-body table-standard-wrap">
        <table class="table table-standard">
            <thead><tr><th>Product</th><th>Stock</th><th>Price</th></tr></thead>
            <tbody id="sd-inventory-body"><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 3, 'type' => 'loading', 'message' => 'Loading inventory...']) ?></tbody>
        </table>
        </div>
        <div id="sd-inventory-pager" class="overview-pager store-detail-pager"></div>
    </section>

    <section class="data-panel">
        <header class="data-panel-head store-detail-section-head"><div><h4>Recent Transactions</h4><p id="sd-transactions-count">Loading transactions...</p></div><label class="store-day-history-filter store-detail-rows-filter"><span>Rows</span><select id="sd-transactions-page-size"><option value="10">10</option><option value="20">20</option></select></label></header>
        <div class="data-panel-body table-standard-wrap">
        <table class="table table-standard">
            <thead><tr><th>Date</th><th>Customer</th><th>Payment</th><th>Amount</th></tr></thead>
            <tbody id="sd-transactions-body"><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 4, 'type' => 'loading', 'message' => 'Loading transactions...']) ?></tbody>
        </table>
        </div>
        <div id="sd-transactions-pager" class="overview-pager store-detail-pager"></div>
    </section>

    <div id="sd-stale-day-modal" class="admin-modal admin-overview-modal sd-stale-day-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="sd-stale-day-title" aria-describedby="sd-stale-day-description">
        <div class="admin-modal-card sd-stale-day-card" tabindex="-1">
            <header class="admin-modal-head">
                <div><span class="modal-eyebrow">Store day reconciliation</span><h4 id="sd-stale-day-title">Resolve previous store day</h4><p id="sd-stale-day-description">Record independently counted balances for the original business date.</p></div>
                <button id="sd-stale-day-close" type="button" class="admin-modal-close" aria-label="Close store day reconciliation">x</button>
            </header>
            <form id="sd-stale-day-form" class="stale-day-resolution-form" data-stale-session-id="">
                <div class="stale-day-expected" role="status"><div><span>System expected cash</span><strong id="sd-stale-expected-cash">PHP 0.00</strong></div><div><span>System expected e-cash</span><strong id="sd-stale-expected-ecash">PHP 0.00</strong></div><p>Count the physical and e-cash balances independently before comparing them with these system totals.</p></div>
                <div id="sd-stale-payment-breakdown" class="stale-day-payment-breakdown" aria-label="Expected balance calculation"></div>
                <div class="stale-day-fields"><label><span>Counted cash</span><input type="number" name="counted_cash" min="0" step="0.01" placeholder="PHP 0.00" required></label><label><span>Counted e-cash</span><input type="number" name="counted_ecash" min="0" step="0.01" placeholder="PHP 0.00" required></label><label class="stale-day-reason"><span>Resolution reason</span><textarea name="reason" rows="3" required placeholder="Explain why the day remained open and how the balances were counted"></textarea></label></div>
                <p class="stale-day-guidance"><i class="bi bi-shield-check" aria-hidden="true"></i> Any difference creates a variance case for independent review.</p>
                <div class="admin-modal-actions"><button id="sd-stale-day-cancel" type="button" class="secondary-btn">Cancel</button><button type="submit" class="danger-btn">Resolve previous day</button></div>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-store-details.js') ?>?v=20260824a"></script>
<?= $this->endSection() ?>
