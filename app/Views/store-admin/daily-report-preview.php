<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>?v=20261004-variance-feedback">
<link rel="stylesheet" href="<?= base_url('assets/css/daily-store-reports.css') ?>?v=20261004-report-feedback">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell daily-report-page space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Store to BE Director',
        'title' => 'Closed-day source preview',
        'description' => 'Read-only figures. Submission recalculates and freezes the current source.',
        'icon' => 'bi bi-file-earmark-bar-graph',
    ]) ?>
    <p><a class="secondary-btn" href="<?= esc($backUrl, 'attr') ?>"><?= ($result['status'] ?? '') === 'success' ? '← Back to daily report handoff' : '← Back to assigned stores' ?></a></p>
    <?php if (($result['status'] ?? '') !== 'success'): ?>
        <article class="dash-panel daily-report-empty"><p role="alert"><?= esc($result['message'] ?? 'Source preview is unavailable.') ?></p></article>
    <?php else: $totals = $result['totals']; $evidence = $result['evidence']; $session = $result['session']; ?>
        <article class="dash-panel daily-report-card">
            <h2><?= esc($result['business_date']) ?> · <?= esc($totals['name']) ?></h2>
            <div class="daily-report-totals">
                <div><span>Transactions</span><strong><?= number_format((int) $totals['transactions']) ?></strong></div>
                <div><span>Total sales</span><strong>₱<?= number_format((int) $totals['sales_cents'] / 100, 2) ?></strong></div>
                <div><span>Cash</span><strong>₱<?= number_format((int) $totals['cash_cents'] / 100, 2) ?></strong></div>
                <div><span>Debt / charge</span><strong>₱<?= number_format((int) $totals['debt_cents'] / 100, 2) ?></strong></div>
                <div><span>Other payments</span><strong>₱<?= number_format((int) $totals['other_cents'] / 100, 2) ?></strong></div>
            </div>
            <p>Sales and payment lines reconcile. Source fingerprint: <code><?= esc($evidence['sha256']) ?></code></p>
            <details class="daily-report-evidence">
                <summary>Store-day close figures</summary>
                <p>Closed <?= esc(!empty($session['closed_at']) ? ibems_datetime($session['closed_at'], 'M d, Y h:i A') : 'time unavailable') ?> (Asia/Manila) · Review <?= esc(str_replace('_', ' ', (string) ($session['review_status'] ?? 'not recorded'))) ?></p>
                <div class="daily-report-totals">
                    <div><span>Expected cash</span><strong><?= $session['expected_cash'] === null ? 'Not recorded' : '₱' . number_format((float) $session['expected_cash'], 2) ?></strong></div>
                    <div><span>Counted cash</span><strong><?= $session['counted_cash'] === null ? 'Not recorded' : '₱' . number_format((float) $session['counted_cash'], 2) ?></strong></div>
                    <div><span>Cash variance</span><strong><?= $session['variance_cash'] === null ? 'Not recorded' : '₱' . number_format((float) $session['variance_cash'], 2) ?></strong></div>
                    <div><span>Expected e-cash</span><strong><?= $session['expected_ecash'] === null ? 'Not recorded' : '₱' . number_format((float) $session['expected_ecash'], 2) ?></strong></div>
                    <div><span>Counted e-cash</span><strong><?= $session['counted_ecash'] === null ? 'Not recorded' : '₱' . number_format((float) $session['counted_ecash'], 2) ?></strong></div>
                    <div><span>E-cash variance</span><strong><?= $session['variance_ecash'] === null ? 'Not recorded' : '₱' . number_format((float) $session['variance_ecash'], 2) ?></strong></div>
                </div>
            </details>
            <p>This preview does not submit a report. Return to the handoff page to submit the recalculated snapshot.</p>
        </article>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
