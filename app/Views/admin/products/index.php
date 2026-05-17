<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Product Catalog';
$pageSubtitle = 'Read-only view of products and stock across all stores.';
$lowStock = count(array_filter($products, static fn($p) => (int) $p['stock_qty'] <= 10));
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Products</div><div class="stat-value"><?= esc((string) count($products)) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Low Stock (<=10)</div><div class="stat-value"><?= esc((string) $lowStock) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Categories</div><div class="stat-value"><?= esc((string) count(array_unique(array_map(static fn($p) => (string) ($p['category'] ?? 'General'), $products)))) ?></div></div></div></div>
</div>

<div class="card page-card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="h6 mb-1">Admin Access Scope</h2>
            <p class="text-secondary small mb-0">Product creation and stock updates are managed in the Store Inventory portal.</p>
        </div>
        <a href="/admin/stores" class="btn btn-outline-primary btn-sm"><i class="bi bi-shop"></i> Manage Stores</a>
    </div>
</div></div>

<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0">
<thead><tr><th>ID</th><th>Store</th><th>Photo</th><th>SKU</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($products as $p): ?>
<tr>
    <td><?= esc($p['id']) ?></td>
    <td><?= esc($storeMap[(int) $p['store_id']] ?? ('Store #' . $p['store_id'])) ?></td>
    <td>
        <?php if (! empty($p['image_path'])): ?>
            <img src="<?= esc($p['image_path']) ?>" alt="<?= esc($p['name']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #dbe3ef;">
        <?php else: ?>
            <span class="text-secondary small">No photo</span>
        <?php endif; ?>
    </td>
    <td><?= esc($p['sku']) ?></td>
    <td><?= esc($p['name']) ?></td>
    <td><span class="badge text-bg-light border"><?= esc($p['category'] ?? 'General') ?></span></td>
    <td>PHP <?= number_format((float) $p['price'], 2) ?></td>
    <td><?= esc($p['stock_qty']) ?></td>
    <td><?= (int) ($p['is_active'] ?? 1) === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>
<?= $this->endSection() ?>
