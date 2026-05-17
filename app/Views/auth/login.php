<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'Sign In'; $pageSubtitle = 'Access the Integrated Business Enterprise Management System.'; ?>
<div class="row justify-content-center">
    <div class="col-lg-5 col-xl-4">
        <div class="card page-card">
            <div class="card-body p-4">
                <div class="text-center mb-3">
                    <h2 class="h5 mb-1">Welcome Back</h2>
                    <p class="text-secondary small mb-0">Enter your account credentials to continue.</p>
                </div>
                <form method="post" action="/auth/login" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input name="email" type="email" class="form-control" placeholder="name@domain.com" required autocomplete="username">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Password</label>
                        <input name="password" type="password" class="form-control" placeholder="Your password" required autocomplete="current-password">
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary" type="submit">Sign In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
