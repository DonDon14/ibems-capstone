<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>?v=20261004-history-totals" data-user-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Personal debt records',
        'title' => 'My deductions',
        'description' => 'Select a pay period to see the deduction applied to your IBEMS debt.',
        'icon' => 'bi bi-receipt-cutoff',
    ]) ?>

    <div class="user-deduction-filter">
        <div class="field">
            <label for="ud-period">Pay period</label>
            <select id="ud-period" aria-describedby="ud-period-help">
                <option value="">Loading pay periods...</option>
            </select>
        </div>
        <p id="ud-period-help"><i class="bi bi-info-circle" aria-hidden="true"></i> Only confirmed results from the Cashier or Accounting Office are shown. A partial or failed result may leave debt to carry forward.</p>
    </div>

    <div class="dashboard-grid user-deduction-summary">
        <?= view('components/stat_card', ['title' => 'Amount Deducted', 'value' => 'Loading…', 'valueId' => 'ud-total', 'icon' => 'bi bi-cash-coin', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Before', 'value' => 'Loading…', 'valueId' => 'ud-before', 'icon' => 'bi bi-arrow-up-right-circle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Debt After', 'value' => 'Loading…', 'valueId' => 'ud-after', 'icon' => 'bi bi-arrow-down-right-circle', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Entries', 'value' => 'Loading…', 'valueId' => 'ud-count', 'icon' => 'bi bi-list-check', 'tone' => 'debt']) ?>
    </div>

    <div class="table-standard-wrap user-mobile-card-table">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>Pay Period</th>
                    <th>Applied Date</th>
                    <th>Requested</th>
                    <th>Deducted</th>
                    <th>Not deducted</th>
                    <th>Debt Before</th>
                    <th>Debt After</th>
                    <th>Result details</th>
                </tr>
            </thead>
            <tbody id="ud-body">
                <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 8, 'type' => 'loading', 'message' => 'Loading deductions...']) ?>
            </tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/user-deductions.js') ?>?v=20261003-period-busy" data-user-page-script></script>
<?= $this->endSection() ?>
