<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>?v=20260822b">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Financial oversight',
        'title' => 'Accounting debts oversight',
        'description' => 'Read-only debt and deduction visibility for admin audit.',
        'icon' => 'bi bi-cash-stack',
    ]) ?>

    <div class="summary-grid">
        <?= view('components/stat_card', ['title' => 'Accounts', 'value' => '0', 'valueId' => 'ad-account-count', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'With Debt', 'value' => '0', 'valueId' => 'ad-debt-accounts', 'icon' => 'bi bi-exclamation-circle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Total Debt', 'value' => 'PHP 0.00', 'valueId' => 'ad-total-debt', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Today Deducted', 'value' => 'PHP 0.00', 'valueId' => 'ad-today-deducted', 'icon' => 'bi bi-cash-coin', 'tone' => 'finance']) ?>
    </div>

    <section class="data-panel">
        <header class="data-panel-head"><h4>Top Debt Accounts</h4></header>
        <div class="data-panel-body table-standard-wrap">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Current Debt</th>
                    <th>Credit Limit</th>
                </tr>
            </thead>
            <tbody id="ad-top-body">
                <?= view('components/data_state', [
                    'tag' => 'tr',
                    'colspan' => 5,
                    'type' => 'loading',
                    'message' => 'Loading debt records...',
                ]) ?>
            </tbody>
        </table>
        </div>
    </section>
</section>

<div id="ad-account-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="ad-account-modal-title" aria-hidden="true">
    <section class="admin-modal-card app-inset-modal-card ad-account-modal-card">
        <header class="admin-modal-head">
            <div>
                <h4 id="ad-account-modal-title">Debt Account Details</h4>
                <p>Read-only employee financial and store activity.</p>
            </div>
            <button id="ad-account-modal-close" class="admin-modal-close" type="button" aria-label="Close account details"></button>
        </header>
        <div class="app-inset-modal-scroll ad-account-modal-scroll">
            <div id="ad-account-modal-content">
                <?= view('components/data_state', [
                    'type' => 'loading',
                    'message' => 'Loading account details...',
                ]) ?>
            </div>
        </div>
    </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-accounting-debts.js') ?>?v=20260822c"></script>
<?= $this->endSection() ?>
