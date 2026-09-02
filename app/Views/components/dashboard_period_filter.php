<?php
$selectedPeriod = strtolower(trim((string) ($selectedPeriod ?? service('request')->getGet('period') ?? 'day')));
$selectedPeriod = in_array($selectedPeriod, ['day', 'week', 'month', 'year'], true) ? $selectedPeriod : 'day';
$resolvedPeriod = \App\Services\DashboardPeriod::resolve($selectedPeriod);
?>
<div class="dashboard-period-bar" data-dashboard-period-filter<?= ($mode ?? '') === 'reload' ? ' data-dashboard-period-mode="reload"' : '' ?>>
    <div class="dashboard-period-copy">
        <span>Dashboard period</span>
        <strong data-dashboard-period-label><?= esc($resolvedPeriod['label']) ?></strong>
    </div>
    <div class="dashboard-period-options" role="group" aria-label="Filter dashboard by period">
        <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $periodKey => $periodLabel): ?>
            <button type="button" data-dashboard-period="<?= esc($periodKey) ?>" aria-pressed="<?= $periodKey === $selectedPeriod ? 'true' : 'false' ?>"><?= esc($periodLabel) ?></button>
        <?php endforeach; ?>
    </div>
</div>
