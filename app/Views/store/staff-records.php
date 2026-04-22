<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-staff-records.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="staff-shell">
    <div class="staff-head">
        <div>
            <h3>Employee Records</h3>
            <p>Search employee debt records and employee transaction records.</p>
        </div>
    </div>

    <article class="staff-card">
        <h4>Debt Records</h4>
        <div class="staff-filters">
            <div class="field">
                <label for="debt-search">Search Employee</label>
                <div class="staff-search-wrap">
                    <input id="debt-search" type="search" placeholder="Name, email, or employee ID">
                    <button id="debt-search-scan-btn" type="button" class="staff-scan-btn" title="Scan employee QR/ID">
                        <i class="bi bi-qr-code-scan"></i>
                    </button>
                </div>
            </div>
            <button id="debt-search-btn" class="primary-btn" type="button">Search</button>
        </div>

        <div class="table-wrap table-standard-wrap">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Current Debt</th>
                        <th>Credit Limit</th>
                        <th>Available Credit</th>
                    </tr>
                </thead>
                <tbody id="debt-body">
                    <tr><td colspan="8">Loading debt records...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <p id="staff-result" class="staff-result"></p>
</section>

<div id="staff-employee-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card staff-employee-card">
        <div class="receipt-head">
            <h3>Employee Transactions</h3>
            <button id="staff-employee-close" type="button" class="receipt-close">x</button>
        </div>

        <div id="staff-employee-summary" class="receipt-content-head"></div>

        <div class="staff-filters staff-modal-filters">
            <div class="field">
                <label for="txn-date-from">From</label>
                <input id="txn-date-from" type="date">
            </div>
            <div class="field">
                <label for="txn-date-to">To</label>
                <input id="txn-date-to" type="date">
            </div>
            <div class="field checkbox-field">
                <label><input id="txn-debt-only" type="checkbox"> Debt only</label>
            </div>
            <button id="txn-search-btn" class="primary-btn" type="button">Apply</button>
        </div>

        <div class="table-wrap table-standard-wrap">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Payment</th>
                        <th>Amount</th>
                        <th>Current Debt</th>
                    </tr>
                </thead>
                <tbody id="txn-body">
                    <tr><td colspan="4">Select an employee to load transactions.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="staff-receipt-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3>Transaction Receipt</h3>
            <button id="staff-receipt-close" type="button" class="receipt-close">x</button>
        </div>

        <div id="staff-receipt-content"></div>

        <div class="receipt-actions">
            <button id="staff-receipt-print" type="button" class="primary-btn">Print Receipt</button>
        </div>
    </div>
</div>

<div id="staff-scanner-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card scanner-card">
        <div class="receipt-head">
            <h3><i class="bi bi-person-badge"></i> Employee QR Scanner</h3>
            <button id="staff-scanner-close" type="button" class="receipt-close">x</button>
        </div>
        <div class="scanner-body">
            <div id="staff-scanner-reader"></div>
            <p id="staff-scanner-status" class="scanner-status">Ready to scan.</p>
            <div class="scanner-actions">
                <button id="staff-scanner-stop" type="button" class="scan-btn"><i class="bi bi-stop-fill"></i> Stop</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= base_url('assets/js/store-staff-records.js') ?>"></script>
<?= $this->endSection() ?>
