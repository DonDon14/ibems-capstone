<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Users';
$pageSubtitle = 'Create and manage user accounts and role assignments.';
$totalUsers = count($users);
$adminCount = count(array_filter($users, static fn($u) => $u['role'] === 'ADMIN'));
$staffLike = count(array_filter($users, static fn($u) => in_array((string) $u['user_type'], ['staff', 'faculty'], true)));
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Total Users</div><div class="stat-value"><?= esc((string) $totalUsers) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Admin Accounts</div><div class="stat-value"><?= esc((string) $adminCount) ?></div></div></div></div>
    <div class="col-md-4"><div class="card page-card"><div class="card-body stat-card"><div class="stat-label">Faculty/Staff</div><div class="stat-value"><?= esc((string) $staffLike) ?></div></div></div></div>
</div>

<div class="card page-card mb-3"><div class="card-body">
<form method="post" action="/admin/users" class="row g-3">
<?= csrf_field() ?>
<div class="col-lg-2"><label class="form-label">Employee ID</label><input class="form-control" name="employee_id" placeholder="EMP-001" required></div>
<div class="col-lg-2"><label class="form-label">Name</label><input class="form-control" name="name" placeholder="Full name" required></div>
<div class="col-lg-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email" placeholder="name@domain.com" required></div>
<div class="col-lg-2"><label class="form-label">Password</label><input class="form-control" name="password" type="password" placeholder="Minimum 8 chars" required></div>
<div class="col-lg-1"><label class="form-label">Role</label><select class="form-select" name="role"><option>USER</option><option>ADMIN</option><option>ACCOUNTING_OFFICE</option><option>STORE_SYSTEM</option></select></div>
<div class="col-lg-1"><label class="form-label">User Type</label><select class="form-select" name="user_type"><option value="">N/A</option><option>faculty</option><option>staff</option><option>student</option></select></div>
<div class="col-lg-2"><label class="form-label">Base Salary</label><input class="form-control" name="base_salary" type="number" step="0.01" placeholder="Optional"></div>
<div class="col-12 d-grid d-md-flex justify-content-md-end"><button class="btn btn-primary">Create User</button></div>
</form>
</div></div>

<div class="card page-card"><div class="table-responsive">
<table class="table table-hover table-sm mb-0">
<thead><tr><th>ID</th><th>Employee ID</th><th>Name</th><th>Email</th><th>Role</th><th>User Type</th></tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= esc($u['id']) ?></td>
    <td><?= esc($u['employee_id']) ?></td>
    <td><?= esc($u['name']) ?></td>
    <td><?= esc($u['email']) ?></td>
    <td><span class="badge text-bg-light border"><?= esc($u['role']) ?></span></td>
    <td><?= esc((string) $u['user_type']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>
<?= $this->endSection() ?>
