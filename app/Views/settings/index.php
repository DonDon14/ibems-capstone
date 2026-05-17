<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'System Settings'; $pageSubtitle = 'Deployment-level configuration for branding and operations.'; ?>
<div class="card page-card"><div class="card-body">
<form method="post" action="/settings" class="row g-3">
    <?= csrf_field() ?>

    <div class="col-12"><h2 class="h6 mb-0">Branding</h2></div>
    <div class="col-md-4"><label class="form-label">Application Name</label><input name="app_name" class="form-control" value="<?= esc($settings['app_name'] ?? 'IBEMS') ?>" required></div>
    <div class="col-md-8"><label class="form-label">Tagline</label><input name="app_tagline" class="form-control" value="<?= esc($settings['app_tagline'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">School Name</label><input name="school_name" class="form-control" value="<?= esc($settings['school_name'] ?? '') ?>"></div>

    <div class="col-12"><hr class="my-1"></div>
    <div class="col-12"><h2 class="h6 mb-0">Operations</h2></div>
    <div class="col-md-3"><label class="form-label">Currency</label><input name="currency" class="form-control" maxlength="5" value="<?= esc($settings['currency'] ?? 'PHP') ?>"></div>
    <div class="col-md-3"><label class="form-label">Low Stock Threshold</label><input type="number" name="low_stock_threshold" class="form-control" value="<?= esc($settings['low_stock_threshold'] ?? '10') ?>"></div>
    <div class="col-md-6"><label class="form-label">Support Email</label><input type="email" name="support_email" class="form-control" value="<?= esc($settings['support_email'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Receipt Footer</label><input name="receipt_footer" class="form-control" value="<?= esc($settings['receipt_footer'] ?? '') ?>"></div>

    <div class="col-12"><hr class="my-1"></div>
    <div class="col-12"><h2 class="h6 mb-0">User Experience</h2></div>
    <div class="col-12 form-check ms-1">
        <input class="form-check-input" type="checkbox" name="ui_compact_mode" value="1" id="compactMode" <?= ($settings['ui_compact_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="compactMode">Compact UI mode (denser spacing for small displays)</label>
    </div>

    <div class="col-12 d-grid d-md-flex justify-content-md-end"><button class="btn btn-primary">Save Settings</button></div>
</form>
</div></div>
<?= $this->endSection() ?>
