<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'HR CSV Import'; $pageSubtitle = 'Upload salary data to create/update staff credit profiles.'; ?>
<div class="card page-card mb-3"><div class="card-body">
<form method="post" action="/hr/import-csv" enctype="multipart/form-data" class="row g-3 align-items-end">
<?= csrf_field() ?>
<div class="col-md-9"><label class="form-label">CSV File</label><input type="file" name="csv_file" accept=".csv" class="form-control" required></div>
<div class="col-md-3 d-grid"><button class="btn btn-primary">Import CSV</button></div>
</form>
</div></div>
<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0"><thead><tr><th>ID</th><th>File</th><th>Total</th><th>Valid</th><th>Invalid</th><th>Result</th></tr></thead><tbody><?php foreach ($batches as $b): ?><tr><td><?= esc($b['id']) ?></td><td><?= esc($b['filename']) ?></td><td><?= esc($b['total_rows']) ?></td><td><span class="badge text-bg-success"><?= esc($b['valid_rows']) ?></span></td><td><span class="badge text-bg-danger"><?= esc($b['invalid_rows']) ?></span></td><td><a href="/hr/import-csv/<?= esc($b['id']) ?>/result">Open</a></td></tr><?php endforeach; ?></tbody></table>
</div></div>
<?= $this->endSection() ?>
