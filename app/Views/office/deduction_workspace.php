<?= $this->extend('layouts/office') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell deduction-workspace space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Shared deduction workflow',
        'title' => 'Salary deductions',
        'description' => 'Cashier and Accounting use the same audited period and batch records. Partial results remain available.',
        'icon' => 'bi bi-cash-coin',
    ]) ?>

    <div class="deduction-notice" role="note">Preparing or submitting a batch does not reduce employee debt. Enter the actual confirmed amount and an official result reference for each employee; only confirmation posts the deduction. A prior-result trace is historical and does not allocate today's debt to a particular purchase.</div>
    <?php if ($message): ?><p class="deduction-feedback is-success" role="status"><?= esc($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="deduction-feedback is-error" role="alert"><?= esc($error) ?></p><?php endif; ?>

    <div class="deduction-grid">
        <section class="data-panel">
            <div class="data-panel-head"><h3>Deduction periods</h3></div>
            <div class="data-panel-body">
                <form action="<?= site_url('deductions/workspace/periods') ?>" method="post" class="deduction-form" data-deduction-workflow-form="period">
                    <?= csrf_field() ?>
                    <label>Frequency <select name="frequency" required><option value="semi_monthly">Semi-monthly</option><option value="monthly">Monthly</option><option value="custom">Custom</option></select></label>
                    <label>Start <input type="date" name="date_start" required></label>
                    <label>End <input type="date" name="date_end" required></label>
                    <label>Expected pay date <input type="date" name="expected_processing_date"></label>
                    <button class="primary-btn" type="submit">Create period</button>
                </form>
                <nav class="deduction-period-list" aria-label="Deduction periods">
                    <?php if ($selectedOutsidePage): ?><p class="deduction-period-context">The selected historical period is shown alongside this page of periods.</p><?php endif; ?>
                    <?php foreach ($periods as $period): ?>
                        <a class="deduction-period<?= (int) ($selected['id'] ?? 0) === (int) $period['id'] ? ' is-selected' : '' ?>"<?= (int) ($selected['id'] ?? 0) === (int) $period['id'] ? ' aria-current="true"' : '' ?> href="<?= site_url('deductions/workspace') . '?period_id=' . (int) $period['id'] . '&amp;page=' . (int) $periodPage ?>">
                            <strong><?= esc($period['period_code']) ?></strong><span><?= esc($period['label']) ?> · <?= esc(str_replace('_', ' ', $period['status'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($periods === []): ?><p>No deduction periods yet.</p><?php endif; ?>
                </nav>
                <?php if ($periodPages > 1): ?>
                    <nav class="deduction-period-pagination" aria-label="Deduction period pages">
                        <?php if ($periodPage > 1): ?><a href="<?= site_url('deductions/workspace') . '?page=' . ($periodPage - 1) ?>">Newer periods</a><?php endif; ?>
                        <span>Page <?= (int) $periodPage ?> of <?= (int) $periodPages ?></span>
                        <?php if ($periodPage < $periodPages): ?><a href="<?= site_url('deductions/workspace') . '?page=' . ($periodPage + 1) ?>">Older periods</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>

        <section class="data-panel">
            <div class="data-panel-head"><h3><?= $selected ? esc($selected['label']) : 'Select a period' ?></h3></div>
            <div class="data-panel-body">
                <?php if ($selected && !$batch): ?>
                    <p>Select employees with debt and prepare a request. This step does not post any deduction.</p>
                    <?php if ($historyUnavailable): ?><p class="deduction-feedback is-error" role="alert">Debt history is unavailable. Employee cutoff balances cannot be verified; batch preparation is disabled until the cashbook is restored.</p><?php endif; ?>
                    <?= view('components/deduction_batch_request_form', ['selected' => $selected, 'register' => $register, 'itemsByUser' => [], 'priorResults' => $priorResults, 'historyUnavailable' => $historyUnavailable, 'editMode' => false]) ?>
                <?php elseif ($selected && $batch): ?>
                    <p>Batch #<?= (int) $batch['id'] ?> · <strong><?= esc(str_replace('_', ' ', $batch['status'])) ?></strong> · Requested ₱<?= number_format((float) $batch['total_requested'], 2) ?> · Confirmed ₱<?= number_format((float) $batch['total_confirmed'], 2) ?></p>
                    <?php
                        $batchOwner = (int) ($batch['created_by'] ?? 0) === (int) session()->get('user_id');
                        $missingPreparedAccounts = array_diff(array_map('intval', array_column($items, 'user_id')), array_map('intval', array_column($register, 'user_id')));
                    ?>
                    <?php if ($batch['status'] === 'prepared'): ?>
                        <?php if ($batchOwner): ?>
                            <?php if ($historyUnavailable || $missingPreparedAccounts !== []): ?>
                                <p class="deduction-feedback is-error" role="alert">This prepared batch cannot be edited until its employee debt history and current eligibility can be verified. Its saved request remains unchanged.</p>
                            <?php else: ?>
                                <p>Review or change the prepared request below. The edit form shows current register values; the saved batch below keeps its original preparation snapshot until changes are saved. Saving changes does not post a deduction.</p>
                                <?= view('components/deduction_batch_request_form', ['selected' => $selected, 'register' => $register, 'itemsByUser' => $itemsByUser, 'priorResults' => $priorResults, 'historyUnavailable' => $historyUnavailable, 'editMode' => true]) ?>
                            <?php endif; ?>
                            <form action="<?= site_url('deductions/workspace/batches/' . (int) $batch['id'] . '/submit') ?>" method="post" class="deduction-action-form" data-deduction-workflow-form="submit"><?= csrf_field() ?><button class="primary-btn" type="submit">Submit batch for results</button></form>
                        <?php else: ?><p class="deduction-feedback" role="note">Only the batch creator may edit or submit this prepared request.</p><?php endif; ?>
                    <?php elseif ($batch['status'] === 'processed' && $batchOwner): ?>
                        <form action="<?= site_url('deductions/workspace/batches/' . (int) $batch['id'] . '/reconcile') ?>" method="post" class="deduction-action-form" data-deduction-workflow-form="reconcile"><?= csrf_field() ?><button class="primary-btn" type="submit">Reconcile totals</button></form>
                    <?php elseif ($batch['status'] === 'reconciled' && $batchOwner): ?>
                        <form action="<?= site_url('deductions/workspace/batches/' . (int) $batch['id'] . '/finalize') ?>" method="post" class="deduction-action-form" data-deduction-workflow-form="finalize"><?= csrf_field() ?><button class="primary-btn" type="submit">Finalize period</button></form>
                    <?php endif; ?>
                    <div class="deduction-rows" role="region" aria-label="Deduction batch employee results" tabindex="0">
                        <?php foreach ($items as $item): ?>
                            <article class="deduction-row">
                                <h4><?= esc($item['name']) ?> · <?= esc($item['employee_id'] ?? '') ?></h4>
                                <?= view('components/deduction_period_breakdown', ['account' => $item, 'prepared' => true]) ?>
                                <?= view('components/deduction_prior_result', ['priorResult' => $priorResults[(int) $item['user_id']] ?? null]) ?>
                                <p>Requested ₱<?= number_format((float) $item['requested_amount'], 2) ?> · <?= esc(str_replace('_', ' ', $item['result_status'])) ?></p>
                                <?php if ($item['result_status'] === 'pending' && in_array($batch['status'], ['submitted', 'partially_processed'], true)): ?>
                                    <form action="<?= site_url('deductions/workspace/items/' . (int) $item['id'] . '/confirm') ?>" method="post" class="deduction-form" data-deduction-workflow-form="result">
                                        <?= csrf_field() ?>
                                        <label>Actually deducted <input type="number" name="confirmed_amount" min="0" max="<?= esc((string) $item['requested_amount']) ?>" step="0.01" value="<?= esc((string) $item['requested_amount']) ?>" required></label>
                                        <label>Official result reference <input type="text" name="result_reference" maxlength="150" required></label>
                                        <label>Reason if partial or zero <select name="reason_code"><option value="">Fully deducted</option><option value="insufficient_salary">Insufficient salary</option><option value="not_deducted">Not deducted</option><option value="employee_not_found">Employee not found</option><option value="duplicate">Duplicate</option><option value="returned_for_correction">Returned for correction</option></select></label>
                                        <label>Notes <input type="text" name="result_notes" maxlength="255"></label>
                                        <button class="primary-btn" type="submit">Confirm actual result</button>
                                    </form>
                                <?php elseif ($item['result_status'] !== 'pending'): ?>
                                    <p>Confirmed ₱<?= number_format((float) $item['confirmed_amount'], 2) ?> · Reference <?= esc($item['result_reference'] ?? '') ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/deduction-workspace.css') ?>?v=20261003-selection-feedback">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/deduction-workspace.js') ?>?v=20261003-selected-validation"></script>
<script src="<?= base_url('assets/js/deduction-workflow-feedback.js') ?>?v=20261003-native-pending"></script>
<?= $this->endSection() ?>
