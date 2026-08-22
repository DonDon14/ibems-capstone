<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-staff-records.css') ?>?v=20260821b">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>?v=20260821b">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="staff-shell space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Employee accounts',
        'title' => 'Employee accounts',
        'description' => 'Review cross-store credit balances and authorized store purchase history.',
        'icon' => 'bi bi-person-vcard',
    ]) ?>

    <article class="staff-card">
        <div class="staff-card-head">
            <div>
                <h4>Credit &amp; debt across all stores</h4>
                <p>Monitor each employee's configured credit profile and current balance.</p>
            </div>
            <span id="staff-count-badge" class="staff-count-badge">0 employees</span>
        </div>
        <div class="staff-filters staff-filter-panel" data-compact-filters>
            <div class="staff-filter-main">
                <div class="staff-search-field">
                    <label for="debt-search">Search employees</label>
                    <div class="staff-search-wrap">
                        <input id="debt-search" type="search" placeholder="Name, ID, or office">
                        <button id="debt-clear-btn" class="search-clear-btn is-hidden" type="button" aria-label="Clear employee search" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        <button id="debt-search-scan-btn" type="button" class="search-scan-btn" aria-label="Scan employee QR or ID" title="Scan employee QR/ID">
                            <i class="bi bi-qr-code-scan"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="staff-filter-actions">
                <label class="field">
                    <span>Sort</span>
                    <select id="debt-sort">
                        <option value="name:asc">Name A-Z</option>
                        <option value="name:desc">Name Z-A</option>
                        <option value="debt:desc">Highest Debt</option>
                        <option value="credit:desc">Highest Credit Limit</option>
                    </select>
                </label>
                <label class="field">
                    <span>Rows</span>
                    <select id="debt-page-size">
                        <option value="10">10</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                    </select>
                </label>
                <button id="debt-search-btn" class="primary-btn" type="button"><i class="bi bi-search"></i> Search</button>
            </div>
        </div>
        <div class="staff-scope-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Only active faculty and staff with Accounting-configured profiles appear here. Financial totals cover <strong>all stores</strong>; transaction history is limited to the selected authorized store.</span></div>
        <p id="staff-count-text" class="staff-count-text text-sm text-slate-500">Showing 0 records</p>

        <div class="staff-record-list-wrap record-panel">
            <div id="debt-body" class="staff-record-list record-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading debt records...']) ?>
            </div>
        </div>
        <div id="debt-pager" class="table-pagination"></div>
    </article>

    <p id="staff-result" class="staff-result text-sm font-semibold"></p>
</section>

<div id="staff-employee-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="staff-employee-title">
    <div class="receipt-card staff-employee-card">
        <div class="receipt-head staff-modal-head">
            <div>
                <span class="staff-modal-eyebrow">Employee account</span>
                <h3 id="staff-employee-title">Transaction history</h3>
            </div>
            <button id="staff-employee-close" type="button" class="secondary-btn btn-icon receipt-close" aria-label="Close employee transactions"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="staff-modal-body">
        <div id="staff-employee-summary" class="staff-employee-summary"></div>

        <div class="staff-filters staff-modal-filters">
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="txn-store-select">Store</label>
                <select id="txn-store-select"></select>
            </div>
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="txn-date-from">From</label>
                <input id="txn-date-from" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="date">
            </div>
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="txn-date-to">To</label>
                <input id="txn-date-to" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="date">
            </div>
            <div class="field checkbox-field">
                <span>Payment</span>
                <label><input id="txn-debt-only" type="checkbox"> Debt only</label>
            </div>
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="txn-sort">Sort</label>
                <select id="txn-sort">
                    <option value="date:desc">Newest First</option>
                    <option value="date:asc">Oldest First</option>
                    <option value="amount:desc">Highest Amount</option>
                    <option value="amount:asc">Lowest Amount</option>
                </select>
            </div>
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="txn-page-size">Rows</label>
                <select id="txn-page-size">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                </select>
            </div>
            <button id="txn-clear-btn" class="secondary-btn is-hidden" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset filters</button>
        </div>

        <p id="txn-filter-result" class="staff-filter-result" role="status" aria-live="polite"></p>

        <div class="table-wrap table-standard-wrap overflow-auto rounded-xl border border-slate-200">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Date</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Payment</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Amount</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Overall Debt Now</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Action</th>
                    </tr>
                </thead>
                <tbody id="txn-body">
                    <tr><td class="px-3 py-4 text-sm text-slate-500" colspan="5">Select an employee to load transactions.</td></tr>
                </tbody>
            </table>
        </div>
        <div id="txn-pager" class="table-pagination"></div>
        </div>
    </div>
</div>

<div id="staff-receipt-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="staff-receipt-title">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3 id="staff-receipt-title" class="text-lg font-bold text-slate-900">Transaction Receipt</h3>
            <button id="staff-receipt-close" type="button" class="secondary-btn btn-icon receipt-close" aria-label="Close transaction receipt"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="staff-receipt-content"></div>

        <div class="receipt-actions">
            <a id="staff-receipt-view" class="secondary-btn link-reset" href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> View Receipt</a>
            <button id="staff-receipt-print" type="button" class="primary-btn"><i class="bi bi-printer"></i> Print Receipt</button>
        </div>
    </div>
</div>

<div id="staff-scanner-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="staff-scanner-title">
    <div class="receipt-card scanner-card max-h-[92vh] w-[min(760px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="receipt-head">
            <h3 id="staff-scanner-title" class="text-lg font-bold text-slate-900"><i class="bi bi-person-badge"></i> Employee QR Scanner</h3>
            <button id="staff-scanner-close" type="button" class="secondary-btn btn-icon receipt-close" aria-label="Close employee QR scanner"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="scanner-body">
            <div id="staff-scanner-reader" class="w-full overflow-hidden rounded-xl border border-slate-200 bg-slate-900"></div>
            <p id="staff-scanner-status" class="scanner-status mt-3 text-sm text-slate-600">Ready to scan.</p>
            <div class="scanner-actions">
                <button id="staff-scanner-stop" type="button" class="secondary-btn"><i class="bi bi-stop-fill"></i> Stop</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260821a"></script>
<script src="<?= base_url('assets/js/store-staff-records.js') ?>?v=20260822b"></script>
<?= $this->endSection() ?>
