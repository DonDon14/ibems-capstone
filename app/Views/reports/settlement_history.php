<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Settlement History';
$pageSubtitle = 'Monthly settlement runs and totals.';
$totalBefore = 0;
foreach ($rows as $r) { $totalBefore += (float) $r['total_debt_before']; }
?>
<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Settlement Runs</div><div class="stat-value"><?= esc((string) count($rows)) ?></div></div></div></div>
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Debt Processed</div><div class="stat-value">PHP <?= number_format($totalBefore, 2) ?></div></div></div></div>
</div>
<div class="d-flex justify-content-end mb-3">
    <a class="btn btn-outline-secondary" href="/reports/settlement-history?export=csv">Export CSV</a>
</div>
<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0"><thead><tr><th>ID</th><th>Month</th><th>Run At</th><th>Accounts</th><th>Total Debt Before</th></tr></thead><tbody><?php if ($rows === []): ?><tr><td colspan="5" class="text-center text-secondary py-4">No settlement history available yet.</td></tr><?php endif; ?><?php foreach ($rows as $r): ?><tr><td><?= esc($r['id']) ?></td><td><?= esc($r['run_month']) ?></td><td><?= esc($r['run_at']) ?></td><td><?= esc($r['total_accounts']) ?></td><td>PHP <?= number_format((float) $r['total_debt_before'], 2) ?></td></tr><?php endforeach; ?></tbody></table>
</div></div>
<?= $this->endSection() ?>
