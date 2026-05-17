<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'Dashboard'; $pageSubtitle = 'Portal overview and key operating metrics.'; ?>
<?php if (! empty($stats)): ?>
<div class="row g-3">
    <?php foreach ($stats as $card): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card page-card h-100"><div class="card-body stat-card">
                <div class="stat-label"><?= esc($card['label']) ?></div>
                <div class="stat-value"><?= esc((string) $card['value']) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card page-card"><div class="card-body">
    <p class="mb-0 text-secondary">Use the sidebar to open your portal modules.</p>
</div></div>
<?php endif; ?>

<?php if (! empty($quickActions)): ?>
<div class="card page-card mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h6 mb-1">Quick Actions</h2>
                <p class="text-secondary small mb-0">Open the most common tasks for this portal.</p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($quickActions as $action): ?>
                <a href="<?= esc($action['href']) ?>" class="btn btn-<?= esc($action['style']) ?>"><?= esc($action['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>
