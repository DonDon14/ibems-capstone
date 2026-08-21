<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
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
        <label for="ud-period">Pay period</label>
        <select id="ud-period" aria-describedby="ud-period-help">
            <option value="">Loading pay periods...</option>
        </select>
        <p id="ud-period-help">Only deductions already applied by Accounting are shown.</p>
    </div>

    <div class="dashboard-grid user-deduction-summary">
        <?= view('components/stat_card', ['title' => 'Amount Deducted', 'value' => 'PHP 0.00', 'valueId' => 'ud-total', 'icon' => 'bi bi-cash-coin', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Before', 'value' => 'PHP 0.00', 'valueId' => 'ud-before', 'icon' => 'bi bi-arrow-up-right-circle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Debt After', 'value' => 'PHP 0.00', 'valueId' => 'ud-after', 'icon' => 'bi bi-arrow-down-right-circle', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Entries', 'value' => '0', 'valueId' => 'ud-count', 'icon' => 'bi bi-list-check', 'tone' => 'debt']) ?>
    </div>

    <div class="table-standard-wrap">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>Pay Period</th>
                    <th>Applied Date</th>
                    <th>Requested</th>
                    <th>Deducted</th>
                    <th>Debt Before</th>
                    <th>Debt After</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="ud-body">
                <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 7, 'type' => 'loading', 'message' => 'Loading deductions...']) ?>
            </tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/user-deductions.js') ?>"></script>
<?= $this->endSection() ?>
