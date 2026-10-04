<?php
$prepared = (bool) ($prepared ?? false);
$values = $prepared
    ? [
        'opening' => $account['opening_debt_snapshot'] ?? 0,
        'charges' => $account['period_debits_snapshot'] ?? 0,
        'credits' => $account['period_credits_snapshot'] ?? 0,
        'cutoff' => $account['debt_snapshot'] ?? 0,
        'salary' => $account['salary_snapshot'] ?? 0,
    ]
    : [
        'opening' => $account['opening_debt'] ?? 0,
        'charges' => $account['period_debits'] ?? 0,
        'credits' => $account['period_credits'] ?? 0,
        'cutoff' => $account['cutoff_debt'] ?? 0,
        'salary' => $account['salary_reference'] ?? 0,
    ];
$hasCompleteBreakdown = !$prepared || abs(
    (float) $values['opening'] + (float) $values['charges']
    - (float) $values['credits'] - (float) $values['cutoff']
) < 0.01;
?>
<div class="deduction-breakdown" aria-label="Employee debt for this deduction period">
    <strong><?= $prepared ? 'Preparation snapshot' : 'Current period register' ?></strong>
    <?php if (!$hasCompleteBreakdown): ?>
        <p>Historical breakdown unavailable for this older batch. The recorded debt-at-cutoff snapshot was ₱<?= number_format((float) $values['cutoff'], 2) ?>; opening debt, period movements, and salary reference were not reliably captured.</p>
    <?php else: ?>
    <dl>
        <div><dt>Opening debt</dt><dd>₱<?= number_format((float) $values['opening'], 2) ?></dd></div>
        <div><dt>Period charges</dt><dd>₱<?= number_format((float) $values['charges'], 2) ?></dd></div>
        <div><dt>Period payments / credits</dt><dd>₱<?= number_format((float) $values['credits'], 2) ?></dd></div>
        <div><dt>Debt at cutoff</dt><dd>₱<?= number_format((float) $values['cutoff'], 2) ?></dd></div>
        <div><dt><?= $prepared ? 'Salary reference at preparation' : 'Current salary reference' ?></dt><dd>₱<?= number_format((float) $values['salary'], 2) ?></dd></div>
    </dl>
    <?php endif; ?>
</div>
