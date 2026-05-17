<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Accounting';
$pageSubtitle = 'Credit-limit administration and monthly settlement operations.';
$totalDebt = 0;
foreach ($balances as $b) { $totalDebt += (float) $b['current_debt']; }
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Accounts with Credit</div><div class="stat-value"><?= esc((string) count($balances)) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Outstanding Debt</div><div class="stat-value">PHP <?= number_format($totalDebt, 2) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body d-flex flex-column justify-content-center h-100">
        <div class="fw-semibold mb-1">Global Settlement</div>
        <div class="small text-secondary mb-2">Resets all current debts and creates settlement record.</div>
        <form method="post" action="/accounting/settle/global" class="mb-0">
            <?= csrf_field() ?>
            <button class="btn btn-danger w-100" type="submit" onclick="return confirm('Run Global Settle?')">Run Global Settle</button>
        </form>
    </div></div></div>
</div>

<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0">
<thead><tr><th>User</th><th>Employee ID</th><th>Credit Limit</th><th>Current Debt</th><th>Override Limit</th></tr></thead>
<tbody>
<?php foreach ($balances as $b): ?>
<tr>
    <td><?= esc($b['name']) ?></td>
    <td><?= esc($b['employee_id']) ?></td>
    <td>PHP <?= number_format((float) $b['credit_limit'], 2) ?></td>
    <td>PHP <?= number_format((float) $b['current_debt'], 2) ?></td>
    <td>
        <form method="post" action="/accounting/credit-override/<?= esc($b['user_id']) ?>" class="d-flex gap-2 mb-0">
            <?= csrf_field() ?>
            <input name="credit_limit" class="form-control form-control-sm" type="number" step="0.01" value="<?= esc($b['credit_limit']) ?>">
            <button class="btn btn-sm btn-primary">Save</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>
<?= $this->endSection() ?>
