<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="user-shell">
    <div class="user-head">
        <h3>Transaction History</h3>
        <p>Your purchase records across stores.</p>
    </div>

    <div class="user-filters">
        <div class="field">
            <label for="uh-date-from">From</label>
            <input id="uh-date-from" type="date">
        </div>
        <div class="field">
            <label for="uh-date-to">To</label>
            <input id="uh-date-to" type="date">
        </div>
        <button id="uh-apply" class="primary-btn" type="button">Apply</button>
        <button id="uh-clear" class="history-action alt" type="button">Clear</button>
    </div>

    <div class="user-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Store</th>
                    <th>Payment</th>
                    <th>Amount</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody id="uh-body">
                <tr><td colspan="5">Loading transactions...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="user-head">
        <h3>Debt Cashbook</h3>
        <p>Track debt purchases, salary/manual deductions, and running debt balance.</p>
    </div>

    <div class="user-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Entry</th>
                    <th>Direction</th>
                    <th>Amount</th>
                    <th>Debt Before</th>
                    <th>Debt After</th>
                    <th>Available Credit</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody id="uh-cashbook-body">
                <tr><td colspan="8">Loading cashbook...</td></tr>
            </tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/user-history.js') ?>"></script>
<?= $this->endSection() ?>
