<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'Low Stock Report'; $pageSubtitle = 'Products below threshold (' . $threshold . ').'; ?>
<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Threshold</div><div class="stat-value"><?= esc((string) $threshold) ?></div></div></div></div>
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Items at Risk</div><div class="stat-value"><?= esc((string) count($rows)) ?></div></div></div></div>
</div>
<div class="d-flex justify-content-end mb-3">
    <a class="btn btn-outline-secondary" href="/reports/low-stock?export=csv">Export CSV</a>
</div>
<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0">
    <thead><tr><th>Store</th><th>SKU</th><th>Name</th><th>Category</th><th>Stock</th><th>Price</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
        <tr><td colspan="6" class="text-center text-secondary py-4">No products are currently below the low-stock threshold.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= esc($r['store_name']) ?></td>
            <td><?= esc($r['sku']) ?></td>
            <td><?= esc($r['name']) ?></td>
            <td><span class="badge text-bg-light border"><?= esc($r['category'] ?? 'General') ?></span></td>
            <td><span class="badge bg-warning text-dark"><?= esc($r['stock_qty']) ?></span></td>
            <td>PHP <?= number_format((float) $r['price'], 2) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>
