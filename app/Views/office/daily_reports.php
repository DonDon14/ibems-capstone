<?= $this->extend('layouts/office') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell daily-report-page space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Daily store reports',
        'title' => $audience === 'be' ? 'BE Director review' : ($audience === 'cashier' ? 'Cashier Office receipt' : 'Accounting furnished copies'),
        'description' => $audience === 'be'
            ? 'Store reports arrive here first. Review a frozen snapshot of the closed day before it goes to Cashier and Accounting.'
            : ($audience === 'cashier' ? 'Receive reports approved by the BE Director.' : 'Read-only copies furnished after BE approval.'),
        'icon' => 'bi bi-clipboard2-check',
    ]) ?>
    <p class="daily-report-timezone">Report times are shown in Asia/Manila.</p>
    <?= view('components/report_pagination', ['pagination' => $pagination ?? null]) ?>

    <?php if ($message): ?><p class="daily-report-feedback is-success" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"><?= esc($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="daily-report-feedback is-error" role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1"><?= esc($error) ?></p><?php endif; ?>
    <div class="daily-report-list">
        <?php foreach ($reports as $report): ?>
            <?php $source = json_decode((string) ($report['source_json'] ?? ''), true) ?: []; ?>
            <article class="data-panel daily-report-card">
                <div class="data-panel-head daily-report-head">
                    <div><h3><?= esc($report['store_name']) ?> · <?= esc($report['business_date']) ?></h3><p>Revision <?= (int) $report['revision'] ?> · Submitted <?= esc(ibems_datetime($report['submitted_at'], 'M d, Y h:i A')) ?></p></div>
                    <span class="daily-report-status"><?= esc(match ($report['status']) {
                        'be_approved' => 'BE approved',
                        'cashier_received' => 'Cashier received',
                        default => ucfirst(str_replace('_', ' ', $report['status'])),
                    }) ?></span>
                </div>
                <div class="data-panel-body">
                    <?php if (($report['source_status'] ?? '') === 'changed'): ?>
                        <p class="daily-report-integrity is-changed" role="alert">Source records have changed since this report was submitted. These frozen figures may no longer match current sales; notify the BE Director before relying on them.</p>
                    <?php elseif (($report['source_status'] ?? '') === 'unverifiable'): ?>
                        <p class="daily-report-integrity is-unverifiable" role="status">This earlier report has no source fingerprint. Reconcile it manually before relying on the totals.</p>
                    <?php else: ?>
                        <p class="daily-report-integrity is-verified">Source sales match this submitted snapshot.</p>
                    <?php endif; ?>
                    <div class="daily-report-totals">
                        <div><span>Transactions</span><strong><?= number_format((int) $report['transaction_count']) ?></strong></div>
                        <div><span>Sales</span><strong>₱<?= number_format((float) $report['sales_total'], 2) ?></strong></div>
                        <div><span>Cash</span><strong>₱<?= number_format((float) $report['cash_total'], 2) ?></strong></div>
                        <div><span>Debt / charge</span><strong>₱<?= number_format((float) $report['debt_total'], 2) ?></strong></div>
                        <div><span>Other</span><strong>₱<?= number_format((float) $report['other_total'], 2) ?></strong></div>
                    </div>
                    <?php if ($source !== []): ?><details class="daily-report-evidence">
                        <summary>Closed-day source and reconciliation evidence</summary>
                        <p>Store day #<?= (int) ($source['session_id'] ?? 0) ?> · Closed <?= esc(!empty($source['closed_at']) ? ibems_datetime($source['closed_at'], 'M d, Y h:i A') : 'time unavailable') ?> · Recorded transactions <?= number_format((int) ($source['sales_evidence']['transaction_count'] ?? $report['transaction_count'])) ?></p>
                        <p>Expected cash ₱<?= number_format((float) ($source['expected_cash'] ?? 0), 2) ?> · Counted cash ₱<?= number_format((float) ($source['counted_cash'] ?? 0), 2) ?> · Variance ₱<?= number_format((float) ($source['variance_cash'] ?? 0), 2) ?></p>
                        <p>Expected e-cash ₱<?= number_format((float) ($source['expected_ecash'] ?? 0), 2) ?> · Counted e-cash ₱<?= number_format((float) ($source['counted_ecash'] ?? 0), 2) ?> · Variance ₱<?= number_format((float) ($source['variance_ecash'] ?? 0), 2) ?></p>
                        <p>Review status: <?= esc(ucfirst(str_replace('_', ' ', (string) ($source['review_status'] ?? 'unknown')))) ?> · Source fingerprint: <code><?= esc((string) ($source['sales_evidence']['sha256'] ?? 'not available for this report')) ?></code></p>
                    </details><?php endif; ?>
                    <?php if ($report['submission_note']): ?><p><strong>Store note:</strong> <?= esc($report['submission_note']) ?></p><?php endif; ?>
                    <?php if ($report['be_review_note'] && ($report['status'] !== 'be_returned' || $report['be_review_note'] !== ($report['return_note'] ?? null))): ?><p><strong>Original BE review:</strong> <?= esc($report['be_review_note']) ?></p><?php endif; ?>
                    <?php if ($report['return_note'] ?? null): ?><p><strong>BE return reason:</strong> <?= esc($report['return_note']) ?></p><?php endif; ?>
                    <?php if ($report['accounting_furnished_at']): ?><p>Accounting copy furnished: <?= esc(ibems_datetime($report['accounting_furnished_at'], 'M d, Y h:i A')) ?></p><?php endif; ?>
                    <?php if ($report['cashier_received_at']): ?><p>Cashier received: <?= esc(ibems_datetime($report['cashier_received_at'], 'M d, Y h:i A')) ?></p><?php endif; ?>
                    <?php if ($audience === 'be' && $report['status'] === 'submitted'): ?>
                        <form action="<?= site_url('be/reports/' . (int) $report['id'] . '/review') ?>" method="post" class="daily-report-action" data-report-action="review">
                            <?= csrf_field() ?>
                            <label>Review note <textarea name="note" rows="2" placeholder="Required when returning a report"></textarea></label>
                            <div><?php if (($report['source_status'] ?? '') === 'verified'): ?><button class="primary-btn" type="submit" name="decision" value="approve">Approve and route</button><?php endif; ?> <button class="secondary-btn" type="submit" name="decision" value="return">Return for revision</button></div>
                        </form>
                    <?php elseif ($audience === 'be' && in_array($report['status'], ['be_approved', 'cashier_received'], true) && ($report['source_status'] ?? '') !== 'verified'): ?>
                        <form action="<?= site_url('be/reports/' . (int) $report['id'] . '/review') ?>" method="post" class="daily-report-action" data-report-action="review">
                            <?= csrf_field() ?>
                            <label>Correction reason <textarea name="note" rows="2" required placeholder="Explain why this approved report needs a new revision"></textarea></label>
                            <div><button class="secondary-btn" type="submit" name="decision" value="return">Return approved report for correction</button></div>
                        </form>
                    <?php elseif ($audience === 'cashier' && $report['status'] === 'be_approved' && ($report['source_status'] ?? '') === 'verified'): ?>
                        <form action="<?= site_url('cashier/reports/' . (int) $report['id'] . '/receive') ?>" method="post" class="daily-report-action" data-report-action="receive">
                            <?= csrf_field() ?><button class="primary-btn" type="submit">Record receipt</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if ($reports === []): ?>
            <div class="data-panel daily-report-empty"><div class="data-panel-body"><p><?= esc(match ($audience) {
                'be' => 'No submitted store reports are waiting for BE review.',
                'cashier' => 'No BE-approved store reports are waiting for Cashier receipt.',
                default => 'No BE-approved store reports have been furnished to Accounting yet.',
            }) ?></p></div></div>
        <?php endif; ?>
    </div>
    <?= view('components/report_pagination', ['pagination' => $pagination ?? null]) ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/daily-report-review.js') ?>?v=20261004-native-pending"></script>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/daily-store-reports.css') ?>?v=20261004-report-feedback">
<?= $this->endSection() ?>
