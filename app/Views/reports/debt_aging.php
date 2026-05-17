<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Debt Aging';
$pageSubtitle = 'Outstanding balances per eligible account.';
$totalDebt = 0;
foreach ($rows as $r) { $totalDebt += (float) $r['current_debt']; }
?>
<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Accounts Listed</div><div class="stat-value"><?= esc((string) count($rows)) ?></div></div></div></div>
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Outstanding</div><div class="stat-value">PHP <?= number_format($totalDebt, 2) ?></div></div></div></div>
</div>
<div class="d-flex justify-content-end mb-3">
    <a class="btn btn-outline-secondary" href="/reports/debt-aging?export=csv">Export CSV</a>
</div>
<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0"><thead><tr><th>Employee</th><th>Name</th><th>Credit Limit</th><th>Current Debt</th></tr></thead><tbody><?php if ($rows === []): ?><tr><td colspan="4" class="text-center text-secondary py-4">No debt records available.</td></tr><?php endif; ?><?php foreach ($rows as $r): ?><tr><td><?= esc($r['employee_id']) ?></td><td><?= esc($r['name']) ?></td><td>PHP <?= number_format((float) $r['credit_limit'], 2) ?></td><td>PHP <?= number_format((float) $r['current_debt'], 2) ?></td></tr><?php endforeach; ?></tbody></table>
</div></div>
<?= $this->endSection() ?>
