<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Store Inventory';
$pageSubtitle = 'Manage your store products, stock levels, and product photos.';
$activeCount = count(array_filter($products, static fn($p) => (int) ($p['is_active'] ?? 1) === 1));
$lowStock = count(array_filter($products, static fn($p) => (int) $p['stock_qty'] <= 10));
?>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Assigned Store</div><div class="stat-value"><?= esc((string) $store['store_name']) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Active Products</div><div class="stat-value"><?= esc((string) $activeCount) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Low Stock (<=10)</div><div class="stat-value"><?= esc((string) $lowStock) ?></div></div></div></div>
</div>

<div class="card page-card mb-3"><div class="card-body">
    <h2 class="h6 mb-3">Add Product</h2>
    <form method="post" action="/store/inventory/products" enctype="multipart/form-data" class="row g-3">
        <?= csrf_field() ?>
        <div class="col-lg-2"><label class="form-label">SKU</label><input class="form-control" name="sku" placeholder="CAF-001" required></div>
        <div class="col-lg-3"><label class="form-label">Product Name</label><input class="form-control" name="name" placeholder="Product Name" required></div>
        <div class="col-lg-2"><label class="form-label">Category</label><input class="form-control" name="category" value="General" required></div>
        <div class="col-lg-2"><label class="form-label">Price</label><input class="form-control" name="price" type="number" step="0.01" placeholder="0.00" required></div>
        <div class="col-lg-1"><label class="form-label">Stock</label><input class="form-control" name="stock_qty" type="number" min="0" value="0" required></div>
        <div class="col-lg-2"><label class="form-label">Photo</label><input class="form-control" name="photo" type="file" accept="image/*"></div>
        <div class="col-12 d-grid d-md-flex justify-content-md-end"><button class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Product</button></div>
    </form>
</div></div>

<div class="card page-card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead><tr><th>Photo</th><th>SKU</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th style="min-width:310px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td>
                        <?php if (! empty($p['image_path'])): ?>
                            <img src="<?= esc($p['image_path']) ?>" alt="<?= esc($p['name']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #dbe3ef;">
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
                    <td>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" action="/store/inventory/products/<?= esc($p['id']) ?>/stock" class="d-flex align-items-center gap-1">
                                <?= csrf_field() ?>
                                <select name="type" class="form-select form-select-sm" style="width:105px;">
                                    <option value="restock">Restock</option>
                                    <option value="adjustment">Adjust -</option>
                                </select>
                                <input type="number" min="1" class="form-control form-control-sm" name="qty" placeholder="Qty" style="width:76px;" required>
                                <input type="text" class="form-control form-control-sm" name="reason" placeholder="Reason" style="width:110px;">
                                <button class="btn btn-outline-primary btn-sm">Apply</button>
                            </form>
                            <form method="post" action="/store/inventory/products/<?= esc($p['id']) ?>/photo" enctype="multipart/form-data" class="d-flex align-items-center gap-1">
                                <?= csrf_field() ?>
                                <input type="file" class="form-control form-control-sm" name="photo" accept="image/*" style="width:180px;" required>
                                <button class="btn btn-outline-secondary btn-sm">Photo</button>
                            </form>
                            <form method="post" action="/store/inventory/products/<?= esc($p['id']) ?>/toggle">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm <?= (int) ($p['is_active'] ?? 1) === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= (int) ($p['is_active'] ?? 1) === 1 ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
