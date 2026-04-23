<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<section class="staff-shell space-y-5">
    <div class="staff-head">
        <div>
            <h3 class="text-3xl font-bold tracking-tight text-slate-900">Employee Records</h3>
            <p class="mt-1 text-base text-slate-600">Search employee debt records and employee transaction records.</p>
        </div>
    </div>

    <article class="staff-card rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h4 class="text-lg font-bold text-slate-900">Debt Records</h4>
        <div class="staff-filters staff-filter-panel mb-3 grid items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_auto]">
            <div class="staff-filter-main flex flex-wrap items-end gap-3">
                <div class="staff-search-wrap relative min-w-[260px] grow">
                    <i class="bi bi-search staff-search-icon pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                    <input id="debt-search" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-12 text-sm text-slate-700 outline-none transition focus:border-blue-300 focus:bg-white" type="search" placeholder="Search name, ID, office...">
                    <button id="debt-search-scan-btn" type="button" class="staff-scan-btn absolute right-1 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" title="Scan employee QR/ID">
                        <i class="bi bi-qr-code-scan"></i>
                    </button>
                </div>
            </div>
            <div class="staff-filter-actions flex flex-wrap gap-2">
                <button id="debt-search-btn" class="history-action alt inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-search"></i> Search</button>
            </div>
        </div>
        <p id="staff-count-text" class="staff-count-text text-sm text-slate-500">Showing 0 records</p>

        <div class="table-wrap table-standard-wrap rounded-2xl border border-slate-200 bg-white p-2">
            <div id="debt-body" class="staff-record-list grid gap-2">
                <div class="staff-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">Loading debt records...</div>
            </div>
        </div>
    </article>

    <p id="staff-result" class="staff-result text-sm font-semibold"></p>
</section>

<div id="staff-employee-modal" class="receipt-modal is-hidden">
    <div class="receipt-card staff-employee-card max-h-[92vh] w-[min(1200px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="receipt-head">
            <h3 class="text-lg font-bold text-slate-900">Employee Transactions</h3>
            <button id="staff-employee-close" type="button" class="receipt-close">x</button>
        </div>

        <div id="staff-employee-summary" class="receipt-content-head mb-3 space-y-1 text-sm text-slate-700"></div>

        <div class="staff-filters staff-modal-filters flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
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
            <button id="txn-search-btn" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700" type="button">Apply</button>
        </div>

        <div class="table-wrap table-standard-wrap overflow-auto rounded-xl border border-slate-200">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Date</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Payment</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Amount</th>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Current Debt</th>
                    </tr>
                </thead>
                <tbody id="txn-body">
                    <tr><td class="px-3 py-4 text-sm text-slate-500" colspan="4">Select an employee to load transactions.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="staff-receipt-modal" class="receipt-modal is-hidden">
    <div class="receipt-card max-h-[92vh] w-[min(760px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="receipt-head">
            <h3 class="text-lg font-bold text-slate-900">Transaction Receipt</h3>
            <button id="staff-receipt-close" type="button" class="receipt-close">x</button>
        </div>

        <div id="staff-receipt-content"></div>

        <div class="receipt-actions">
            <button id="staff-receipt-print" type="button" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700">Print Receipt</button>
        </div>
    </div>
</div>

<div id="staff-scanner-modal" class="receipt-modal is-hidden">
    <div class="receipt-card scanner-card max-h-[92vh] w-[min(760px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="receipt-head">
            <h3 class="text-lg font-bold text-slate-900"><i class="bi bi-person-badge"></i> Employee QR Scanner</h3>
            <button id="staff-scanner-close" type="button" class="receipt-close">x</button>
        </div>
        <div class="scanner-body">
            <div id="staff-scanner-reader" class="w-full overflow-hidden rounded-xl border border-slate-200 bg-slate-900"></div>
            <p id="staff-scanner-status" class="scanner-status mt-3 text-sm text-slate-600">Ready to scan.</p>
            <div class="scanner-actions">
                <button id="staff-scanner-stop" type="button" class="scan-btn inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"><i class="bi bi-stop-fill"></i> Stop</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= base_url('assets/js/store-staff-records.js') ?>"></script>
<?= $this->endSection() ?>
