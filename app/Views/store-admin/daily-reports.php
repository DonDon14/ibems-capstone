<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>?v=20261004-variance-feedback">
<link rel="stylesheet" href="<?= base_url('assets/css/daily-store-reports.css') ?>?v=20261004-report-feedback">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell daily-report-page space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Store to BE Director',
        'title' => 'Daily report handoff',
        'description' => $storeName . ' · Only an assigned Store Supervisor can submit a closed day. Submitted figures are frozen for BE review.',
        'icon' => 'bi bi-send-check',
    ]) ?>
    <?= view('components/report_pagination', ['pagination' => $pagination ?? null]) ?>
    <?php if ($message): ?><p class="daily-report-feedback is-success" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"><?= esc($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="daily-report-feedback is-error" role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1"><?= esc($error) ?></p><?php endif; ?>
    <div class="daily-report-list">
        <?php foreach ($days as $day): $session = $day['session']; $report = $day['report']; ?>
            <article class="dash-panel daily-report-card">
                <div class="daily-report-head"><div><h3><?= esc($session['business_date']) ?></h3><p>Closed <?= esc(!empty($session['closed_at']) ? ibems_datetime($session['closed_at'], 'M d, Y h:i A') : 'time unavailable') ?> (Asia/Manila) · Session #<?= (int) $session['id'] ?></p></div><span class="daily-report-status"><?= esc($report ? str_replace('_', ' ', $report['status']) : 'Not submitted') ?></span></div>
                <?php if ($report): ?>
                    <p>Revision <?= (int) $report['revision'] ?> · <?= number_format((int) $report['transaction_count']) ?> transactions · Sales ₱<?= number_format((float) $report['sales_total'], 2) ?> · Cash ₱<?= number_format((float) $report['cash_total'], 2) ?> · Debt ₱<?= number_format((float) $report['debt_total'], 2) ?></p>
                    <?php if ($report['be_review_note'] && ($report['status'] !== 'be_returned' || $report['be_review_note'] !== ($report['return_note'] ?? null))): ?><p><strong>Original BE review:</strong> <?= esc($report['be_review_note']) ?></p><?php endif; ?>
                    <?php if ($report['return_note'] ?? null): ?><p><strong>BE return reason:</strong> <?= esc($report['return_note']) ?></p><?php endif; ?>
                <?php else: ?><p>No submitted snapshot yet. Submission captures completed sales for this store day and sends them to BE review.</p><?php endif; ?>
                <?php if (!$report || $report['status'] === 'be_returned'): ?>
                    <div class="daily-report-preview-controls">
                        <a class="secondary-btn" href="<?= esc(site_url('store-admin/daily-reports/preview/' . (int) $session['id']), 'attr') ?>">Preview source figures</a>
                    </div>
                    <form action="<?= site_url('store-admin/daily-reports/submit') ?>" method="post" class="daily-report-action" data-report-action="submit">
                        <?= csrf_field() ?><input type="hidden" name="store_id" value="<?= (int) $storeId ?>"><input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                        <label>Submission note <textarea name="note" rows="2" maxlength="1000" placeholder="Describe the closed day or address the BE return note"></textarea></label>
                        <div><button class="primary-btn" type="submit"><?= $report ? 'Submit revised snapshot' : 'Submit to BE Director' ?></button></div>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($days === []): ?><article class="dash-panel daily-report-empty"><p>No closed store days are available to submit.</p></article><?php endif; ?>
    </div>
    <?= view('components/report_pagination', ['pagination' => $pagination ?? null]) ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/daily-report-review.js') ?>?v=20261004-native-pending"></script>
<?= $this->endSection() ?>
