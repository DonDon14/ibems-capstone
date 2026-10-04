<?= $this->extend('layouts/department') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/department-debt.css') ?>?v=20261004-admin-save-feedback" data-portal-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section id="department-user-page" class="department-page dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Department authorization',
        'title' => 'Department approval PIN',
        'description' => 'Manage the PIN used when you personally approve a department charge. It is separate from your personal purchase card.',
        'icon' => 'bi bi-shield-lock',
    ]) ?>
    <div class="department-user-grid">
        <article class="department-assignment-panel"><header><span>Your authority</span><h3>Departments you head</h3></header><div id="department-user-assignments" aria-live="polite"><?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading department assignments...']) ?></div></article>
        <form id="department-pin-form" class="department-pin-panel">
            <header><span>Secure authorization</span><h3 id="department-pin-heading">Set approval PIN</h3></header>
            <p>This PIN is only for approving department-funded purchases at POS.</p>
            <label id="department-current-password-wrap" hidden><span>Current account password</span><input id="department-current-password" aria-label="Current account password" type="password" autocomplete="current-password" maxlength="4096"></label>
            <label><span>New PIN</span><input id="department-pin" aria-label="New PIN" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" pattern="[0-9]{4,6}" required></label>
            <label><span>Confirm PIN</span><input id="department-pin-confirmation" aria-label="Confirm PIN" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" pattern="[0-9]{4,6}" required></label>
            <button class="primary-btn" type="submit" disabled><i class="bi bi-shield-check" aria-hidden="true"></i> <span id="department-pin-save-label">Save Department PIN</span></button>
            <p id="department-pin-result" class="department-result" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p><small id="department-pin-status">Checking PIN status...</small>
            <button id="department-pin-retry" class="secondary-btn" type="button" hidden>Reload assignments</button>
        </form>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/department-debt-pages.js') ?>?v=20261004-admin-save-feedback" data-portal-page-script></script>
<?= $this->endSection() ?>
