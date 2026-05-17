<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Import Result';
$pageSubtitle = 'Validation result for batch #' . ($batch['id'] ?? '');
$total = (int) ($batch['total_rows'] ?? 0);
$valid = (int) ($batch['valid_rows'] ?? 0);
$invalid = (int) ($batch['invalid_rows'] ?? 0);
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Rows</div><div class="stat-value"><?= esc((string) $total) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Valid Rows</div><div class="stat-value"><?= esc((string) $valid) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Invalid Rows</div><div class="stat-value"><?= esc((string) $invalid) ?></div></div></div></div>
</div>
<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0"><thead><tr><th>Employee ID</th><th>Name</th><th>Email</th><th>Salary</th><th>Status</th><th>Error</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= esc($r['employee_id']) ?></td><td><?= esc($r['name']) ?></td><td><?= esc($r['email']) ?></td><td><?= esc((string) $r['monthly_salary']) ?></td><td><span class="badge <?= $r['status'] === 'valid' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= esc($r['status']) ?></span></td><td><?= esc((string) $r['error_msg']) ?></td></tr><?php endforeach; ?></tbody></table>
</div></div>
<?= $this->endSection() ?>
