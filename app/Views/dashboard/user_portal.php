<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'My Account'; $pageSubtitle = 'Balance, debt status, profile, and account security.'; ?>
<?php if ($balance): ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Credit Limit</div><div class="stat-value">PHP <?= number_format((float) $balance['credit_limit'], 2) ?></div></div></div></div>
  <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Current Debt</div><div class="stat-value">PHP <?= number_format((float) $balance['current_debt'], 2) ?></div></div></div></div>
  <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Available Credit</div><div class="stat-value">PHP <?= number_format((float) $balance['credit_limit'] - (float) $balance['current_debt'], 2) ?></div></div></div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card page-card"><div class="card-body">
            <h2 class="h6 mb-3">Profile Settings</h2>
            <form method="post" action="/me/profile" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-12"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?= esc($profile['name'] ?? '') ?>" required></div>
                <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= esc($profile['email'] ?? '') ?>" required></div>
                <div class="col-12 d-grid"><button class="btn btn-primary">Save Profile</button></div>
            </form>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card page-card"><div class="card-body">
            <h2 class="h6 mb-3">Security</h2>
            <form method="post" action="/me/password" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-12"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                <div class="col-12"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="8" required></div>
                <div class="col-12"><label class="form-label">Confirm Password</label><input type="password" name="confirm_password" class="form-control" minlength="8" required></div>
                <div class="col-12 d-grid"><button class="btn btn-outline-primary">Update Password</button></div>
            </form>
        </div></div>
    </div>
</div>

<div class="card page-card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead><tr><th>Reference</th><th>Date</th><th>Method</th><th>Amount</th><th>Receipt</th></tr></thead>
            <tbody>
            <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?= esc($t['reference_no']) ?></td>
                    <td><?= esc($t['created_at']) ?></td>
                    <td><span class="badge text-bg-light border"><?= esc($t['payment_method']) ?></span></td>
                    <td>PHP <?= number_format((float) $t['amount'], 2) ?></td>
                    <td><a href="/pos/receipt/<?= esc($t['reference_no']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
