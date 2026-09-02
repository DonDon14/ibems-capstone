<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-purchase-card.css') ?>?v=20260825a" data-user-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section id="user-purchase-card-page" class="dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Purchase security',
        'title' => 'Employee purchase card',
        'description' => 'Control whether your employee ID can authorize a debt purchase at POS.',
        'icon' => 'bi bi-credit-card-2-front',
    ]) ?>

    <div class="purchase-card-grid">
        <article id="purchase-card-visual" class="purchase-card-visual is-locked" aria-live="polite">
            <div class="purchase-card-head"><span>IBEMS</span><i class="bi bi-wifi-2" aria-hidden="true"></i></div>
            <div class="purchase-card-identity">
                <img id="purchase-card-avatar" src="<?= esc(ibems_profile_image_url(session()->get('profile_image_url'))) ?>" alt="">
                <div><small>Employee purchase account</small><strong id="purchase-card-name"><?= esc((string) session()->get('name')) ?></strong><span id="purchase-card-employee-id">Loading employee ID...</span></div>
            </div>
            <div class="purchase-card-state"><i id="purchase-card-state-icon" class="bi bi-lock-fill" aria-hidden="true"></i><div><small>Current status</small><strong id="purchase-card-state-label">Locked</strong></div></div>
        </article>

        <article class="purchase-card-controls">
            <span class="purchase-card-kicker">Phone-controlled authorization</span>
            <h3 id="purchase-card-control-title">Your card is locked</h3>
            <p id="purchase-card-control-copy">Your employee ID may be scanned, but a debt transaction cannot proceed while this card is locked.</p>
            <div id="purchase-card-countdown" class="purchase-card-countdown" hidden><i class="bi bi-clock"></i><span>Ready for <strong id="purchase-card-time-left">5:00</strong></span></div>
            <div class="purchase-card-actions">
                <button id="purchase-card-unlock" type="button" class="primary-btn"><i class="bi bi-unlock"></i> Unlock for One Purchase</button>
                <button id="purchase-card-lock" type="button" class="secondary-btn" hidden><i class="bi bi-lock"></i> Lock Now</button>
            </div>
            <p id="purchase-card-result" class="purchase-card-result" role="status" aria-live="polite"></p>
            <ul class="purchase-card-rules">
                <li><i class="bi bi-person-badge"></i><span>The QR on your employee ID identifies your account; it does not unlock it.</span></li>
                <li><i class="bi bi-hourglass-split"></i><span>An unlock expires after 5 minutes if it is not used.</span></li>
                <li><i class="bi bi-shield-check"></i><span>After one successful debt purchase, the card locks automatically.</span></li>
            </ul>
        </article>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/user-purchase-card.js') ?>?v=20260825a" data-user-page-script></script>
<?= $this->endSection() ?>
