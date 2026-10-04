<form action="<?= site_url('deductions/workspace/batches') ?>" method="post" class="deduction-form" data-deduction-workflow-form="prepare">
    <?= csrf_field() ?><input type="hidden" name="period_id" value="<?= (int) $selected['id'] ?>">
    <?php if ($register !== []): ?>
        <div class="deduction-register-tools" aria-label="Employee register filters">
            <label>Search employees <input id="deduction-register-search" type="search" placeholder="Name, employee ID, or email"></label>
            <label>Employee type <select id="deduction-register-type"><option value="all">All types</option><option value="faculty">Faculty</option><option value="staff">Staff</option></select></label>
            <label>Salary reference <select id="deduction-register-salary"><option value="all">Any salary</option><option value="provided">Provided</option><option value="missing">Missing</option></select></label>
            <div class="deduction-register-actions">
                <button id="deduction-select-matching" type="button" class="secondary-btn">Select matching</button>
                <button id="deduction-clear-selection" type="button" class="secondary-btn">Clear selection</button>
            </div>
            <p id="deduction-match-count" role="status">Showing <?= count($register) ?> of <?= count($register) ?> employees.</p>
        </div>
    <?php endif; ?>
    <?php if ($register !== []): ?><p id="deduction-selection-summary" class="deduction-selection-summary" role="status" aria-live="polite" aria-atomic="true"></p><?php endif; ?>
    <div class="deduction-rows" role="region" aria-label="Deduction preparation employee register" tabindex="0">
        <?php foreach ($register as $row):
            $id = (int) $row['user_id'];
            $existing = $itemsByUser[$id] ?? null;
            $choice = (string) ($existing['deduction_choice'] ?? 'full');
            $requestedAmount = $existing === null ? (float) $row['cutoff_debt'] : (float) $existing['requested_amount'];
        ?>
            <fieldset class="deduction-row" data-deduction-candidate data-search="<?= esc(implode(' ', [(string) ($row['name'] ?? ''), (string) ($row['employee_id'] ?? ''), (string) ($row['email'] ?? '')])) ?>" data-employee-type="<?= esc(strtolower((string) ($row['user_type'] ?? ''))) ?>" data-salary-state="<?= (float) ($row['salary_reference'] ?? 0) > 0 ? 'provided' : 'missing' ?>">
                <legend><?= esc($row['name']) ?> · <?= esc($row['employee_id'] ?? '') ?> · Debt at cutoff ₱<?= number_format((float) $row['cutoff_debt'], 2) ?></legend>
                <?= view('components/deduction_period_breakdown', ['account' => $row, 'prepared' => false]) ?>
                <?= view('components/deduction_prior_result', ['priorResult' => $priorResults[$id] ?? null]) ?>
                <label class="deduction-include"><input type="checkbox" name="requests[<?= $id ?>][selected]" value="1"<?= $existing !== null ? ' checked' : '' ?>> Include</label>
                <label>Choice <select class="deduction-choice" name="requests[<?= $id ?>][deduction_choice]"><option value="full"<?= $choice === 'full' ? ' selected' : '' ?>>Full</option><option value="partial"<?= $choice === 'partial' ? ' selected' : '' ?>>Partial</option><option value="none"<?= $choice === 'none' ? ' selected' : '' ?>>No deduction</option></select></label>
                <label>Requested amount <input class="deduction-request-amount" type="number" min="0" max="<?= esc((string) $row['cutoff_debt']) ?>" step="0.01" name="requests[<?= $id ?>][requested_amount]" value="<?= esc((string) $requestedAmount) ?>" data-cutoff-debt="<?= esc((string) $row['cutoff_debt']) ?>"></label>
                <label>Reason, if none <input class="deduction-preparation-reason" type="text" name="requests[<?= $id ?>][preparation_reason]" maxlength="255" value="<?= esc((string) ($existing['preparation_reason'] ?? '')) ?>"></label>
            </fieldset>
        <?php endforeach; ?>
        <?php if ($register === [] && !$historyUnavailable): ?><p>No eligible employee debt at this period's cutoff.</p><?php endif; ?>
    </div>
    <?php if ($register !== []): ?><button class="primary-btn" type="submit"><?= $editMode ? 'Save prepared changes' : 'Prepare selected batch' ?></button><?php endif; ?>
</form>
