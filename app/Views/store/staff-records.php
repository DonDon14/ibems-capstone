<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-staff-records.css') ?>?v=20260813c">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
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
        <h4 class="text-lg font-bold text-slate-900">Credit &amp; Debt Across All Stores</h4>
        <div class="staff-filters staff-filter-panel">
            <div class="staff-filter-main">
                <div class="staff-search-field">
                    <label for="debt-search">Search Employees</label>
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
                <button id="debt-search-btn" class="secondary-btn" type="button"><i class="bi bi-search"></i> Search</button>
            </div>
        </div>
        <p class="staff-scope-note">Showing active Faculty and Staff with an employee credit account. Debt, credit limit, and available credit are totals across all stores; purchase history is filtered to an authorized store.</p>
        <p id="staff-count-text" class="staff-count-text text-sm text-slate-500">Showing 0 records</p>

        <div class="staff-record-list-wrap record-panel">
            <div id="debt-body" class="staff-record-list record-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading debt records...']) ?>
            </div>
        </div>
    </article>

    <p id="staff-result" class="staff-result text-sm font-semibold"></p>
</section>

<div id="staff-employee-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="staff-employee-title">
    <div class="receipt-card staff-employee-card max-h-[92vh] w-[min(1200px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="receipt-head">
            <h3 id="staff-employee-title" class="text-lg font-bold text-slate-900">Employee transactions</h3>
            <button id="staff-employee-close" type="button" class="secondary-btn btn-icon receipt-close" aria-label="Close employee transactions"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="staff-employee-summary" class="receipt-content-head mb-3 space-y-1 text-sm text-slate-700"></div>

        <div class="staff-filters staff-modal-filters flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
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
                <label class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700"><input id="txn-debt-only" class="h-4 w-4" type="checkbox"> Debt only</label>
            </div>
            <button id="txn-search-btn" class="primary-btn" type="button">Apply</button>
            <button id="txn-clear-btn" class="secondary-btn" type="button"><i class="bi bi-x-circle"></i> Clear</button>
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
    </div>
</div>

<div id="staff-receipt-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="staff-receipt-title">
    <div class="receipt-card max-h-[92vh] w-[min(760px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
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
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260813j"></script>
<script src="<?= base_url('assets/js/store-staff-records.js') ?>?v=20260813d"></script>
<?= $this->endSection() ?>
