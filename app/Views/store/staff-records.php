<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-staff-records.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="staff-shell">
    <div class="staff-head">
        <div>
            <h3>Staff Records</h3>
            <p>Search staff debt records and staff transaction records.</p>
        </div>
        <div class="staff-store-wrap">
            <label for="staff-store-select">Store</label>
            <select id="staff-store-select"></select>
        </div>
    </div>

    <article class="staff-card">
        <h4>Debt Records</h4>
        <div class="staff-filters">
            <div class="field">
                <label for="debt-search">Search Staff</label>
                <input id="debt-search" type="search" placeholder="Name, email, or employee ID">
            </div>
            <button id="debt-search-btn" class="primary-btn" type="button">Search</button>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Current Debt</th>
                        <th>Credit Limit</th>
                        <th>Available Credit</th>
                    </tr>
                </thead>
                <tbody id="debt-body">
                    <tr><td colspan="6">Loading debt records...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <article class="staff-card">
        <h4>Staff Transactions</h4>
        <div class="staff-filters">
            <div class="field">
                <label for="txn-search">Search Staff</label>
                <input id="txn-search" type="search" placeholder="Name, email, or employee ID">
            </div>
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
            <button id="txn-search-btn" class="primary-btn" type="button">Search</button>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Staff</th>
                        <th>Employee ID</th>
                        <th>Payment</th>
                        <th>Amount</th>
                        <th>Current Debt</th>
                    </tr>
                </thead>
                <tbody id="txn-body">
                    <tr><td colspan="6">Loading transactions...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <p id="staff-result" class="staff-result"></p>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-staff-records.js') ?>"></script>
<?= $this->endSection() ?>
