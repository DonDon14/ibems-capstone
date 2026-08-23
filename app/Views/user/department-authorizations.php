<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/department-debt.css') ?>?v=20260824d" data-user-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section id="department-user-page" class="department-page">
    <?= view('components/page_header', [
        'eyebrow' => 'Department authorization',
        'title' => 'Department approval PIN',
        'description' => 'Manage the separate PIN used to approve a department charge at POS. Department charges never become your personal debt.',
        'icon' => 'bi bi-shield-lock',
    ]) ?>

    <div class="department-user-grid">
        <article class="department-assignment-panel">
            <header><span>Your authority</span><h3>Departments you head</h3></header>
            <div id="department-user-assignments"><p>Loading assignments...</p></div>
        </article>
        <form id="department-pin-form" class="department-pin-panel">
            <header><span>Secure authorization</span><h3 id="department-pin-heading">Set approval PIN</h3></header>
            <p>This 4–6 digit PIN is separate from your employee debt PIN. Enter it only when you personally approve a department purchase.</p>
            <label id="department-current-password-wrap" hidden><span>Current account password</span><input id="department-current-password" type="password" autocomplete="current-password" maxlength="4096"></label>
            <label><span>New PIN</span><input id="department-pin" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" pattern="[0-9]{4,6}" required></label>
            <label><span>Confirm PIN</span><input id="department-pin-confirmation" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" pattern="[0-9]{4,6}" required></label>
            <button class="primary-btn" type="submit"><i class="bi bi-shield-check"></i> Save Department PIN</button>
            <p id="department-pin-result" class="department-result" role="status" aria-live="polite"></p>
            <small id="department-pin-status">Checking PIN status...</small>
        </form>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/department-debt-pages.js') ?>?v=20260824d" data-user-page-script></script>
<?= $this->endSection() ?>
