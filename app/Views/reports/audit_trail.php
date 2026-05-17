<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'Audit Trail'; $pageSubtitle = 'Recent system actions for security and traceability.'; ?>
<div class="d-flex justify-content-end mb-3">
    <a class="btn btn-outline-secondary" href="/reports/audit-trail?export=csv">Export CSV</a>
</div>
<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0"><thead><tr><th>ID</th><th>Actor</th><th>Action</th><th>Entity</th><th>Entity ID</th><th>Time</th></tr></thead><tbody><?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-secondary py-4">No audit entries found.</td></tr><?php endif; ?><?php foreach ($rows as $r): ?><tr><td><?= esc($r['id']) ?></td><td><?= esc((string) $r['actor_id']) ?></td><td><span class="badge text-bg-light border"><?= esc($r['action']) ?></span></td><td><?= esc($r['entity']) ?></td><td><?= esc((string) $r['entity_id']) ?></td><td><?= esc($r['created_at']) ?></td></tr><?php endforeach; ?></tbody></table>
</div></div>
<?= $this->endSection() ?>
