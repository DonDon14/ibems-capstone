<?php if (is_array($priorResult ?? null)): ?>
    <p class="deduction-prior-result">
        Latest earlier result with a remaining balance:
        <strong><?= esc($priorResult['period_label'] ?: $priorResult['period_code']) ?></strong>
        · <?= esc(str_replace('_', ' ', $priorResult['result_status'])) ?>
        · ₱<?= number_format((float) $priorResult['balance_after_result'], 2) ?> after that result
        <?php if ($priorResult['days_since_result'] !== null): ?>
            · <?= (int) $priorResult['days_since_result'] ?> days since confirmation
        <?php endif; ?>
        <?php if ($priorResult['result_reference'] !== ''): ?>
            · Ref <?= esc($priorResult['result_reference']) ?>
        <?php endif; ?>
    </p>
<?php endif; ?>
