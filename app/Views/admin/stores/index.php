<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Stores';
$pageSubtitle = 'Maintain store records and assign operating officers.';
?>
<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Stores</div><div class="stat-value"><?= esc((string) count($stores)) ?></div></div></div></div>
    <div class="col-md-6"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Assigned Officers</div><div class="stat-value"><?= esc((string) count(array_filter($stores, static fn($s) => ! empty($s['officer_id'])))) ?></div></div></div></div>
</div>

<div class="card page-card mb-3"><div class="card-body">
<form method="post" action="/admin/stores" class="row g-3">
<?= csrf_field() ?>
<div class="col-md-8"><label class="form-label">Store Name</label><input class="form-control" name="store_name" placeholder="Campus Cafeteria" required></div>
<div class="col-md-4"><label class="form-label">Officer User ID</label><input class="form-control" name="officer_id" type="number" placeholder="Optional"></div>
<div class="col-12 d-grid d-md-flex justify-content-md-end"><button class="btn btn-primary">Create Store</button></div>
</form>
</div></div>

<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0">
<thead><tr><th>ID</th><th>Store Name</th><th>Officer ID</th></tr></thead>
<tbody><?php foreach ($stores as $s): ?><tr><td><?= esc($s['id']) ?></td><td><?= esc($s['store_name']) ?></td><td><?= esc((string) $s['officer_id']) ?></td></tr><?php endforeach; ?></tbody>
</table>
</div></div>
<?= $this->endSection() ?>
