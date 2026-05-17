<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'Daily Sales'; $pageSubtitle = 'Sales summary by payment method and customer type.'; ?>
<div class="card page-card mb-3"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
        <label class="form-label">Date</label>
        <input type="date" name="date" class="form-control" value="<?= esc($date) ?>">
    </div>
    <div class="col-md-2 d-grid"><button class="btn btn-primary">Filter</button></div>
    <div class="col-md-3 d-grid"><a class="btn btn-outline-secondary" href="/reports/daily-sales?date=<?= esc(urlencode($date)) ?>&export=csv">Export CSV</a></div>
</form>
</div></div>

<?php
$totalAmount = 0;
$totalTransactions = 0;
foreach ($rows as $r) {
    $totalAmount += (float) $r['total_amount'];
    $totalTransactions += (int) $r['txn_count'];
}
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Transactions</div><div class="stat-value"><?= esc((string) $totalTransactions) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Sales</div><div class="stat-value">PHP <?= number_format($totalAmount, 2) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Groups</div><div class="stat-value"><?= esc((string) count($rows)) ?></div></div></div></div>
</div>

<div class="card page-card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead><tr><th>Payment Method</th><th>Customer Type</th><th>Transactions</th><th>Total Amount</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="4" class="text-center text-secondary py-4">No sales data found for the selected date.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><span class="badge text-bg-light border"><?= esc($r['payment_method']) ?></span></td>
                    <td><?= esc($r['customer_type']) ?></td>
                    <td><?= esc($r['txn_count']) ?></td>
                    <td>PHP <?= number_format((float) $r['total_amount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
